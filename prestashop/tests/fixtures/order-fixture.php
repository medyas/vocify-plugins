<?php
/**
 * Test fixture — a realistic normalized order data map for PrestaShop.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Build a valid normalized order data map consumed by VocifyPayloadBuilder.
 *
 * @return array
 */
function vocify_test_order_fixture()
{
    return array(
        'order_id' => '42',
        'order_number' => 'XA123456',
        'order_key' => 'ps_secure_key_abc',
        'status' => 'payment_accepted',
        'financial_status' => 'paid',
        'fulfillment_status' => 'unfulfilled',
        'customer' => array(
            'id' => '7',
            'first_name' => 'Ahmed',
            'last_name' => 'Ben Ali',
            'email' => 'ahmed@example.com',
            'phone' => '',
            'mobile_phone' => '+21622110033',
        ),
        'phones' => array(
            '(220) 221-1003',
            '+21622110033',
            '',
            '102.221.1003',
        ),
        'items' => array(
            array(
                'id' => '501',
                'name' => 'Tunisian Olive Oil 1L',
                'sku' => 'OIL-1L',
                'quantity' => 2,
                'price' => 12.5,
                'total' => 25.0,
                'image' => 'https://store.example.com/img/p/5/0/1/501.jpg',
            ),
            array(
                'id' => '502',
                'name' => 'Couscous 500g',
                'sku' => 'COU-500',
                'quantity' => 1,
                'price' => 3.75,
                'total' => 3.75,
                'image' => '',
            ),
        ),
        'totals' => array(
            'subtotal' => 28.75,
            'discount' => 0.0,
            'shipping' => 5.0,
            'tax' => 6.74,
            'total' => 40.49,
        ),
        'currency' => 'tnD',
        'shipping_address' => array(
            'first_name' => 'Ahmed',
            'last_name' => 'Ben Ali',
            'company' => '',
            'address1' => '12 Rue de Carthage',
            'address2' => 'Immeuble 3',
            'city' => 'Tunis',
            'state' => 'Tunis',
            'zip' => '1000',
            'country' => 'TN',
            'phone' => '+21622110033',
        ),
        'billing_address' => array(
            'first_name' => 'Ahmed',
            'last_name' => 'Ben Ali',
            'company' => '',
            'address1' => '12 Rue de Carthage',
            'address2' => 'Immeuble 3',
            'city' => 'Tunis',
            'state' => 'Tunis',
            'zip' => '1000',
            'country' => 'TN',
            'phone' => '+21622110033',
        ),
        'payment_method' => 'cod',
        'transaction_id' => 'PS_TXN_0099',
        'created_at' => 1735689600,
        'updated_at' => null,
        'paid_at' => 1735693200,
        'needs_shipping' => true,
        'tax_included' => false,
        'default_country' => 'TN',
        'metadata' => array(
            'prestashopOrderKey' => 'ps_secure_key_abc',
            'prestashopCustomerId' => '7',
        ),
    );
}