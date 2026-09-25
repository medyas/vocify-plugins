<?php
/**
 * Vocify AI Signer
 *
 * Builds the HMAC-SHA256 signature and request headers required by the
 * Vocify AI unified webhook endpoint.
 *
 * The platform verifies X-Signature against the per-key "webhook signing
 * secret" (signatureSecret) shown once in the Vocify AI dashboard — NOT the
 * API key itself. Per the 2026-09-18 platform contract, the signature is
 * computed over `"{X-Timestamp}.{rawBody}"` (timestamp bound into the signed
 * message, hex digest) — NOT over the body alone — and the platform rejects
 * the request if X-Timestamp is missing, unparsable, or more than 300s from
 * its clock. The X-Timestamp header sent with the request MUST be the exact
 * same string used to build the signed message, and raw_body MUST be the
 * exact bytes that are transmitted.
 *
 * @package VocifyAI
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Builds the HMAC-SHA256 signature and request headers for the Vocify AI
 * unified webhook contract. Pure and WP-free — see the file docblock above.
 */
class Vocify_AI_Signer {

    const PLATFORM = 'WOOCOMMERCE';

    /**
     * Build the canonical message the platform signs and verifies:
     * `"{timestamp}.{raw_body}"` (literal dot separator).
     *
     * @param string $timestamp ISO 8601 timestamp — must match the
     *                          X-Timestamp header sent with the request.
     * @param string $raw_body  Raw JSON body that will be transmitted.
     * @return string
     */
    public function build_signed_message($timestamp, $raw_body) {
        return $timestamp . '.' . $raw_body;
    }

    /**
     * Compute the HMAC-SHA256 signature over the timestamp-bound message.
     *
     * @param string $timestamp ISO 8601 timestamp (same value sent as
     *                          X-Timestamp).
     * @param string $raw_body  Raw JSON body that will be transmitted.
     * @param string $secret    Webhook signing secret (signatureSecret).
     * @return string Hex-encoded HMAC-SHA256 signature.
     */
    public function sign($timestamp, $raw_body, $secret) {
        return hash_hmac('sha256', $this->build_signed_message($timestamp, $raw_body), $secret);
    }

    /**
     * Build the headers for the unified webhook request.
     *
     * X-Signature is only included when a local signing secret is configured
     * (`$signature_secret` — the merchant's plugin setting). This is a LEGACY
     * fallback from before the platform's 2026-09-18 fail-closed change: the
     * platform now REJECTS every request — signed or not — for any API key
     * whose server-side `signatureSecret` is unset (`SIGNATURE_REQUIRED`), and
     * rejects a signed-but-secretless-locally request as a missing signature
     * (`SIGNATURE_MISMATCH`) the moment the key DOES have a secret configured
     * server-side. In both cases, sending unsigned no longer "succeeds
     * without a signature" — it simply fails the same way sending a wrong
     * signature would. Every API key must have its signing secret entered
     * here for webhooks to work at all; this fallback branch will not save a
     * misconfigured install, it only avoids sending a header that would be
     * outright empty. The X-Timestamp used here is the SAME value folded into
     * the signature below — never regenerate it separately, or the signed
     * message and the header will disagree and the platform will reject the
     * request regardless of whether a secret is configured.
     *
     * @param string $api_key          Agent API key (vcf_live_...).
     * @param string $domain           Store domain (no scheme).
     * @param string $raw_body         Raw JSON request body.
     * @param string $signature_secret Webhook signing secret (may be empty).
     * @return array Headers keyed by header name.
     */
    public function build_headers($api_key, $domain, $raw_body, $signature_secret = '') {
        $timestamp = gmdate('c');

        $headers = array(
            'Content-Type' => 'application/json',
            'X-Platform'   => self::PLATFORM,
            'X-API-Key'    => $api_key,
            'X-Domain'     => $domain,
            'X-Timestamp'  => $timestamp,
        );

        if (!empty($signature_secret)) {
            $headers['X-Signature'] = $this->sign($timestamp, $raw_body, $signature_secret);
        }

        return $headers;
    }

    /**
     * Extract the bare store hostname (no scheme, no leading www, no path, no
     * trailing slash) from any URL-ish input.
     *
     * The platform normalizes domains the same way (`validateIntegrationDomain`
     * strips scheme, www and trailing slashes before comparing), so either
     * form matches — but the canonical hostname is what merchant-facing logs
     * should show.
     *
     * @param string $url Store URL or domain.
     * @return string Bare hostname; input unchanged when no scheme is present.
     */
    public function extract_domain($url) {
        $url = trim((string)$url);

        if (empty($url)) {
            return '';
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- deliberately the native function, not wp_parse_url(): this class loads and is unit-tested with no WordPress bootstrap (tests/bootstrap.php), so a WP-only function here would fatal-error under PHPUnit.
        $host = parse_url($url, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            $host = rtrim($url, '/');
        }

        $host = strtolower($host);

        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
