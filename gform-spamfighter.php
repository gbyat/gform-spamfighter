<?php

/**
 * Plugin Name: GForm Spamfighter
 * Plugin URI: https://github.com/gbyat/gform-spamfighter
 * Description: Advanced spam protection for Gravity Forms using AI detection, pattern analysis, and behavior monitoring
 * Version: 1.0.9
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
define('GFORM_SPAMFIGHTER_VERSION', '1.0.9');
define('GFORM_SPAMFIGHTER_PLUGIN_FILE', __FILE__);
define('GFORM_SPAMFIGHTER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GFORM_SPAMFIGHTER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GFORM_SPAMFIGHTER_GITHUB_REPO', 'gbyat/gform-spamfighter');

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
        add_action('plugins_loaded', array($this, 'init_github_updater'), 5); // Early, before other plugins_loaded hooks
        add_action('plugins_loaded', array($this, 'load_gf_integration'), 20); // After GF loads
        add_action('init', array($this, 'init'));

        // Check if Gravity Forms is active.
        add_action('admin_init', array($this, 'check_gravity_forms_dependency'));
        add_action('admin_notices', array($this, 'gravity_forms_missing_notice'));

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
     * Initialize GitHub Updater for automatic updates.
     */
    public function init_github_updater()
    {
        // Only load in admin or when checking for updates.
        if (is_admin() || wp_doing_cron()) {
            new Core\Updater(GFORM_SPAMFIGHTER_PLUGIN_FILE);
        }
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
     * Plugin activation.
     */
    public function activate()
    {
        // Check if Gravity Forms is installed and active.
        if (! $this->is_gravity_forms_active()) {
            deactivate_plugins(plugin_basename(GFORM_SPAMFIGHTER_PLUGIN_FILE));
            wp_die(
                '<h1>' . esc_html__('Plugin Activation Error', 'gform-spamfighter') . '</h1>' .
                    '<p>' . esc_html__('GFORM Spamfighter requires Gravity Forms to be installed and activated.', 'gform-spamfighter') . '</p>' .
                    '<p><a href="https://www.gravityforms.com/" target="_blank">' . esc_html__('Get Gravity Forms', 'gform-spamfighter') . '</a></p>' .
                    '<p><a href="' . esc_url(admin_url('plugins.php')) . '">' . esc_html__('Return to Plugins', 'gform-spamfighter') . '</a></p>',
                esc_html__('Plugin Activation Error', 'gform-spamfighter'),
                array('back_link' => true)
            );
        }

        Core\Database::get_instance()->create_tables();

        // Set default options.
        $defaults = array(
            'enabled'                    => true,
            'openai_enabled'             => false,
            'openai_api_key'             => '',
            'openai_model'               => 'gpt-4o-mini',
            'ai_threshold'               => 0.7,
            'time_check_enabled'         => true,
            'min_submission_time'        => 5,
            'pattern_check_enabled'      => true,
            'exclude_hidden_fields'      => true,
            'language_check_enabled'     => true,
            'duplicate_check_enabled'    => true,
            'duplicate_check_timeframe'  => 24,
            'log_retention_days'         => 30,
            'block_action'               => 'mark',
            'notification_email'         => get_option('admin_email'),
            'notify_on_spam'             => true,
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
     * Check if Gravity Forms is active.
     *
     * @return bool
     */
    private function is_gravity_forms_active()
    {
        // Check if Gravity Forms class exists.
        if (class_exists('GFForms')) {
            return true;
        }

        // Check if Gravity Forms plugin is active.
        $active_plugins = get_option('active_plugins', array());

        // Single site check.
        if (in_array('gravityforms/gravityforms.php', $active_plugins, true)) {
            return true;
        }

        // Multisite check.
        if (is_multisite()) {
            $active_sitewide_plugins = get_site_option('active_sitewide_plugins', array());
            if (isset($active_sitewide_plugins['gravityforms/gravityforms.php'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check Gravity Forms dependency on admin_init.
     */
    public function check_gravity_forms_dependency()
    {
        if (! $this->is_gravity_forms_active()) {
            deactivate_plugins(plugin_basename(GFORM_SPAMFIGHTER_PLUGIN_FILE));
            set_transient('gform_spamfighter_deactivated', true, 10);
        }
    }

    /**
     * Show admin notice if Gravity Forms is missing.
     */
    public function gravity_forms_missing_notice()
    {
        if (get_transient('gform_spamfighter_deactivated')) {
            delete_transient('gform_spamfighter_deactivated');
?>
            <div class="notice notice-error">
                <p>
                    <strong><?php esc_html_e('GFORM Spamfighter has been deactivated.', 'gform-spamfighter'); ?></strong>
                </p>
                <p>
                    <?php esc_html_e('This plugin requires Gravity Forms to be installed and activated.', 'gform-spamfighter'); ?>
                    <a href="https://www.gravityforms.com/" target="_blank"><?php esc_html_e('Get Gravity Forms', 'gform-spamfighter'); ?></a>
                </p>
            </div>
<?php
        }
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
