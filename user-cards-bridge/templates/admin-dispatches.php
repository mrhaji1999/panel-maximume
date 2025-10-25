<?php
/**
 * Dispatch Logs admin page.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!current_user_can('manage_options')) {
    wp_die(__('You do not have permission to access this page.', UCB_TEXT_DOMAIN));
}

global $wpdb;
$table = $wpdb->prefix . 'cb_dispatch_log';

$page     = max(1, (int) ($_GET['paged'] ?? 1));
$per_page = 20;
$status   = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
$store    = isset($_GET['store_url']) ? esc_url_raw($_GET['store_url']) : '';
$search   = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';

$where = ['1=1'];
$params = [];

if ($status !== '') {
    $where[] = 'status = %s';
    $params[] = $status;
}

if ($store !== '') {
    $where[] = 'store_url = %s';
    $params[] = untrailingslashit($store);
}

if ($search !== '') {
    $where[] = '(code LIKE %s OR user_email LIKE %s)';
    $like = '%' . $wpdb->esc_like($search) . '%';
    $params[] = $like;
    $params[] = $like;
}

$where_sql = implode(' AND ', $where);

$sql_total = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
if (!empty($params)) {
    $sql_total = $wpdb->prepare($sql_total, ...$params);
}
$total = (int) $wpdb->get_var($sql_total);

$total_pages = max(1, (int) ceil($total / $per_page));
$offset = ($page - 1) * $per_page;

$query_params = $params;
$query_params[] = $per_page;
$query_params[] = $offset;

$sql_rows = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY created_at DESC LIMIT %d OFFSET %d";
$sql_rows = $wpdb->prepare($sql_rows, ...$query_params);
$rows = $wpdb->get_results($sql_rows);

$statuses = [
    '' => __('All statuses', UCB_TEXT_DOMAIN),
    'pending' => __('Pending', UCB_TEXT_DOMAIN),
    'success' => __('Success', UCB_TEXT_DOMAIN),
    'failed' => __('Failed', UCB_TEXT_DOMAIN),
];
?>

<div class="wrap">
    <h1><?php _e('Dispatch History', UCB_TEXT_DOMAIN); ?></h1>

    <form method="get" class="ucb-filters">
        <input type="hidden" name="page" value="user-cards-bridge-dispatches">
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Search code or email', UCB_TEXT_DOMAIN); ?>">
        <select name="status">
            <?php foreach ($statuses as $value => $label): ?>
                <option value="<?php echo esc_attr($value); ?>" <?php selected($status, $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>
        <input type="url" name="store_url" value="<?php echo esc_attr($store); ?>" placeholder="https://store.example.com">
        <button type="submit" class="button"><?php _e('Filter', UCB_TEXT_DOMAIN); ?></button>
        <a href="<?php echo esc_url(admin_url('admin.php?page=user-cards-bridge-dispatches')); ?>" class="button"><?php _e('Reset', UCB_TEXT_DOMAIN); ?></a>
    </form>

    <?php if (empty($rows)): ?>
        <p><?php _e('No dispatch records found.', UCB_TEXT_DOMAIN); ?></p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Code', UCB_TEXT_DOMAIN); ?></th>
                    <th><?php _e('Store', UCB_TEXT_DOMAIN); ?></th>
                    <th><?php _e('Type', UCB_TEXT_DOMAIN); ?></th>
                    <th><?php _e('Amount', UCB_TEXT_DOMAIN); ?></th>
                    <th><?php _e('User', UCB_TEXT_DOMAIN); ?></th>
                    <th><?php _e('Status', UCB_TEXT_DOMAIN); ?></th>
                    <th><?php _e('Attempts', UCB_TEXT_DOMAIN); ?></th>
                    <th><?php _e('Last response', UCB_TEXT_DOMAIN); ?></th>
                    <th><?php _e('Updated', UCB_TEXT_DOMAIN); ?></th>
                    <th><?php _e('Actions', UCB_TEXT_DOMAIN); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($row->code); ?></strong><br>
                            <small><?php echo esc_html($row->dispatch_uuid); ?></small>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($row->store_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($row->store_url); ?></a>
                        </td>
                        <td><?php echo esc_html(ucfirst($row->type)); ?></td>
                        <td><?php echo esc_html(number_format_i18n((float) $row->amount, 2)) . ' ' . esc_html($row->currency); ?></td>
                        <td>
                            <?php echo esc_html($row->user_email); ?><br>
                            <small><?php printf(__('User ID: %d', UCB_TEXT_DOMAIN), (int) $row->user_id); ?></small>
                        </td>
                        <td><?php echo esc_html(ucfirst($row->status)); ?></td>
                        <td><?php echo esc_html((int) $row->attempts); ?></td>
                        <td>
                            <?php if ($row->last_response_code): ?>
                                <span><?php echo esc_html($row->last_response_code); ?></span><br>
                            <?php endif; ?>
                            <?php if (!empty($row->last_error)): ?>
                                <small><?php echo esc_html(wp_trim_words($row->last_error, 12)); ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($row->updated_at); ?></td>
                        <td>
                            <?php if ($row->status !== 'success'): ?>
                                <button type="button" class="button ucb-dispatch-retry" data-log-id="<?php echo esc_attr($row->id); ?>"><?php _e('Retry', UCB_TEXT_DOMAIN); ?></button>
                            <?php else: ?>
                                <span class="dashicons dashicons-yes"></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php if ($total_pages > 1): ?>
            <div class="tablenav">
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('&laquo;'),
                    'next_text' => __('&raquo;'),
                    'total' => $total_pages,
                    'current' => $page,
                ]);
                ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
jQuery(function($) {
    $('.ucb-dispatch-retry').on('click', function() {
        var $btn = $(this);
        var logId = $btn.data('log-id');
        $btn.prop('disabled', true).text('<?php echo esc_js(__('Retrying...', UCB_TEXT_DOMAIN)); ?>');

        $.post(ajaxurl, {
            action: 'ucb_dispatch_retry',
            nonce: ucbAdmin.nonce,
            log_id: logId
        }).done(function(response) {
            if (response.success) {
                window.location.reload();
            } else {
                alert(response.data && response.data.message ? response.data.message : '<?php echo esc_js(__('Retry failed.', UCB_TEXT_DOMAIN)); ?>');
                $btn.prop('disabled', false).text('<?php echo esc_js(__('Retry', UCB_TEXT_DOMAIN)); ?>');
            }
        }).fail(function() {
            alert('<?php echo esc_js(__('Retry failed.', UCB_TEXT_DOMAIN)); ?>');
            $btn.prop('disabled', false).text('<?php echo esc_js(__('Retry', UCB_TEXT_DOMAIN)); ?>');
        });
    });
});
</script>
