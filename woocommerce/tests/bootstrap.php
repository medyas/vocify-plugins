<?php
/**
 * Test bootstrap — loads the platform-agnostic core classes without
 * bootstrapping WordPress.
 *
 * @package VocifyAI
 */

if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__DIR__) . '/');
}

require_once dirname(__DIR__) . '/includes/class-vocify-signer.php';
require_once dirname(__DIR__) . '/includes/class-vocify-payload-builder.php';
require_once dirname(__DIR__) . '/includes/class-vocify-payload-validator.php';

require_once __DIR__ . '/fixtures/order-fixture.php';
require_once __DIR__ . '/fixtures/order-fixture-mutators.php';

// Brain Monkey suites (WebhookServiceSendTest, StatusReceiverAuthTest) stub
// WordPress *functions*; these classes are the small slice of WP/WC classes
// those two files construct directly, faked outright rather than pulling in
// a WP bootstrap for. Loaded unconditionally: defining these classes when no
// real WordPress is present is always safe, since ABSPATH is already defined
// above and every real WP/WC class is guarded the same way this plugin's own
// classes are.
require_once __DIR__ . '/fixtures/wp-rest-fixtures.php';
require_once dirname(__DIR__) . '/includes/class-vocify-webhook-service.php';
require_once dirname(__DIR__) . '/includes/class-vocify-status-receiver.php';