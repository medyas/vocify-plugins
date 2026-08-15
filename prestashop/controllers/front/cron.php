<?php
/**
 * Vocify AI - Failed Webhook Retry Cron
 *
 * Token-protected endpoint that retries webhooks stuck in the failed queue.
 * Add the cron URL (shown on the module configuration page) to your hosting
 * cron, e.g. every hour: 0 * * * * curl -s "URL"
 *
 * @author Vocify AI
 * @copyright 2025 Vocify AI
 * @license MIT License
 * @version 1.1.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class VocifyAICronModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function __construct()
    {
        parent::__construct();
        $this->display_header = false;
        $this->display_footer = false;
        $this->display_column_left = false;
        $this->display_column_right = false;
    }

    /**
     * Validate the token and retry the failed webhook queue.
     *
     * @return void
     */
    public function initContent()
    {
        if (headers_sent() === false) {
            header('Content-Type: text/plain; charset=utf-8');
        }

        $token = (string)Tools::getValue('token');
        $expected = (string)Configuration::get('VOCIFY_CRON_TOKEN');

        if ($expected === '' || !hash_equals($expected, $token)) {
            header('HTTP/1.1 403 Forbidden');
            die('Invalid token');
        }

        $service = new VocifyWebhookService();
        $sent = $service->retryFailedWebhooks();

        die('OK:' . $sent);
    }
}