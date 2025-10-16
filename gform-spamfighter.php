<?php

/**
 * Plugin Name: GForm Spamfighter
 * Plugin URI: https://webentwicklerin.at
 * Description: Advanced spam protection for Gravity Forms using AI detection, pattern analysis, and behavior monitoring
 * Version: 1.0.0
 * Author: webentwicklerin, Gabriele Laesser
 * Author URI: https://webentwicklerin.at
 * Text Domain: gform-spamfighter
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package GformSpamfighter
 */

namespace GformSpamfighter;

// Exit if accessed directly.
if (! defined('ABSPATH')) {
    exit;
}

// Define plugin constants.
define('GFORM_SPAMFIGHTER_VERSION', '1.0.0');
define('GFORM_SPAMFIGHTER_PLUGIN_FILE', __FILE__);
define('GFORM_SPAMFIGHTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GFORM_SPAMFIGHTER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Autoloader.
spl_autoload_register(
    function ($class) {
        $prefix   = 'GformSpamfighter\\';
        $base_dir = __DIR__ . '/includes/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);

        // Convert namespace separators to directory separators.
        $relative_class = str_replace('\\', '/', $relative_class);

        // Convert class name from PascalCase to kebab-case for WordPress standards.
        // Extract directory and class name.
        $parts      = explode('/', $relative_class);
        $class_name = array_pop($parts);

        // Convert directories to lowercase (WordPress standard).
        $parts = array_map('strtolower', $parts);

        // Convert PascalCase to kebab-case.
        $class_name = strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $class_name));

        // Build file path with 'class-' prefix.
        $file = $base_dir . (!empty($parts) ? implode('/', $parts) . '/' : '') . 'class-' . $class_name . '.php';

        if (file_exists($file)) {
            require $file;
        }
    }
);

/**
 * Main plugin class.
 */
class Plugin
{

    /**
     * Plugin instance.
     *
     * @var Plugin
     */
    private static $instance = null;

    /**
     * Get plugin instance.
     *
     * @return Plugin
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        $this->init_hooks();
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks()
    {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('plugins_loaded', array($this, 'load_gf_integration'), 20); // After GF loads
        add_action('init', array($this, 'init'));

        // Cron: daily cleanup of old logs.
        add_action('gform_spamfighter_clean_logs', array($this, 'clean_logs_cron'));

        // Admin hooks.
        if (is_admin()) {
            Admin\Settings::get_instance();
            Admin\Dashboard::get_instance();
        }

        // Activation/Deactivation.
        register_activation_hook(GFORM_SPAMFIGHTER_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(GFORM_SPAMFIGHTER_PLUGIN_FILE, array($this, 'deactivate'));
    }

    /**
     * Load text domain for translations.
     */
    public function load_textdomain()
    {
        load_plugin_textdomain('gform-spamfighter', false, dirname(plugin_basename(GFORM_SPAMFIGHTER_PLUGIN_FILE)) . '/languages');
    }

    /**
     * Initialize plugin.
     */
    public function init()
    {
        // Initialize core components.
        Core\Logger::get_instance();
        Core\Database::get_instance();
    }

    /**
     * Load Gravity Forms integration.
     */
    public function load_gf_integration()
    {
        if (! class_exists('GFForms')) {
            add_action('admin_notices', array($this, 'gravity_forms_notice'));
            return;
        }

        Integration\GravityForms::get_instance();
    }

    /**
     * Show notice if Gravity Forms is not active.
     */
    public function gravity_forms_notice()
    {
?>
        <div class="notice notice-error">
            <p>
                <?php
                echo esc_html__('GFORM Spamfighter requires Gravity Forms to be installed and activated.', 'gform-spamfighter');
                ?>
            </p>
        </div>
<?php
    }

    /**
     * Plugin activation.
     */
    public function activate()
    {
        Core\Database::get_instance()->create_tables();

        // Set default options.
        $defaults = array(
            'enabled'                    => true,
            'openai_enabled'             => false,
            'openai_api_key'             => '',
            'openai_model'               => 'moderation',
            'ai_threshold'               => 0.7,
            'time_check_enabled'         => true,
            'min_submission_time'        => 3,
            'pattern_check_enabled'      => true,
            'language_check_enabled'     => true,
            'duplicate_check_enabled'    => true,
            'duplicate_check_timeframe'  => 24,
            'log_retention_days'         => 30,
            'block_action'               => 'reject',
            'notification_email'         => get_option('admin_email'),
            'notify_on_spam'             => false,
        );

        add_option('gform_spamfighter_settings', $defaults);

        flush_rewrite_rules();

        // Schedule daily cleanup if not already scheduled.
        if (! wp_next_scheduled('gform_spamfighter_clean_logs')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'gform_spamfighter_clean_logs');
        }
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate()
    {
        flush_rewrite_rules();
        // Clear scheduled cleanup.
        wp_clear_scheduled_hook('gform_spamfighter_clean_logs');
    }

    /**
     * Cron callback: clean old logs based on retention settings.
     */
    public function clean_logs_cron()
    {
        $settings = get_option('gform_spamfighter_settings', array());
        $days     = isset($settings['log_retention_days']) ? absint($settings['log_retention_days']) : 30;
        if ($days < 7) {
            $days = 7; // enforce a sane minimum
        }

        $deleted = Core\Database::get_instance()->clean_old_logs($days);

        // Optional: debug log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            Core\Logger::get_instance()->info('Cron: cleaned old spam logs', array('retention_days' => $days, 'deleted_rows' => (int) $deleted));
        }
    }
}

// Initialize plugin.
Plugin::get_instance();
