<?php
/**
 * Vocify AI Payload Builder
 *
 * Transforms a normalized order data map into the unified webhook payload
 * accepted by the Vocify AI platform (`POST /api/webhooks/ecommerce`).
 *
 * The class is intentionally platform-agnostic: it takes a plain array of
 * order values extracted by the caller from PrestaShop, so every rule below
 * is unit-testable without a PrestaShop bootstrap. It mirrors the
 * WooCommerce builder (`Vocify_AI_Payload_Builder`), with PrestaShop's
 * phone-priority chain (customer mobile first).
 *
 * @author Vocify AI
 * @copyright 2025 Vocify AI
 * @license MIT License
 * @version 1.1.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VocifyPayloadBuilder
{
    const FINANCIAL_STATUSES = array(
        'pending', 'authorized', 'partially_paid', 'paid',
        'partially_refunded', 'refunded', 'voided',
    );

    const FULFILLMENT_STATUSES = array(
        'unfulfilled', 'partial', 'fulfilled', 'restocked',
    );

    /**
     * Normalized order data keys (consumed by self::build()):
     *
     *   order_id          (string)      Platform order ID
     *   order_number      (string)      Human-readable order reference
     *   order_key         (string|null)
     *   status            (string)      Order state slug/name
     *   financial_status  (string|null) Pre-mapped (clamped to enum here)
     *   fulfillment_status(string|null) Pre-mapped (clamped to enum here)
     *   customer          (array)       id, first_name, last_name, email,
     *                                   phone, mobile_phone
     *   phones            (array[])     Ordered phone candidates (priority 1st)
     *   items             (array[])     id, name, sku, quantity, price, total,
     *                                   image
     *   totals            (array)       subtotal, discount, shipping, tax, total
     *   currency          (string)
     *   shipping_address  (array)       address1, address2, city, state, zip,
     *                                   country, first_name, last_name,
     *                                   company, phone
     *   billing_address   (array)       Same fields as shipping_address
     *   payment_method    (string|null)
     *   transaction_id    (string|null)
     *   created_at        (int)         Unix timestamp
     *   updated_at        (int|null)
     *   paid_at           (int|null)
     *   needs_shipping    (bool)
     *   tax_included      (bool)
     *   default_country   (string)      ISO-3166 alpha-2 fallback (e.g. 'TN')
     */

    /**
     * Build the unified payload.
     *
     * @param array $raw Normalized order data (see key docs above).
     * @return array|null Unified payload, or null when essential data is
     *                    missing (no customer name/email or no items).
     */
    public function build($raw)
    {
        $customer = isset($raw['customer']) && is_array($raw['customer']) ? $raw['customer'] : array();
        $firstName = isset($customer['first_name']) ? (string)$customer['first_name'] : '';
        $lastName = isset($customer['last_name']) ? (string)$customer['last_name'] : '';
        $email = isset($customer['email']) ? (string)$customer['email'] : '';
        $items = isset($raw['items']) && is_array($raw['items']) ? $raw['items'] : array();

        if ($firstName === '' || $lastName === '' || $email === '' || count($items) === 0) {
            return null;
        }

        $phone = $this->extractPhone($raw, $customer);

        $totals = isset($raw['totals']) && is_array($raw['totals']) ? $raw['totals'] : array();
        $createdAt = isset($raw['created_at']) ? (int)$raw['created_at'] : time();

        $payload = array(
            'orderId' => isset($raw['order_id']) ? (string)$raw['order_id'] : '',
            'orderNumber' => isset($raw['order_number']) ? (string)$raw['order_number'] : '',
            'status' => isset($raw['status']) ? (string)$raw['status'] : '',
            'customer' => array(
                'id' => isset($customer['id']) ? (string)$customer['id'] : '',
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => $email,
                'phone' => $phone,
            ),
            'items' => $this->buildItems($items),
            'totals' => array(
                'subtotal' => $this->floatOf($totals, 'subtotal'),
                'total' => $this->floatOf($totals, 'total'),
            ),
            'currency' => isset($raw['currency']) ? strtoupper((string)$raw['currency']) : '',
            'shippingAddress' => $this->buildShippingAddress($raw),
            'createdAt' => gmdate('c', $createdAt),
        );

        if (!empty($raw['order_key'])) {
            $payload['orderKey'] = (string)$raw['order_key'];
        }

        if (isset($raw['financial_status'])) {
            $financial = $this->clampEnum($raw['financial_status'], self::FINANCIAL_STATUSES);
            if ($financial !== null) {
                $payload['financialStatus'] = $financial;
            }
        }

        if (isset($raw['fulfillment_status'])) {
            $fulfillment = $this->clampEnum($raw['fulfillment_status'], self::FULFILLMENT_STATUSES);
            if ($fulfillment !== null) {
                $payload['fulfillmentStatus'] = $fulfillment;
            }
        }

        if (isset($customer['mobile_phone']) && (string)$customer['mobile_phone'] !== '') {
            $payload['customer']['mobilePhone'] = (string)$customer['mobile_phone'];
        }

        foreach (array('discount', 'shipping', 'tax', 'shippingTax', 'handlingFee', 'refunded') as $key) {
            if (isset($totals[$key]) && is_numeric($totals[$key])) {
                $payload['totals'][$key] = (float)$totals[$key];
            }
        }

        $billing = $this->buildAddress(
            isset($raw['billing_address']) && is_array($raw['billing_address']) ? $raw['billing_address'] : array(),
            $raw
        );
        if (!empty($billing['address1']) || !empty($billing['city'])) {
            $payload['billingAddress'] = $billing;
        }

        if (isset($raw['payment_method']) && (string)$raw['payment_method'] !== '') {
            $payload['paymentMethod'] = (string)$raw['payment_method'];
        }
        if (isset($raw['transaction_id']) && (string)$raw['transaction_id'] !== '') {
            $payload['transactionId'] = (string)$raw['transaction_id'];
        }

        $this->addOptionalDate($payload, 'updatedAt', isset($raw['updated_at']) ? $raw['updated_at'] : null);
        $this->addOptionalDate($payload, 'paidAt', isset($raw['paid_at']) ? $raw['paid_at'] : null);

        $payload['requiresShipping'] = isset($raw['needs_shipping']) ? (bool)$raw['needs_shipping'] : true;
        $payload['taxIncluded'] = isset($raw['tax_included']) ? (bool)$raw['tax_included'] : false;
        $payload['isGift'] = false;

        if (isset($raw['metadata']) && is_array($raw['metadata']) && count($raw['metadata']) > 0) {
            $payload['metadata'] = $raw['metadata'];
        }

        return $payload;
    }

    /**
     * Build the items array, omitting empty image URLs (schema rejects null).
     *
     * @param array $items
     * @return array
     */
    private function buildItems($items)
    {
        $result = array();

        foreach ($items as $item) {
            $entry = array(
                'id' => isset($item['id']) ? (string)$item['id'] : '',
                'name' => isset($item['name']) ? (string)$item['name'] : '',
                'quantity' => isset($item['quantity']) ? (int)$item['quantity'] : 1,
                'price' => isset($item['price']) ? (float)$item['price'] : 0.0,
                'total' => isset($item['total']) ? (float)$item['total'] : 0.0,
            );

            if (isset($item['sku']) && (string)$item['sku'] !== '') {
                $entry['sku'] = (string)$item['sku'];
            }

            if (isset($item['image']) && is_string($item['image']) && $item['image'] !== '') {
                $entry['image'] = $item['image'];
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Extract the preferred phone number and format it E.164 when possible.
     *
     * Priority chain: an explicit `phones` candidate list (PrestaShop:
     * customer mobile, customer phone, delivery mobile, delivery phone,
     * invoice mobile, invoice phone), else customer phone → shipping phone →
     * customer mobile (WooCommerce semantics).
     *
     * @param array $raw      Normalized order data.
     * @param array $customer Customer map.
     * @return string
     */
    private function extractPhone($raw, $customer)
    {
        $defaultCountry = isset($raw['default_country']) ? (string)$raw['default_country'] : 'US';

        if (isset($raw['phones']) && is_array($raw['phones']) && count($raw['phones']) > 0) {
            foreach ($raw['phones'] as $phone) {
                if (is_string($phone) && $phone !== '') {
                    return $this->formatPhone($phone, $defaultCountry);
                }
            }

            return '';
        }

        $shipping = isset($raw['shipping_address']) && is_array($raw['shipping_address']) ? $raw['shipping_address'] : array();

        $phones = array(
            isset($customer['phone']) ? $customer['phone'] : '',
            isset($shipping['phone']) ? $shipping['phone'] : '',
            isset($customer['mobile_phone']) ? $customer['mobile_phone'] : '',
        );

        foreach ($phones as $phone) {
            if (is_string($phone) && $phone !== '') {
                return $this->formatPhone($phone, $defaultCountry);
            }
        }

        return '';
    }

    /**
     * Build the shipping address, falling back to billing fields and then the
     * store's default country so pick-up orders never produce an empty
     * address (the platform requires address1/city/country).
     *
     * @param array $raw Normalized order data.
     * @return array
     */
    private function buildShippingAddress($raw)
    {
        $shipping = isset($raw['shipping_address']) && is_array($raw['shipping_address']) ? $raw['shipping_address'] : array();
        $billing = isset($raw['billing_address']) && is_array($raw['billing_address']) ? $raw['billing_address'] : array();

        return $this->buildAddress($shipping, $raw, $billing);
    }

    /**
     * Build a single address map, merging missing fields from the fallback.
     *
     * @param array $address  Primary address fields.
     * @param array $raw      Normalized order data (default_country).
     * @param array $fallback Fallback address (billing for shipping).
     * @return array
     */
    private function buildAddress($address, $raw, $fallback = array())
    {
        $defaultCountry = isset($raw['default_country']) ? (string)$raw['default_country'] : '';

        $keys = array('first_name', 'last_name', 'company', 'address1', 'address2', 'city', 'state', 'zip', 'country', 'phone');
        $merged = array();

        foreach ($keys as $key) {
            $value = '';

            if (isset($address[$key])) {
                $value = $address[$key];
            } elseif (isset($fallback[$key])) {
                $value = $fallback[$key];
            }

            $merged[$key] = is_string($value) || is_numeric($value) ? (string)$value : '';
        }

        $country = strtoupper($merged['country']);

        if ($country === '' && $defaultCountry !== '') {
            $country = strtoupper($defaultCountry);
        }

        $mapped = array(
            'firstName' => $merged['first_name'],
            'lastName' => $merged['last_name'],
            'company' => $merged['company'],
            'address1' => $merged['address1'],
            'city' => $merged['city'],
            'state' => $merged['state'],
            'zip' => $merged['zip'],
            'country' => $country,
            'phone' => $merged['phone'],
        );

        if ($merged['address2'] !== '') {
            $mapped['address2'] = $merged['address2'];
        }

        return $mapped;
    }

    /**
     * Add an optional ISO-8601 date field, omitting it when absent/invalid.
     *
     * @param array $payload Payload being built (by reference).
     * @param string $key    Unified payload key (e.g. updatedAt).
     * @param mixed  $value  Timestamp or null.
     * @return void
     */
    private function addOptionalDate(&$payload, $key, $value)
    {
        if ($value === null || $value === 0 || $value === '') {
            return;
        }

        if (is_numeric($value)) {
            $payload[$key] = gmdate('c', (int)$value);
        }
    }

    /**
     * Clamp a value to a whitelist, returning null when it does not match.
     *
     * @param mixed $value
     * @param array $allowed
     * @return string|null
     */
    private function clampEnum($value, $allowed)
    {
        $value = (string)$value;

        if (in_array($value, $allowed, true)) {
            return $value;
        }

        return null;
    }

    /**
     * Read a float from a totals map, defaulting to zero.
     *
     * @param array  $totals
     * @param string $key
     * @return float
     */
    private function floatOf($totals, $key)
    {
        return isset($totals[$key]) && is_numeric($totals[$key]) ? (float)$totals[$key] : 0.0;
    }

    /**
     * Format a phone number to E.164 using libphonenumber when available,
     * falling back to separator-stripping.
     *
     * @param string $phone
     * @param string $defaultCountry
     * @return string
     */
    private function formatPhone($phone, $defaultCountry)
    {
        if ($phone === '') {
            return '';
        }

        if (class_exists('\libphonenumber\PhoneNumberUtil')) {
            try {
                $phoneUtil = \libphonenumber\PhoneNumberUtil::getInstance();
                $phoneNumber = $phoneUtil->parse($phone, $defaultCountry);

                if ($phoneUtil->isValidNumber($phoneNumber)) {
                    return $phoneUtil->format($phoneNumber, \libphonenumber\PhoneNumberFormat::E164);
                }
            } catch (\libphonenumber\NumberParseException $e) {
                // Fall through to basic formatting
            }
        }

        // Fallback: strip common separators, keep leading +.
        $phone = preg_replace('/[\s\-\(\)\.]/', '', $phone);

        return $phone;
    }
}