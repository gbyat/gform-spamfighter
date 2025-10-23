<?php

/**
 * Admin dashboard and statistics.
 *
 * @package GformSpamfighter
 */

namespace GformSpamfighter\Admin;

use GformSpamfighter\Core\Database;

/**
 * Dashboard class.
 */
class Dashboard
{

    /**
     * Instance.
     *
     * @var Dashboard
     */
    private static $instance = null;

    /**
     * Get instance.
     *
     * @return Dashboard
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
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_gform_spamfighter_get_stats', array($this, 'ajax_get_stats'));
        add_action('wp_ajax_gform_spamfighter_get_log_details', array($this, 'ajax_get_log_details'));
        add_action('wp_ajax_gform_spamfighter_clear_strikes', array($this, 'ajax_clear_strikes'));
        add_action('wp_ajax_gform_spamfighter_run_migration', array($this, 'handle_migration_ajax'));
    }

    /**
     * Add admin menu.
     */
    public function add_menu()
    {
        add_menu_page(
            __('GFORM Spamfighter', 'gform-spamfighter'),
            __('Spamfighter', 'gform-spamfighter'),
            'manage_options',
            'gform-spamfighter-dashboard',
            array($this, 'render_dashboard'),
            'dashicons-shield',
            80
        );

        add_submenu_page(
            'gform-spamfighter-dashboard',
            __('Dashboard', 'gform-spamfighter'),
            __('Dashboard', 'gform-spamfighter'),
            'manage_options',
            'gform-spamfighter-dashboard',
            array($this, 'render_dashboard')
        );

        add_submenu_page(
            'gform-spamfighter-dashboard',
            __('Spam Logs', 'gform-spamfighter'),
            __('Spam Logs', 'gform-spamfighter'),
            'manage_options',
            'gform-spamfighter-logs',
            array($this, 'render_logs')
        );
    }

