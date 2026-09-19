<?php
/**
 * Test bootstrap — loads the platform-agnostic core classes without
 * bootstrapping PrestaShop.
 */

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '1.7.8.9');
}

require_once dirname(__DIR__) . '/classes/VocifySigner.php';
require_once dirname(__DIR__) . '/classes/VocifyPayloadBuilder.php';
require_once dirname(__DIR__) . '/classes/VocifyPayloadValidator.php';
// The RETURN leg's decision logic. It lives in `classes/` rather than in the
// `webhook` front controller exactly so it can be loaded here — a
// ModuleFrontController subclass cannot be tested without bootstrapping
// PrestaShop, which this file deliberately does not do.
require_once dirname(__DIR__) . '/classes/VocifyStatusReceiver.php';

require_once __DIR__ . '/fixtures/order-fixture.php';
require_once __DIR__ . '/fixtures/order-fixture-mutators.php';