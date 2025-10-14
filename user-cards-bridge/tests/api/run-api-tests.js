#!/usr/bin/env node
'use strict';

/**
 * User Cards Bridge API smoke test runner.
 *
 * This script exercises all REST endpoints exposed by the plugin and ensures that
 * dependency warnings such as “requires WooCommerce” or “requires JWT Authentication”
 * do not appear in responses.
 *
 * Configuration is controlled via environment variables. The script expects at least
 * the base URL and manager credentials used for JWT authentication. Optional IDs allow
 * deeper coverage (customer specific endpoints, schedule updates, etc.).
 *
 * Required:
 *   - UCB_BASE_URL (e.g. https://example.com)
 *   - UCB_MANAGER_USERNAME
 *   - UCB_MANAGER_PASSWORD
 *
 * Optional flags (set only if you have data prepared):
 *   - UCB_SUPERVISOR_ID, UCB_SUPERVISOR_CARD_IDS (JSON array)
 *   - UCB_CARD_ID, UCB_CARD_FIELD_KEY
 *   - UCB_SUPERVISOR_TARGET_ID (for assigning cards)
 *   - UCB_AGENT_ID, UCB_AGENT_SUPERVISOR_ID, UCB_AGENT_NEW_SUPERVISOR_ID
 *   - UCB_CUSTOMER_ID, UCB_CUSTOMER_STATUS, UCB_CUSTOMER_NOTE,
 *     UCB_ASSIGN_SUPERVISOR_ID, UCB_ASSIGN_AGENT_ID
 *   - UCB_STATUS_META (JSON object passed when updating status)
 *   - UCB_FORM_ID
 *   - UCB_SCHEDULE_SUPERVISOR_ID, UCB_SCHEDULE_CARD_ID, UCB_SCHEDULE_MATRIX (JSON array)
 *   - UCB_AVAILABILITY_CARD_ID, UCB_AVAILABILITY_SUPERVISOR_ID
 *   - UCB_RESERVATION (JSON object with customer_id, card_id, supervisor_id, weekday, hour)
 *   - UCB_SMS_PHONE, UCB_SMS_BODY_ID, UCB_SMS_TEXT (JSON array of template variables),
 *     UCB_RUN_SMS_TEST (set to "true" to actually call /sms/test)
 *   - UCB_WEBHOOK_SECRET, UCB_WEBHOOK_PAYLOAD (JSON sent to the webhook)
 *
 * Usage:
 *   node tests/api/run-api-tests.js
 *
 * Tip: you can prefix the command with environment variables or use a .env file
 * (install `dotenv` locally if you want automatic loading).
 */

// Attempt to load .env files when dotenv is available.
try {
  // eslint-disable-next-line global-require, import/no-extraneous-dependencies
  require('dotenv').config();
} catch (err) {
  // Ignore when dotenv is not installed.
}

if (typeof fetch !== 'function') {
  console.error('❌ This script requires Node.js 18 or newer (fetch API not found).');
  process.exit(1);
}

const { URL } = require('url');
const crypto = require('crypto');

function env(name, fallback = '') {
  const value = process.env[name];
  return value === undefined ? fallback : value;
}

function toInt(value) {
  if (value === undefined || value === null || value === '') {
    return null;
  }
  const parsed = Number.parseInt(value, 10);
  return Number.isNaN(parsed) ? null : parsed;
}

function parseJSON(value) {
  if (!value) {
    return null;
  }
  try {
    return JSON.parse(value);
  } catch (err) {
    console.warn(`⚠️  Failed to parse JSON value: ${value}`);
    return null;
  }
}

