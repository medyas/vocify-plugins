<?php
/**
 * PHPStan bootstrap: the handful of PrestaShop core constants this module
 * reads. Only existence and rough type matter for static analysis — the
 * values are never executed against a real shop.
 *
 * @see phpstan.neon.dist
 */

if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '8.2.0');
}
if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}
if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}
if (!defined('__PS_BASE_URI__')) {
    define('__PS_BASE_URI__', '/');
}
if (!defined('PS_TAX_INC')) {
    define('PS_TAX_INC', 1);
}
