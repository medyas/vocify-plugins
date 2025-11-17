<?php
/**
 * Vocify Webhook Service
 *
 * Handles webhook sending, data transformation, and retry logic
 *
 * @author Vocify AI
 * @copyright 2025 Vocify AI
 * @license MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VocifyWebhookService
{
    const PLATFORM = 'PRESTASHOP';
    const MAX_RETRIES = 3;

    /**
     * Send order to Vocify AI
     *
     * @param Order $order
     * @return bool
     */
    public function sendOrder($order)
    {
        $apiKey = Configuration::get('VOCIFY_API_KEY');
        $webhookUrl = Configuration::get('VOCIFY_WEBHOOK_URL');
        $debugMode = Configuration::get('VOCIFY_DEBUG_MODE');

        if (empty($apiKey)) {
            $this->log($order->id, 'error', 0, 'API key not configured');
            return false;
        }

        // Transform order to unified payload
        $payload = $this->transformOrder($order);

        if (!$payload) {
            $this->log($order->id, 'error', 0, 'Failed to transform order data');
            return false;
        }

        // Send webhook with retry logic
        return $this->sendWebhookWithRetry($order->id, $payload, $apiKey, $webhookUrl, $debugMode);
    }

    /**
     * Transform PrestaShop order to unified payload format
     *
     * @param Order $order
     * @return array|false
     */
    private function transformOrder($order)
    {
        try {
            $context = Context::getContext();
            $customer = new Customer($order->id_customer);
            $addressDelivery = new Address($order->id_address_delivery);
            $addressInvoice = new Address($order->id_address_invoice);
            $currency = new Currency($order->id_currency);
            $orderState = new OrderState($order->current_state, $context->language->id);

            // Get order products
            $products = $order->getProducts();
            $items = array();

            foreach ($products as $product) {
                $imageLink = '';
                if (!empty($product['id_image'])) {
                    $imageLink = $context->link->getImageLink(
                        $product['link_rewrite'],
                        $product['id_image'],
                        ImageType::getFormattedName('large')
                    );
                }

                $items[] = array(
                    'id' => (string)$product['product_id'],
                    'name' => $product['product_name'],
                    'sku' => $product['product_reference'],
                    'quantity' => (int)$product['product_quantity'],
                    'price' => (float)$product['unit_price_tax_incl'],
                    'total' => (float)$product['total_price_tax_incl'],
                    'image' => $imageLink,
                );
            }

            // Extract phone number (priority order)
            $phone = $this->extractPhoneNumber($customer, $addressDelivery, $addressInvoice);

            if (empty($phone)) {
                PrestaShopLogger::addLog(
                    'Vocify AI: No phone number found for order #' . $order->reference,
                    2,
                    null,
                    'Order',
                    $order->id
                );
            }

            // Get country ISO code
            $deliveryCountry = new Country($addressDelivery->id_country);
            $invoiceCountry = new Country($addressInvoice->id_country);

            // Get state/province
            $deliveryState = '';
            if ($addressDelivery->id_state) {
                $state = new State($addressDelivery->id_state);
                $deliveryState = $state->iso_code;
            }

            $invoiceState = '';
            if ($addressInvoice->id_state) {
                $state = new State($addressInvoice->id_state);
                $invoiceState = $state->iso_code;
            }

            // Get payment transaction ID
            $transactionId = '';
            $orderPayments = $order->getOrderPaymentCollection();
            if ($orderPayments && count($orderPayments) > 0) {
                foreach ($orderPayments as $payment) {
                    if (!empty($payment->transaction_id)) {
                        $transactionId = $payment->transaction_id;
                        break;
                    }
                }
            }

            // Determine financial status based on payment and order state
            $financialStatus = $this->getFinancialStatus($order);

            // Determine fulfillment status
            $fulfillmentStatus = $this->getFulfillmentStatus($order);

            // Get paid date
            $paidAt = null;
            if ($orderPayments && count($orderPayments) > 0) {
                foreach ($orderPayments as $payment) {
                    if (!empty($payment->date_add)) {
                        $paidAt = date('c', strtotime($payment->date_add));
                        break;
                    }
                }
            }

            // Build unified payload
            $payload = array(
                'orderId' => (string)$order->id,
                'orderNumber' => $order->reference,
                'status' => $orderState->name,
                'financialStatus' => $financialStatus,
                'fulfillmentStatus' => $fulfillmentStatus,

                'customer' => array(
                    'id' => (string)$customer->id,
                    'firstName' => $customer->firstname,
                    'lastName' => $customer->lastname,
                    'email' => $customer->email,
                    'phone' => $phone,
                ),

                'items' => $items,

                'totals' => array(
                    'subtotal' => (float)$order->total_products,
                    'discount' => (float)$order->total_discounts,
                    'shipping' => (float)$order->total_shipping,
                    'tax' => (float)($order->total_paid_tax_incl - $order->total_paid_tax_excl),
                    'total' => (float)$order->total_paid,
                ),
                'currency' => strtoupper($currency->iso_code),

                'shippingAddress' => array(
                    'firstName' => $addressDelivery->firstname,
                    'lastName' => $addressDelivery->lastname,
                    'company' => $addressDelivery->company,
                    'address1' => $addressDelivery->address1,
                    'address2' => $addressDelivery->address2,
                    'city' => $addressDelivery->city,
                    'state' => $deliveryState,
                    'zip' => $addressDelivery->postcode,
                    'country' => strtoupper($deliveryCountry->iso_code),
                    'phone' => $addressDelivery->phone_mobile ?: $addressDelivery->phone,
                ),

                'billingAddress' => array(
                    'firstName' => $addressInvoice->firstname,
                    'lastName' => $addressInvoice->lastname,
                    'company' => $addressInvoice->company,
                    'address1' => $addressInvoice->address1,
                    'address2' => $addressInvoice->address2,
                    'city' => $addressInvoice->city,
                    'state' => $invoiceState,
                    'zip' => $addressInvoice->postcode,
                    'country' => strtoupper($invoiceCountry->iso_code),
                    'phone' => $addressInvoice->phone_mobile ?: $addressInvoice->phone,
                ),

                'paymentMethod' => $order->payment,
                'transactionId' => $transactionId,

                'createdAt' => date('c', strtotime($order->date_add)),
                'updatedAt' => date('c', strtotime($order->date_upd)),
                'paidAt' => $paidAt,

                'requiresShipping' => true,
                'taxIncluded' => Group::getPriceDisplayMethod($customer->id_default_group) == PS_TAX_INC,

                'metadata' => array(
                    'prestashopOrderReference' => $order->reference,
                    'prestashopCurrentState' => (string)$order->current_state,
                    'prestashopCarrierId' => (string)$order->id_carrier,
                    'prestashopOrderId' => (string)$order->id,
                ),
            );

            return $payload;
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Vocify AI: Failed to transform order - ' . $e->getMessage(),
                3,
                null,
                'Order',
                $order->id
            );
            return false;
        }
    }

    /**
     * Extract phone number with priority
     *
     * @param Customer $customer
     * @param Address $addressDelivery
     * @param Address $addressInvoice
     * @return string
     */
    private function extractPhoneNumber($customer, $addressDelivery, $addressInvoice)
    {
        // Get default country from delivery address for phone validation
        $defaultCountry = 'US'; // Fallback
        if ($addressDelivery->id_country) {
            $country = new Country($addressDelivery->id_country);
            $defaultCountry = $country->iso_code;
        }

        // Priority: customer mobile, customer phone, delivery mobile, delivery phone, invoice mobile, invoice phone
        $phones = array(
            isset($customer->phone_mobile) ? $customer->phone_mobile : '',
            isset($customer->phone) ? $customer->phone : '',
            $addressDelivery->phone_mobile,
            $addressDelivery->phone,
            $addressInvoice->phone_mobile,
            $addressInvoice->phone,
        );

        foreach ($phones as $phone) {
            if (!empty($phone)) {
                return $this->formatPhoneNumber($phone, $defaultCountry);
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
     * @param string $defaultCountry ISO country code (e.g., 'US', 'FR')
     * @return string
     */
    private function formatPhoneNumber($phone, $defaultCountry = 'US')
    {
        if (empty($phone)) {
            return '';
        }

        // Try using libphonenumber-php if available
        if (class_exists('\libphonenumber\PhoneNumberUtil')) {
            try {
                $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
                $phoneNumber = $phoneUtil->parse($phone, $defaultCountry);

                if ($phoneUtil->isValidNumber($phoneNumber)) {
                    // Format as E.164 (international format with +)
                    return $phoneUtil->format($phoneNumber, \libphonenumber\PhoneNumberFormat::E164);
                }
            } catch (\libphonenumber\NumberParseException $e) {
                // Log parsing error in debug mode
                if (Configuration::get('VOCIFY_DEBUG_MODE')) {
                    PrestaShopLogger::addLog(
                        'Vocify AI: Phone number parsing failed - ' . $e->getMessage() . ' - Phone: ' . $phone,
                        2
                    );
                }
                // Fall through to basic formatting
            }
        }

        // Fallback: Basic phone number cleanup
        // Remove spaces, dashes, parentheses, dots
        $phone = preg_replace('/[\s\-\(\)\.]/', '', $phone);

        // Ensure it starts with + if it looks like E.164
        // PHP 7.1 compatible check (str_starts_with requires PHP 8.0+)
        if (!empty($phone) && substr($phone, 0, 1) !== '+') {
            // If it's a number and doesn't start with +, it might need country code
            // Without libphonenumber, we can't reliably add country code
            return $phone;
        }

        return $phone;
    }

    /**
     * Send webhook with retry logic
     *
     * @param int $orderId
     * @param array $payload
     * @param string $apiKey
     * @param string $webhookUrl
     * @param bool $debugMode
     * @return bool
     */
    private function sendWebhookWithRetry($orderId, $payload, $apiKey, $webhookUrl, $debugMode = false)
    {
        $lastError = '';
        $lastHttpCode = 0;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $result = $this->sendWebhook($payload, $apiKey, $webhookUrl);

                if ($result['success']) {
                    // Log success
                    $this->log($orderId, 'success', $result['http_code'], json_encode($result['response']));

                    if ($debugMode) {
                        PrestaShopLogger::addLog(
                            'Vocify AI: Order sent successfully - Order #' . $payload['orderNumber'] .
                            ' (JobID: ' . ($result['response']['jobId'] ?? 'N/A') . ')',
                            1,
                            null,
                            'Order',
                            $orderId
                        );
                    }

                    // Remove from failed queue if exists
                    $this->removeFromFailedQueue($orderId);

                    return true;
                }

                $lastError = $result['error'];
                $lastHttpCode = $result['http_code'];

                // Don't retry client errors (4xx)
                if ($result['http_code'] >= 400 && $result['http_code'] < 500) {
                    $this->log($orderId, 'error', $result['http_code'], $lastError);

                    PrestaShopLogger::addLog(
                        'Vocify AI: Client error (no retry) - Order #' . $payload['orderNumber'] .
                        ' - HTTP ' . $result['http_code'] . ': ' . $lastError,
                        3,
                        null,
                        'Order',
                        $orderId
                    );

                    return false;
                }

                // Log retry attempt
                if ($debugMode) {
                    PrestaShopLogger::addLog(
                        'Vocify AI: Retry attempt ' . $attempt . '/' . self::MAX_RETRIES .
                        ' for order #' . $payload['orderNumber'] . ' - Error: ' . $lastError,
                        2,
                        null,
                        'Order',
                        $orderId
                    );
                }

                // Exponential backoff (2s, 4s, 8s)
                if ($attempt < self::MAX_RETRIES) {
                    sleep(pow(2, $attempt));
                }
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                $lastHttpCode = 0;

                if ($attempt < self::MAX_RETRIES) {
                    sleep(pow(2, $attempt));
                }
            }
        }

        // All retries failed - add to failed queue
        $this->log($orderId, 'failed', $lastHttpCode, $lastError);
        $this->addToFailedQueue($orderId, $payload, $lastError);

        PrestaShopLogger::addLog(
            'Vocify AI: All retries failed for order #' . $payload['orderNumber'] .
            ' - Last error: ' . $lastError,
            3,
            null,
            'Order',
            $orderId
        );

        return false;
    }

    /**
     * Send webhook to Vocify AI
     *
     * @param array $payload
     * @param string $apiKey
     * @param string $webhookUrl
     * @return array
     */
    private function sendWebhook($payload, $apiKey, $webhookUrl)
    {
        $storeDomain = parse_url(Tools::getShopDomainSsl(true), PHP_URL_HOST);
        $rawBody = json_encode($payload);
        $signature = hash_hmac('sha256', $rawBody, $apiKey);

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'X-Platform: ' . self::PLATFORM,
                'X-API-Key: ' . $apiKey,
                'X-Domain: ' . $storeDomain,
                'X-Signature: ' . $signature,
                'X-Timestamp: ' . gmdate('c'),
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            return array(
                'success' => false,
                'http_code' => 0,
                'error' => 'cURL error: ' . $curlError,
                'response' => null,
            );
        }

        if ($httpCode === 200 || $httpCode === 201) {
            return array(
                'success' => true,
                'http_code' => $httpCode,
                'error' => null,
                'response' => json_decode($response, true),
            );
        }

        return array(
            'success' => false,
            'http_code' => $httpCode,
            'error' => 'HTTP ' . $httpCode . ': ' . $response,
            'response' => json_decode($response, true),
        );
    }

    /**
     * Log webhook attempt
     *
     * @param int $orderId
     * @param string $status
     * @param int $httpCode
     * @param string $message
     * @return void
     */
    private function log($orderId, $status, $httpCode, $message)
    {
        Db::getInstance()->insert('vocify_webhook_logs', array(
            'id_order' => (int)$orderId,
            'status' => pSQL($status),
            'http_code' => (int)$httpCode,
            'response' => pSQL(substr($message, 0, 5000)), // Limit to 5000 chars
            'created_at' => date('Y-m-d H:i:s'),
        ));
    }

    /**
     * Add order to failed webhooks queue
     *
     * @param int $orderId
     * @param array $payload
     * @param string $errorMessage
     * @return void
     */
    private function addToFailedQueue($orderId, $payload, $errorMessage)
    {
        // Check if already exists
        $exists = Db::getInstance()->getValue(
            'SELECT id_webhook FROM `' . _DB_PREFIX_ . 'vocify_failed_webhooks`
            WHERE id_order = ' . (int)$orderId
        );

        if ($exists) {
            // Update retry count
            Db::getInstance()->update('vocify_failed_webhooks', array(
                'error_message' => pSQL($errorMessage),
                'retry_count' => array('type' => 'sql', 'value' => 'retry_count + 1'),
                'last_retry_at' => date('Y-m-d H:i:s'),
            ), 'id_order = ' . (int)$orderId);
        } else {
            // Insert new
            Db::getInstance()->insert('vocify_failed_webhooks', array(
                'id_order' => (int)$orderId,
                'payload' => pSQL(json_encode($payload)),
                'error_message' => pSQL($errorMessage),
                'retry_count' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ));
        }
    }

    /**
     * Remove order from failed webhooks queue
     *
     * @param int $orderId
     * @return void
     */
    private function removeFromFailedQueue($orderId)
    {
        Db::getInstance()->delete('vocify_failed_webhooks', 'id_order = ' . (int)$orderId);
    }

    /**
     * Get financial status based on order state
     *
     * @param Order $order
     * @return string
     */
    private function getFinancialStatus($order)
    {
        // PrestaShop order states mapping to financial status
        // Reference: https://devdocs.prestashop.com/1.7/development/database/structure/order_state/

        $orderState = new OrderState($order->current_state);

        // Check if order is paid
        if ($orderState->paid == 1) {
            // Check if there's any refund
            if ($order->getTotalPaid() > 0 && $order->getTotalPaid() < $order->total_paid) {
                return 'partially_refunded';
            }
            if ($order->getTotalPaid() == 0) {
                return 'refunded';
            }
            return 'paid';
        }

        // Check if order is cancelled
        if (in_array($order->current_state, array(6, 7, 8))) { // Cancelled, Refunded, Payment error
            return 'voided';
        }

        // Check specific states
        switch ($order->current_state) {
            case 1: // Awaiting check payment
            case 10: // Awaiting bank wire payment
            case 11: // Remote payment accepted
                return 'pending';

            case 2: // Payment accepted
            case 3: // Processing in progress
            case 4: // Shipped
            case 5: // Delivered
            case 9: // Payment received
                return 'paid';

            case 6: // Canceled
            case 7: // Refunded
            case 8: // Payment error
                return 'voided';

            default:
                // Check if order has any payments
                $orderPayments = $order->getOrderPaymentCollection();
                if ($orderPayments && count($orderPayments) > 0) {
                    return 'paid';
                }
                return 'pending';
        }
    }

    /**
     * Get fulfillment status based on order state
     *
     * @param Order $order
     * @return string
     */
    private function getFulfillmentStatus($order)
    {
        $orderState = new OrderState($order->current_state);

        // Check if order is shipped or delivered
        switch ($order->current_state) {
            case 4: // Shipped
            case 5: // Delivered
                return 'fulfilled';

            case 6: // Canceled
            case 7: // Refunded
                return 'restocked';

            case 1: // Awaiting payment
            case 2: // Payment accepted
            case 3: // Processing in progress
            case 10: // Awaiting bank wire
            case 11: // Remote payment accepted
                return 'unfulfilled';

            default:
                // Check if order has shipped state
                if ($orderState->shipped == 1) {
                    return 'fulfilled';
                }
                if ($orderState->delivery == 1) {
                    return 'fulfilled';
                }
                return 'unfulfilled';
        }
    }
}
