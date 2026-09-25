<?php
/**
 * Plugin Name: Vocify AI - Order Confirmation Calls
 * Plugin URI: https://vocify-ai.com
 * Description: Automate order confirmation calls with AI voice technology. Enhance customer experience and reduce order cancellations.
 * Version: 1.1.0
 * Author: Vocify AI
 * Author URI: https://vocify-ai.com
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: vocify-ai
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 *
 * @package VocifyAI
 * @version 1.1.0
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('VOCIFY_AI_VERSION', '1.1.0');
define('VOCIFY_AI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('VOCIFY_AI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('VOCIFY_AI_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Load Composer autoloader if available (for libphonenumber-php)
if (file_exists(VOCIFY_AI_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once VOCIFY_AI_PLUGIN_DIR . 'vendor/autoload.php';
}

// Include required files
require_once VOCIFY_AI_PLUGIN_DIR . 'includes/class-vocify-signer.php';
require_once VOCIFY_AI_PLUGIN_DIR . 'includes/class-vocify-payload-builder.php';
require_once VOCIFY_AI_PLUGIN_DIR . 'includes/class-vocify-payload-validator.php';
require_once VOCIFY_AI_PLUGIN_DIR . 'includes/class-vocify-webhook-service.php';
require_once VOCIFY_AI_PLUGIN_DIR . 'includes/class-vocify-admin.php';
require_once VOCIFY_AI_PLUGIN_DIR . 'includes/class-vocify-order-handler.php';
require_once VOCIFY_AI_PLUGIN_DIR . 'includes/class-vocify-status-receiver.php';

/**
 * Main Vocify AI Plugin Class
 */
class Vocify_AI_WooCommerce {

    /**
     * Single instance of the class
     *
     * @var Vocify_AI_WooCommerce
     */
    private static $instance = null;

    /**
     * Admin instance. Null until on_plugins_loaded() runs, and stays null
     * when WooCommerce is not active.
     *
     * @var Vocify_AI_Admin|null
     */
    public $admin;

    /**
     * Order handler instance. Null until on_plugins_loaded() runs, and stays
     * null when WooCommerce is not active — see retry_failed_webhooks(),
     * which is why this must be nullable rather than asserted non-null.
     *
     * @var Vocify_AI_Order_Handler|null
     */
    public $order_handler;

    /**
     * Inbound status receiver (platform -> shop). Null until init_hooks()
     * runs, and stays null when WooCommerce is not active.
     *
     * @var Vocify_AI_Status_Receiver|null
     */
    public $status_receiver;