    /**
     * Enqueue admin scripts.
     *
     * @param string $hook Page hook.
     */
    public function enqueue_scripts($hook)
    {
        if (strpos($hook, 'gform-spamfighter') === false) {
            return;
        }

        wp_enqueue_style(
            'gform-spamfighter-dashboard',
            GFORM_SPAMFIGHTER_PLUGIN_URL . 'assets/css/dashboard.css',
            array(),
            GFORM_SPAMFIGHTER_VERSION
        );

        wp_enqueue_script(
            'gform-spamfighter-dashboard',
            GFORM_SPAMFIGHTER_PLUGIN_URL . 'assets/js/dashboard.js',
            array('jquery'),
            GFORM_SPAMFIGHTER_VERSION,
            true
        );

        wp_localize_script(
            'gform-spamfighter-dashboard',
            'gformSpamfighter',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('gform_spamfighter_nonce'),
            )
        );
?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                // Migration button handler
                $('#gform-run-migration').on('click', function() {
                    var button = $(this);
                    var resultSpan = $('#gform-migration-result');

                    button.prop('disabled', true).text('🔄 Running Migration...');
                    resultSpan.text('');

                    $.post(ajaxurl, {
                        action: 'gform_spamfighter_run_migration',
                        nonce: '<?php echo wp_create_nonce('gform_migration_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            resultSpan.html('<span style="color: green;">✅ ' + response.data.message + '</span>');
                            button.text('✅ Migration Complete').removeClass('button-primary').addClass('button-secondary');
                            // Reload page after 2 seconds to show updated data
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            resultSpan.html('<span style="color: red;">❌ ' + response.data.message + '</span>');
                            button.prop('disabled', false).text('🚀 Run Database Migration');
                        }
                    }).fail(function() {
                        resultSpan.html('<span style="color: red;">❌ Migration failed. Please try again.</span>');
                        button.prop('disabled', false).text('🚀 Run Database Migration');
                    });
                });

                // Clear strikes button handler
                $('#gform-clear-strikes').on('click', function() {
                    var button = $(this);
                    var resultSpan = $('#gform-clear-strikes-result');

                    button.prop('disabled', true).text('🔄 Clearing...');
                    resultSpan.text('');

                    $.post(ajaxurl, {
                        action: 'gform_spamfighter_clear_strikes',
                        nonce: '<?php echo wp_create_nonce('gform_spamfighter_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            resultSpan.html('<span style="color: green;">✅ ' + response.data.message + '</span>');
                        } else {
                            resultSpan.html('<span style="color: red;">❌ ' + response.data.message + '</span>');
                        }
                        button.prop('disabled', false).text('🔓 Clear All Strikes (Testing)');
                    });
                });

                // View details button handler
                $('.gform-view-details').on('click', function() {
                    var logId = $(this).data('log-id');
                    var button = $(this);

                    button.prop('disabled', true).text('🔄 Loading...');

                    $.post(ajaxurl, {
                        action: 'gform_spamfighter_get_log_details',
                        log_id: logId,
                        nonce: '<?php echo wp_create_nonce('gform_spamfighter_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            var log = response.data;
                            var details = '<div style="background: #f9f9f9; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa;">';
                            details += '<h4>📋 Submission Details</h4>';
                            details += '<p><strong>Form:</strong> ' + log.form_name + '</p>';
                            details += '<p><strong>Score:</strong> ' + log.spam_score + '</p>';
                            details += '<p><strong>Method:</strong> ' + log.detection_method + '</p>';
                            details += '<p><strong>Action:</strong> ' + log.action_taken + '</p>';

                            if (log.submission_data && typeof log.submission_data === 'object') {
                                details += '<h4>📝 Submitted Data:</h4>';
                                details += '<pre style="background: white; padding: 10px; border: 1px solid #ddd; max-height: 300px; overflow-y: auto;">';
                                details += JSON.stringify(log.submission_data, null, 2);
                                details += '</pre>';
                            }

                            if (log.detection_details && typeof log.detection_details === 'object') {
                                details += '<h4>🔍 Detection Details:</h4>';
                                details += '<pre style="background: white; padding: 10px; border: 1px solid #ddd; max-height: 300px; overflow-y: auto;">';
                                details += JSON.stringify(log.detection_details, null, 2);
                                details += '</pre>';
                            }

                            details += '</div>';

                            // Show in modal or replace button content
                            button.closest('td').html(details);
                        } else {
                            alert('Error loading details: ' + response.data.message);
                            button.prop('disabled', false).text('👁️ View Details');
                        }
                    });
                });
            });
        </script>
    <?php
    }

    /**
     * Render dashboard page.
     */
    public function render_dashboard()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $db    = Database::get_instance();
        $stats = $db->get_statistics(30);

    ?>
        <div class="wrap gform-spamfighter-dashboard">
            <h1><?php esc_html_e('GFORM Spamfighter Dashboard', 'gform-spamfighter'); ?></h1>

            <?php $this->render_migration_button(); ?>

            <div style="margin-bottom: 20px;">
                <button type="button" id="gform-clear-strikes" class="button button-secondary">
                    <?php esc_html_e('🔓 Clear All Strikes (Testing)', 'gform-spamfighter'); ?>
                </button>
                <span id="gform-clear-strikes-result" style="margin-left:10px;"></span>
            </div>

            <div class="gform-stats-grid">
                <div class="gform-stat-box">
                    <div class="gform-stat-value"><?php echo esc_html(number_format_i18n($stats['total_blocked'])); ?></div>
                    <div class="gform-stat-label"><?php esc_html_e('Spam Blocked (30 days)', 'gform-spamfighter'); ?></div>
                </div>

                <?php if (! empty($stats['by_method'])) : ?>
                    <div class="gform-stat-box">
                        <div class="gform-stat-value"><?php echo esc_html($stats['by_method'][0]['detection_method']); ?></div>
                        <div class="gform-stat-label"><?php esc_html_e('Top Detection Method', 'gform-spamfighter'); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (! empty($stats['daily_trend'])) : ?>
                    <?php
                    $today_count = 0;
                    $today_date  = gmdate('Y-m-d');
                    foreach ($stats['daily_trend'] as $day) {
                        if ($day['date'] === $today_date) {
                            $today_count = $day['count'];
                            break;
                        }
                    }
                    ?>
                    <div class="gform-stat-box">
                        <div class="gform-stat-value"><?php echo esc_html(number_format_i18n($today_count)); ?></div>
                        <div class="gform-stat-label"><?php esc_html_e('Spam Blocked Today', 'gform-spamfighter'); ?></div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="gform-charts-grid">
                <div class="gform-chart-container">
                    <h2><?php esc_html_e('Spam Trend (Last 7 days)', 'gform-spamfighter'); ?></h2>
                    <div class="gform-simple-chart">
                        <?php
                        // Simple bar chart using CSS
                        $max_count = 0;
                        foreach ($stats['daily_trend'] as $day) {
                            if ($day['count'] > $max_count) {
                                $max_count = $day['count'];
                            }
                        }

                        // Show last 7 days only
                        $recent_days = array_slice($stats['daily_trend'], -7);

                        foreach ($recent_days as $day) :
                            $percentage = $max_count > 0 ? ($day['count'] / $max_count) * 100 : 0;
                        ?>
                            <div class="gform-chart-bar">
                                <span class="gform-chart-label"><?php echo esc_html(mysql2date('M j', $day['date'])); ?></span>
                                <div class="gform-chart-bar-container">
                                    <div class="gform-chart-bar-fill" style="width: <?php echo esc_attr($percentage); ?>%">
                                        <span class="gform-chart-value"><?php echo esc_html($day['count']); ?></span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="gform-chart-container">
                    <h2><?php esc_html_e('Detection Methods', 'gform-spamfighter'); ?></h2>
                    <div class="gform-methods-list">
                        <?php if (!empty($stats['by_method'])) : ?>
                            <?php foreach ($stats['by_method'] as $method) : ?>
                                <div class="gform-method-item">
                                    <span class="gform-method-name"><?php echo esc_html(ucfirst($method['detection_method'])); ?></span>
                                    <span class="gform-method-count"><?php echo esc_html(number_format_i18n($method['count'])); ?></span>
                                </div>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <p><?php esc_html_e('No data yet', 'gform-spamfighter'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if (! empty($stats['by_form'])) : ?>
                <div class="gform-table-container">
                    <h2><?php esc_html_e('Most Targeted Forms', 'gform-spamfighter'); ?></h2>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e('Source', 'gform-spamfighter'); ?></th>
                                <th><?php esc_html_e('Form Name', 'gform-spamfighter'); ?></th>
                                <th><?php esc_html_e('Spam Count', 'gform-spamfighter'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['by_form'] as $form) : ?>
                                <?php
                                $source_type = $form['source_type'] ?? 'gravity_forms';
                                $source_id = $form['source_id'] ?? $form['form_id'] ?? 'N/A';
                                $form_name = 'Unknown';

                                if ($source_type === 'gravity_forms' && class_exists('GFAPI')) {
                                    $gf_form = \GFAPI::get_form($source_id);
                                    if ($gf_form) {
                                        $form_name = $gf_form['title'];
                                    }
                                } else {
                                    $form_name = ucfirst(str_replace('_', ' ', $source_type)) . ' #' . $source_id;
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html($source_id); ?></td>
                                    <td><?php echo esc_html($form_name); ?></td>
                                    <td><?php echo esc_html(number_format_i18n($form['count'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <!-- OpenAI Rate Limits & Usage -->
            <?php
            $rate_limits = \GformSpamfighter\Detection\OpenAI::get_rate_limits();
            if ($rate_limits) :
            ?>
                <div class="gform-table-container" style="margin-top: 30px;">
                    <h2><?php esc_html_e('OpenAI API Rate Limits', 'gform-spamfighter'); ?></h2>

                    <div class="gform-stats-grid">
                        <?php if ($rate_limits['remaining_requests'] !== null) : ?>
                            <div class="gform-stat-box">
                                <div class="gform-stat-value">
                                    <?php echo esc_html(number_format_i18n($rate_limits['remaining_requests'])); ?>
                                    / <?php echo esc_html(number_format_i18n($rate_limits['limit_requests'])); ?>
                                </div>
                                <div class="gform-stat-label"><?php esc_html_e('Remaining Requests', 'gform-spamfighter'); ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($rate_limits['remaining_tokens'] !== null) : ?>
                            <div class="gform-stat-box">
                                <div class="gform-stat-value">
                                    <?php echo esc_html(number_format_i18n($rate_limits['remaining_tokens'])); ?>
                                    / <?php echo esc_html(number_format_i18n($rate_limits['limit_tokens'])); ?>
                                </div>
                                <div class="gform-stat-label"><?php esc_html_e('Remaining Tokens', 'gform-spamfighter'); ?></div>
                            </div>
                        <?php endif; ?>

                        <div class="gform-stat-box">
                            <div class="gform-stat-value">
                                <?php echo esc_html(human_time_diff($rate_limits['timestamp'], time())); ?> <?php esc_html_e('ago', 'gform-spamfighter'); ?>
                            </div>
                            <div class="gform-stat-label"><?php esc_html_e('Last API Call', 'gform-spamfighter'); ?></div>
                        </div>
                    </div>

                    <p class="description" style="margin-top: 10px;">
                        <?php esc_html_e('Rate limits are updated after each OpenAI API call. Check', 'gform-spamfighter'); ?>
                        <a href="https://platform.openai.com/usage" target="_blank"><?php esc_html_e('OpenAI Usage Dashboard', 'gform-spamfighter'); ?></a>
                        <?php esc_html_e('for detailed billing information.', 'gform-spamfighter'); ?>
                    </p>
                </div>
            <?php endif; ?>

        </div>
    <?php
    }

    /**
     * Render logs page.
     */
    public function render_logs()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $db   = Database::get_instance();
        $page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $per_page = 20;
        $offset   = ($page - 1) * $per_page;

        $logs = $db->get_spam_logs(
            array(
                'limit'  => $per_page,
                'offset' => $offset,
            )
        );

    ?>
        <div class="wrap">
            <h1><?php esc_html_e('Spam Logs', 'gform-spamfighter'); ?></h1>

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Date', 'gform-spamfighter'); ?></th>
                        <th><?php esc_html_e('Source', 'gform-spamfighter'); ?></th>
                        <th><?php esc_html_e('Score', 'gform-spamfighter'); ?></th>
                        <th><?php esc_html_e('Detection Method', 'gform-spamfighter'); ?></th>
                        <th><?php esc_html_e('IP Address', 'gform-spamfighter'); ?></th>
                        <th><?php esc_html_e('Action', 'gform-spamfighter'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)) : ?>
                        <tr>
                            <td colspan="6">
                                <?php esc_html_e('No spam logs found.', 'gform-spamfighter'); ?>
                            </td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $log['created_at'])); ?></td>
                                <td>
                                    <?php
                                    $source_type = $log['source_type'] ?? 'gravity_forms';
                                    $source_id = $log['source_id'] ?? $log['form_id'] ?? 'N/A';
                                    echo esc_html(ucfirst(str_replace('_', ' ', $source_type)) . ' #' . $source_id);
                                    ?>
                                </td>
                                <td><?php echo esc_html(number_format($log['spam_score'], 2)); ?></td>
                                <td><?php echo esc_html($log['detection_method']); ?></td>
                                <td><?php echo esc_html($log['user_ip']); ?></td>
                                <td>
                                    <button type="button" class="button button-small button-primary gform-view-details" data-log-id="<?php echo esc_attr($log['id']); ?>">
                                        <?php esc_html_e('👁️ View Details', 'gform-spamfighter'); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php
            // Simple pagination.
            if (count($logs) === $per_page) {
                $next_page = $page + 1;
                echo '<p>';
                if ($page > 1) {
                    printf(
                        '<a href="%s" class="button">%s</a> ',
                        esc_url(add_query_arg('paged', $page - 1)),
                        esc_html__('Previous', 'gform-spamfighter')
                    );
                }
                printf(
                    '<a href="%s" class="button">%s</a>',
                    esc_url(add_query_arg('paged', $next_page)),
                    esc_html__('Next', 'gform-spamfighter')
                );
                echo '</p>';
            }
            ?>
        </div>
        <?php
    }

    /**
     * AJAX handler for getting statistics.
     */
    public function ajax_get_stats()
    {
        check_ajax_referer('gform_spamfighter_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        // Sanitize and validate days parameter.
        $days = isset($_POST['days']) ? absint($_POST['days']) : 30;
        $days = min(max($days, 1), 365); // Limit range.

        $db   = Database::get_instance();
        $stats = $db->get_statistics($days);

        wp_send_json_success($stats);
    }

    /**
     * AJAX handler for getting single log details.
     */
    public function ajax_get_log_details()
    {
        check_ajax_referer('gform_spamfighter_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $log_id = isset($_POST['log_id']) ? absint($_POST['log_id']) : 0;

        if (! $log_id) {
            wp_send_json_error(array('message' => 'Invalid log ID'));
        }

        global $wpdb;
        $table_name = $wpdb->prefix . 'gform_spam_logs';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table query, no caching needed for single log detail.
        $log = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table_name} WHERE id = %d", $log_id),
            ARRAY_A
        );

        if (! $log) {
            wp_send_json_error(array('message' => 'Log not found'));
        }

        // Decode JSON fields (support both old and new structure)
        $submission_data = $log['submission_data'] ?? $log['entry_data'] ?? '';
        if (! empty($submission_data)) {
            $log['submission_data'] = json_decode($submission_data, true);
        }
        if (! empty($log['detection_details'])) {
            $log['detection_details'] = json_decode($log['detection_details'], true);
        }

        // Get form name if available (support both old and new structure)
        $log['form_name'] = 'Unknown';
        $source_type = $log['source_type'] ?? 'gravity_forms';
        $source_id = $log['source_id'] ?? $log['form_id'] ?? 0;

        if ($source_type === 'gravity_forms' && class_exists('GFAPI')) {
            $gf_form = \GFAPI::get_form($source_id);
            if ($gf_form) {
                $log['form_name'] = $gf_form['title'];
            }
        } else {
            $log['form_name'] = ucfirst(str_replace('_', ' ', $source_type)) . ' #' . $source_id;
        }

        wp_send_json_success($log);
    }

    /**
     * Render migration button if needed.
     */
    private function render_migration_button()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'gform_spam_logs';

        // Check if migration is needed
        $has_old_columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'form_id'");
        $has_new_columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_name} LIKE 'source_type'");

        // Only show button if old columns exist but new ones don't
        if (!empty($has_old_columns) && empty($has_new_columns)) {
        ?>
            <div class="notice notice-warning" style="margin-bottom: 20px;">
                <p>
                    <strong><?php esc_html_e('Database Migration Required', 'gform-spamfighter'); ?></strong><br>
                    <?php esc_html_e('Your database needs to be updated to support multiple form systems (Gravity Forms, Contact Form 7, Comments).', 'gform-spamfighter'); ?>
                </p>
                <button type="button" id="gform-run-migration" class="button button-primary">
                    <?php esc_html_e('🚀 Run Database Migration', 'gform-spamfighter'); ?>
                </button>
                <span id="gform-migration-result" style="margin-left:10px;"></span>
            </div>
<?php
        }
    }

    /**
     * Handle migration AJAX request.
     */
    public function handle_migration_ajax()
    {
        if (! current_user_can('manage_options') || ! wp_verify_nonce($_POST['nonce'], 'gform_migration_nonce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $db = Database::get_instance();
        $result = $db->run_migration_manual();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler for clearing all strikes (admin testing).
     */
    public function ajax_clear_strikes()
    {
        check_ajax_referer('gform_spamfighter_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        global $wpdb;

        // Delete all strike transients
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk transient cleanup, no caching applicable.
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_gform_spam_strike_%' OR option_name LIKE '_transient_timeout_gform_spam_strike_%'"
        );

        wp_send_json_success(array(
            'message' => sprintf(
                /* translators: %d: Number of strikes cleared */
                __('Cleared %d strike entries', 'gform-spamfighter'),
                $deleted
            ),
        ));
    }
}
