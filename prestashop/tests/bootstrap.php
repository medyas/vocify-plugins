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

require_once __DIR__ . '/fixtures/order-fixture.php';
require_once __DIR__ . '/fixtures/order-fixture-mutators.php';