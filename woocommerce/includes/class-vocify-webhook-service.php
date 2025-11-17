<?php
/**
 * Vocify Webhook Service
 *
 * Handles webhook sending, data transformation, and retry logic
 *
 * @package VocifyAI
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vocify_AI_Webhook_Service {

    const PLATFORM = 'WOOCOMMERCE';
    const MAX_RETRIES = 3;

    /**
     * Send order to Vocify AI
     *
     * @param WC_Order $order
     * @return bool
     */
    public function send_order($order) {
        $api_key = get_option('vocify_api_key');
        $webhook_url = get_option('vocify_webhook_url');
        $debug_mode = get_option('vocify_debug_mode') === 'yes';

        if (empty($api_key)) {
            $this->log($order->get_id(), 'error', 0, 'API key not configured');
            return false;
        }

        // Transform order to unified payload
        $payload = $this->transform_order($order);

        if (!$payload) {
            $this->log($order->get_id(), 'error', 0, 'Failed to transform order data');
            return false;
        }

        // Send webhook with retry logic
        return $this->send_webhook_with_retry($order->get_id(), $payload, $api_key, $webhook_url, $debug_mode);
    }

    /**
     * Transform WooCommerce order to unified payload format
     *
     * @param WC_Order $order
     * @return array|false
     */
    private function transform_order($order) {
        try {
            // Get order items
            $items = array();
            foreach ($order->get_items() as $item_id => $item) {
                $product = $item->get_product();
                $product_id = $product ? $product->get_id() : 0;
                $image_id = $product ? $product->get_image_id() : 0;
                $image_url = $image_id ? wp_get_attachment_url($image_id) : '';

                // Get product variations/attributes
                $attributes = array();
                if ($product && $product->is_type('variation')) {
                    $attributes = $product->get_variation_attributes();
                }

                $items[] = array(
                    'id' => (string)$product_id,
                    'name' => $item->get_name(),
                    'sku' => $product ? $product->get_sku() : '',
                    'quantity' => $item->get_quantity(),
                    'price' => (float)$order->get_item_total($item, true), // Include tax
                    'total' => (float)$order->get_line_total($item, true), // Include tax
                    'image' => $image_url,
                    'attributes' => $attributes,
                );
            }

            // Get phone number with priority
            $phone = $this->extract_phone_number($order);

            if (empty($phone)) {
                wc_get_logger()->warning(
                    'No phone number found for order #' . $order->get_order_number(),
                    array('source' => 'vocify-ai')
                );
            }

            // Get customer data
            $customer_id = $order->get_customer_id();
            $customer_orders_count = 0;
            $customer_total_spent = 0;

            if ($customer_id) {
                $customer = new WC_Customer($customer_id);
                $customer_orders_count = $customer->get_order_count();
                $customer_total_spent = $customer->get_total_spent();
            }

            // Get payment transaction ID
            $transaction_id = $order->get_transaction_id();

            // Determine financial status
            $financial_status = $this->get_financial_status($order);

            // Determine fulfillment status
            $fulfillment_status = $this->get_fulfillment_status($order);

            // Get paid date
            $paid_at = $order->get_date_paid();
            $paid_at_iso = $paid_at ? $paid_at->date('c') : null;

            // Build unified payload
            $payload = array(
                'orderId' => (string)$order->get_id(),
                'orderNumber' => $order->get_order_number(),
                'orderKey' => $order->get_order_key(),
                'status' => $order->get_status(),
                'financialStatus' => $financial_status,
                'fulfillmentStatus' => $fulfillment_status,

                'customer' => array(
                    'id' => (string)$customer_id,
                    'firstName' => $order->get_billing_first_name(),
                    'lastName' => $order->get_billing_last_name(),
                    'email' => $order->get_billing_email(),
                    'phone' => $phone,
                    'isNewCustomer' => $customer_orders_count === 0,
                    'totalOrders' => $customer_orders_count,
                    'totalSpent' => (float)$customer_total_spent,
                ),

                'items' => $items,

                'totals' => array(
                    'subtotal' => (float)$order->get_subtotal(),
                    'discount' => (float)$order->get_total_discount(),
                    'shipping' => (float)$order->get_shipping_total(),
                    'tax' => (float)$order->get_total_tax(),
                    'total' => (float)$order->get_total(),
                ),
                'currency' => strtoupper($order->get_currency()),

                'shippingAddress' => array(
                    'firstName' => $order->get_shipping_first_name(),
                    'lastName' => $order->get_shipping_last_name(),
                    'company' => $order->get_shipping_company(),
                    'address1' => $order->get_shipping_address_1(),
                    'address2' => $order->get_shipping_address_2(),
                    'city' => $order->get_shipping_city(),
                    'state' => $order->get_shipping_state(),
                    'zip' => $order->get_shipping_postcode(),
                    'country' => strtoupper($order->get_shipping_country()),
                    'phone' => $order->get_billing_phone(), // WC doesn't have separate shipping phone
                ),

                'billingAddress' => array(
                    'firstName' => $order->get_billing_first_name(),
                    'lastName' => $order->get_billing_last_name(),
                    'company' => $order->get_billing_company(),
                    'address1' => $order->get_billing_address_1(),
                    'address2' => $order->get_billing_address_2(),
                    'city' => $order->get_billing_city(),
                    'state' => $order->get_billing_state(),
                    'zip' => $order->get_billing_postcode(),
                    'country' => strtoupper($order->get_billing_country()),
                    'phone' => $order->get_billing_phone(),
                ),

                'paymentMethod' => $order->get_payment_method(),
                'paymentMethodTitle' => $order->get_payment_method_title(),
                'transactionId' => $transaction_id,

                'createdAt' => $order->get_date_created()->date('c'),
                'updatedAt' => $order->get_date_modified()->date('c'),
                'paidAt' => $paid_at_iso,

                'customerNote' => $order->get_customer_note(),

                'requiresShipping' => $order->needs_shipping_address(),
                'taxIncluded' => wc_prices_include_tax(),

                'metadata' => array(
                    'woocommerceOrderKey' => $order->get_order_key(),
                    'woocommerceOrderId' => (string)$order->get_id(),
                    'woocommerceCustomerId' => (string)$customer_id,
                ),
            );

            return $payload;
        } catch (Exception $e) {
            wc_get_logger()->error(
                'Failed to transform order: ' . $e->getMessage(),
                array('source' => 'vocify-ai', 'order_id' => $order->get_id())
            );
            return false;
        }
    }

    /**
     * Extract phone number with priority
     *
     * @param WC_Order $order
     * @return string
     */
    private function extract_phone_number($order) {
        // Get default country from billing or shipping address
        $default_country = $order->get_billing_country();
        if (empty($default_country)) {
            $default_country = $order->get_shipping_country();
        }
        if (empty($default_country)) {
            $default_country = 'US'; // Fallback
        }

        // Priority: billing phone, shipping phone (if different)
        $phones = array(
            $order->get_billing_phone(),
            // WooCommerce doesn't have separate shipping phone
        );

        foreach ($phones as $phone) {
            if (!empty($phone)) {
                return $this->format_phone_number($phone, $default_country);
            }
        }

        return '';
    }

    /**
     * Format phone number to E.164 format
     *
     * Uses libphonenumber-php if available, otherwise falls back to basic formatting
     *
     * @param string $phone
     * @param string $default_country ISO country code (e.g., 'US', 'FR')
     * @return string
     */
    private function format_phone_number($phone, $default_country = 'US') {
        if (empty($phone)) {
            return '';
        }

        // Try using libphonenumber-php if available
        if (class_exists('\libphonenumber\PhoneNumberUtil')) {
            try {
                $phone_util = \libphonenumber\PhoneNumberUtil::getInstance();
                $phone_number = $phone_util->parse($phone, $default_country);

                if ($phone_util->isValidNumber($phone_number)) {
                    // Format as E.164 (international format with +)
                    return $phone_util->format($phone_number, \libphonenumber\PhoneNumberFormat::E164);
                }
            } catch (\libphonenumber\NumberParseException $e) {
                // Log parsing error in debug mode
                if (get_option('vocify_debug_mode') === 'yes') {
                    wc_get_logger()->warning(
                        'Phone number parsing failed: ' . $e->getMessage() . ' - Phone: ' . $phone,
                        array('source' => 'vocify-ai')
                    );
                }
                // Fall through to basic formatting
            }
        }

        // Fallback: Basic phone number cleanup
        // Remove spaces, dashes, parentheses, dots
        $phone = preg_replace('/[\s\-\(\)\.]/', '', $phone);

        // Return as-is if it starts with +
        if (!empty($phone) && substr($phone, 0, 1) === '+') {
            return $phone;
        }

        return $phone;
    }

    /**
     * Get financial status based on order status
     *
     * @param WC_Order $order
     * @return string
     */
    private function get_financial_status($order) {
        $status = $order->get_status();
        $total_refunded = $order->get_total_refunded();
        $total = $order->get_total();

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
     * @param int $order_id
     * @param array $payload
     * @param string $api_key
     * @param string $webhook_url
     * @param bool $debug_mode
     * @return bool
     */
    private function send_webhook_with_retry($order_id, $payload, $api_key, $webhook_url, $debug_mode = false) {
        $last_error = '';
        $last_http_code = 0;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $result = $this->send_webhook($payload, $api_key, $webhook_url);

                if ($result['success']) {
                    // Log success
                    $this->log($order_id, 'success', $result['http_code'], json_encode($result['response']));

                    if ($debug_mode) {
                        wc_get_logger()->info(
                            'Order sent successfully - Order #' . $payload['orderNumber'] .
                            ' (JobID: ' . ($result['response']['jobId'] ?? 'N/A') . ')',
                            array('source' => 'vocify-ai')
                        );
                    }

                    // Remove from failed queue if exists
                    $this->remove_from_failed_queue($order_id);

                    return true;
                }

                $last_error = $result['error'];
                $last_http_code = $result['http_code'];

                // Don't retry client errors (4xx)
                if ($result['http_code'] >= 400 && $result['http_code'] < 500) {
                    $this->log($order_id, 'error', $result['http_code'], $last_error);

                    wc_get_logger()->error(
                        'Client error (no retry) - Order #' . $payload['orderNumber'] .
                        ' - HTTP ' . $result['http_code'] . ': ' . $last_error,
                        array('source' => 'vocify-ai')
                    );

                    return false;
                }

                // Log retry attempt
                if ($debug_mode) {
                    wc_get_logger()->warning(
                        'Retry attempt ' . $attempt . '/' . self::MAX_RETRIES .
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

        wc_get_logger()->error(
            'All retries failed for order #' . $payload['orderNumber'] .
            ' - Last error: ' . $last_error,
            array('source' => 'vocify-ai')
        );

        return false;
    }

    /**
     * Send webhook to Vocify AI
     *
     * @param array $payload
     * @param string $api_key
     * @param string $webhook_url
     * @return array
     */
    private function send_webhook($payload, $api_key, $webhook_url) {
        $store_domain = parse_url(get_site_url(), PHP_URL_HOST);
        $raw_body = json_encode($payload);
        $signature = hash_hmac('sha256', $raw_body, $api_key);

        $response = wp_remote_post($webhook_url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Platform' => self::PLATFORM,
                'X-API-Key' => $api_key,
                'X-Domain' => $store_domain,
                'X-Signature' => $signature,
                'X-Timestamp' => gmdate('c'),
            ),
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
     * Log webhook attempt
     *
     * @param int $order_id
     * @param string $status
     * @param int $http_code
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
     * @param int $order_id
     * @param array $payload
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
}
