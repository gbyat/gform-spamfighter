<?php

/**
 * Database handler.
 *
 * @package GformSpamfighter
 */

namespace GformSpamfighter\Core;

/**
 * Database class.
 */
class Database
{

    /**
     * Instance.
     *
     * @var Database
     */
    private static $instance = null;

    /**
     * Get instance.
     *
     * @return Database
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
        // Table name is generated dynamically to support Multisite.
    }

    /**
     * Get table name (dynamic for Multisite compatibility).
     *
     * @return string Table name with current site's prefix.
     */
    private function get_table_name()
    {
        global $wpdb;
        return $wpdb->prefix . 'gform_spam_logs';
    }

    /**
     * Create database tables.
     */
    public function create_tables()
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$this->get_table_name()} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id bigint(20) UNSIGNED NOT NULL,
			entry_data longtext NOT NULL,
			spam_score float NOT NULL DEFAULT 0,
			detection_method varchar(255) NOT NULL,
			detection_details longtext,
            user_ip varchar(100),
            user_agent text,
            site_id bigint(20) UNSIGNED,
            site_locale varchar(20),
            action_taken varchar(50) NOT NULL DEFAULT 'rejected',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY created_at (created_at),
			KEY spam_score (spam_score),
			KEY site_id (site_id)
		) $charset_collate;";

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant in global namespace.
        require_once \ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Log spam attempt.
     *
     * @param array $data Log data.
     * @return int|false Insert ID or false on failure.
     */
    public function log_spam($data)
    {
        global $wpdb;

        $defaults = array(
            'form_id'           => 0,
            'entry_data'        => '',
            'spam_score'        => 0,
            'detection_method'  => '',
            'detection_details' => '',
            'user_ip'           => $this->get_user_ip(),
            'user_agent'        => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '',
            'site_id'           => get_current_blog_id(),
            'site_locale'       => get_locale(),
            'action_taken'      => 'rejected',
        );

        $data = wp_parse_args($data, $defaults);

        // Serialize complex data.
        if (is_array($data['entry_data'])) {
            $data['entry_data'] = wp_json_encode($data['entry_data']);
        }
        if (is_array($data['detection_details'])) {
            $data['detection_details'] = wp_json_encode($data['detection_details']);
        }

        // Truncate site_locale to fit in VARCHAR(20)
        if (isset($data['site_locale']) && strlen($data['site_locale']) > 20) {
            $data['site_locale'] = substr($data['site_locale'], 0, 20);
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table insert for spam log.
        $result = $wpdb->insert(
            $this->get_table_name(),
            $data,
            array(
                '%d', // form_id.
                '%s', // entry_data.
                '%f', // spam_score.
                '%s', // detection_method.
                '%s', // detection_details.
                '%s', // user_ip.
                '%s', // user_agent.
                '%d', // site_id.
                '%s', // site_locale.
                '%s', // action_taken.
            )
        );

        // If insert failed due to missing table/columns, try to repair and retry once.
        if (! $result && ! empty($wpdb->last_error)) {
            // Check if error is related to missing table or column.
            $error_lower = strtolower($wpdb->last_error);
            if (
                stripos($error_lower, "doesn't exist") !== false ||
                stripos($error_lower, 'unknown column') !== false ||
                stripos($error_lower, 'table') !== false
            ) {
                // Attempt to repair table structure.
                $this->verify_and_repair_table();
                // Retry insert once.
                $result = $wpdb->insert(
                    $this->get_table_name(),
                    $data,
                    array(
                        '%d',
                        '%s',
                        '%f',
                        '%s',
                        '%s',
                        '%s',
                        '%s',
                        '%d',
                        '%s',
                        '%s',
                    )
                );
            }
        }

        if (! $result) {
            return false;
        }

        // Increment lifetime total counter (does not decrease when logs are deleted).
        $this->increment_total_blocked();

        return $wpdb->insert_id;
    }

    /**
     * Get spam logs.
     *
     * @param array $args Query arguments.
     * @return array
     */
    public function get_spam_logs($args = array())
    {
        global $wpdb;

        $defaults = array(
            'limit'      => 50,
            'offset'     => 0,
            'order_by'   => 'created_at',
            'order'      => 'DESC',
            'form_id'    => null,
            'site_id'    => null,
            'date_from'  => null,
            'date_to'    => null,
            'min_score'  => null,
        );

        $args = wp_parse_args($args, $defaults);

        // Whitelist for order_by to prevent SQL injection.
        $allowed_order_by = array('id', 'created_at', 'form_id', 'spam_score', 'site_id');
        if (! in_array($args['order_by'], $allowed_order_by, true)) {
            $args['order_by'] = 'created_at';
        }

        // Whitelist for order direction.
        $args['order'] = strtoupper($args['order']);
        if (! in_array($args['order'], array('ASC', 'DESC'), true)) {
            $args['order'] = 'DESC';
        }

        // Validate and sanitize limit and offset.
        $args['limit']  = absint($args['limit']);
        $args['offset'] = absint($args['offset']);

        $where = array('1=1');

        if (! is_null($args['form_id'])) {
            $where[] = $wpdb->prepare('form_id = %d', absint($args['form_id']));
        }

        if (! is_null($args['site_id'])) {
            $where[] = $wpdb->prepare('site_id = %d', absint($args['site_id']));
        }

        if (! is_null($args['date_from'])) {
            $where[] = $wpdb->prepare('created_at >= %s', sanitize_text_field($args['date_from']));
        }

        if (! is_null($args['date_to'])) {
            $where[] = $wpdb->prepare('created_at <= %s', sanitize_text_field($args['date_to']));
        }

        if (! is_null($args['min_score'])) {
            $where[] = $wpdb->prepare('spam_score >= %f', floatval($args['min_score']));
        }

        $where_clause = implode(' AND ', $where);

        // Safe to use $args['order_by'] and $args['order'] now as they're whitelisted.
        $query = $wpdb->prepare(
            "SELECT * FROM {$this->get_table_name()} WHERE {$where_clause} ORDER BY {$args['order_by']} {$args['order']} LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $args['limit'],
            $args['offset']
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- Custom table query with dynamic filters, no caching applicable.
        $results = $wpdb->get_results($query, \ARRAY_A);

        // If query failed due to missing table/columns, try to repair and retry once.
        if (false === $results && ! empty($wpdb->last_error)) {
            $error_lower = strtolower($wpdb->last_error);
            if (
                stripos($error_lower, "doesn't exist") !== false ||
                stripos($error_lower, 'unknown column') !== false ||
                stripos($error_lower, 'table') !== false
            ) {
                // Attempt to repair table structure.
                $this->verify_and_repair_table();
                // Retry query once.
                $results = $wpdb->get_results($query, \ARRAY_A); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.NotPrepared
            }
        }

        // If still false (error), return empty array instead of false to prevent errors in calling code.
        if (false === $results) {
            $results = array();
        }

        return $results;
    }

    /**
     * Get total count of spam logs.
     *
     * @return int
     */
    public function get_spam_logs_count()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Count query for custom table.
        $count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->get_table_name()}"
        );

        return $count;
    }

    /**
     * Get lifetime total of blocked spam submissions.
     *
     * This value is stored in an option and does not decrease when logs are deleted.
     * On first access it is initialized from the current number of log rows.
     *
     * @return int
     */
    public function get_total_blocked()
    {
        $option_key = 'gform_spamfighter_total_blocked';

        // Use null as sentinel to detect "not initialized yet".
        $stored = get_option($option_key, null);

        if (null === $stored) {
            // Initialize from existing logs so we keep historical data
            // even if logs have already been collected before this feature existed.
            $initial = $this->get_spam_logs_count();
            update_option($option_key, (int) $initial);
            return (int) $initial;
        }

        return (int) $stored;
    }

    /**
     * Increment lifetime total of blocked spam submissions.
     *
     * @param int $amount Amount to add.
     * @return void
     */
    private function increment_total_blocked($amount = 1)
    {
        $option_key = 'gform_spamfighter_total_blocked';

        $current = (int) get_option($option_key, 0);
        $new     = $current + (int) $amount;

        update_option($option_key, $new);
    }

    /**
     * Get spam statistics.
     *
     * @param int $days Number of days to look back.
     * @return array
     */
    public function get_statistics($days = 30)
    {
        global $wpdb;

        // Sanitize days parameter.
        $days = absint($days);
        if ($days < 1 || $days > 365) {
            $days = 30;
        }

        $date_from = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        $stats = array();

        // Total spam blocked.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statistics query for custom table, caching handled at application level.
        $stats['total_blocked'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->get_table_name()} WHERE created_at >= %s",
                $date_from
            )
        );

        // By detection method.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statistics query for custom table, caching handled at application level.
        $stats['by_method'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT detection_method, COUNT(*) as count 
				 FROM {$this->get_table_name()} 
				 WHERE created_at >= %s 
				 GROUP BY detection_method 
				 ORDER BY count DESC",
                $date_from
            ),
            \ARRAY_A
        );

        // By form.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statistics query for custom table, caching handled at application level.
        $stats['by_form'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT form_id, COUNT(*) as count 
				 FROM {$this->get_table_name()} 
				 WHERE created_at >= %s 
				 GROUP BY form_id 
				 ORDER BY count DESC 
				 LIMIT 10",
                $date_from
            ),
            \ARRAY_A
        );

        // By site (multisite).
        if (is_multisite()) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statistics query for custom table, caching handled at application level.
            $stats['by_site'] = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT site_id, COUNT(*) as count 
					 FROM {$this->get_table_name()} 
					 WHERE created_at >= %s 
					 GROUP BY site_id 
					 ORDER BY count DESC",
                    $date_from
                ),
                \ARRAY_A
            );
        }

        // Daily trend.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Statistics query for custom table, caching handled at application level.
        $stats['daily_trend'] = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT DATE(created_at) as date, COUNT(*) as count 
				 FROM {$this->get_table_name()} 
				 WHERE created_at >= %s 
				 GROUP BY DATE(created_at) 
				 ORDER BY date ASC",
                $date_from
            ),
            \ARRAY_A
        );

        return $stats;
    }

    /**
     * Clean old logs.
     *
     * @param int $days Number of days to keep.
     * @return int Number of deleted rows.
     */
    public function clean_old_logs($days = 30)
    {
        global $wpdb;

        // Sanitize days parameter to prevent manipulation.
        $days = absint($days);
        if ($days < 1) {
            $days = 30;
        }

        $date = gmdate('Y-m-d H:i:s', strtotime("-{$days} days"));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Cleanup operation on custom table.
        return $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->get_table_name()} WHERE created_at < %s",
                $date
            )
        );
    }

    /**
     * Delete a single spam log entry.
     *
     * @param int $id Log ID.
     * @return bool
     */
    public function delete_log($id)
    {
        global $wpdb;

        $id = absint($id);
        if (! $id) {
            return false;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete operation on custom table.
        $deleted = (bool) $wpdb->delete(
            $this->get_table_name(),
            array('id' => $id),
            array('%d')
        );

        return $deleted;
    }

    /**
     * Bulk delete spam log entries.
     *
     * @param array $ids Array of log IDs.
     * @return int Number of deleted rows.
     */
    public function bulk_delete_logs($ids)
    {
        global $wpdb;

        if (empty($ids) || ! is_array($ids)) {
            return 0;
        }

        // Sanitize IDs.
        $ids = array_map('absint', $ids);
        $ids = array_filter($ids);

        if (empty($ids)) {
            return 0;
        }

        // Build placeholders for IN clause.
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk delete operation on custom table.
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->get_table_name()} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholders are prepared.
                ...$ids
            )
        );

        return (int) $result;
    }

    /**
     * Check for duplicate submissions.
     *
     * @param array  $entry_data Entry data to check.
     * @param int    $form_id Form ID.
     * @param int    $hours Hours to look back.
     * @param string $ip User IP.
     * @return bool True if duplicate found.
     */
    public function check_duplicate($entry_data, $form_id, $hours = 24, $ip = null)
    {
        global $wpdb;

        $date_from = gmdate('Y-m-d H:i:s', strtotime("-{$hours} hours"));
        $ip        = $ip ?? $this->get_user_ip();

        // Get recent submissions.
        $query = $wpdb->prepare(
            "SELECT entry_data FROM {$this->get_table_name()} 
			 WHERE form_id = %d 
			 AND user_ip = %s 
			 AND created_at >= %s",
            $form_id,
            $ip,
            $date_from
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Duplicate check on custom table, no caching applicable.
        $results = $wpdb->get_col($query);

        if (empty($results)) {
            return false;
        }

        $current_hash = md5(wp_json_encode($entry_data));

        foreach ($results as $result) {
            $stored_hash = md5($result);
            if ($current_hash === $stored_hash) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if table exists.
     *
     * @return bool True if table exists, false otherwise.
     */
    private function table_exists()
    {
        global $wpdb;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Table existence check.
        $table_exists = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $this->get_table_name()
            )
        );

        return (bool) $table_exists;
    }

    /**
     * Verify table structure and repair if needed.
     *
     * Checks if table exists and all required columns are present.
     * Automatically repairs by recreating table structure using dbDelta.
     *
     * @return bool True if table is valid or was successfully repaired, false on failure.
     */
    public function verify_and_repair_table()
    {
        // Check if table exists.
        if (! $this->table_exists()) {
            // Table doesn't exist - recreate it.
            $this->create_tables();
            return $this->table_exists();
        }

        // Table exists - verify structure using dbDelta (which adds missing columns).
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->get_table_name()} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			form_id bigint(20) UNSIGNED NOT NULL,
			entry_data longtext NOT NULL,
			spam_score float NOT NULL DEFAULT 0,
			detection_method varchar(255) NOT NULL,
			detection_details longtext,
            user_ip varchar(100),
            user_agent text,
            site_id bigint(20) UNSIGNED,
            site_locale varchar(20),
            action_taken varchar(50) NOT NULL DEFAULT 'rejected',
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY form_id (form_id),
			KEY created_at (created_at),
			KEY spam_score (spam_score),
			KEY site_id (site_id)
		) $charset_collate;";

        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core constant in global namespace.
        require_once \ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql); // dbDelta compares and adds missing columns without losing data.

        return true;
    }

    /**
     * Get user IP address.
     *
     * @return string
     */
    private function get_user_ip()
    {
        $ip = '';

        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_CLIENT_IP']));
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR']));
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        return $ip;
    }
}
