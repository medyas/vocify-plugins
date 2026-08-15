<?php
/**
 * Vocify AI Payload Builder
 *
 * Transforms a normalized order data map into the unified webhook payload
 * accepted by the Vocify AI platform (`POST /api/webhooks/ecommerce`).
 *
 * The class is intentionally platform-agnostic: it takes a plain array of
 * order values extracted by the caller from WooCommerce, so every rule below
 * is unit-testable without a WordPress bootstrap:
 *
 * - orderId/orderNumber are always strings (the platform schema requires it)
 * - item images are omitted when empty (schema rejects null URL values)
 * - optional dates are omitted when absent (schema rejects null datetimes)
 * - shipping address falls back to billing, then to the store country
 * - phone priority: billing phone only source for WooCommerce
 * - financial/fulfillment statuses are clamped to the platform enums
 *
 * @package VocifyAI
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vocify_AI_Payload_Builder {

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
     *   order_number      (string)      Human-readable order number
     *   order_key         (string|null)
     *   status            (string)      WooCommerce status (no wc- prefix)
     *   is_paid           (bool)
     *   total_refunded    (float)
     *   customer          (array)       id, first_name, last_name, email,
     *                                   phone, mobile_phone
     *   items             (array[])     id, name, sku, quantity, price, total,
     *                                   image
     *   totals            (array)       subtotal, discount, shipping, tax, total
     *   currency          (string)
     *   shipping_address  (array)       address1, address2, city, state, zip,
     *                                   country, first_name, last_name,
     *                                   company, phone
     *   billing_address   (array)       Same fields as shipping_address
     *   payment_method    (string|null)
     *   payment_method_title (string|null)
     *   transaction_id    (string|null)
     *   created_at        (int)         Unix timestamp
     *   updated_at        (int|null)
     *   paid_at           (int|null)
     *   customer_note     (string|null)
     *   needs_shipping    (bool)
     *   tax_included      (bool)
     *   default_country   (string)      ISO-3166 alpha-2 fallback (e.g. 'US')
     */

    /**
     * Build the unified payload.
     *
     * @param array $raw Normalized order data (see key docs above).
     * @return array|null Unified payload, or null when essential data is
     *                    missing (no customer name/email or no items).
     */
    public function build($raw) {
        $customer = isset($raw['customer']) && is_array($raw['customer']) ? $raw['customer'] : array();
        $firstName = isset($customer['first_name']) ? (string)$customer['first_name'] : '';
        $lastName = isset($customer['last_name']) ? (string)$customer['last_name'] : '';
        $email = isset($customer['email']) ? (string)$customer['email'] : '';
        $items = isset($raw['items']) && is_array($raw['items']) ? $raw['items'] : array();

        if ($firstName === '' || $lastName === '' || $email === '' || count($items) === 0) {
            return null;
        }

        $phone = $this->extract_phone($raw, $customer);

        $shipping = $this->build_shipping_address($raw);
        $totals = isset($raw['totals']) && is_array($raw['totals']) ? $raw['totals'] : array();

        // Required date fields: createdAt must always be present.
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
            'items' => $this->build_items($items),
            'totals' => array(
                'subtotal' => $this->float_of($totals, 'subtotal'),
                'total' => $this->float_of($totals, 'total'),
            ),
            'currency' => isset($raw['currency']) ? strtoupper((string)$raw['currency']) : '',
            'shippingAddress' => $shipping,
            'createdAt' => gmdate('c', $createdAt),
        );

        // Optional identifiers / statuses (only set when non-empty).
        if (!empty($raw['order_key'])) {
            $payload['orderKey'] = (string)$raw['order_key'];
        }

        // Financial/fulfillment statuses are optional on the platform but must
        // match its enums exactly — clamp anything unknown away.
        if (isset($raw['financial_status'])) {
            $financial = $this->clamp_enum($raw['financial_status'], self::FINANCIAL_STATUSES);
            if ($financial !== null) {
                $payload['financialStatus'] = $financial;
            }
        }

        if (isset($raw['fulfillment_status'])) {
            $fulfillment = $this->clamp_enum($raw['fulfillment_status'], self::FULFILLMENT_STATUSES);
            if ($fulfillment !== null) {
                $payload['fulfillmentStatus'] = $fulfillment;
            }
        }

        // Customer extras.
        if (isset($customer['mobile_phone']) && (string)$customer['mobile_phone'] !== '') {
            $payload['customer']['mobilePhone'] = (string)$customer['mobile_phone'];
        }
        if (isset($customer['is_new_customer'])) {
            $payload['customer']['isNewCustomer'] = (bool)$customer['is_new_customer'];
        }
        if (isset($customer['total_orders'])) {
            $payload['customer']['totalOrders'] = (int)$customer['total_orders'];
        }
        if (isset($customer['total_spent'])) {
            $payload['customer']['totalSpent'] = (float)$customer['total_spent'];
        }

        // Remaining optional totals.
        foreach (array('discount', 'shipping', 'tax', 'shippingTax', 'handlingFee', 'refunded') as $key) {
            if (isset($totals[$key]) && is_numeric($totals[$key])) {
                $payload['totals'][$key] = (float)$totals[$key];
            }
        }

        // Billing address (optional on the platform) when it has usable data.
        $billing = $this->build_address(
            isset($raw['billing_address']) && is_array($raw['billing_address']) ? $raw['billing_address'] : array(),
            $raw
        );
        if (!empty($billing['address1']) || !empty($billing['city'])) {
            $payload['billingAddress'] = $billing;
        }

        // Payment + dates + notes.
        if (isset($raw['payment_method']) && (string)$raw['payment_method'] !== '') {
            $payload['paymentMethod'] = (string)$raw['payment_method'];
        }
        if (isset($raw['payment_method_title']) && (string)$raw['payment_method_title'] !== '') {
            $payload['paymentMethodTitle'] = (string)$raw['payment_method_title'];
        }
        if (isset($raw['transaction_id']) && (string)$raw['transaction_id'] !== '') {
            $payload['transactionId'] = (string)$raw['transaction_id'];
        }

        $this->add_optional_date($payload, 'updatedAt', isset($raw['updated_at']) ? $raw['updated_at'] : null);
        $this->add_optional_date($payload, 'paidAt', isset($raw['paid_at']) ? $raw['paid_at'] : null);

        if (isset($raw['customer_note']) && (string)$raw['customer_note'] !== '') {
            $payload['customerNote'] = (string)$raw['customer_note'];
        }

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
    private function build_items($items) {
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

            if (isset($item['attributes']) && is_array($item['attributes']) && count($item['attributes']) > 0) {
                $entry['attributes'] = $item['attributes'];
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * Extract the preferred phone number and format it E.164 when possible.
     *
     * Priority (WooCommerce has no separate customer-account phone beyond the
     * billing phone): billing phone, then shipping phone, then mobile phone.
     *
     * @param array $raw      Normalized order data.
     * @param array $customer Customer map.
     * @return string
     */
    private function extract_phone($raw, $customer) {
        $shipping = isset($raw['shipping_address']) && is_array($raw['shipping_address']) ? $raw['shipping_address'] : array();

        $phones = array(
            isset($customer['phone']) ? $customer['phone'] : '',
            isset($shipping['phone']) ? $shipping['phone'] : '',
            isset($customer['mobile_phone']) ? $customer['mobile_phone'] : '',
        );

        $defaultCountry = isset($raw['default_country']) ? (string)$raw['default_country'] : 'US';

        foreach ($phones as $phone) {
            if (is_string($phone) && $phone !== '') {
                return $this->format_phone($phone, $defaultCountry);
            }
        }

        return '';
    }

    /**
     * Build the shipping address, falling back to billing fields and then the
     * store's base country so virtual/local-pickup orders never produce an
     * empty address (the platform requires address1/city/country).
     *
     * @param array $raw Normalized order data.
     * @return array
     */
    private function build_shipping_address($raw) {
        $shipping = isset($raw['shipping_address']) && is_array($raw['shipping_address']) ? $raw['shipping_address'] : array();
        $billing = isset($raw['billing_address']) && is_array($raw['billing_address']) ? $raw['billing_address'] : array();

        return $this->build_address($shipping, $raw, $billing);
    }

    /**
     * Build a single address map, merging missing fields from the fallback.
     *
     * @param array $address            Primary address fields.
     * @param array $raw                Normalized order data (default_country).
     * @param array $fallback           Fallback address (billing for shipping).
     * @return array
     */
    private function build_address($address, $raw, $fallback = array()) {
        $defaultCountry = isset($raw['default_country']) ? (string)$raw['default_country'] : 'US';

        $keys = array('first_name', 'last_name', 'company', 'address1', 'address2', 'city', 'state', 'zip', 'country', 'phone');

        $merged = array();

        foreach ($keys as $key) {
            $value = '';

            if (isset($address[$key])) {
                $value = $address[$key];
            } elseif (isset($fallback[$key])) {
                $value = $fallback[$key];
            }

            if (is_string($value) || is_numeric($value)) {
                $merged[$key] = (string)$value;
            } else {
                $merged[$key] = '';
            }
        }

        if ($merged['country'] === '') {
            $merged['country'] = strtoupper($defaultCountry);
        } else {
            $merged['country'] = strtoupper($merged['country']);
        }

        return $this->remap_address($merged);
    }

    /**
     * Convert snake_case address fields to the unified payload keys.
     *
     * @param array $address Merged snake_case address fields.
     * @return array
     */
    private function remap_address($address) {
        $mapped = array(
            'firstName' => $address['first_name'],
            'lastName' => $address['last_name'],
            'company' => $address['company'],
            'address1' => $address['address1'],
            'city' => $address['city'],
            'state' => $address['state'],
            'zip' => $address['zip'],
            'country' => $address['country'],
            'phone' => $address['phone'],
        );

        if ($address['address2'] !== '') {
            $mapped['address2'] = $address['address2'];
        }

        return $mapped;
    }

    /**
     * Add an optional ISO-8601 date field, omitting it when absent/invalid.
     *
     * @param array  $payload Payload being built (by reference).
     * @param string $key     Unified payload key (e.g. updatedAt).
     * @param mixed  $value   Timestamp or null.
     * @return void
     */
    private function add_optional_date(&$payload, $key, $value) {
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
     * @param mixed  $value
     * @param array  $allowed
     * @return string|null
     */
    private function clamp_enum($value, $allowed) {
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
    private function float_of($totals, $key) {
        return isset($totals[$key]) && is_numeric($totals[$key]) ? (float)$totals[$key] : 0.0;
    }

    /**
     * Format a phone number to E.164 using libphonenumber when available,
     * falling back to separator-stripping.
     *
     * @param string $phone
     * @param string $default_country
     * @return string
     */
    private function format_phone($phone, $default_country) {
        if ($phone === '') {
            return '';
        }

        if (class_exists('\libphonenumber\PhoneNumberUtil')) {
            try {
                $phone_util = \libphonenumber\PhoneNumberUtil::getInstance();
                $phone_number = $phone_util->parse($phone, $default_country);

                if ($phone_util->isValidNumber($phone_number)) {
                    return $phone_util->format($phone_number, \libphonenumber\PhoneNumberFormat::E164);
                }
            } catch (\libphonenumber\NumberParseException $e) {
                // Fall through to basic formatting
            }
        }

        // Fallback: strip common separators, keep leading +.
        return preg_replace('/[\s\-\(\)\.]/', '', $phone);
    }
}