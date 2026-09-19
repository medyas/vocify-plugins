<?php
/** Write one module Configuration value. Run: php /e2e/set-config.php KEY VALUE */
require_once '/var/www/html/config/config.inc.php';
$key = isset($argv[1]) ? $argv[1] : '';
$value = isset($argv[2]) ? $argv[2] : '';
if ($key === '') {
    fwrite(STDERR, "usage: set-config.php KEY VALUE\n");
    exit(1);
}
Configuration::updateValue($key, $value);
echo 'PS-CONFIG-SET ' . $key . "\n";
