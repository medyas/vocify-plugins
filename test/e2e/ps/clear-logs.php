<?php
/** Truncate the module's own log + retry tables between assertions. */
require_once '/var/www/html/config/config.inc.php';
Db::getInstance()->execute('TRUNCATE ' . _DB_PREFIX_ . 'vocify_webhook_logs');
Db::getInstance()->execute('TRUNCATE ' . _DB_PREFIX_ . 'vocify_failed_webhooks');
echo "PS-LOGS-CLEARED\n";
