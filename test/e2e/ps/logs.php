<?php
/** Emit the module's own webhook log + retry queue as JSON, newest first. */
require_once '/var/www/html/config/config.inc.php';
echo json_encode(array(
    'logs' => Db::getInstance()->executeS(
        'SELECT id_log, id_order, status, http_code, response, error_message
           FROM ' . _DB_PREFIX_ . 'vocify_webhook_logs ORDER BY id_log DESC'
    ) ?: array(),
    'failed' => Db::getInstance()->executeS(
        'SELECT id_webhook, id_order, retry_count FROM ' . _DB_PREFIX_ . 'vocify_failed_webhooks'
    ) ?: array(),
)), PHP_EOL;
