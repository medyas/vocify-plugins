<?php
/**
 * Vocify Webhook Service
 *
 * Handles webhook sending, data transformation, and retry logic
 *
 * @package VocifyAI
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vocify_AI_Webhook_Service {

    const PLATFORM = 'WOOCOMMERCE';
    const MAX_RETRIES = 3;
    const FAILED_QUEUE_MAX_RETRIES = 5;
    const FAILED_QUEUE_BATCH = 20;

    /**
     * Payload builder instance
     *
     * @var Vocify_AI_Payload_Builder
     */
    private $builder;

    /**
     * Payload validator instance
     *
     * @var Vocify_AI_Payload_Validator
     */
    private $validator;

    /**
     * Signer instance
     *
     * @var Vocify_AI_Signer
     */
    private $signer;

    /**
     * Constructor
     */
    public function __construct() {
        $this->builder = new Vocify_AI_Payload_Builder();
        $this->validator = new Vocify_AI_Payload_Validator();
        $this->signer = new Vocify_AI_Signer();
    }

    /**
     * Send order to Vocify AI
     *
     * @param WC_Order $order
     * @return array Result: { success, http_code, response, error, retries_exhausted, payload }
     */
    public function send_order($order) {
        $api_key = get_option('vocify_api_key');
        $webhook_url = get_option('vocify_webhook_url');
        $debug_mode = get_option('vocify_debug_mode') === 'yes';

        if (empty($api_key)) {
            $this->log($order->get_id(), 'error', 0, 'API key not configured');
            return $this->result(false, 0, null, 'API key not configured', false, null);
        }

        // Transform order to unified payload
        $payload = $this->transform_order($order);

        if (!$payload) {
            $message = 'Failed to transform order data';
            $this->log($order->get_id(), 'error', 0, $message);
            return $this->result(false, 0, null, $message, false, null);
        }

        // Fail fast on payloads the platform would reject with 400 — a local
        // validation error is a configuration/data problem, not transient.
        $errors = $this->validator->validate($payload);

        if (count($errors) > 0) {
            $message = 'Payload validation failed: ' . implode('; ', array_slice($errors, 0, 5));
            $this->log($order->get_id(), 'error', 0, $message);

            if ($debug_mode) {
                wc_get_logger()->error(
                    'Vocify AI payload invalid for order #' . $order->get_id() . ': ' . $message,
                    array('source' => 'vocify-ai')
                );
            }

            return $this->result(false, 400, null, $message, false, $payload);
        }

        // Send webhook with retry logic
        return $this->send_webhook_with_retry($order->get_id(), $payload, $api_key, $webhook_url, $debug_mode);
    }

    /**
     * Transform WooCommerce order to unified payload format
     *
     * @param WC_Order $order
     * @return array|null
     */
    public function transform_order($order) {
        try {
            $customer_id = $order->get_customer_id();
            $customer_orders_count = 0;
            $customer_total_spent = 0;

            if ($customer_id) {
                $customer = new WC_Customer($customer_id);
                $customer_orders_count = $customer->get_order_count();
                $customer_total_spent = $customer->get_total_spent();
            }

            $default_country = $order->get_billing_country();
            if (empty($default_country)) {
                $default_country = $order->get_shipping_country();
            }
            if (empty($default_country)) {
                $default_country = wc()->countries->get_base_country();
            }
            if (empty($default_country)) {
                $default_country = 'US';
            }

            // Raw data map — consumed by the platform-agnostic payload builder.
            $raw = array(
                'order_id' => (string)$order->get_id(),
                'order_number' => (string)$order->get_order_number(),
                'order_key' => $order->get_order_key(),
                'status' => $order->get_status(),
                'financial_status' => $this->get_financial_status($order),
                'fulfillment_status' => $this->get_fulfillment_status($order),
                'is_paid' => $order->is_paid(),
                'total_refunded' => (float)$order->get_total_refunded(),
                'default_country' => $default_country,
                'customer' => array(
                    'id' => (string)$customer_id,
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'phone' => $order->get_billing_phone(),
                    'is_new_customer' => $customer_orders_count === 0,
                    'total_orders' => $customer_orders_count,
                    'total_spent' => (float)$customer_total_spent,
                ),
                'items' => $this->extract_items($order),
                'totals' => array(
                    'subtotal' => (float)$order->get_subtotal(),
                    'discount' => (float)$order->get_total_discount(),
                    'shipping' => (float)$order->get_shipping_total(),
                    'tax' => (float)$order->get_total_tax(),
                    'total' => (float)$order->get_total(),
                ),
                'currency' => $order->get_currency(),
                'shipping_address' => array(
                    'first_name' => $order->get_shipping_first_name(),
                    'last_name' => $order->get_shipping_last_name(),
                    'company' => $order->get_shipping_company(),
                    'address1' => $order->get_shipping_address_1(),
                    'address2' => $order->get_shipping_address_2(),
                    'city' => $order->get_shipping_city(),
                    'state' => $order->get_shipping_state(),
                    'zip' => $order->get_shipping_postcode(),
                    'country' => $order->get_shipping_country(),
                    'phone' => $order->get_billing_phone(),
                ),
                'billing_address' => array(
                    'first_name' => $order->get_billing_first_name(),
                    'last_name' => $order->get_billing_last_name(),
                    'company' => $order->get_billing_company(),
                    'address1' => $order->get_billing_address_1(),
                    'address2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'zip' => $order->get_billing_postcode(),
                    'country' => $order->get_billing_country(),
                    'phone' => $order->get_billing_phone(),
                ),
                'payment_method' => $order->get_payment_method(),
                'payment_method_title' => $order->get_payment_method_title(),
                'transaction_id' => $order->get_transaction_id(),
                'created_at' => $this->date_timestamp($order->get_date_created()),
                'updated_at' => $this->date_timestamp($order->get_date_modified()),
                'paid_at' => $this->date_timestamp($order->get_date_paid()),
                'customer_note' => $order->get_customer_note(),
                'needs_shipping' => $order->needs_shipping_address(),
                'tax_included' => wc_prices_include_tax(),
                'metadata' => array(
                    'woocommerceOrderKey' => $order->get_order_key(),
                    'woocommerceOrderId' => (string)$order->get_id(),
                    'woocommerceCustomerId' => (string)$customer_id,
                ),
            );

            return $this->builder->build($raw);
        } catch (Exception $e) {
            wc_get_logger()->error(
                'Vocify AI: Failed to transform order: ' . $e->getMessage(),
                array('source' => 'vocify-ai', 'order_id' => $order->get_id())
            );
            return null;
        }
    }

    /**
     * Extract order items into the normalized format.
     *
     * @param WC_Order $order
     * @return array
     */
    private function extract_items($order) {
        $items = array();

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            $product_id = $product ? $product->get_id() : 0;
            $image_id = $product ? $product->get_image_id() : 0;
            $image_url = $image_id ? wp_get_attachment_url($image_id) : '';

            if (!is_string($image_url)) {
                $image_url = '';
            }

            // Get product variations/attributes
            $attributes = array();
            if ($product && $product->is_type('variation')) {
                $attributes = $product->get_variation_attributes();
            }

            $items[] = array(
                'id' => (string)$product_id,
                'name' => $item->get_name(),
                'sku' => $product ? $product->get_sku() : '',
                'quantity' => (int)$item->get_quantity(),
                'price' => (float)$order->get_item_total($item, true), // Include tax
                'total' => (float)$order->get_line_total($item, true), // Include tax
                'image' => $image_url,
                'attributes' => $attributes,
            );
        }

        return $items;
    }

    /**
     * Convert a WC_DateTime to a unix timestamp, or null when absent.
     *
     * @param mixed $date
     * @return int|null
     */
    private function date_timestamp($date) {
        if ($date instanceof WC_DateTime) {
            return $date->getTimestamp();
        }

        return null;
    }

    /**
     * Get financial status based on order status
     *
     * @param WC_Order $order
     * @return string
     */
    private function get_financial_status($order) {
        $status = $order->get_status();
        $total_refunded = (float)$order->get_total_refunded();
        $total = (float)$order->get_total();

        // Check for refunds
        if ($total_refunded > 0) {
            if ($total_refunded >= $total) {
                return 'refunded';
            }
            return 'partially_refunded';
        }

        // Map WooCommerce status to financial status
        switch ($status) {
            case 'pending':
            case 'on-hold':
                return 'pending';

            case 'processing':
            case 'completed':
                return 'paid';

            case 'refunded':
                return 'refunded';

            case 'failed':
            case 'cancelled':
                return 'voided';

            default:
                // Check if order has been paid
                if ($order->is_paid()) {
                    return 'paid';
                }
                return 'pending';
        }
    }

    /**
     * Get fulfillment status based on order status
     *
     * @param WC_Order $order
     * @return string
     */
    private function get_fulfillment_status($order) {
        $status = $order->get_status();

        switch ($status) {
            case 'completed':
                return 'fulfilled';

            case 'cancelled':
            case 'refunded':
                return 'restocked';

            case 'pending':
            case 'on-hold':
            case 'processing':
            case 'failed':
            default:
                return 'unfulfilled';
        }
    }

    /**
     * Send webhook with retry logic
     *
     * @param int    $order_id
     * @param array  $payload
     * @param string $api_key
     * @param string $webhook_url
     * @param bool   $debug_mode
     * @return array Result array (see self::result()).
     */
    private function send_webhook_with_retry($order_id, $payload, $api_key, $webhook_url, $debug_mode = false) {
        $last_error = '';
        $last_http_code = 0;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $result = $this->send_webhook($payload, $api_key, $webhook_url);

                if ($result['success']) {
                    $this->log($order_id, 'success', $result['http_code'], json_encode($result['response']));

                    if ($debug_mode) {
                        wc_get_logger()->info(
                            'Vocify AI: Order #' . $payload['orderNumber'] . ' sent successfully',
                            array('source' => 'vocify-ai')
                        );
                    }

                    // Remove from failed queue if exists
                    $this->remove_from_failed_queue($order_id);

                    return $this->result(true, $result['http_code'], $result['response'], null, false, $payload);
                }

                $last_error = $result['error'];
                $last_http_code = $result['http_code'];

                // Don't retry client errors (4xx) — configuration problem
                if ($result['http_code'] >= 400 && $result['http_code'] < 500) {
                    $this->log($order_id, 'error', $result['http_code'], $last_error);

                    if ($debug_mode) {
                        wc_get_logger()->error(
                            'Vocify AI: Client error (no retry) - Order #' . $payload['orderNumber'] .
                            ' - HTTP ' . $result['http_code'] . ': ' . $last_error,
                            array('source' => 'vocify-ai')
                        );
                    }

                    return $this->result(false, $result['http_code'], $result['response'], $last_error, false, $payload);
                }

                // Log retry attempt
                if ($debug_mode) {
                    wc_get_logger()->warning(
                        'Vocify AI: Retry attempt ' . $attempt . '/' . self::MAX_RETRIES .
                        ' for order #' . $payload['orderNumber'] . ' - Error: ' . $last_error,
                        array('source' => 'vocify-ai')
                    );
                }

                // Exponential backoff (2s, 4s, 8s)
                if ($attempt < self::MAX_RETRIES) {
                    sleep(pow(2, $attempt));
                }
            } catch (Exception $e) {
                $last_error = $e->getMessage();
                $last_http_code = 0;

                if ($attempt < self::MAX_RETRIES) {
                    sleep(pow(2, $attempt));
                }
            }
        }

        // All retries failed - add to failed queue
        $this->log($order_id, 'failed', $last_http_code, $last_error);
        $this->add_to_failed_queue($order_id, $payload, $last_error);

        if ($debug_mode) {
            wc_get_logger()->error(
                'Vocify AI: All retries failed for order #' . $payload['orderNumber'] . ' - Last error: ' . $last_error,
                array('source' => 'vocify-ai')
            );
        }

        return $this->result(false, $last_http_code, null, $last_error, true, $payload);
    }

    /**
     * Send webhook to Vocify AI
     *
     * The request body MUST be the exact string the HMAC signature was
     * computed over — the platform verifies the signature against the raw
     * body it receives.
     *
     * @param array  $payload
     * @param string $api_key
     * @param string $webhook_url
     * @return array
     */
    public function send_webhook($payload, $api_key, $webhook_url) {
        $store_domain = $this->signer->extract_domain(get_site_url());
        $signature_secret = get_option('vocify_signature_secret', '');

        $raw_body = json_encode($payload);
        $headers = $this->signer->build_headers($api_key, $store_domain, $raw_body, $signature_secret);

        $response = wp_remote_post($webhook_url, array(
            'headers' => $headers,
            'body' => $raw_body,
            'timeout' => 30,
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'http_code' => 0,
                'error' => 'WP Error: ' . $response->get_error_message(),
                'response' => null,
            );
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($http_code === 200 || $http_code === 201) {
            return array(
                'success' => true,
                'http_code' => $http_code,
                'error' => null,
                'response' => json_decode($body, true),
            );
        }

        return array(
            'success' => false,
            'http_code' => $http_code,
            'error' => 'HTTP ' . $http_code . ': ' . $body,
            'response' => json_decode($body, true),
        );
    }

    /**
     * Retry the failed webhooks queue (WP-Cron).
     *
     * Processes a batch of queued payloads in a single cron tick. Successes
     * and permanent (4xx) failures are removed from the queue; transient
     * failures (5xx, network) stay queued with an incremented retry_count.
     *
     * @return int Number of successfully re-sent webhooks.
     */
    public function retry_failed_webhooks() {
        global $wpdb;

        $table = $wpdb->prefix . 'vocify_failed_webhooks';
        $api_key = get_option('vocify_api_key');

        if (empty($api_key)) {
            return 0;
        }

        $webhook_url = get_option('vocify_webhook_url');
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT id, order_id, payload FROM {$table}
             WHERE retry_count < %d
             ORDER BY created_at ASC
             LIMIT %d",
            self::FAILED_QUEUE_MAX_RETRIES,
            self::FAILED_QUEUE_BATCH
        ));

        $sent = 0;

        foreach ($rows as $row) {
            $payload = json_decode($row->payload, true);

            if (!is_array($payload)) {
                // Corrupt payload — drop it, it can never be sent.
                $wpdb->delete($table, array('id' => $row->id), array('%d'));
                continue;
            }

            $result = $this->send_webhook($payload, $api_key, $webhook_url);

            if ($result['success']) {
                $this->log((int)$row->order_id, 'success', $result['http_code'], json_encode($result['response']));
                $wpdb->delete($table, array('id' => $row->id), array('%d'));
                $sent++;
                continue;
            }

            $http_code = $result['http_code'];

            // Permanent failure — remove from queue, log as error.
            if ($http_code >= 400 && $http_code < 500) {
                $this->log((int)$row->order_id, 'error', $http_code, $result['error']);
                $wpdb->delete($table, array('id' => $row->id), array('%d'));
                continue;
            }

            // Transient failure — keep queued for the next tick.
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table}
                 SET error_message = %s, retry_count = retry_count + 1, last_retry_at = %s
                 WHERE id = %d",
                substr($result['error'], 0, 5000),
                current_time('mysql'),
                $row->id
            ));
        }

        return $sent;
    }

    /**
     * Log webhook attempt
     *
     * @param int    $order_id
     * @param string $status
     * @param int    $http_code
     * @param string $message
     */
    private function log($order_id, $status, $http_code, $message) {
        global $wpdb;

        $wpdb->insert(
            $wpdb->prefix . 'vocify_webhook_logs',
            array(
                'order_id' => $order_id,
                'status' => $status,
                'http_code' => $http_code,
                'response' => substr($message, 0, 5000), // Limit to 5000 chars
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%d', '%s', '%s')
        );
    }

    /**
     * Add order to failed webhooks queue
     *
     * @param int    $order_id
     * @param array  $payload
     * @param string $error_message
     */
    private function add_to_failed_queue($order_id, $payload, $error_message) {
        global $wpdb;

        // Check if already exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}vocify_failed_webhooks WHERE order_id = %d",
            $order_id
        ));

        if ($exists) {
            // Update retry count
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->prefix}vocify_failed_webhooks
                SET error_message = %s, retry_count = retry_count + 1, last_retry_at = %s
                WHERE order_id = %d",
                $error_message,
                current_time('mysql'),
                $order_id
            ));
        } else {
            // Insert new
            $wpdb->insert(
                $wpdb->prefix . 'vocify_failed_webhooks',
                array(
                    'order_id' => $order_id,
                    'payload' => json_encode($payload),
                    'error_message' => $error_message,
                    'retry_count' => 0,
                    'created_at' => current_time('mysql'),
                ),
                array('%d', '%s', '%s', '%d', '%s')
            );
        }
    }

    /**
     * Remove order from failed webhooks queue
     *
     * @param int $order_id
     */
    private function remove_from_failed_queue($order_id) {
        global $wpdb;

        $wpdb->delete(
            $wpdb->prefix . 'vocify_failed_webhooks',
            array('order_id' => $order_id),
            array('%d')
        );
    }

    /**
     * Build a standardized result array.
     *
     * @param bool        $success
     * @param int         $http_code
     * @param mixed       $response
     * @param string|null $error
     * @param bool        $retries_exhausted
     * @param array|null  $payload
     * @return array
     */
    private function result($success, $http_code, $response, $error, $retries_exhausted, $payload) {
        return array(
            'success' => (bool)$success,
            'http_code' => (int)$http_code,
            'response' => $response,
            'error' => $error,
            'retries_exhausted' => (bool)$retries_exhausted,
            'payload' => $payload,
        );
    }
}