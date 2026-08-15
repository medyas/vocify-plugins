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