<?php
/**
 * Plugin Name: Vocify E2E request capture
 * Description: HARNESS-OWNED. Records the exact bytes the Vocify plugin
 *              transmits so the negative suite can replay and mutate a REAL
 *              plugin-produced request instead of a hand-built one.
 *
 * This file is NOT part of the plugin under test and never ships to a
 * merchant. It lives in wp-content/mu-plugins/, copied there by
 * wp-provision.sh.
 *
 * `http_api_debug` is deliberate: it fires AFTER the request completes and
 * receives `$parsed_args`, which carries the headers and body actually sent.
 * `pre_http_request` would short-circuit the request and defeat the point —
 * the plugin's real HTTP call must happen, unmodified, for the happy path to
 * mean anything.
 *
 * Writes JSONL to wp-content/uploads/ (writable) — never into the plugin's
 * read-only bind mount.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('VOCIFY_E2E_CAPTURE_FILE', WP_CONTENT_DIR . '/uploads/vocify-e2e-capture.jsonl');

add_action('http_api_debug', function ($response, $context, $class, $parsed_args, $url) {
    // Only Vocify webhook traffic; WordPress makes plenty of unrelated calls
    // (update checks, WooCommerce telemetry) that would drown the log.
    $headers = isset($parsed_args['headers']) && is_array($parsed_args['headers'])
        ? $parsed_args['headers']
        : array();

    $is_vocify = false;
    foreach ($headers as $name => $value) {
        if (strtolower($name) === 'x-platform' && $value === 'WOOCOMMERCE') {
            $is_vocify = true;
            break;
        }
    }
    if (!$is_vocify) {
        return;
    }

    $record = array(
        'at' => gmdate('c'),
        'url' => $url,
        'method' => isset($parsed_args['method']) ? $parsed_args['method'] : 'POST',
        'headers' => $headers,
        'body' => isset($parsed_args['body']) ? $parsed_args['body'] : null,
        'response_code' => is_wp_error($response)
            ? 0
            : (int) wp_remote_retrieve_response_code($response),
        'response_body' => is_wp_error($response)
            ? $response->get_error_message()
            : substr((string) wp_remote_retrieve_body($response), 0, 2000),
        'wp_error' => is_wp_error($response) ? $response->get_error_message() : null,
    );

    file_put_contents(
        VOCIFY_E2E_CAPTURE_FILE,
        wp_json_encode($record) . "\n",
        FILE_APPEND | LOCK_EX
    );
}, 10, 5);
