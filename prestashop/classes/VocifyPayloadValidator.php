<?php
/**
 * Vocify AI Payload Validator
 *
 * Mirrors the validation rules of the platform's unified webhook schema
 * (`src/lib/validations/unified-webhook.schema.ts`) so plugins fail fast with
 * clear, local error messages instead of receiving opaque 400 responses.
 *
 * @author Vocify AI
 * @copyright 2025 Vocify AI
 * @license MIT License
 * @version 1.1.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VocifyPayloadValidator
{
    const FINANCIAL_STATUSES = array(
        'pending', 'authorized', 'partially_paid', 'paid',
        'partially_refunded', 'refunded', 'voided',
    );

    const FULFILLMENT_STATUSES = array(
        'unfulfilled', 'partial', 'fulfilled', 'restocked',
    );

    const ADDRESS_KEYS = array(
        'firstName', 'lastName', 'company', 'address1', 'address2',
        'city', 'state', 'zip', 'country', 'phone',
    );

    /**
     * Validate a unified payload.
     *
     * @param array $payload The payload produced by the payload builder.
     * @return array List of human-readable validation error messages
     *               (empty when the payload is valid).
     */
    public function validate($payload)
    {
        $errors = array();

        if (!is_array($payload)) {
            return array('Payload must be an object');
        }

        // ---- Order identification -----------------------------------------
        $this->requireString($errors, $payload, 'orderId', 'orderId');
        $this->requireString($errors, $payload, 'orderNumber', 'orderNumber');
        $this->requireString($errors, $payload, 'status', 'status');

        if (isset($payload['orderKey']) && $payload['orderKey'] !== null && !is_string($payload['orderKey'])) {
            $errors[] = 'orderKey must be a string';
        }

        // ---- Statuses (enums) ---------------------------------------------
        if (isset($payload['financialStatus']) && $payload['financialStatus'] !== null
            && !in_array($payload['financialStatus'], self::FINANCIAL_STATUSES, true)) {
            $errors[] = 'financialStatus must be one of: ' . implode(', ', self::FINANCIAL_STATUSES);
        }

        if (isset($payload['fulfillmentStatus']) && $payload['fulfillmentStatus'] !== null
            && !in_array($payload['fulfillmentStatus'], self::FULFILLMENT_STATUSES, true)) {
            $errors[] = 'fulfillmentStatus must be one of: ' . implode(', ', self::FULFILLMENT_STATUSES);
        }

        // ---- Customer ------------------------------------------------------
        if (!isset($payload['customer']) || !is_array($payload['customer'])) {
            $errors[] = 'customer is required';
        } else {
            $customer = $payload['customer'];

            $this->requireStringIn($errors, $customer, 'firstName', 'customer.firstName');
            $this->requireStringIn($errors, $customer, 'lastName', 'customer.lastName');

            if (empty($customer['phone'])) {
                $errors[] = 'customer.phone is required';
            }

            if (empty($customer['email'])) {
                $errors[] = 'customer.email is required';
            } elseif (!filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'customer.email must be a valid email address';
            }
        }

        // ---- Items -----------------------------------------------------------
        if (!isset($payload['items']) || !is_array($payload['items']) || count($payload['items']) === 0) {
            $errors[] = 'items must contain at least one item';
        } else {
            foreach ($payload['items'] as $index => $item) {
                $prefix = 'items[' . $index . ']';

                $this->requireString($errors, $item, 'id', $prefix . '.id');
                $this->requireString($errors, $item, 'name', $prefix . '.name');

                if (empty($item['quantity']) || !is_numeric($item['quantity']) || (int)$item['quantity'] < 1) {
                    $errors[] = $prefix . '.quantity must be a positive integer';
                }

                if (isset($item['price']) && (!is_numeric($item['price']) || (float)$item['price'] < 0)) {
                    $errors[] = $prefix . '.price must be a non-negative number';
                }

                if (isset($item['total']) && (!is_numeric($item['total']) || (float)$item['total'] < 0)) {
                    $errors[] = $prefix . '.total must be a non-negative number';
                }

                if (isset($item['image']) && !is_string($item['image'])) {
                    $errors[] = $prefix . '.image must be a string';
                }
            }
        }

        // ---- Totals ------------------------------------------------------------
        if (!isset($payload['totals']) || !is_array($payload['totals'])) {
            $errors[] = 'totals is required';
        } else {
            $totals = $payload['totals'];

            foreach (array('subtotal', 'total') as $key) {
                if (!isset($totals[$key]) || !is_numeric($totals[$key]) || (float)$totals[$key] < 0) {
                    $errors[] = 'totals.' . $key . ' must be a non-negative number';
                }
            }
        }

        // ---- Currency ------------------------------------------------------------
        if (empty($payload['currency']) || !preg_match('/^[A-Z]{3}$/', $payload['currency'])) {
            $errors[] = 'currency must be a 3-letter ISO 4217 code (e.g. USD, TND)';
        }

        // ---- Addresses --------------------------------------------------------------
        if (!isset($payload['shippingAddress']) || !is_array($payload['shippingAddress'])) {
            $errors[] = 'shippingAddress is required';
        } else {
            $this->validateAddress($errors, $payload['shippingAddress'], 'shippingAddress');
        }

        if (isset($payload['billingAddress']) && is_array($payload['billingAddress'])) {
            $this->validateAddress($errors, $payload['billingAddress'], 'billingAddress');
        }

        // ---- Dates ------------------------------------------------------------------
        if (empty($payload['createdAt'])) {
            $errors[] = 'createdAt is required';
        } elseif (!$this->isValidDate($payload['createdAt'])) {
            $errors[] = 'createdAt must be a valid ISO 8601 date';
        }

        foreach (array('updatedAt', 'paidAt') as $key) {
            if (isset($payload[$key]) && $payload[$key] !== null && !$this->isValidDate($payload[$key])) {
                $errors[] = $key . ' must be a valid ISO 8601 date';
            }
        }

        // ---- Flags ---------------------------------------------------------------------
        foreach (array('requiresShipping', 'isGift', 'taxIncluded') as $key) {
            if (isset($payload[$key]) && !is_bool($payload[$key])) {
                $errors[] = $key . ' must be a boolean';
            }
        }

        return $errors;
    }

    /**
     * Convenience wrapper: is the payload valid?
     *
     * @param array $payload
     * @return bool
     */
    public function isValid($payload)
    {
        return count($this->validate($payload)) === 0;
    }

    /**
     * Validate a single address map.
     *
     * @param array  $errors Errors list (by reference).
     * @param array  $address
     * @param string $prefix
     * @return void
     */
    private function validateAddress(&$errors, $address, $prefix)
    {
        foreach (array_keys($address) as $key) {
            if (!in_array($key, self::ADDRESS_KEYS, true)) {
                $errors[] = $prefix . ' contains unknown field: ' . $key;
            }
        }

        if (empty($address['address1'])) {
            $errors[] = $prefix . '.address1 is required';
        }

        if (empty($address['city'])) {
            $errors[] = $prefix . '.city is required';
        }

        if (empty($address['country']) || !preg_match('/^[A-Z]{2}$/', $address['country'])) {
            $errors[] = $prefix . '.country must be a 2-letter ISO 3166-1 code';
        }
    }

    /**
     * Require a non-empty string at the top level of the payload.
     *
     * @param array  $errors
     * @param array  $payload
     * @param string $key
     * @param string $label
     * @return void
     */
    private function requireString(&$errors, $payload, $key, $label)
    {
        if (!isset($payload[$key]) || !is_string($payload[$key]) || $payload[$key] === '') {
            $errors[] = $label . ' must be a non-empty string';
        }
    }

    /**
     * Require a non-empty string inside a nested map (e.g. customer).
     *
     * @param array  $errors
     * @param array  $map
     * @param string $key
     * @param string $label
     * @return void
     */
    private function requireStringIn(&$errors, $map, $key, $label)
    {
        if (!isset($map[$key]) || !is_string($map[$key]) || $map[$key] === '') {
            $errors[] = $label . ' must be a non-empty string';
        }
    }

    /**
     * Accept ISO-8601 strings and unix timestamps.
     *
     * @param mixed $value
     * @return bool
     */
    private function isValidDate($value)
    {
        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return $value > 0;
        }

        if (is_string($value)) {
            $timestamp = strtotime($value);

            return $timestamp !== false && $timestamp > 0;
        }

        return false;
    }
}