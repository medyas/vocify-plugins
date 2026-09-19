<?php
/**
 * Transport helper for the PrestaShop RETURN-path suite: POST one call result
 * to the module's `webhook` front controller and print what came back.
 *
 * Run INSIDE the prestashop container:
 *   php /e2e/post-result.php <base64(json spec)>
 *
 * Spec keys (all optional but `body`):
 *   body           object|string  request body; a string is sent byte-for-byte
 *   timestamp      string         X-Vocify-Timestamp; defaults to now (ISO 8601 Z)
 *   signature      string         X-Vocify-Signature; defaults to a correct one
 *   signBody       string         sign THIS instead of what is sent (tamper cases)
 *   signTimestamp  string         fold THIS timestamp into the signature instead
 *   secret         string         sign with this instead of VOCIFY_SIGNATURE_SECRET
 *   omitSignature  bool           send no X-Vocify-Signature header
 *   omitTimestamp  bool           send no X-Vocify-Timestamp header
 *   method         string         defaults to POST
 *   query          string         query string after index.php?; defaults to the
 *                                 canonical `fc=module&module=vocifyai&controller=webhook`
 *
 * Prints one line of JSON: {"http": int, "body": mixed, "raw": string, "error": string|null}
 *
 * WHY base64: the spec travels through `docker compose exec` argv, and a raw
 * JSON argument with braces, quotes and `&` is mangled differently by every
 * shell in the chain. One opaque token is not.
 *
 * WHY a PHP script and not `fetch()` from the harness: this stack publishes no
 * port (see docker-compose.ps-return.yml), the shop answers on
 * `http://localhost/` inside the container, and the request has to traverse
 * Apache and PrestaShop's real dispatcher — not be handed to the controller
 * class directly, which would prove nothing about routing.
 */

require_once '/var/www/html/config/config.inc.php';

$spec = array();

if (isset($argv[1]) && $argv[1] !== '') {
    $decoded = json_decode(base64_decode($argv[1]), true);
    if (is_array($decoded)) {
        $spec = $decoded;
    } else {
        fwrite(STDERR, "PS-POST-FAIL: spec is not base64-encoded JSON\n");
        exit(1);
    }
}

$body = isset($spec['body']) ? $spec['body'] : array();
$rawBody = is_string($body) ? $body : json_encode($body);

$timestamp = isset($spec['timestamp']) ? (string) $spec['timestamp'] : gmdate('Y-m-d\TH:i:s.000\Z');
$secret = isset($spec['secret']) ? (string) $spec['secret'] : (string) Configuration::get('VOCIFY_SIGNATURE_SECRET');

// The signature is computed over the timestamp-bound message here rather than
// by calling VocifySigner, so a bug in the shipped signer cannot make a
// request "correctly signed" by the same mistake the receiver makes.
$signBody = isset($spec['signBody']) ? (string) $spec['signBody'] : $rawBody;
$signTimestamp = isset($spec['signTimestamp']) ? (string) $spec['signTimestamp'] : $timestamp;
$signature = isset($spec['signature'])
    ? (string) $spec['signature']
    : hash_hmac('sha256', $signTimestamp . '.' . $signBody, $secret);

$headers = array('Content-Type: application/json');
if (empty($spec['omitTimestamp'])) {
    $headers[] = 'X-Vocify-Timestamp: ' . $timestamp;
}
if (empty($spec['omitSignature'])) {
    $headers[] = 'X-Vocify-Signature: ' . $signature;
}

// ⚠️ The request MUST carry the shop's CANONICAL domain, not `localhost`.
// `Shop::initialize()` 302s any request whose Host does not match the
// configured shop URL — measured here: `POST http://localhost/index.php?fc=…`
// answered `302 Location: http://ps-rt.vocify.test/?fc=…` before the module
// front controller ran at all. The platform fetches with `redirect: 'error'`,
// so that 302 is a hard failure, and blaming it on the receiver would be
// wrong. (It is also a real merchant trap: `integrations.store_url` has to be
// the shop's canonical domain.)
//
// CURLOPT_RESOLVE pins that hostname to the loopback inside the container, so
// the request is byte-for-byte what the platform would send without needing
// DNS or a published port.
$host = (string) Configuration::get('PS_SHOP_DOMAIN');
$query = isset($spec['query'])
    ? (string) $spec['query']
    : 'fc=module&module=vocifyai&controller=webhook';
$url = 'http://' . $host . __PS_BASE_URI__ . 'index.php?' . $query;

$ch = curl_init($url);
curl_setopt_array($ch, array(
    CURLOPT_CUSTOMREQUEST => isset($spec['method']) ? (string) $spec['method'] : 'POST',
    CURLOPT_POSTFIELDS => $rawBody,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_RESOLVE => array($host . ':80:127.0.0.1'),
    // Never follow a redirect: the platform fetches with `redirect: 'error'`,
    // so a 3xx is a hard failure there and must be visible as one here.
    CURLOPT_FOLLOWLOCATION => false,
));

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

$parsed = json_decode((string) $response, true);

echo json_encode(array(
    'http' => $httpCode,
    'body' => $parsed === null ? null : $parsed,
    'raw' => Tools::substr((string) $response, 0, 400),
    'error' => $curlError === '' ? null : $curlError,
)), PHP_EOL;
