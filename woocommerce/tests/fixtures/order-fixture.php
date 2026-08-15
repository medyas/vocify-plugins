<?php
/**
 * Test fixture — a realistic normalized order data map for WooCommerce.
 *
 * @package VocifyAI
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Build a valid normalized order data map consumed by Vocify_AI_Payload_Builder.
 *
 * @return array
 */
function vocify_test_order_fixture()
{
    return array(
        'order_id' => '12345',
        'order_number' => '12345',
        'order_key' => 'wc_order_abc123',
        'status' => 'processing',
        'financial_status' => 'paid',
        'fulfillment_status' => 'unfulfilled',
        'is_paid' => true,
        'total_refunded' => 0.0,
        'default_country' => 'US',
        'customer' => array(
            'id' => '42',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'john@example.com',
            'phone' => '+12025551234',
            'is_new_customer' => false,
            'total_orders' => 3,
            'total_spent' => 250.0,
        ),
        'items' => array(
            array(
                'id' => '101',
                'name' => 'Widget',
                'sku' => 'WID-1',
                'quantity' => 2,
                'price' => 19.99,
                'total' => 39.98,
                'image' => 'https://store.example.com/wp-content/uploads/widget.jpg',
                'attributes' => array('Color' => 'Red'),
            ),
            array(
                'id' => '102',
                'name' => 'Gadget',
                'sku' => 'GAD-2',
                'quantity' => 1,
                'price' => 9.99,
                'total' => 9.99,
                'image' => '',
                'attributes' => array(),
            ),
        ),
        'totals' => array(
            'subtotal' => 49.97,
            'discount' => 5.0,
            'shipping' => 4.99,
            'tax' => 2.5,
            'total' => 52.46,
        ),
        'currency' => 'usd',
        'shipping_address' => array(
            'first_name' => 'John',
            'last_name' => 'Doe',
            'company' => '',
            'address1' => '123 Main St',
            'address2' => 'Apt 4',
            'city' => 'Springfield',
            'state' => 'IL',
            'zip' => '62701',
            'country' => 'US',
            'phone' => '+12025551234',
        ),
        'billing_address' => array(
            'first_name' => 'John',
            'last_name' => 'Doe',
            'company' => '',
            'address1' => '123 Main St',
            'address2' => 'Apt 4',
            'city' => 'Springfield',
            'state' => 'IL',
            'zip' => '62701',
            'country' => 'US',
            'phone' => '+12025551234',
        ),
        'payment_method' => 'cod',
        'payment_method_title' => 'Cash on delivery',
        'transaction_id' => 'txn_98765',
        'created_at' => 1735689600,
        'updated_at' => 1735693200,
        'paid_at' => 1735693200,
        'customer_note' => 'Leave at the front door',
        'needs_shipping' => true,
        'tax_included' => false,
        'metadata' => array(
            'woocommerceOrderKey' => 'wc_order_abc123',
            'woocommerceCustomerId' => '42',
        ),
    );
}