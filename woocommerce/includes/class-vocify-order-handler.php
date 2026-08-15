<?php
/**
 * Order Handler Class
 *
 * Handles WooCommerce order events and sends webhooks to Vocify AI
 *
 * @package VocifyAI
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Vocify AI Order Handler
 */
class Vocify_AI_Order_Handler {

    /**
     * Webhook service instance
     *
     * @var Vocify_AI_Webhook_Service
     */
    private $webhook_service;

    /**
     * Constructor
     */
    public function __construct() {
        $this->webhook_service = new Vocify_AI_Webhook_Service();
        $this->init_hooks();
    }

    /**
     * Initialize WordPress/WooCommerce hooks
     */
    private function init_hooks() {
        // Order creation hook
        add_action('woocommerce_new_order', array($this, 'handle_new_order'), 10, 1);

        // Order status change hook
        add_action('woocommerce_order_status_changed', array($this, 'handle_order_status_change'), 10, 4);

        // Add meta box to order detail page
        add_action('add_meta_boxes', array($this, 'add_order_meta_box'));
    }

    /**
     * Handle new order creation
     *
     * @param int $order_id Order ID
     */
    public function handle_new_order($order_id) {
        // Check if integration is enabled
        if (get_option('vocify_enabled') !== 'yes') {
            return;
        }

        // Check if API key is configured
        $api_key = get_option('vocify_api_key');
        if (empty($api_key)) {
            if (get_option('vocify_debug_mode') === 'yes') {
                wc_get_logger()->warning('Vocify AI: API key not configured', array('source' => 'vocify-ai'));
            }
            return;
        }

        // Get order object
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // Send webhook
        $this->send_order_webhook($order, 'new_order');
    }

    /**
     * Handle order status change
     *
     * @param int    $order_id   Order ID
     * @param string $old_status Old status
     * @param string $new_status New status
     * @param object $order      Order object
     */
    public function handle_order_status_change($order_id, $old_status, $new_status, $order) {
        // Check if integration is enabled
        if (get_option('vocify_enabled') !== 'yes') {
            return;
        }

        // Check if API key is configured
        $api_key = get_option('vocify_api_key');
        if (empty($api_key)) {
            return;
        }

        // Only send webhook for specific status changes
        $trigger_statuses = array(
            'processing',  // Payment received
            'completed',   // Order fulfilled
            'cancelled',   // Order cancelled
            'refunded',    // Order refunded
            'failed',      // Payment failed
        );

        if (in_array($new_status, $trigger_statuses)) {
            $this->send_order_webhook($order, 'status_change');
        }
    }

    /**
     * Retry failed webhooks (scheduled hourly via WP-Cron)
     *
     * @return int Number of successfully re-sent webhooks.
     */
    public function retry_failed_webhooks() {
        return $this->webhook_service->retry_failed_webhooks();
    }

