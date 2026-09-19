<?php
/**
 * Emit the exact request the module WOULD transmit for an existing order.
 *
 * Run: php /e2e/capture-request.php <id_order>
 *
 * PrestaShop's VocifyWebhookService::sendWebhook() uses a bare curl_exec(),
 * so — unlike WooCommerce, where `http_api_debug` hands the harness the real
 * transmitted bytes — there is no interception point. This script reproduces
 * the request by calling the module's OWN transformOrder() / payload builder /
 * VocifySigner::buildHeaders(), i.e. the same three objects sendWebhook()
 * calls, with the same inputs, in the same order.
 *
 * What that buys: the negative suite can mutate and replay a request whose
 * signature was produced by the shipped PrestaShop signer, rather than a
 * hand-rolled one. What it does NOT prove on its own: that the module actually
 * put these bytes on the wire — that is what the positive assertions (module
 * log row http_code=201 plus the Postgres rows) are for.
 */

require_once '/var/www/html/config/config.inc.php';

// transformOrder() formats prices through the CLDR locale, which resolves via
// the Symfony container — absent in a plain CLI bootstrap. Same reason
// create-order.php boots the kernel.
require_once '/var/www/html/app/AppKernel.php';
$kernel = new AppKernel('prod', false);
$kernel->boot();
$context = Context::getContext();
$context->container = $kernel->getContainer();
// Context::getComputingPrecision() reads $context->currency->precision; a CLI
// bootstrap has no currency, so price formatting dies with a null-argument
// TypeError deep inside CLDR. Seed the shop context the way a request would.
$context->shop = new Shop((int) Configuration::get('PS_SHOP_DEFAULT'));
$context->language = new Language((int) Configuration::get('PS_LANG_DEFAULT'));
$context->currency = new Currency((int) Configuration::get('PS_CURRENCY_DEFAULT'));

require_once '/var/www/html/modules/vocifyai/classes/VocifySigner.php';
require_once '/var/www/html/modules/vocifyai/classes/VocifyPayloadBuilder.php';
require_once '/var/www/html/modules/vocifyai/classes/VocifyPayloadValidator.php';
require_once '/var/www/html/modules/vocifyai/classes/VocifyWebhookService.php';

$idOrder = isset($argv[1]) ? (int) $argv[1] : 0;
$order = new Order($idOrder);
if (!Validate::isLoadedObject($order)) {
    fwrite(STDERR, "PS-CAPTURE-FAIL: order {$idOrder} not found\n");
    exit(1);
}

$service = new VocifyWebhookService();
$payload = $service->transformOrder($order);
if (!$payload) {
    fwrite(STDERR, "PS-CAPTURE-FAIL: transformOrder() returned nothing for order {$idOrder}\n");
    exit(1);
}

$apiKey = Configuration::get('VOCIFY_API_KEY');
$signatureSecret = Configuration::get('VOCIFY_SIGNATURE_SECRET');

$signer = new VocifySigner();
$storeDomain = $signer->extractDomain(Tools::getShopDomainSsl(true));
$rawBody = json_encode($payload);
$headers = $signer->buildHeaders($apiKey, $storeDomain, $rawBody, $signatureSecret);

// buildHeaders() returns cURL-style "Name: value" strings; re-key them so the
// harness can treat this the same shape as the WooCommerce capture.
$assoc = array();
foreach ($headers as $key => $value) {
    if (is_int($key)) {
        $parts = explode(':', $value, 2);
        if (count($parts) === 2) {
            $assoc[trim($parts[0])] = trim($parts[1]);
        }
    } else {
        $assoc[$key] = $value;
    }
}

echo json_encode(array(
    'url' => Configuration::get('VOCIFY_WEBHOOK_URL'),
    'method' => 'POST',
    'headers' => $assoc,
    'body' => $rawBody,
)), PHP_EOL;
