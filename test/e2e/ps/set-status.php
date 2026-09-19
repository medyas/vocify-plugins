<?php
/**
 * Transition an order's state, firing `actionOrderStatusPostUpdate`.
 *
 * Run: php /e2e/set-status.php <id_order> <PS_OS_* configuration key>
 *
 * Uses OrderHistory::changeIdOrderState() — the same call
 * PaymentModule::validateOrder() and the back office both make — so the hook
 * fires exactly as it does in production.
 */

require_once '/var/www/html/config/config.inc.php';
require_once '/var/www/html/app/AppKernel.php';
$kernel = new AppKernel('prod', false);
$kernel->boot();
$context = Context::getContext();
$context->container = $kernel->getContainer();
$context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
$context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

$idOrder = isset($argv[1]) ? (int) $argv[1] : 0;
$stateKey = isset($argv[2]) ? $argv[2] : 'PS_OS_PREPARATION';

$order = new Order($idOrder);
if (!Validate::isLoadedObject($order)) {
    fwrite(STDERR, "PS-STATUS-FAIL: order {$idOrder} not found\n");
    exit(1);
}

$idState = (int) Configuration::get($stateKey);
if (!$idState) {
    fwrite(STDERR, "PS-STATUS-FAIL: no order state for {$stateKey}\n");
    exit(1);
}

$history = new OrderHistory();
$history->id_order = (int) $order->id;
$history->changeIdOrderState($idState, $order, true);
$history->add();

echo 'PS-STATUS-CHANGED order_id=' . $idOrder . ' state=' . $idState . "\n";
