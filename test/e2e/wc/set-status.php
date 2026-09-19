<?php
/**
 * Transition an existing order's status, firing `woocommerce_order_status_changed`.
 *
 * Run: wp --allow-root eval-file /e2e/set-status.php <order_id> <new_status>
 *
 * The plugin forwards only a fixed set of statuses (processing, completed,
 * cancelled, refunded, failed — class-vocify-order-handler.php:96-104), so a
 * transition outside that set must produce NO webhook. Both cases are asserted.
 */

if (!defined('ABSPATH')) {
    exit;
}

$order_id = isset($args[0]) ? (int) $args[0] : 0;
$status = isset($args[1]) ? $args[1] : 'processing';

$order = wc_get_order($order_id);
if (!$order) {
    fwrite(STDERR, "WC-STATUS-FAIL: order {$order_id} not found\n");
    exit(1);
}

$order->update_status($status, 'e2e harness transition');
echo 'WC-STATUS-CHANGED order_id=' . $order_id . ' status=' . $order->get_status() . "\n";
