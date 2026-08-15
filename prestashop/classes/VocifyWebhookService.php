<?php
/**
 * Vocify Webhook Service
 *
 * Handles webhook sending, data transformation, and retry logic
 *
 * @author Vocify AI
 * @copyright 2025 Vocify AI
 * @license MIT License
 * @version 1.1.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VocifyWebhookService
{
    const PLATFORM = 'PRESTASHOP';
    const MAX_RETRIES = 3;
    const FAILED_QUEUE_MAX_RETRIES = 5;
    const FAILED_QUEUE_BATCH = 20;

    /**
     * Payload builder instance
     *
     * @var VocifyPayloadBuilder
     */
    private $builder;

    /**
     * Payload validator instance
     *
     * @var VocifyPayloadValidator
     */
    private $validator;

    /**
     * Signer instance
     *
     * @var VocifySigner
     */
    private $signer;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->builder = new VocifyPayloadBuilder();
        $this->validator = new VocifyPayloadValidator();
        $this->signer = new VocifySigner();
    }

    /**
     * Send order to Vocify AI
     *
     * @param Order $order
     * @return array Result: { success, http_code, response, error, retries_exhausted, payload }
     */
    public function sendOrder($order)
    {
        $apiKey = Configuration::get('VOCIFY_API_KEY');
        $webhookUrl = Configuration::get('VOCIFY_WEBHOOK_URL');
        $debugMode = Configuration::get('VOCIFY_DEBUG_MODE');

        if (empty($apiKey)) {
            $this->log($order->id, 'error', 0, 'API key not configured');
            return $this->result(false, 0, null, 'API key not configured', false, null);
        }

        // Transform order to unified payload
        $payload = $this->transformOrder($order);

        if (!$payload) {
            $message = 'Failed to transform order data';
            $this->log($order->id, 'error', 0, $message);
            return $this->result(false, 0, null, $message, false, null);
        }

        // Fail fast on payloads the platform would reject with 400 — a local
        // validation error is a configuration/data problem, not transient.
        $errors = $this->validator->validate($payload);

        if (count($errors) > 0) {
            $message = 'Payload validation failed: ' . implode('; ', array_slice($errors, 0, 5));
            $this->log($order->id, 'error', 0, $message);

            if ($debugMode) {
                PrestaShopLogger::addLog(
                    'Vocify AI: Payload invalid for order #' . $order->reference . ' - ' . $message,
                    3,
                    null,
                    'Order',
                    $order->id
                );
            }

            return $this->result(false, 400, null, $message, false, $payload);
        }

        // Send webhook with retry logic
        return $this->sendWebhookWithRetry($order->id, $payload, $apiKey, $webhookUrl, $debugMode);
    }

    /**
     * Transform PrestaShop order to unified payload format
     *
     * @param Order $order
     * @return array|null
     */
    public function transformOrder($order)
    {
        try {
            $context = Context::getContext();
            $customer = new Customer($order->id_customer);
            $addressDelivery = new Address($order->id_address_delivery);
            $addressInvoice = new Address($order->id_address_invoice);
            $currency = new Currency($order->id_currency);
            $orderState = new OrderState($order->current_state, $context->language->id);

            // Phone candidates in priority order (first non-empty wins).
            $phones = array(
                isset($customer->phone_mobile) ? $customer->phone_mobile : '',
                isset($customer->phone) ? $customer->phone : '',
                $addressDelivery->phone_mobile,
                $addressDelivery->phone,
                $addressInvoice->phone_mobile,
                $addressInvoice->phone,
            );

            // Default country from the delivery address, falling back to the
            // invoice address, then the shop's default country.
            $defaultCountry = $this->countryIso($addressDelivery->id_country);
            if (empty($defaultCountry)) {
                $defaultCountry = $this->countryIso($addressInvoice->id_country);
            }
            if (empty($defaultCountry)) {
                $defaultCountry = $this->countryIso((int)Configuration::get('PS_COUNTRY_DEFAULT'));
            }
            if (empty($defaultCountry)) {
                $defaultCountry = 'US';
            }

            // Raw data map — consumed by the platform-agnostic payload builder.
            $raw = array(
                'order_id' => (string)$order->id,
                'order_number' => $order->reference,
                'order_key' => $order->reference,
                'status' => isset($orderState->slug) && $orderState->slug !== ''
                    ? $orderState->slug
                    : $orderState->name,
                'financial_status' => $this->getFinancialStatus($order),
                'fulfillment_status' => $this->getFulfillmentStatus($order),
                'default_country' => $defaultCountry,
                'phones' => $phones,
                'customer' => array(
                    'id' => (string)$customer->id,
                    'first_name' => $customer->firstname,
                    'last_name' => $customer->lastname,
                    'email' => $customer->email,
                    'mobile_phone' => isset($customer->phone_mobile) ? $customer->phone_mobile : '',
                ),
                'items' => $this->extractItems($order, $context),
                'totals' => array(
                    'subtotal' => (float)$order->total_products,
                    'discount' => (float)$order->total_discounts,
                    'shipping' => (float)$order->total_shipping,
                    'tax' => (float)($order->total_paid_tax_incl - $order->total_paid_tax_excl),
                    'total' => (float)$order->total_paid,
                ),
                'currency' => isset($currency->iso_code) ? $currency->iso_code : '',
                'shipping_address' => array(
                    'first_name' => $addressDelivery->firstname,
                    'last_name' => $addressDelivery->lastname,
                    'company' => $addressDelivery->company,
                    'address1' => $addressDelivery->address1,
                    'address2' => $addressDelivery->address2,
                    'city' => $addressDelivery->city,
                    'state' => $this->stateIso($addressDelivery->id_state),
                    'zip' => $addressDelivery->postcode,
                    'country' => $this->countryIso($addressDelivery->id_country),
                    'phone' => !empty($addressDelivery->phone_mobile) ? $addressDelivery->phone_mobile : $addressDelivery->phone,
                ),
                'billing_address' => array(
                    'first_name' => $addressInvoice->firstname,
                    'last_name' => $addressInvoice->lastname,
                    'company' => $addressInvoice->company,
                    'address1' => $addressInvoice->address1,
                    'address2' => $addressInvoice->address2,
                    'city' => $addressInvoice->city,
                    'state' => $this->stateIso($addressInvoice->id_state),
                    'zip' => $addressInvoice->postcode,
                    'country' => $this->countryIso($addressInvoice->id_country),
                    'phone' => !empty($addressInvoice->phone_mobile) ? $addressInvoice->phone_mobile : $addressInvoice->phone,
                ),
                'payment_method' => $order->payment,
                'transaction_id' => $this->extractTransactionId($order),
                'created_at' => strtotime($order->date_add),
                'updated_at' => strtotime($order->date_upd),
                'paid_at' => $this->extractPaidAt($order),
                'needs_shipping' => (int)$order->id_carrier > 0,
                'tax_included' => Group::getPriceDisplayMethod($customer->id_default_group) == PS_TAX_INC,
                'metadata' => array(
                    'prestashopOrderReference' => $order->reference,
                    'prestashopCurrentState' => (string)$order->current_state,
                    'prestashopCarrierId' => (string)$order->id_carrier,
                    'prestashopOrderId' => (string)$order->id,
                ),
            );

            return $this->builder->build($raw);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Vocify AI: Failed to transform order - ' . $e->getMessage(),
                3,
                null,
                'Order',
                isset($order->id) ? $order->id : 0
            );
            return null;
        }
    }

    /**
     * Extract order items into the normalized format.
     *
     * @param Order   $order
     * @param Context $context
     * @return array
     */
    private function extractItems($order, $context)
    {
        $items = array();
        $products = $order->getProducts();

        foreach ($products as $product) {
            $imageLink = '';

            if (!empty($product['id_image']) && isset($context->link)) {
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

        return $items;
    }

    /**
     * Resolve a country ISO code from a country ID.
     *
     * @param int $idCountry
     * @return string
     */
    private function countryIso($idCountry)
    {
        if (empty($idCountry)) {
            return '';
        }

        $country = new Country((int)$idCountry);

        return isset($country->iso_code) && $country->iso_code !== null ? (string)$country->iso_code : '';
    }

    /**
     * Resolve a state ISO code from a state ID.
     *
     * @param int $idState
     * @return string
     */
    private function stateIso($idState)
    {
        if (empty($idState)) {
            return '';
        }

        $state = new State((int)$idState);

        return isset($state->iso_code) && $state->iso_code !== null ? (string)$state->iso_code : '';
    }

    /**
     * Extract the first non-empty payment transaction ID.
     *
     * @param Order $order
     * @return string
     */
    private function extractTransactionId($order)
    {
        $orderPayments = $order->getOrderPaymentCollection();

        if ($orderPayments && count($orderPayments) > 0) {
            foreach ($orderPayments as $payment) {
                if (!empty($payment->transaction_id)) {
                    return (string)$payment->transaction_id;
                }
            }
        }

        return '';
    }

    /**
     * Extract the paid date as a unix timestamp, or null when unpaid.
     *
     * @param Order $order
     * @return int|null
     */
    private function extractPaidAt($order)
    {
        $orderPayments = $order->getOrderPaymentCollection();

        if ($orderPayments && count($orderPayments) > 0) {
            foreach ($orderPayments as $payment) {
                if (!empty($payment->date_add)) {
                    $timestamp = strtotime($payment->date_add);
                    if ($timestamp !== false) {
                        return $timestamp;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Send webhook with retry logic
     *
     * @param int    $orderId
     * @param array  $payload
     * @param string $apiKey
     * @param string $webhookUrl
     * @param bool   $debugMode
     * @return array Result array (see self::result()).
     */
    private function sendWebhookWithRetry($orderId, $payload, $apiKey, $webhookUrl, $debugMode = false)
    {
        $lastError = '';
        $lastHttpCode = 0;

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            try {
                $result = $this->sendWebhook($payload, $apiKey, $webhookUrl);

                if ($result['success']) {
                    $this->log($orderId, 'success', $result['http_code'], json_encode($result['response']));

                    if ($debugMode) {
                        PrestaShopLogger::addLog(
                            'Vocify AI: Order #' . $payload['orderNumber'] . ' sent successfully',
                            1,
                            null,
                            'Order',
                            $orderId
                        );
                    }

                    // Remove from failed queue if exists
                    $this->removeFromFailedQueue($orderId);

                    return $this->result(true, $result['http_code'], $result['response'], null, false, $payload);
                }

                $lastError = $result['error'];
                $lastHttpCode = $result['http_code'];

                // Don't retry client errors (4xx) — configuration problem
                if ($result['http_code'] >= 400 && $result['http_code'] < 500) {
                    $this->log($orderId, 'error', $result['http_code'], $lastError);

                    if ($debugMode) {
                        PrestaShopLogger::addLog(
                            'Vocify AI: Client error (no retry) - Order #' . $payload['orderNumber'] .
                            ' - HTTP ' . $result['http_code'] . ': ' . $lastError,
                            3,
                            null,
                            'Order',
                            $orderId
                        );
                    }

                    return $this->result(false, $result['http_code'], $result['response'], $lastError, false, $payload);
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

        if ($debugMode) {
            PrestaShopLogger::addLog(
                'Vocify AI: All retries failed for order #' . $payload['orderNumber'] . ' - Last error: ' . $lastError,
                3,
                null,
                'Order',
                $orderId
            );
        }

        return $this->result(false, $lastHttpCode, null, $lastError, true, $payload);
    }

    /**
     * Send webhook to Vocify AI
     *
     * The request body MUST be the exact string the HMAC signature was
     * computed over — the platform verifies the signature against the raw
     * body it receives.
     *
     * @param array  $payload
     * @param string $apiKey
     * @param string $webhookUrl
     * @return array
     */
    public function sendWebhook($payload, $apiKey, $webhookUrl)
    {
        $storeDomain = $this->signer->extractDomain(Tools::getShopDomainSsl(true));
        $signatureSecret = Configuration::get('VOCIFY_SIGNATURE_SECRET');

        $rawBody = json_encode($payload);
        $headers = $this->signer->buildHeaders($apiKey, $storeDomain, $rawBody, $signatureSecret);

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_HTTPHEADER => $headers,
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
     * Retry the failed webhooks queue (cron.php).
     *
     * Processes a batch of queued payloads in a single tick. Successes and
     * permanent (4xx) failures are removed from the queue; transient
     * failures (5xx, network) stay queued with an incremented retry_count.
     *
     * @return int Number of successfully re-sent webhooks.
     */
    public function retryFailedWebhooks()
    {
        $apiKey = Configuration::get('VOCIFY_API_KEY');

        if (empty($apiKey)) {
            return 0;
        }

        $webhookUrl = Configuration::get('VOCIFY_WEBHOOK_URL');
        $rows = Db::getInstance()->executeS(
            'SELECT id_webhook, id_order, payload FROM `' . _DB_PREFIX_ . 'vocify_failed_webhooks`
             WHERE retry_count < ' . (int)self::FAILED_QUEUE_MAX_RETRIES . '
             ORDER BY created_at ASC
             LIMIT ' . (int)self::FAILED_QUEUE_BATCH
        );

        if (!$rows) {
            return 0;
        }

        $sent = 0;
        $db = Db::getInstance();

        foreach ($rows as $row) {
            $payload = json_decode($row['payload'], true);

            if (!is_array($payload)) {
                // Corrupt payload — drop it, it can never be sent.
                $db->delete('vocify_failed_webhooks', 'id_webhook = ' . (int)$row['id_webhook']);
                continue;
            }

            $result = $this->sendWebhook($payload, $apiKey, $webhookUrl);

            if ($result['success']) {
                $this->log((int)$row['id_order'], 'success', $result['http_code'], json_encode($result['response']));
                $db->delete('vocify_failed_webhooks', 'id_webhook = ' . (int)$row['id_webhook']);
                $sent++;
                continue;
            }

            $httpCode = $result['http_code'];

            // Permanent failure — remove from queue, log as error.
            if ($httpCode >= 400 && $httpCode < 500) {
                $this->log((int)$row['id_order'], 'error', $httpCode, $result['error']);
                $db->delete('vocify_failed_webhooks', 'id_webhook = ' . (int)$row['id_webhook']);
                continue;
            }

            // Transient failure — keep queued for the next tick.
            $db->update('vocify_failed_webhooks', array(
                'error_message' => pSQL(substr($result['error'], 0, 5000)),
                'retry_count' => array('type' => 'sql', 'value' => 'retry_count + 1'),
                'last_retry_at' => date('Y-m-d H:i:s'),
            ), 'id_webhook = ' . (int)$row['id_webhook']);
        }

        return $sent;
    }

    /**
     * Log webhook attempt
     *
     * @param int    $orderId
     * @param string $status
     * @param int    $httpCode
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
     * @param int    $orderId
     * @param array  $payload
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
        $orderPayments = $order->getOrderPaymentCollection();

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

    /**
     * Build a standardized result array.
     *
     * @param bool        $success
     * @param int         $httpCode
     * @param mixed       $response
     * @param string|null $error
     * @param bool        $retriesExhausted
     * @param array|null  $payload
     * @return array
     */
    private function result($success, $httpCode, $response, $error, $retriesExhausted, $payload)
    {
        return array(
            'success' => (bool)$success,
            'http_code' => (int)$httpCode,
            'response' => $response,
            'error' => $error,
            'retries_exhausted' => (bool)$retriesExhausted,
            'payload' => $payload,
        );
    }
}