    /**
     * Get singleton instance
     *
     * @return Vocify_AI_WooCommerce
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     *
     * Runs while this file is being INCLUDED, which is before any other plugin
     * has necessarily been loaded. Only things that must be registered that
     * early belong here; everything that needs WooCommerce waits for
     * `plugins_loaded`.
     *
     * This split is not stylistic. WordPress includes active plugin files in
     * the order they appear in the `active_plugins` option, which it keeps
     * sorted by path — so `vocify-ai-woocommerce/…` is ALWAYS included before
     * `woocommerce/woocommerce.php`. A `class_exists('WooCommerce')` test at
     * include time is therefore false on every normal install, and gating
     * registration on it silently disabled the entire plugin: no order hooks,
     * no admin page, and no activation hook (so the log/retry tables were
     * never created either).
     */
    private function __construct() {
        // Activation/deactivation hooks MUST be registered while the plugin
        // file is being included — WordPress fires them later in that same
        // request — and must not depend on WooCommerce, or a plugin activated
        // before WooCommerce never creates its tables and never gets a second
        // chance (an activation hook fires once, on activation).
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // `before_woocommerce_init` fires while WooCommerce's own file is being
        // included, which is BEFORE `plugins_loaded`. Declaring HPOS
        // compatibility any later is simply ignored.
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));

        // Everything that needs the WooCommerce class waits until every plugin
        // file has been included.
        add_action('plugins_loaded', array($this, 'on_plugins_loaded'));
    }

    /**
     * Boot the parts of the plugin that require WooCommerce.
     *
     * By `plugins_loaded` every active plugin file has been included, so
     * `class_exists('WooCommerce')` is finally a meaningful test.
     */
    public function on_plugins_loaded() {
        $this->load_textdomain();

        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Initialize plugin
        $this->init_hooks();

        // Initialize admin and order handler
        $this->admin = new Vocify_AI_Admin();
        $this->order_handler = new Vocify_AI_Order_Handler();
    }

    /**
     * Initialize WordPress hooks that require WooCommerce to be loaded.
     */
    private function init_hooks() {
        // Plugin action links
        add_filter('plugin_action_links_' . VOCIFY_AI_PLUGIN_BASENAME, array($this, 'plugin_action_links'));

        // Hourly retry of failed webhooks (WP-Cron)
        add_action('vocify_retry_failed_webhooks', array($this, 'retry_failed_webhooks'));

        // The RETURN half of the round trip: the platform POSTs the call
        // outcome back to /vocify/v1/order-status once the call completes.
        // `rest_api_init` fires on every REST request; there is no earlier
        // hook a route can be registered on.
        $this->status_receiver = new Vocify_AI_Status_Receiver();
        add_action('rest_api_init', array($this->status_receiver, 'register_routes'));
    }

    /**
     * Retry failed webhooks (scheduled hourly)
     */
    public function retry_failed_webhooks() {
        if ($this->order_handler) {
            $this->order_handler->retry_failed_webhooks();
        }
    }

    /**
     * Check if WooCommerce is active
     *
     * @return bool
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * Display admin notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('Vocify AI requires WooCommerce to be installed and active.', 'vocify-ai'); ?></p>
        </div>
        <?php
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables
        $this->create_tables();

        // Set default options
        $this->set_default_options();

        // Schedule hourly retry of failed webhooks
        if (!wp_next_scheduled('vocify_retry_failed_webhooks')) {
            wp_schedule_event(time(), 'hourly', 'vocify_retry_failed_webhooks');
        }

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled retry
        wp_clear_scheduled_hook('vocify_retry_failed_webhooks');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create database tables
     */
    private function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        // Table for webhook logs
        $table_name = $wpdb->prefix . 'vocify_webhook_logs';
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            status varchar(20) NOT NULL,
            http_code int(3),
            response text,
            error_message text,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";

        // Table for failed webhooks (retry queue)
        $table_failed = $wpdb->prefix . 'vocify_failed_webhooks';
        $sql_failed = "CREATE TABLE IF NOT EXISTS $table_failed (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            payload longtext NOT NULL,
            error_message text,
            retry_count int(3) DEFAULT 0,
            last_retry_at datetime,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY retry_count (retry_count)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        dbDelta($sql_failed);
    }

    /**
     * Set default options
     */
    private function set_default_options() {
        $defaults = array(
            'vocify_api_key' => '',
            'vocify_signature_secret' => '',
            'vocify_enabled' => 'no',
            'vocify_debug_mode' => 'no',
            'vocify_webhook_url' => 'https://app.vocify-ai.com/api/webhooks/ecommerce',
        );

        foreach ($defaults as $key => $value) {
            if (false === get_option($key)) {
                add_option($key, $value);
            }
        }
    }

    /**
     * Add plugin action links
     *
     * @param array $links
     * @return array
     */
    public function plugin_action_links($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=vocify-ai') . '">' . __('Settings', 'vocify-ai') . '</a>';
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain('vocify-ai', false, dirname(VOCIFY_AI_PLUGIN_BASENAME) . '/languages');
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        }
    }
}

/**
 * Initialize the plugin
 */
function vocify_ai_init() {
    return Vocify_AI_WooCommerce::get_instance();
}

// Start the plugin
vocify_ai_init();