    /**
     * Send order webhook
     *
     * @param WC_Order $order      Order object
     * @param string   $event_type Event type (new_order, status_change)
     */
    private function send_order_webhook($order, $event_type) {
        try {
            $result = $this->webhook_service->send_order($order);

            // The webhook service already logs every attempt to the database
            // and manages the failed-webhook queue; the handler only surfaces
            // the outcome to the merchant via order notes and debug logs.
            if ($result['success']) {
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: Event type 2: HTTP code */
                        __('Vocify AI webhook sent successfully (Event: %s, HTTP Code: %d)', 'vocify-ai'),
                        $event_type,
                        $result['http_code']
                    )
                );

                if (get_option('vocify_debug_mode') === 'yes') {
                    wc_get_logger()->info(
                        sprintf('Vocify AI webhook sent for order #%d (Event: %s)', $order->get_id(), $event_type),
                        array('source' => 'vocify-ai')
                    );
                }
            } else {
                $order->add_order_note(
                    sprintf(
                        /* translators: 1: Event type 2: Error */
                        __('Vocify AI webhook failed (Event: %s, Error: %s)', 'vocify-ai'),
                        $event_type,
                        $result['error']
                    )
                );

                if (get_option('vocify_debug_mode') === 'yes') {
                    wc_get_logger()->error(
                        sprintf('Vocify AI webhook failed for order #%d: %s', $order->get_id(), $result['error']),
                        array('source' => 'vocify-ai')
                    );
                }
            }
        } catch (Exception $e) {
            $error_message = $e->getMessage();

            $order->add_order_note(
                sprintf(
                    /* translators: 1: Event type 2: Error */
                    __('Vocify AI webhook exception (Event: %s, Error: %s)', 'vocify-ai'),
                    $event_type,
                    $error_message
                )
            );

            if (get_option('vocify_debug_mode') === 'yes') {
                wc_get_logger()->critical(
                    sprintf('Vocify AI webhook exception for order #%d: %s', $order->get_id(), $error_message),
                    array('source' => 'vocify-ai', 'exception' => $e)
                );
            }
        }
    }

    /**
     * Add meta box to order detail page
     */
    public function add_order_meta_box() {
        // For WooCommerce HPOS compatibility
        $screen = class_exists('\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController') &&
                  wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
                  ? wc_get_page_screen_id('shop-order')
                  : 'shop_order';

        add_meta_box(
            'vocify_ai_order_info',
            __('Vocify AI - Order Confirmation Calls', 'vocify-ai'),
            array($this, 'render_order_meta_box'),
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Render order meta box content
     *
     * @param WP_Post|WC_Order $post_or_order Post or Order object
     */
    public function render_order_meta_box($post_or_order) {
        // Get order object (HPOS compatibility)
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);

        if (!$order) {
            echo '<p>' . esc_html__('Order not found.', 'vocify-ai') . '</p>';
            return;
        }

        $order_id = $order->get_id();

        // Check if integration is enabled
        $enabled = get_option('vocify_enabled') === 'yes';
        $api_key = get_option('vocify_api_key');

        if (!$enabled || empty($api_key)) {
            ?>
            <div class="vocify-status-disabled">
                <p><strong><?php esc_html_e('Status:', 'vocify-ai'); ?></strong> <?php esc_html_e('Integration not configured', 'vocify-ai'); ?></p>
                <p>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=vocify-ai')); ?>" class="button button-secondary">
                        <?php esc_html_e('Configure Vocify AI', 'vocify-ai'); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }

        // Get webhook logs for this order
        global $wpdb;
        $table_name = $wpdb->prefix . 'vocify_webhook_logs';

        $logs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE order_id = %d ORDER BY created_at DESC LIMIT 10",
                $order_id
            )
        );

        ?>
        <div class="vocify-order-info">
            <?php if (empty($logs)) : ?>
                <p><em><?php esc_html_e('No webhook activity for this order yet.', 'vocify-ai'); ?></em></p>
            <?php else : ?>
                <table class="widefat">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Status', 'vocify-ai'); ?></th>
                            <th><?php esc_html_e('HTTP Code', 'vocify-ai'); ?></th>
                            <th><?php esc_html_e('Date', 'vocify-ai'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td>
                                    <?php
                                    $status_class = 'success' === $log->status ? 'vocify-status-success' : 'vocify-status-failed';
                                    $status_label = 'success' === $log->status ? __('Success', 'vocify-ai') : __('Failed', 'vocify-ai');
                                    ?>
                                    <span class="<?php echo esc_attr($status_class); ?>">
                                        <?php echo esc_html($status_label); ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($log->http_code ?: 'N/A'); ?></td>
                                <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->created_at))); ?></td>
                            </tr>
                            <?php if (!empty($log->error_message)) : ?>
                                <tr>
                                    <td colspan="3">
                                        <small><strong><?php esc_html_e('Error:', 'vocify-ai'); ?></strong> <?php echo esc_html($log->error_message); ?></small>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <style>
            .vocify-status-success {
                color: #2e7d32;
                font-weight: bold;
            }
            .vocify-status-failed {
                color: #c62828;
                font-weight: bold;
            }
            .vocify-order-info table {
                margin-top: 10px;
            }
            .vocify-order-info table th {
                font-weight: bold;
                background: #f5f5f5;
            }
        </style>
        <?php
    }
}