const config = {
  baseUrl: env('UCB_BASE_URL'),
  managerUsername: env('UCB_MANAGER_USERNAME'),
  managerPassword: env('UCB_MANAGER_PASSWORD'),
  supervisorId: toInt(env('UCB_SUPERVISOR_ID')),
  supervisorTargetId: toInt(env('UCB_SUPERVISOR_TARGET_ID')),
  supervisorCardIds: parseJSON(env('UCB_SUPERVISOR_CARD_IDS')),
  cardId: toInt(env('UCB_CARD_ID')),
  cardFieldKey: env('UCB_CARD_FIELD_KEY'),
  agentId: toInt(env('UCB_AGENT_ID')),
  agentSupervisorId: toInt(env('UCB_AGENT_SUPERVISOR_ID')),
  agentNewSupervisorId: toInt(env('UCB_AGENT_NEW_SUPERVISOR_ID')),
  customerId: toInt(env('UCB_CUSTOMER_ID')),
  customerStatus: env('UCB_CUSTOMER_STATUS', 'normal'),
  customerNote: env('UCB_CUSTOMER_NOTE', 'Automated test note'),
  assignSupervisorId: toInt(env('UCB_ASSIGN_SUPERVISOR_ID')),
  assignAgentId: toInt(env('UCB_ASSIGN_AGENT_ID')),
  statusMeta: parseJSON(env('UCB_STATUS_META')),
  formId: toInt(env('UCB_FORM_ID')),
  scheduleSupervisorId: toInt(env('UCB_SCHEDULE_SUPERVISOR_ID')),
  scheduleCardId: toInt(env('UCB_SCHEDULE_CARD_ID')),
  scheduleMatrix: parseJSON(env('UCB_SCHEDULE_MATRIX')),
  availabilityCardId: toInt(env('UCB_AVAILABILITY_CARD_ID')),
  availabilitySupervisorId: toInt(env('UCB_AVAILABILITY_SUPERVISOR_ID')),
  reservationPayload: parseJSON(env('UCB_RESERVATION')),
  smsPhone: env('UCB_SMS_PHONE'),
  smsBodyId: env('UCB_SMS_BODY_ID'),
  smsText: parseJSON(env('UCB_SMS_TEXT')),
  runSmsConfigTest: env('UCB_RUN_SMS_TEST', 'false').toLowerCase() === 'true',
  webhookSecret: env('UCB_WEBHOOK_SECRET'),
  webhookPayload: parseJSON(env('UCB_WEBHOOK_PAYLOAD'))
};

if (!config.baseUrl || !config.managerUsername || !config.managerPassword) {
  console.error('❌ Missing required configuration. Please set UCB_BASE_URL, UCB_MANAGER_USERNAME, and UCB_MANAGER_PASSWORD.');
  process.exit(1);
}

const state = {
  token: null,
  managerId: null
};

const results = [];

async function test(name, fn, options = {}) {
  const { skip = false } = options;
  if (skip) {
    console.log(`⏭️  Skipping ${name}`);
    results.push({ name, status: 'skipped' });
    return;
  }

  const started = Date.now();
  try {
    await fn();
    const duration = Date.now() - started;
    console.log(`✅ ${name} (${duration} ms)`);
    results.push({ name, status: 'passed', duration });
  } catch (error) {
    const duration = Date.now() - started;
    console.error(`❌ ${name} (${duration} ms)`);
    console.error(error.message);
    if (error.responseText) {
      console.error(error.responseText);
    }
    results.push({ name, status: 'failed', error, duration });
  }
}

function buildUrl(path) {
  const trimmedBase = config.baseUrl.replace(/\/+$/, '');
  if (!path.startsWith('/')) {
    return `${trimmedBase}/${path}`;
  }
  return `${trimmedBase}${path}`;
}

async function apiRequest(method, path, options = {}) {
  const {
    auth = true,
    body,
    headers = {},
    allowStatuses = [200, 201],
    description
  } = options;

  const requestHeaders = {
    'Content-Type': 'application/json',
    ...headers
  };

  if (auth) {
    if (!state.token) {
      throw new Error('JWT token not available. Did the login test run first?');
    }
    requestHeaders.Authorization = `Bearer ${state.token}`;
  }

  const url = new URL(buildUrl(path));
  const response = await fetch(url, {
    method,
    headers: requestHeaders,
    body: body !== undefined ? JSON.stringify(body) : undefined
  });

  const responseText = await response.text();

  if (/requires\s+WooCommerce/i.test(responseText) || /requires\s+JWT Authentication/i.test(responseText)) {
    const err = new Error('Dependency warning detected in API response. Ensure WooCommerce and JWT Authentication are active.');
    err.responseText = responseText;
    throw err;
  }

  let json = null;
  try {
    json = responseText ? JSON.parse(responseText) : null;
  } catch (err) {
    // Not JSON, leave as null.
  }

  if (!allowStatuses.includes(response.status)) {
    const err = new Error(`Unexpected HTTP status ${response.status}${description ? ` for ${description}` : ''}`);
    err.responseText = responseText.slice(0, 500);
    throw err;
  }

  if (json && json.success === false) {
    const err = new Error(`API responded with success=false (${json.error?.code || 'unknown error'})`);
    err.responseText = JSON.stringify(json, null, 2);
    throw err;
  }

  return { response, json, text: responseText };
}

