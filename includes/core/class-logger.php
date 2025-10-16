<?php

/**
 * Logger class.
 *
 * @package GformSpamfighter
 */

namespace GformSpamfighter\Core;

/**
 * Logger class for debugging and monitoring.
 */
class Logger
{

    /**
     * Instance.
     *
     * @var Logger
     */
    private static $instance = null;

    /**
     * Log file path.
     *
     * @var string
     */
    private $log_file;

    /**
     * Get instance.
     *
     * @return Logger
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
        $upload_dir     = wp_upload_dir();
        $log_dir        = $upload_dir['basedir'] . '/gform-spamfighter-logs';
        $this->log_file = $log_dir . '/debug.log';

        // Create log directory if it doesn't exist.
        if (! file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            $this->protect_log_directory($log_dir);
        }
    }

    /**
     * Protect log directory from direct access.
     *
     * @param string $log_dir Log directory path.
     */
    private function protect_log_directory($log_dir)
    {
        global $wp_filesystem;

        // Initialize WP_Filesystem.
        if (! function_exists('WP_Filesystem')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        WP_Filesystem();

        if (! $wp_filesystem) {
            return;
        }

        // Protect log directory with comprehensive rules.
        $htaccess_content = "# Protect log files from direct access\n";
        $htaccess_content .= "<Files *>\n";
        $htaccess_content .= "    Order allow,deny\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "</Files>\n";

        $wp_filesystem->put_contents(
            $log_dir . '/.htaccess',
            $htaccess_content,
            FS_CHMOD_FILE
        );

        $wp_filesystem->put_contents(
            $log_dir . '/index.php',
            '<?php // Silence is golden',
            FS_CHMOD_FILE
        );

        // Add nginx protection hint file.
        $nginx_hint = "# To protect logs on nginx, add this to your nginx config:\n";
        $nginx_hint .= "# location ~ /wp-content/uploads/gform-spamfighter-logs/ {\n";
        $nginx_hint .= "#     deny all;\n";
        $nginx_hint .= "#     return 403;\n";
        $nginx_hint .= "# }\n";

        $wp_filesystem->put_contents(
            $log_dir . '/nginx.conf.example',
            $nginx_hint,
            FS_CHMOD_FILE
        );
    }

    /**
     * Log a message.
     *
     * @param string $message Log message.
     * @param string $level Log level (info, warning, error).
     * @param array  $context Additional context.
     */
    public function log($message, $level = 'info', $context = array())
    {
        if (! defined('WP_DEBUG') || ! WP_DEBUG) {
            return;
        }

        global $wp_filesystem;

        // Initialize WP_Filesystem if needed.
        if (! $wp_filesystem) {
            if (! function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            WP_Filesystem();
        }

        $timestamp = gmdate('Y-m-d H:i:s');
        $level     = strtoupper($level);

        $log_entry = sprintf(
            "[%s] [%s] %s\n",
            $timestamp,
            $level,
            $message
        );

        if (! empty($context)) {
            $log_entry .= 'Context: ' . wp_json_encode($context, JSON_PRETTY_PRINT) . "\n";
        }

        $log_entry .= "---\n";

        // Append to log file using WP_Filesystem.
        if ($wp_filesystem && $wp_filesystem->exists($this->log_file)) {
            $existing_content = $wp_filesystem->get_contents($this->log_file);
            $wp_filesystem->put_contents(
                $this->log_file,
                $existing_content . $log_entry,
                FS_CHMOD_FILE
            );
        } elseif ($wp_filesystem) {
            // Create new log file.
            $wp_filesystem->put_contents(
                $this->log_file,
                $log_entry,
                FS_CHMOD_FILE
            );
        } else {
            // Fallback to error_log if WP_Filesystem fails.
            error_log($log_entry, 3, $this->log_file); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        }
    }

    /**
     * Log info message.
     *
     * @param string $message Message.
     * @param array  $context Context.
     */
    public function info($message, $context = array())
    {
        $this->log($message, 'info', $context);
    }

    /**
     * Log warning message.
     *
     * @param string $message Message.
     * @param array  $context Context.
     */
    public function warning($message, $context = array())
    {
        $this->log($message, 'warning', $context);
    }

    /**
     * Log error message.
     *
     * @param string $message Message.
     * @param array  $context Context.
     */
    public function error($message, $context = array())
    {
        $this->log($message, 'error', $context);
    }
}
