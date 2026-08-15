<?php
/**
 * Admin Settings Class
 *
 * Handles admin settings page and configuration
 *
 * @package VocifyAI
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * Vocify AI Admin
 */
class Vocify_AI_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }

    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Register settings
        add_action('admin_init', array($this, 'register_settings'));

        // Enqueue admin scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX handler for test connection
        add_action('wp_ajax_vocify_test_connection', array($this, 'ajax_test_connection'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Vocify AI Settings', 'vocify-ai'),
            __('Vocify AI', 'vocify-ai'),
            'manage_woocommerce',
            'vocify-ai',
            array($this, 'render_settings_page'),
            'dashicons-phone',
            56
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        // API Key
        register_setting('vocify_ai_settings', 'vocify_api_key', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ));

        // Enable/Disable
        register_setting('vocify_ai_settings', 'vocify_enabled', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => 'no',
        ));

        // Debug Mode
        register_setting('vocify_ai_settings', 'vocify_debug_mode', array(
            'type'              => 'string',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => 'no',
        ));

        // Webhook URL
        register_setting('vocify_ai_settings', 'vocify_webhook_url', array(
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => 'https://app.vocify-ai.com/api/webhooks/ecommerce',
        ));

        // Webhook Signing Secret (signatureSecret from the Vocify AI dashboard)
        register_setting('vocify_ai_settings', 'vocify_signature_secret', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ));
    }

    /**
     * Sanitize checkbox input
     *
     * @param string $value Input value
     * @return string 'yes' or 'no'
     */
    public function sanitize_checkbox($value) {
        return $value === 'yes' ? 'yes' : 'no';
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our settings page
        if ($hook !== 'toplevel_page_vocify-ai') {
            return;
        }

        // Enqueue WordPress color picker
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');

        // Enqueue custom admin script
        wp_enqueue_script(
            'vocify-admin',
            VOCIFY_AI_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            VOCIFY_AI_VERSION,
            true
        );

        // Localize script with AJAX URL
        wp_localize_script('vocify-admin', 'vocifyAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('vocify_test_connection'),
        ));

        // Enqueue custom admin styles
        wp_enqueue_style(
            'vocify-admin',
            VOCIFY_AI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            VOCIFY_AI_VERSION
        );
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'vocify-ai'));
        }

        // Get current settings
        $api_key           = get_option('vocify_api_key', '');
        $enabled           = get_option('vocify_enabled', 'no');
        $debug_mode        = get_option('vocify_debug_mode', 'no');
        $webhook_url       = get_option('vocify_webhook_url', 'https://app.vocify-ai.com/api/webhooks/ecommerce');
        $signature_secret  = get_option('vocify_signature_secret', '');
        $store_domain      = parse_url(get_site_url(), PHP_URL_HOST);

        $status_class = 'vocify-status-not-configured';
        $status_label = __('Not configured', 'vocify-ai');

        if ('yes' === $enabled && !empty($api_key)) {
            if (empty($signature_secret)) {
                $status_class = 'vocify-status-warning';
                $status_label = __('Active - no signing secret', 'vocify-ai');
            } else {
                $status_class = 'vocify-status-active';
                $status_label = __('Active', 'vocify-ai');
            }
        } elseif ('yes' !== $enabled && !empty($api_key)) {
            $status_class = 'vocify-status-warning';
            $status_label = __('Disabled', 'vocify-ai');
        }

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors('vocify_ai_settings'); ?>

            <div class="vocify-settings-container">
                <div class="vocify-main-content">
                    <div class="vocify-status-bar">
                        <span class="vocify-status-dot <?php echo esc_attr($status_class); ?>"></span>
                        <strong><?php echo esc_html($status_label); ?></strong>
                    </div>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('vocify_ai_settings');
                        do_settings_sections('vocify_ai_settings');
                        ?>

                        <table class="form-table" role="presentation">
                            <!-- API Key -->
                            <tr>
                                <th scope="row">
                                    <label for="vocify_api_key"><?php esc_html_e('API Key', 'vocify-ai'); ?></label>
                                </th>
                                <td>
                                    <input
                                        type="password"
                                        id="vocify_api_key"
                                        name="vocify_api_key"
                                        value="<?php echo esc_attr($api_key); ?>"
                                        class="regular-text"
                                        placeholder="vcf_live_XXXXXXXXXXXXXXXXXXXX"
                                    />
                                    <p class="description">
                                        <?php
                                        printf(
                                            /* translators: %s: Link to Vocify AI dashboard */
                                            esc_html__('Enter your Vocify AI API key from your %s.', 'vocify-ai'),
                                            '<a href="https://app.vocify-ai.com" target="_blank">' . esc_html__('dashboard', 'vocify-ai') . '</a>'
                                        );
                                        ?>
                                    </p>
                                </td>
                            </tr>

                            <!-- Enable Integration -->
                            <tr>
                                <th scope="row">
                                    <label for="vocify_enabled"><?php esc_html_e('Enable Integration', 'vocify-ai'); ?></label>
                                </th>
                                <td>
                                    <label for="vocify_enabled">
                                        <input
                                            type="checkbox"
                                            id="vocify_enabled"
                                            name="vocify_enabled"
                                            value="yes"
                                            <?php checked($enabled, 'yes'); ?>
                                        />
                                        <?php esc_html_e('Enable Vocify AI order confirmation calls', 'vocify-ai'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('Turn on/off the integration. When disabled, no webhooks will be sent.', 'vocify-ai'); ?>
                                    </p>
                                </td>
                            </tr>

                            <!-- Debug Mode -->
                            <tr>
                                <th scope="row">
                                    <label for="vocify_debug_mode"><?php esc_html_e('Debug Mode', 'vocify-ai'); ?></label>
                                </th>
                                <td>
                                    <label for="vocify_debug_mode">
                                        <input
                                            type="checkbox"
                                            id="vocify_debug_mode"
                                            name="vocify_debug_mode"
                                            value="yes"
                                            <?php checked($debug_mode, 'yes'); ?>
                                        />
                                        <?php esc_html_e('Enable verbose logging', 'vocify-ai'); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e('Enable detailed logging for troubleshooting. Logs can be viewed in WooCommerce > Status > Logs.', 'vocify-ai'); ?>
                                    </p>
                                </td>
                            </tr>

                            <!-- Webhook URL -->
                            <tr>
                                <th scope="row">
                                    <label for="vocify_webhook_url"><?php esc_html_e('Webhook URL', 'vocify-ai'); ?></label>
                                </th>
                                <td>
                                    <input
                                        type="url"
                                        id="vocify_webhook_url"
                                        name="vocify_webhook_url"
                                        value="<?php echo esc_url($webhook_url); ?>"
                                        class="regular-text"
                                    />
                                    <p class="description">
                                        <?php esc_html_e('Vocify AI webhook endpoint. Leave as default unless instructed otherwise.', 'vocify-ai'); ?>
                                    </p>
                                </td>
                            </tr>

                            <!-- Webhook Signing Secret -->
                            <tr>
                                <th scope="row">
                                    <label for="vocify_signature_secret"><?php esc_html_e('Webhook Signing Secret', 'vocify-ai'); ?></label>
                                </th>
                                <td>
                                    <input
                                        type="password"
                                        id="vocify_signature_secret"
                                        name="vocify_signature_secret"
                                        value="<?php echo esc_attr($signature_secret); ?>"
                                        class="regular-text"
                                        autocomplete="off"
                                    />
                                    <p class="description">
                                        <?php esc_html_e('The webhook signing secret (signatureSecret) shown once in the Vocify AI dashboard when the API key is created. Required to sign webhook requests; leave empty only if your API key has no signing secret.', 'vocify-ai'); ?>
                                    </p>
                                </td>
                            </tr>

                            <!-- Store Domain (Read-only) -->
                            <tr>
                                <th scope="row">
                                    <label><?php esc_html_e('Store Domain', 'vocify-ai'); ?></label>
                                </th>
                                <td>
                                    <input
                                        type="text"
                                        value="<?php echo esc_attr($store_domain); ?>"
                                        class="regular-text"
                                        readonly
                                    />
                                    <p class="description">
                                        <?php esc_html_e('Your store domain (auto-detected).', 'vocify-ai'); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>

                        <?php submit_button(__('Save Settings', 'vocify-ai')); ?>
                    </form>

                    <!-- Test Connection -->
                    <hr>
                    <h2><?php esc_html_e('Test Connection', 'vocify-ai'); ?></h2>
                    <p><?php esc_html_e('Verify your API key and webhook configuration.', 'vocify-ai'); ?></p>
                    <button type="button" id="vocify-test-connection" class="button button-secondary">
                        <?php esc_html_e('Test Connection', 'vocify-ai'); ?>
                    </button>
                    <div id="vocify-test-result" style="margin-top: 15px;"></div>

                    <!-- Recent Webhook Activity -->
                    <hr>
                    <h2><?php esc_html_e('Recent Webhook Activity', 'vocify-ai'); ?></h2>
                    <?php $this->render_recent_webhooks(); ?>
                </div>

                <div class="vocify-sidebar">
                    <!-- Info Box -->
                    <div class="vocify-info-box">
                        <h3><?php esc_html_e('Getting Started', 'vocify-ai'); ?></h3>
                        <ol>
                            <li><?php esc_html_e('Get your API key from Vocify AI dashboard', 'vocify-ai'); ?></li>
                            <li><?php esc_html_e('Enter the API key above', 'vocify-ai'); ?></li>
                            <li><?php esc_html_e('Enable the integration', 'vocify-ai'); ?></li>
                            <li><?php esc_html_e('Test the connection', 'vocify-ai'); ?></li>
                            <li><?php esc_html_e('Create a test order to verify', 'vocify-ai'); ?></li>
                        </ol>
                    </div>

                    <!-- Support Box -->
                    <div class="vocify-info-box">
                        <h3><?php esc_html_e('Need Help?', 'vocify-ai'); ?></h3>
                        <ul>
                            <li>
                                <a href="https://docs.vocify-ai.com/cms-plugins" target="_blank">
                                    <?php esc_html_e('Documentation', 'vocify-ai'); ?>
                                </a>
                            </li>
                            <li>
                                <a href="mailto:developers@vocify-ai.com">
                                    <?php esc_html_e('Email Support', 'vocify-ai'); ?>
                                </a>
                            </li>
                            <li>
                                <a href="https://github.com/vocify-ai/woocommerce-plugin/issues" target="_blank">
                                    <?php esc_html_e('Report Issue', 'vocify-ai'); ?>
                                </a>
                            </li>
                        </ul>
                    </div>

                    <!-- Version Info -->
                    <div class="vocify-info-box">
                        <h3><?php esc_html_e('Plugin Information', 'vocify-ai'); ?></h3>
                        <p><strong><?php esc_html_e('Version:', 'vocify-ai'); ?></strong> <?php echo esc_html(VOCIFY_AI_VERSION); ?></p>
                        <p><strong><?php esc_html_e('WooCommerce:', 'vocify-ai'); ?></strong> <?php echo esc_html(WC()->version); ?></p>
                        <p><strong><?php esc_html_e('WordPress:', 'vocify-ai'); ?></strong> <?php echo esc_html(get_bloginfo('version')); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render recent webhook activity
     */
    private function render_recent_webhooks() {
        global $wpdb;

        $table_name = $wpdb->prefix . 'vocify_webhook_logs';

        // Get recent webhooks
        $logs = $wpdb->get_results(
            "SELECT l.*, p.post_title as order_number
             FROM {$table_name} l
             LEFT JOIN {$wpdb->posts} p ON l.order_id = p.ID
             ORDER BY l.created_at DESC
             LIMIT 20"
        );

        if (empty($logs)) {
            echo '<p><em>' . esc_html__('No webhook activity yet.', 'vocify-ai') . '</em></p>';
            return;
        }

        ?>
        <table class="widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('Order ID', 'vocify-ai'); ?></th>
                    <th><?php esc_html_e('Status', 'vocify-ai'); ?></th>
                    <th><?php esc_html_e('HTTP Code', 'vocify-ai'); ?></th>
                    <th><?php esc_html_e('Date', 'vocify-ai'); ?></th>
                    <th><?php esc_html_e('Error', 'vocify-ai'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log) : ?>
                    <tr>
                        <td>
                            <a href="<?php echo esc_url(admin_url('post.php?post=' . $log->order_id . '&action=edit')); ?>">
                                #<?php echo esc_html($log->order_id); ?>
                            </a>
                        </td>
                        <td>
                            <?php
                            $badge_class = 'success' === $log->status ? 'vocify-badge-success' : 'vocify-badge-error';
                            $status_label = 'success' === $log->status ? __('Success', 'vocify-ai') : __('Failed', 'vocify-ai');
                            ?>
                            <span class="vocify-badge <?php echo esc_attr($badge_class); ?>">
                                <?php echo esc_html($status_label); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html($log->http_code ?: 'N/A'); ?></td>
                        <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($log->created_at))); ?></td>
                        <td>
                            <?php if (!empty($log->error_message)) : ?>
                                <span title="<?php echo esc_attr($log->error_message); ?>">
                                    <?php echo esc_html(wp_trim_words($log->error_message, 10)); ?>
                                </span>
                            <?php else : ?>
                                -
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * AJAX handler for test connection
     *
     * The platform has no dedicated key-validation endpoint, so the test:
     * 1. verifies the API key format locally (vcf_live_...)
     * 2. verifies the webhook URL is reachable via its GET health endpoint
     * 3. warns when the signing secret is missing but a key is configured
     */
    public function ajax_test_connection() {
        // Verify nonce
        check_ajax_referer('vocify_test_connection', 'nonce');

        // Check user capabilities
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array(
                'message' => __('You do not have permission to perform this action.', 'vocify-ai'),
            ));
        }

        // Get API key
        $api_key = get_option('vocify_api_key');

        if (empty($api_key)) {
            wp_send_json_error(array(
                'message' => __('API key is not configured. Please enter your API key and save settings first.', 'vocify-ai'),
            ));
        }

        if (!preg_match('/^vcf_(live|test)_[a-zA-Z0-9]{16,}$/', $api_key)) {
            wp_send_json_error(array(
                'message' => __('API key format looks invalid. Expected format: vcf_live_XXXXXXXXXXXXXXXXXXXX', 'vocify-ai'),
            ));
        }

        $signature_secret = get_option('vocify_signature_secret', '');

        // Get webhook URL
        $webhook_url = get_option('vocify_webhook_url', 'https://app.vocify-ai.com/api/webhooks/ecommerce');

        // 1. Reachability + health of the endpoint (GET).
        $response = wp_remote_get($webhook_url, array(
            'timeout' => 10,
            'headers' => array(
                'X-API-Key' => $api_key,
            ),
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => sprintf(
                    /* translators: %s: Error message */
                    __('Connection failed: %s', 'vocify-ai'),
                    $response->get_error_message()
                ),
            ));
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        $warnings = array();

        if (empty($signature_secret)) {
            $warnings[] = __('No webhook signing secret configured. If your API key was created with a signing secret, requests will be rejected with 401 — add it above.', 'vocify-ai');
        }

        if ($http_code === 200 && isset($body['status']) && $body['status'] === 'healthy') {
            $message = __('Connection successful! The Vocify AI webhook endpoint is reachable.', 'vocify-ai');

            if (count($warnings) > 0) {
                $message .= ' ' . implode(' ', $warnings);
                wp_send_json_error(array(
                    'message' => $message,
                ));
            }

            wp_send_json_success(array(
                'message' => $message,
            ));
        }

        wp_send_json_error(array(
            'message' => sprintf(
                /* translators: 1: HTTP code 2: Response body */
                __('Connection failed with HTTP code %1$d: %2$s', 'vocify-ai'),
                $http_code,
                wp_remote_retrieve_body($response)
            ),
        ));
    }
}
