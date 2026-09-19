<?php
/**
 * Place a REAL WooCommerce order the way the STOREFRONT does.
 *
 * Run:  wp --allow-root eval-file /e2e/create-order-checkout.php [phone]
 *
 * Why this exists alongside create-order.sh:
 *
 * `wp wc shop_order create` drives WooCommerce's REST controller, which saves
 * the order BEFORE attaching line items. `woocommerce_new_order` therefore
 * fires against an item-less order, the plugin's payload builder correctly
 * returns null ("Failed to transform order data"), and the webhook that
 * actually succeeds is the one from `woocommerce_order_status_changed`.
 *
 * A storefront checkout does not behave that way: `WC_Checkout::create_order()`
 * builds the whole order — line items, addresses, totals — and saves ONCE, so
 * `woocommerce_new_order` fires against a complete order. This script mirrors
 * that shape so the `new_order` hook is exercised on a payload the plugin can
 * actually send, which is the path a merchant's real traffic takes.
 *
 * It uses WooCommerce's own order API throughout — nothing about the plugin is
 * stubbed, and the webhook it triggers is sent by the shipped plugin code.
 */

if (!defined('ABSPATH')) {
    exit;
}

$phone = isset($args[0]) ? $args[0] : '+21600000000';

$product_id = (int) wc_get_product_id_by_sku('WH-100');
if (!$product_id) {
    fwrite(STDERR, "WC-ORDER-FAIL: product WH-100 not found\n");
    exit(1);
}
$product = wc_get_product($product_id);

$address = array(
    'first_name' => 'Amina',
    'last_name' => 'Ben Ali',
    'address_1' => '1 Avenue Habib Bourguiba',
    'city' => 'Tunis',
    'postcode' => '1000',
    'country' => 'TN',
);

// `new WC_Order()` — NOT `wc_create_order()`, which saves an empty order
// immediately and so fires `woocommerce_new_order` before any line item
// exists. WC_Checkout::create_order() builds the object first and saves once;
// that is the shape being reproduced here.
$order = new WC_Order();
$order->add_product($product, 1);
$order->set_address(array_merge($address, array('email' => 'amina@e2e.invalid', 'phone' => $phone)), 'billing');
$order->set_address($address, 'shipping');
$order->set_currency('TND');
$order->set_payment_method('cod');
$order->set_payment_method_title('Cash on delivery');

// Set the totals EXPLICITLY, the way WC_Checkout::create_order() does from the
// cart — do NOT call $order->calculate_totals() here. calculate_totals() saves
// the order internally BEFORE it writes the computed total back onto the
// object, so `woocommerce_new_order` would fire with total=0 and this fixture
// would be measuring an artifact of its own making rather than a checkout.
// Measured on WooCommerce 11.1.1: inside the hook, subtotal=129.9 but total=0.
$line_total = 0.0;
foreach ($order->get_items() as $item) {
    $line_total += (float) $item->get_total();
}
$order->set_shipping_total(0);
$order->set_discount_total(0);
$order->set_cart_tax(0);
$order->set_shipping_tax(0);
$order->set_total($line_total);

// ONE save of a complete order — this is the call that fires
// `woocommerce_new_order` with line items present, exactly as checkout does.
// The order is left `pending` on purpose so this run exercises the
// `woocommerce_new_order` hook alone; set-status.php drives the separate
// `woocommerce_order_status_changed` hook afterwards.
$order->set_status(isset($args[1]) ? $args[1] : 'pending');
$order->save();

echo 'WC-ORDER-CREATED order_id=' . $order->get_id() . "\n";
