<?php
/**
 * Vocify AI Signer
 *
 * Builds the HMAC-SHA256 signature and request headers required by the
 * Vocify AI unified webhook endpoint.
 *
 * The platform verifies X-Signature against the per-key "webhook signing
 * secret" (signatureSecret) shown once in the Vocify AI dashboard — NOT the
 * API key itself. The signature MUST be computed over the exact raw request
 * body that is transmitted.
 *
 * @package VocifyAI
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class Vocify_AI_Signer {

    const PLATFORM = 'WOOCOMMERCE';

    /**
     * Compute the HMAC-SHA256 signature for a raw request body.
     *
     * @param string $raw_body Raw JSON body that will be transmitted.
     * @param string $secret   Webhook signing secret (signatureSecret).
     * @return string Hex-encoded HMAC-SHA256 signature.
     */
    public function sign($raw_body, $secret) {
        return hash_hmac('sha256', $raw_body, $secret);
    }

    /**
     * Build the headers for the unified webhook request.
     *
     * X-Signature is only included when a signing secret is configured.
     * Sending a signature the platform cannot verify would hard-fail with
     * 401, so an unsigned request is the correct fallback for keys that
     * have no signatureSecret configured.
     *
     * @param string $api_key          Agent API key (vcf_live_...).
     * @param string $domain           Store domain (no scheme).
     * @param string $raw_body         Raw JSON request body.
     * @param string $signature_secret Webhook signing secret (may be empty).
     * @return array Headers keyed by header name.
     */
    public function build_headers($api_key, $domain, $raw_body, $signature_secret = '') {
        $headers = array(
            'Content-Type' => 'application/json',
            'X-Platform'   => self::PLATFORM,
            'X-API-Key'    => $api_key,
            'X-Domain'     => $domain,
            'X-Timestamp'  => gmdate('c'),
        );

        if (!empty($signature_secret)) {
            $headers['X-Signature'] = $this->sign($raw_body, $signature_secret);
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