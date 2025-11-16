<?php
/**
 * Vocify AI - Order Confirmation Calls Module
 *
 * @author Vocify AI
 * @copyright 2025 Vocify AI
 * @license MIT License
 * @version 1.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

// Load Composer autoloader if available (for libphonenumber-php)
if (file_exists(dirname(__FILE__) . '/vendor/autoload.php')) {
    require_once dirname(__FILE__) . '/vendor/autoload.php';
}

require_once dirname(__FILE__) . '/classes/VocifyWebhookService.php';

class VocifyAI extends Module
{
    /**
     * Constructor
     */
    public function __construct()
    {
        $this->name = 'vocifyai';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Vocify AI';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = array('min' => '1.7.0', 'max' => _PS_VERSION_);
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Vocify AI - Order Confirmation Calls');
        $this->description = $this->l('Automate order confirmation calls with AI voice technology. Enhance customer experience and reduce order cancellations.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the Vocify AI module? This will remove all configuration settings.');
    }

    /**
     * Install module
     *
     * @return bool
     */
    public function install()
    {
        return parent::install() &&
            $this->registerHook('actionValidateOrder') &&
            $this->registerHook('actionOrderStatusPostUpdate') &&
            $this->registerHook('displayAdminOrderLeft') &&
            $this->createTables() &&
            $this->installConfiguration();
    }

    /**
     * Uninstall module
     *
     * @return bool
     */
    public function uninstall()
    {
        return $this->deleteConfiguration() &&
            $this->deleteTables() &&
            parent::uninstall();
    }

    /**
     * Create database tables
     *
     * @return bool
     */
    private function createTables()
    {
        $sql = array();

        // Table for failed webhooks (retry queue)
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'vocify_failed_webhooks` (
            `id_webhook` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT(11) UNSIGNED NOT NULL,
            `payload` TEXT NOT NULL,
            `error_message` TEXT,
            `retry_count` INT(3) DEFAULT 0,
            `last_retry_at` DATETIME,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_webhook`),
            KEY `id_order` (`id_order`),
            KEY `retry_count` (`retry_count`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        // Table for webhook logs
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'vocify_webhook_logs` (
            `id_log` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order` INT(11) UNSIGNED NOT NULL,
            `status` VARCHAR(20) NOT NULL,
            `http_code` INT(3),
            `response` TEXT,
            `error_message` TEXT,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_log`),
            KEY `id_order` (`id_order`),
            KEY `status` (`status`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;';

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Delete database tables
     *
     * @return bool
     */
    private function deleteTables()
    {
        $sql = array(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'vocify_failed_webhooks`',
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'vocify_webhook_logs`',
        );

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Install configuration
     *
     * @return bool
     */
    private function installConfiguration()
    {
        return Configuration::updateValue('VOCIFY_API_KEY', '') &&
            Configuration::updateValue('VOCIFY_ENABLED', false) &&
            Configuration::updateValue('VOCIFY_DEBUG_MODE', false) &&
            Configuration::updateValue('VOCIFY_WEBHOOK_URL', 'https://app.vocify-ai.com/api/webhooks/ecommerce');
    }

    /**
     * Delete configuration
     *
     * @return bool
     */
    private function deleteConfiguration()
    {
        return Configuration::deleteByName('VOCIFY_API_KEY') &&
            Configuration::deleteByName('VOCIFY_ENABLED') &&
            Configuration::deleteByName('VOCIFY_DEBUG_MODE') &&
            Configuration::deleteByName('VOCIFY_WEBHOOK_URL');
    }

    /**
     * Hook: Order validation (new order created)
     *
     * @param array $params
     * @return void
     */
    public function hookActionValidateOrder($params)
    {
        if (!Configuration::get('VOCIFY_ENABLED')) {
            return;
        }

        if (!isset($params['order']) || !Validate::isLoadedObject($params['order'])) {
            return;
        }

        $order = $params['order'];
        $this->sendOrderWebhook($order);
    }

    /**
     * Hook: Order status update
     *
     * @param array $params
     * @return void
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        if (!Configuration::get('VOCIFY_ENABLED')) {
            return;
        }

        if (!isset($params['id_order'])) {
            return;
        }

        $order = new Order((int)$params['id_order']);

        if (!Validate::isLoadedObject($order)) {
            return;
        }

        // Only send webhook for specific status changes if needed
        // For now, we'll send on every status update
        $this->sendOrderWebhook($order);
    }

    /**
     * Hook: Display in admin order page
     *
     * @param array $params
     * @return string
     */
    public function hookDisplayAdminOrderLeft($params)
    {
        if (!isset($params['id_order'])) {
            return '';
        }

        $orderId = (int)$params['id_order'];

        // Get webhook logs for this order
        $logs = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'vocify_webhook_logs`
            WHERE `id_order` = ' . $orderId . '
            ORDER BY `created_at` DESC
            LIMIT 5'
        );

        $this->context->smarty->assign(array(
            'vocify_logs' => $logs,
            'vocify_enabled' => Configuration::get('VOCIFY_ENABLED'),
        ));

        return $this->display(__FILE__, 'views/templates/admin/order_info.tpl');
    }

    /**
     * Send order webhook to Vocify AI
     *
     * @param Order $order
     * @return void
     */
    private function sendOrderWebhook($order)
    {
        try {
            $webhookService = new VocifyWebhookService();
            $webhookService->sendOrder($order);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'Vocify AI: Failed to send webhook - ' . $e->getMessage(),
                3,
                null,
                'Order',
                $order->id
            );
        }
    }

    /**
     * Configuration page
     *
     * @return string
     */
    public function getContent()
    {
        $output = '';

        // Handle form submission
        if (Tools::isSubmit('submitVocifyConfig')) {
            $output .= $this->processConfigurationForm();
        }

        // Handle test connection
        if (Tools::isSubmit('testVocifyConnection')) {
            $output .= $this->testConnection();
        }

        // Display configuration form
        $output .= $this->renderConfigurationForm();

        // Display webhook logs
        $output .= $this->renderWebhookLogs();

        return $output;
    }

    /**
     * Process configuration form
     *
     * @return string
     */
    private function processConfigurationForm()
    {
        $apiKey = Tools::getValue('VOCIFY_API_KEY');
        $enabled = Tools::getValue('VOCIFY_ENABLED');
        $debugMode = Tools::getValue('VOCIFY_DEBUG_MODE');
        $webhookUrl = Tools::getValue('VOCIFY_WEBHOOK_URL');

        // Validate API key format
        if (!empty($apiKey) && !preg_match('/^vcf_live_[a-zA-Z0-9]{20,}$/', $apiKey)) {
            return $this->displayError($this->l('Invalid API key format. Expected format: vcf_live_XXXXXXXXXXXXXXXXXXXX'));
        }

        // Update configuration
        Configuration::updateValue('VOCIFY_API_KEY', $apiKey);
        Configuration::updateValue('VOCIFY_ENABLED', (bool)$enabled);
        Configuration::updateValue('VOCIFY_DEBUG_MODE', (bool)$debugMode);
        Configuration::updateValue('VOCIFY_WEBHOOK_URL', $webhookUrl);

        return $this->displayConfirmation($this->l('Settings updated successfully'));
    }

    /**
     * Test connection to Vocify AI
     *
     * @return string
     */
    private function testConnection()
    {
        $apiKey = Configuration::get('VOCIFY_API_KEY');
        $webhookUrl = Configuration::get('VOCIFY_WEBHOOK_URL');

        if (empty($apiKey)) {
            return $this->displayError($this->l('Please configure your API key before testing the connection.'));
        }

        try {
            // Send a test request to the health check endpoint
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, array(
                CURLOPT_CUSTOMREQUEST => 'GET',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_HTTPHEADER => array(
                    'X-API-Key: ' . $apiKey,
                ),
            ));

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 || $httpCode === 201) {
                return $this->displayConfirmation($this->l('Connection successful! Your API key is valid.'));
            } else {
                return $this->displayWarning($this->l('Connection established but received HTTP code: ') . $httpCode);
            }
        } catch (Exception $e) {
            return $this->displayError($this->l('Connection failed: ') . $e->getMessage());
        }
    }

    /**
     * Render configuration form
     *
     * @return string
     */
    private function renderConfigurationForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);

        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitVocifyConfig';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
            . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');

        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFormValues(),
            'languages' => $this->context->controller->getLanguages(),
            'id_language' => $this->context->language->id,
        );

        return $helper->generateForm(array($this->getConfigForm()));
    }

    /**
     * Get configuration form structure
     *
     * @return array
     */
    private function getConfigForm()
    {
        $storeDomain = Tools::getShopDomainSsl(true);

        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Vocify AI Configuration'),
                    'icon' => 'icon-cogs',
                ),
                'input' => array(
                    array(
                        'type' => 'text',
                        'label' => $this->l('API Key'),
                        'name' => 'VOCIFY_API_KEY',
                        'size' => 50,
                        'required' => true,
                        'desc' => $this->l('Enter your Vocify AI API key from the dashboard (format: vcf_live_...)'),
                        'placeholder' => 'vcf_live_XXXXXXXXXXXXXXXXXXXX',
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Enable Integration'),
                        'name' => 'VOCIFY_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Enable or disable the Vocify AI integration'),
                        'values' => array(
                            array(
                                'id' => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'switch',
                        'label' => $this->l('Debug Mode'),
                        'name' => 'VOCIFY_DEBUG_MODE',
                        'is_bool' => true,
                        'desc' => $this->l('Enable verbose logging for troubleshooting'),
                        'values' => array(
                            array(
                                'id' => 'debug_on',
                                'value' => true,
                                'label' => $this->l('Enabled')
                            ),
                            array(
                                'id' => 'debug_off',
                                'value' => false,
                                'label' => $this->l('Disabled')
                            )
                        ),
                    ),
                    array(
                        'type' => 'text',
                        'label' => $this->l('Webhook URL'),
                        'name' => 'VOCIFY_WEBHOOK_URL',
                        'size' => 50,
                        'required' => true,
                        'desc' => $this->l('Vocify AI webhook endpoint (leave default unless instructed otherwise)'),
                    ),
                    array(
                        'type' => 'html',
                        'label' => $this->l('Store Domain'),
                        'name' => 'store_domain_display',
                        'html_content' => '<p class="form-control-static"><strong>' . $storeDomain . '</strong></p>
                                         <p class="help-block">' . $this->l('This is your store domain that will be sent with webhooks') . '</p>',
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
                'buttons' => array(
                    array(
                        'type' => 'submit',
                        'title' => $this->l('Test Connection'),
                        'icon' => 'process-icon-refresh',
                        'name' => 'testVocifyConnection',
                        'class' => 'btn btn-default pull-right',
                    ),
                ),
            ),
        );
    }

    /**
     * Get configuration form values
     *
     * @return array
     */
    private function getConfigFormValues()
    {
        return array(
            'VOCIFY_API_KEY' => Configuration::get('VOCIFY_API_KEY'),
            'VOCIFY_ENABLED' => Configuration::get('VOCIFY_ENABLED'),
            'VOCIFY_DEBUG_MODE' => Configuration::get('VOCIFY_DEBUG_MODE'),
            'VOCIFY_WEBHOOK_URL' => Configuration::get('VOCIFY_WEBHOOK_URL'),
        );
    }

    /**
     * Render webhook logs table
     *
     * @return string
     */
    private function renderWebhookLogs()
    {
        $logs = Db::getInstance()->executeS(
            'SELECT l.*, o.reference as order_reference
            FROM `' . _DB_PREFIX_ . 'vocify_webhook_logs` l
            LEFT JOIN `' . _DB_PREFIX_ . 'orders` o ON l.id_order = o.id_order
            ORDER BY l.created_at DESC
            LIMIT 20'
        );

        $this->context->smarty->assign(array(
            'webhook_logs' => $logs,
        ));

        return $this->display(__FILE__, 'views/templates/admin/webhook_logs.tpl');
    }
}
