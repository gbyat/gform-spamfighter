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

        // Placeholder: Classifier submenu will be provided by Classifier class
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
    }

    /**
     * Render dashboard page.
     */
    public function render_dashboard()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        $db            = Database::get_instance();
        $stats         = $db->get_statistics(30);
        $total_blocked = $db->get_total_blocked();

?>
        <div class="wrap gform-spamfighter-dashboard">
            <h1><?php esc_html_e('GFORM Spamfighter Dashboard', 'gform-spamfighter'); ?></h1>

            <div class="gform-stats-grid">
                <div class="gform-stat-box">
                    <div class="gform-stat-value"><?php echo esc_html(number_format_i18n($total_blocked)); ?></div>
                    <div class="gform-stat-label"><?php esc_html_e('Spam Blocked (Total)', 'gform-spamfighter'); ?></div>
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
                                <th><?php esc_html_e('Form ID', 'gform-spamfighter'); ?></th>
                                <th><?php esc_html_e('Form Name', 'gform-spamfighter'); ?></th>
                                <th><?php esc_html_e('Spam Count', 'gform-spamfighter'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($stats['by_form'] as $form) : ?>
                                <?php
                                $form_name = 'Unknown';
                                if (class_exists('\\GFAPI')) {
                                    $gf_form = \GFAPI::get_form($form['form_id']);
                                    if ($gf_form) {
                                        $form_name = $gf_form['title'];
                                    }
                                }
                                ?>
                                <tr>
                                    <td><?php echo esc_html($form['form_id']); ?></td>
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

        $message = '';

        // Single delete via GET (with nonce).
        if (isset($_GET['gform_spamfighter_delete_log'])) {
            $log_id = absint($_GET['gform_spamfighter_delete_log']);
            if ($log_id && isset($_GET['_wpnonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'gform_spamfighter_delete_log_' . $log_id)) {
                if ($db->delete_log($log_id)) {
                    /* translators: %d: Log ID. */
                    $message = sprintf(esc_html__('Deleted log #%d.', 'gform-spamfighter'), $log_id);
                }
            }
        }

        // Bulk/maintenance actions via POST.
        if ('POST' === $_SERVER['REQUEST_METHOD'] && isset($_POST['gform_spamfighter_logs_action'])) {
            check_admin_referer('gform_spamfighter_logs_action');

            $action = sanitize_text_field(wp_unslash($_POST['gform_spamfighter_logs_action']));

            if ('bulk_delete' === $action && ! empty($_POST['log_ids']) && is_array($_POST['log_ids'])) {
                $ids     = array_map('absint', wp_unslash($_POST['log_ids']));
                $deleted = $db->bulk_delete_logs($ids);

                /* translators: %d: number of deleted logs. */
                $message = sprintf(esc_html__('Deleted %d log entries.', 'gform-spamfighter'), $deleted);
            } elseif ('delete_older' === $action && isset($_POST['delete_older_days'])) {
                $days = absint($_POST['delete_older_days']);
                if ($days < 1) {
                    $days = 1;
                }

                $deleted = $db->clean_old_logs($days);

                /* translators: 1: number of deleted logs, 2: number of days. */
                $message = sprintf(esc_html__('Deleted %1$d log entries older than %2$d days.', 'gform-spamfighter'), (int) $deleted, $days);
            }
        }

        $logs = $db->get_spam_logs(
            array(
                'limit'  => $per_page,
                'offset' => $offset,
            )
        );

        $total_logs  = $db->get_spam_logs_count();
        $total_pages = (int) ceil($total_logs / $per_page);

    ?>
        <div class="wrap">
            <h1><?php esc_html_e('Spam Logs', 'gform-spamfighter'); ?></h1>

            <?php if (! empty($message)) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html($message); ?></p>
                </div>
            <?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('gform_spamfighter_logs_action'); ?>

                <div class="tablenav top" style="display:flex;align-items:center;gap:20px;">
                    <div class="alignleft actions bulkactions">
                        <button type="submit" name="gform_spamfighter_logs_action" value="bulk_delete" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Delete selected logs permanently?', 'gform-spamfighter')); ?>');">
                            <?php esc_html_e('Delete Selected', 'gform-spamfighter'); ?>
                        </button>
                    </div>

                    <div class="alignleft actions">
                        <label for="gform-delete-older-days" class="screen-reader-text"><?php esc_html_e('Delete logs older than (days)', 'gform-spamfighter'); ?></label>
                        <input type="number" id="gform-delete-older-days" name="delete_older_days" value="30" min="1" style="width:80px;" />
                        <button type="submit" name="gform_spamfighter_logs_action" value="delete_older" class="button" onclick="return confirm('<?php echo esc_js(__('Delete all logs older than the specified number of days?', 'gform-spamfighter')); ?>');">
                            <?php esc_html_e('Delete logs older than X days', 'gform-spamfighter'); ?>
                        </button>
                    </div>
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <td id="cb" class="manage-column column-cb check-column">
                                <input type="checkbox" id="gform-spamfighter-select-all" />
                            </td>
                            <th><?php esc_html_e('Date', 'gform-spamfighter'); ?></th>
                            <th><?php esc_html_e('Form ID', 'gform-spamfighter'); ?></th>
                            <th><?php esc_html_e('Score', 'gform-spamfighter'); ?></th>
                            <th><?php esc_html_e('Detection Method', 'gform-spamfighter'); ?></th>
                            <th><?php esc_html_e('IP Address', 'gform-spamfighter'); ?></th>
                            <th><?php esc_html_e('Action', 'gform-spamfighter'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)) : ?>
                            <tr>
                                <td colspan="7">
                                    <?php esc_html_e('No spam logs found.', 'gform-spamfighter'); ?>
                                </td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($logs as $log) : ?>
                                <tr>
                                    <th scope="row" class="check-column">
                                        <input type="checkbox" name="log_ids[]" value="<?php echo esc_attr($log['id']); ?>" />
                                    </th>
                                    <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $log['created_at'])); ?></td>
                                    <td><?php echo esc_html($log['form_id']); ?></td>
                                    <td><?php echo esc_html(number_format($log['spam_score'], 2)); ?></td>
                                    <td><?php echo esc_html($log['detection_method']); ?></td>
                                    <td><?php echo esc_html($log['user_ip']); ?></td>
                                    <td>
                                        <button type="button" class="button button-small button-primary gform-view-details" data-log-id="<?php echo esc_attr($log['id']); ?>">
                                            <?php esc_html_e('👁️ View Details', 'gform-spamfighter'); ?>
                                        </button>
                                        <?php
                                        $delete_url = wp_nonce_url(
                                            add_query_arg(
                                                array(
                                                    'page'                          => 'gform-spamfighter-logs',
                                                    'gform_spamfighter_delete_log'  => $log['id'],
                                                )
                                            ),
                                            'gform_spamfighter_delete_log_' . $log['id']
                                        );
                                        ?>
                                        <a href="<?php echo esc_url($delete_url); ?>" class="button button-small button-secondary" onclick="return confirm('<?php echo esc_js(__('Delete this log permanently?', 'gform-spamfighter')); ?>');">
                                            <?php esc_html_e('Delete', 'gform-spamfighter'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php
                if ($total_pages > 1) {
                    $base_url = remove_query_arg('paged');
                    $prev_url = $page > 1 ? esc_url(add_query_arg('paged', $page - 1, $base_url)) : '';
                    $next_url = $page < $total_pages ? esc_url(add_query_arg('paged', $page + 1, $base_url)) : '';

                    $center_links = paginate_links(
                        array(
                            'base'      => esc_url(add_query_arg('paged', '%#%', $base_url)),
                            'format'    => '',
                            'current'   => $page,
                            'total'     => $total_pages,
                            'type'      => 'plain',
                            'mid_size'  => 2,
                            'end_size'  => 1,
                            'prev_next' => false,
                        )
                    );

                    echo '<div class="tablenav gform-spamfighter-pagination" style="margin-top:15px;text-align:center;">';
                    echo '<div class="tablenav-pages" style="display:flex;align-items:center;justify-content:center;gap:40px;">';

                    // Left: Previous button.
                    echo '<div class="gform-pagination-prev">';
                    if ($prev_url) {
                        printf(
                            '<a href="%s" class="button">%s</a>',
                            $prev_url,
                            esc_html__('zurück', 'gform-spamfighter')
                        );
                    } else {
                        echo '&nbsp;';
                    }
                    echo '</div>';

                    // Center: Numbered links.
                    echo '<div class="gform-pagination-center">';
                    echo wp_kses_post($center_links);
                    echo '</div>';

                    // Right: Next button.
                    echo '<div class="gform-pagination-next">';
                    if ($next_url) {
                        printf(
                            '<a href="%s" class="button">%s</a>',
                            $next_url,
                            esc_html__('weiter', 'gform-spamfighter')
                        );
                    } else {
                        echo '&nbsp;';
                    }
                    echo '</div>';

                    echo '</div></div>';
                }
                ?>
            </form>
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
            \ARRAY_A
        );

        if (! $log) {
            wp_send_json_error(array('message' => 'Log not found'));
        }

        // Decode JSON fields
        if (! empty($log['entry_data'])) {
            $log['entry_data'] = json_decode($log['entry_data'], true);
        }
        if (! empty($log['detection_details'])) {
            $log['detection_details'] = json_decode($log['detection_details'], true);
        }

        // Get form name if available
        $log['form_name'] = 'Unknown';
        if (class_exists('\\GFAPI')) {
            $gf_form = \GFAPI::get_form($log['form_id']);
            if ($gf_form) {
                $log['form_name'] = $gf_form['title'];
            }
        }

        wp_send_json_success($log);
    }
}
