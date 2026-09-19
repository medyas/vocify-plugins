<?php
/**
 * Place a REAL PrestaShop order, inside the container.
 *
 * Run: php /e2e/create-order.php [phone]
 *
 * WHY THIS IS A PHP SCRIPT AND NOT AN API CALL
 * --------------------------------------------
 * PrestaShop's webservice API writes order rows more directly and does NOT
 * fire `actionValidateOrder` — the hook the Vocify module actually listens on
 * (`vocifyai.php:180`). An order created that way would prove nothing about
 * the module, the same way curl-ing the webhook endpoint would prove nothing.
 * And unlike WooCommerce there is no supported non-interactive checkout: the
 * front-office flow needs real HTTP form posts, session state and CSRF tokens
 * across several requests.
 *
 * So this builds the objects a checkout builds and calls
 * `PaymentModule::validateOrder()` — the exact method the front-office
 * OrderController calls, and the one that fires `actionValidateOrder`. The
 * module code under test runs unmodified; only the caller differs.
 */

require_once '/var/www/html/config/config.inc.php';

// `config.inc.php` alone is not enough: PaymentModule::validateOrder() calls
// Tools::getContextLocale(), which resolves through ContainerFinder and throws
// "Kernel Container is not available" unless the Symfony kernel is booted and
// attached to the context. A front-office request gets this from its
// controller; a CLI script has to do it explicitly.
require_once '/var/www/html/app/AppKernel.php';
$kernel = new AppKernel('prod', false);
$kernel->boot();

$phone = isset($argv[1]) ? $argv[1] : '+21600000000';

$context = Context::getContext();
$context->container = $kernel->getContainer();
$idLang = (int) Configuration::get('PS_LANG_DEFAULT');
$idShop = (int) Configuration::get('PS_SHOP_DEFAULT');
$idCurrency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
$idCountry = (int) Configuration::get('PS_COUNTRY_DEFAULT');

$context->shop = new Shop($idShop);
$context->language = new Language($idLang);
$context->currency = new Currency($idCurrency);

// ---- customer -------------------------------------------------------------
$email = 'amina@e2e.invalid';
$idCustomer = (int) Db::getInstance()->getValue(
    'SELECT id_customer FROM ' . _DB_PREFIX_ . 'customer WHERE email = "' . pSQL($email) . '"'
);
if (!$idCustomer) {
    $customer = new Customer();
    $customer->firstname = 'Amina';
    $customer->lastname = 'Ben Ali';
    $customer->email = $email;
    $customer->passwd = Tools::hash('E2eTest123!');
    $customer->id_shop = $idShop;
    $customer->id_shop_group = (int) Configuration::get('PS_SHOP_GROUP_DEFAULT') ?: 1;
    $customer->id_default_group = (int) Configuration::get('PS_CUSTOMER_GROUP');
    $customer->add();
    $idCustomer = (int) $customer->id;
}
$context->customer = new Customer($idCustomer);

// ---- address --------------------------------------------------------------
$address = new Address();
$address->id_customer = $idCustomer;
$address->id_country = $idCountry;
$address->alias = 'e2e-' . uniqid();
$address->firstname = 'Amina';
$address->lastname = 'Ben Ali';
$address->address1 = '1 Avenue Habib Bourguiba';
$address->city = 'Tunis';
$address->postcode = '75001';
$address->phone = $phone;
$address->phone_mobile = $phone;
$address->add();

// ---- cart -----------------------------------------------------------------
$idProduct = (int) Db::getInstance()->getValue(
    'SELECT id_product FROM ' . _DB_PREFIX_ . 'product WHERE active = 1 ORDER BY id_product ASC'
);
if (!$idProduct) {
    fwrite(STDERR, "PS-ORDER-FAIL: no active product in the catalogue\n");
    exit(1);
}

$cart = new Cart();
$cart->id_customer = $idCustomer;
$cart->id_address_delivery = (int) $address->id;
$cart->id_address_invoice = (int) $address->id;
$cart->id_lang = $idLang;
$cart->id_currency = $idCurrency;
$cart->id_shop = $idShop;
$cart->id_carrier = (int) Configuration::get('PS_CARRIER_DEFAULT');
$cart->recyclable = 0;
$cart->gift = 0;
// validateOrder() reloads the cart and compares the secure_key it is handed
// against the PERSISTED one. A programmatically created cart has none, so it
// must be seeded from the customer exactly as the front office does, before
// the row is written.
$cart->secure_key = $context->customer->secure_key;
$cart->add();

$context->cart = $cart;
$context->cookie = new Cookie('ps-e2e');

if (!$cart->updateQty(1, $idProduct)) {
    fwrite(STDERR, "PS-ORDER-FAIL: could not add product {$idProduct} to the cart\n");
    exit(1);
}
$cart->update();

// ---- validate the order (fires actionValidateOrder) -----------------------
// Any always-present PaymentModule will do: validateOrder() needs *a* payment
// module for its order-state bookkeeping, and the Vocify hook does not care
// which one. `ps_checkpayment` / `ps_wirepayment` ship with PrestaShop 8.
$paymentModule = null;
foreach (array('ps_checkpayment', 'ps_wirepayment', 'ps_cashondelivery') as $name) {
    $candidate = Module::getInstanceByName($name);
    if ($candidate instanceof PaymentModule) {
        if (!$candidate->active) {
            $candidate->install();
            $candidate = Module::getInstanceByName($name);
        }
        $paymentModule = $candidate;
        break;
    }
}
if (!$paymentModule) {
    fwrite(STDERR, "PS-ORDER-FAIL: no PaymentModule available to validate the order\n");
    exit(1);
}

$total = (float) $cart->getOrderTotal(true, Cart::BOTH);

$paymentModule->validateOrder(
    (int) $cart->id,
    (int) Configuration::get('PS_OS_PAYMENT'),
    $total,
    'E2E Test Payment',
    null,
    array(),
    null,
    false,
    $cart->secure_key
);

echo 'PS-ORDER-CREATED order_id=' . (int) $paymentModule->currentOrder
    . ' reference=' . pSQL((string) $paymentModule->currentOrderReference)
    . ' total=' . $total . "\n";
