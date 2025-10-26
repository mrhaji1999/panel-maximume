# Woo Wallet Bridge

Woo Wallet Bridge is a lightweight companion plugin for WooCommerce stores that accepts wallet codes pushed from the main User Cards platform, exposes a REST API for remote redemptions, and offers rich wallet features for customers and administrators.

## Requirements

- WordPress 6.4 or newer
- WooCommerce 8.x or newer
- PHP 8.1 or newer

## Installation

1. Copy the `woo-wallet-bridge` folder into your destination store’s `wp-content/plugins/` directory.
2. Activate **Woo Wallet Bridge** from the WordPress Plugins screen.
3. Navigate to **Wallet Bridge → Settings** and configure:
   - API authentication (API key or JWT bearer token) and optional HMAC secret.
   - Default wallet code expiry and redemption rate limits.
   - Wallet “double credit” multiplier for paid top-ups.
4. Share the API key and HMAC secret with the source bridge (user-cards-bridge plugin).

## REST API

### Push wallet codes

`POST /wp-json/wwb/v1/wallet-codes`

```bash
curl https://dest-shop.example.com/wp-json/wwb/v1/wallet-codes \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
        "code": "ABC123",
        "amount": 150000,
        "user_id": 42,
        "expires_at": "2024-12-31T23:59:59"
      }'
```

### Redeem wallet codes (server-to-server)

`POST /wp-json/wwb/v1/redeem`

```bash
curl https://dest-shop.example.com/wp-json/wwb/v1/redeem \
  -H 'Authorization: Bearer YOUR_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{
        "code": "ABC123",
        "user_id": 42
      }'
```

All REST calls require a valid API key or JWT token. If an HMAC secret is configured, include an `X-WWB-Signature` header with the SHA-256 HMAC of the JSON body.

## Customer Experience

- **My Account → کیف پول من** – shows the current balance, a redemption form, and recent transactions.
- **My Account → افزایش اعتبار** – allows customers to top up their wallet. Confirmed payments are credited using the configured multiplier.
- **Checkout** – displays wallet balance, lets customers apply wallet funds, and redeem codes on the fly.

## Admin Features

- **Wallet Bridge → Settings** – API/auth configuration, top-up multiplier, expiry defaults, and rate-limiting.
- **Wallet Bridge → Wallet Codes** – searchable list of pushed codes with status and ownership.
- **Wallet Bridge → Transactions** – ledger of credits/debits with links to users and orders.

## Security Notes

- Nonces guard all customer-facing forms.
- REST endpoints enforce API key/JWT authentication and optional HMAC signatures.
- Code redemption is rate-limited (configurable window and maximum attempts) to mitigate brute-force attacks.

## Credits

Developed as part of the Panel Maximum user card ecosystem.