async function loginManager() {
  const payload = {
    username: config.managerUsername,
    password: config.managerPassword,
    role: 'company_manager'
  };

  const { json } = await apiRequest('POST', '/wp-json/user-cards-bridge/v1/auth/login', {
    auth: false,
    body: payload
  });

  if (!json?.data?.token) {
    throw new Error('Login succeeded but no token returned.');
  }

  state.token = json.data.token;
  state.managerId = json.data.user?.id || json.data.user?.ID || null;
}

async function main() {
  console.log('🔍 User Cards Bridge API test suite starting…');

  await test('Authenticate as company manager', loginManager);

  await test('List managers', async () => {
    const { json } = await apiRequest('GET', '/wp-json/user-cards-bridge/v1/managers');
    if (!Array.isArray(json?.data?.items)) {
      throw new Error('Managers response does not contain items array.');
    }
  });

  await test('List supervisors', async () => {
    const { json } = await apiRequest('GET', '/wp-json/user-cards-bridge/v1/supervisors?page=1&per_page=20');
    if (!json?.data?.items) {
      throw new Error('Supervisors response missing items.');
    }
  });

  await test('List agents', async () => {
    const { json } = await apiRequest('GET', '/wp-json/user-cards-bridge/v1/agents?page=1&per_page=20');
    if (!json?.data?.items) {
      throw new Error('Agents response missing items.');
    }
  });

  await test('List customers', async () => {
    const { json } = await apiRequest('GET', '/wp-json/user-cards-bridge/v1/customers?page=1&per_page=20');
    if (!json?.data?.items) {
      throw new Error('Customers response missing items.');
    }
  });

  await test('Customer tabs overview', async () => {
    const { json } = await apiRequest('GET', '/wp-json/user-cards-bridge/v1/customers/tabs');
    if (!json?.data?.tabs) {
      throw new Error('Customer tabs payload missing.');
    }
  });

  await test('List cards', async () => {
    const { json } = await apiRequest('GET', '/wp-json/user-cards-bridge/v1/cards?page=1&per_page=20');
    if (!json?.data?.items) {
      throw new Error('Cards response missing items.');
    }
  });

  await test('List forms', async () => {
    const { json } = await apiRequest('GET', '/wp-json/user-cards-bridge/v1/forms?page=1&per_page=20');
    if (!json?.data?.items) {
      throw new Error('Forms response missing items.');
    }
  });

  await test('List reservations', async () => {
    const { json } = await apiRequest('GET', '/wp-json/user-cards-bridge/v1/reservations?page=1&per_page=20');
    if (!json?.data?.items) {
      throw new Error('Reservations response missing items.');
    }
  });

  await test('SMS logs listing', async () => {
    const { json } = await apiRequest('GET', '/wp-json/user-cards-bridge/v1/sms/logs?page=1&per_page=20');
    if (!json?.data?.items) {
      throw new Error('SMS logs response missing items.');
    }
  });

  await test('SMS statistics', async () => {
    const { json } = await apiRequest('GET', '/wp-json/user-cards-bridge/v1/sms/statistics?days=7');
    if (!json?.data) {
      throw new Error('SMS statistics response missing data.');
    }
  });

  await test('Dashboard summary statistics', async () => {
    const { json } = await apiRequest('GET', '/wp-json/user-cards-bridge/v1/dashboard/summary?days=30&activity=10');
    if (!json?.data) {
      throw new Error('Dashboard summary missing data field.');
    }
  });

  // -------- Optional coverage --------

  await test('Supervisor card assignment', async () => {
    if (!config.supervisorTargetId || !Array.isArray(config.supervisorCardIds)) {
      throw new Error('Missing UCB_SUPERVISOR_TARGET_ID or UCB_SUPERVISOR_CARD_IDS for this test.');
    }
    const { json } = await apiRequest(
      'POST',
      `/wp-json/user-cards-bridge/v1/supervisors/${config.supervisorTargetId}/cards`,
      { body: { cards: config.supervisorCardIds, set_default: true } }
    );
    if (!json?.data?.cards) {
      throw new Error('Supervisor cards response missing cards array.');
    }
  }, { skip: !config.supervisorTargetId || !Array.isArray(config.supervisorCardIds) });

  await test('Card fields lookup', async () => {
    const cardId = config.cardId;
    if (!cardId) {
      throw new Error('Set UCB_CARD_ID to test card fields.');
    }
    const { json } = await apiRequest('GET', `/wp-json/user-cards-bridge/v1/cards/${cardId}/fields`);
    if (!json?.data?.fields) {
      throw new Error('Card fields response missing fields.');
    }
  }, { skip: !config.cardId });

  await test('Supervisor card list', async () => {
    if (!config.supervisorId) {
      throw new Error('Set UCB_SUPERVISOR_ID to test supervisor card listing.');
    }
    const { json } = await apiRequest('GET', `/wp-json/user-cards-bridge/v1/supervisors/${config.supervisorId}/cards`);
    if (!json?.data?.items) {
      throw new Error('Supervisor cards response missing items.');
    }
  }, { skip: !config.supervisorId });

  await test('Form detail', async () => {
    if (!config.formId) {
      throw new Error('Set UCB_FORM_ID to test form detail.');
    }
    const { json } = await apiRequest('GET', `/wp-json/user-cards-bridge/v1/forms/${config.formId}`);
    if (!json?.data) {
      throw new Error('Form detail response missing data.');
    }
  }, { skip: !config.formId });

  await test('Schedule matrix retrieval', async () => {
    if (!config.scheduleSupervisorId || !config.scheduleCardId) {
      throw new Error('Set UCB_SCHEDULE_SUPERVISOR_ID and UCB_SCHEDULE_CARD_ID.');
    }
    const { json } = await apiRequest('GET', `/wp-json/user-cards-bridge/v1/schedule/${config.scheduleSupervisorId}/${config.scheduleCardId}`);
    if (!json?.data?.matrix) {
      throw new Error('Schedule response missing matrix.');
    }
  }, { skip: !config.scheduleSupervisorId || !config.scheduleCardId });

  await test('Schedule matrix update', async () => {
    if (!config.scheduleSupervisorId || !config.scheduleCardId || !Array.isArray(config.scheduleMatrix)) {
      throw new Error('Provide UCB_SCHEDULE_SUPERVISOR_ID, UCB_SCHEDULE_CARD_ID, and UCB_SCHEDULE_MATRIX.');
    }
    const { json } = await apiRequest(
      'PUT',
      `/wp-json/user-cards-bridge/v1/schedule/${config.scheduleSupervisorId}/${config.scheduleCardId}`,
      { body: { matrix: config.scheduleMatrix } }
    );
    if (!json?.data?.matrix) {
      throw new Error('Schedule update response missing matrix.');
    }
  }, { skip: !config.scheduleSupervisorId || !config.scheduleCardId || !Array.isArray(config.scheduleMatrix) });

  await test('Card availability', async () => {
    if (!config.availabilityCardId) {
      throw new Error('Set UCB_AVAILABILITY_CARD_ID (and optional supervisor).');
    }
    const query = config.availabilitySupervisorId
      ? `?supervisor_id=${config.availabilitySupervisorId}`
      : '';
    const { json } = await apiRequest('GET', `/wp-json/user-cards-bridge/v1/availability/${config.availabilityCardId}${query}`);
    if (!json?.data?.slots) {
      throw new Error('Availability response missing slots array.');
    }
  }, { skip: !config.availabilityCardId });

  await test('Reservation creation', async () => {
    const payload = config.reservationPayload;
    if (!payload) {
      throw new Error('Provide UCB_RESERVATION JSON object to create reservations.');
    }
    await apiRequest('POST', '/wp-json/user-cards-bridge/v1/reservations', {
      body: payload,
      allowStatuses: [201]
    });
  }, { skip: !config.reservationPayload });

  await test('Customer detail', async () => {
    if (!config.customerId) {
      throw new Error('Set UCB_CUSTOMER_ID to inspect customer detail.');
    }
    const { json } = await apiRequest('GET', `/wp-json/user-cards-bridge/v1/customers/${config.customerId}`);
    if (!json?.data) {
      throw new Error('Customer detail response missing data.');
    }
  }, { skip: !config.customerId });

  await test('Customer status update', async () => {
    if (!config.customerId) {
      throw new Error('Set UCB_CUSTOMER_ID to update status.');
    }
    await apiRequest('PATCH', `/wp-json/user-cards-bridge/v1/customers/${config.customerId}/status`, {
      body: {
        status: config.customerStatus || 'normal',
        meta: config.statusMeta || {}
      }
    });
  }, { skip: !config.customerId });

  await test('Add customer note', async () => {
    if (!config.customerId) {
      throw new Error('Set UCB_CUSTOMER_ID to add note.');
    }
    await apiRequest('POST', `/wp-json/user-cards-bridge/v1/customers/${config.customerId}/notes`, {
      body: { note: config.customerNote || 'Automated test note' },
      allowStatuses: [201]
    });
  }, { skip: !config.customerId });

  await test('Assign supervisor to customer', async () => {
    if (!config.customerId || !config.assignSupervisorId) {
      throw new Error('Set UCB_CUSTOMER_ID and UCB_ASSIGN_SUPERVISOR_ID.');
    }
    await apiRequest('POST', `/wp-json/user-cards-bridge/v1/customers/${config.customerId}/assign-supervisor`, {
      body: { supervisor_id: config.assignSupervisorId }
    });
  }, { skip: !config.customerId || !config.assignSupervisorId });

  await test('Assign agent to customer', async () => {
    if (!config.customerId || !config.assignAgentId) {
      throw new Error('Set UCB_CUSTOMER_ID and UCB_ASSIGN_AGENT_ID.');
    }
    await apiRequest('POST', `/wp-json/user-cards-bridge/v1/customers/${config.customerId}/assign-agent`, {
      body: { agent_id: config.assignAgentId }
    });
  }, { skip: !config.customerId || !config.assignAgentId });

  await test('Initiate upsell process', async () => {
    if (!config.customerId || !config.cardId || !config.cardFieldKey) {
      throw new Error('Set UCB_CUSTOMER_ID, UCB_CARD_ID, and UCB_CARD_FIELD_KEY.');
    }
    await apiRequest('POST', `/wp-json/user-cards-bridge/v1/customers/${config.customerId}/upsell/init`, {
      body: {
        card_id: config.cardId,
        field_key: config.cardFieldKey
      }
    });
  }, { skip: !config.customerId || !config.cardId || !config.cardFieldKey });

  await test('Send SMS', async () => {
    if (!config.smsPhone || !config.smsBodyId) {
      throw new Error('Set UCB_SMS_PHONE and UCB_SMS_BODY_ID to send SMS.');
    }
    await apiRequest('POST', '/wp-json/user-cards-bridge/v1/sms/send', {
      body: {
        to: config.smsPhone,
        bodyId: config.smsBodyId,
        text: Array.isArray(config.smsText) ? config.smsText : []
      }
    });
  }, { skip: !config.smsPhone || !config.smsBodyId });

  await test('Send normal status code', async () => {
    if (!config.customerId) {
      throw new Error('Set UCB_CUSTOMER_ID to send normal status code.');
    }
    await apiRequest('POST', `/wp-json/user-cards-bridge/v1/customers/${config.customerId}/normal/send-code`);
  }, { skip: !config.customerId });

  await test('SMS configuration self-test', async () => {
    await apiRequest('POST', '/wp-json/user-cards-bridge/v1/sms/test', {
      allowStatuses: [200, 400] // 400 indicates configuration error with message.
    });
  }, { skip: !config.runSmsConfigTest });

  await test('WooCommerce payment webhook', async () => {
    if (!config.webhookSecret || !config.webhookPayload) {
      throw new Error('Provide UCB_WEBHOOK_SECRET and UCB_WEBHOOK_PAYLOAD.');
    }
    const body = JSON.stringify(config.webhookPayload);
    const signature = crypto
      .createHmac('sha256', config.webhookSecret)
      .update(body)
      .digest('base64');
    await apiRequest('POST', '/wp-json/user-cards-bridge/v1/webhooks/woocommerce/payment', {
      auth: false,
      headers: {
        'X-WC-Webhook-Signature': signature
      },
      body: config.webhookPayload,
      allowStatuses: [200, 400, 404]
    });
  }, { skip: !config.webhookSecret || !config.webhookPayload });

  // -------- Summary --------

  const passed = results.filter((item) => item.status === 'passed').length;
  const failed = results.filter((item) => item.status === 'failed');
  const skipped = results.filter((item) => item.status === 'skipped').length;

  console.log('─────────────');
  console.log(`✅ Passed:   ${passed}`);
  console.log(`⏭️  Skipped:  ${skipped}`);
  console.log(`❌ Failed:   ${failed.length}`);

  if (failed.length > 0) {
    process.exitCode = 1;
  } else {
    console.log('🎉 All executed tests passed.');
  }
}

main().catch((err) => {
  console.error('Unexpected error while running the test suite.');
  console.error(err);
  process.exit(1);
});
