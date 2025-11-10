<?php

/**
 * Admin settings page.
 *
 * @package GformSpamfighter
 */

namespace GformSpamfighter\Admin;

/**
 * Settings class.
 */
class Settings
{

    /**
     * Instance.
     *
     * @var Settings
     */
    private static $instance = null;

    /**
     * Settings option name.
     *
     * @var string
     */
    private $option_name = 'gform_spamfighter_settings';

    /**
     * Get instance.
     *
     * @return Settings
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
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_gform_spamfighter_test_api', array($this, 'ajax_test_connection'));
    }

    /**
     * Add admin menu.
     */
    public function add_menu()
    {
        // Register settings as a submenu under the top-level Spamfighter menu
        add_submenu_page(
            'gform-spamfighter-dashboard',
            __('Settings', 'gform-spamfighter'),
            __('Settings', 'gform-spamfighter'),
            'manage_options',
            'gform-spamfighter-settings',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Enqueue admin scripts.
     *
     * @param string $hook Page hook.
     */
    public function enqueue_scripts($hook)
    {
        // Load on any Spamfighter admin page (dashboard, logs, settings, classifier)
        if (strpos($hook, 'gform-spamfighter') === false) {
            return;
        }

        wp_enqueue_style(
            'gform-spamfighter-admin',
            GFORM_SPAMFIGHTER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            GFORM_SPAMFIGHTER_VERSION
        );

        wp_enqueue_script(
            'gform-spamfighter-admin',
            GFORM_SPAMFIGHTER_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            GFORM_SPAMFIGHTER_VERSION,
            true
        );

        wp_localize_script(
            'gform-spamfighter-admin',
            'gformSpamfighterAdmin',
            array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('gform_spamfighter_nonce'),
            )
        );
    }

    /**
     * Register settings.
     */
    public function register_settings()
    {
        register_setting(
            'gform_spamfighter_settings_group',
            $this->option_name,
            array($this, 'sanitize_settings')
        );

        // General section.
        add_settings_section(
            'gform_spamfighter_general',
            __('General Settings', 'gform-spamfighter'),
            array($this, 'render_general_section'),
            'gform-spamfighter'
        );

        add_settings_field(
            'enabled',
            __('Enable Spam Protection', 'gform-spamfighter'),
            array($this, 'render_checkbox_field'),
            'gform-spamfighter',
            'gform_spamfighter_general',
            array(
                'field_id'    => 'enabled',
                'description' => __('Enable or disable all spam protection features', 'gform-spamfighter'),
            )
        );

        add_settings_field(
            'block_action',
            __('Block Action', 'gform-spamfighter'),
            array($this, 'render_select_field'),
            'gform-spamfighter',
            'gform_spamfighter_general',
            array(
                'field_id'    => 'block_action',
                'options'     => array(
                    'mark'   => __('Mark as spam (allow submission)', 'gform-spamfighter'),
                    'reject' => __('Reject submission (show error)', 'gform-spamfighter'),
                ),
                'description' => __('How to handle detected spam', 'gform-spamfighter'),
            )
        );

        // OpenAI section.
        add_settings_section(
            'gform_spamfighter_openai',
            __('OpenAI Integration', 'gform-spamfighter'),
            array($this, 'render_openai_section'),
            'gform-spamfighter'
        );

        add_settings_field(
            'openai_enabled',
            __('Enable OpenAI Detection', 'gform-spamfighter'),
            array($this, 'render_checkbox_field'),
            'gform-spamfighter',
            'gform_spamfighter_openai',
            array(
                'field_id'    => 'openai_enabled',
                'description' => __('Use OpenAI to detect AI-generated spam', 'gform-spamfighter'),
            )
        );

        add_settings_field(
            'openai_api_key',
            __('OpenAI API Key', 'gform-spamfighter'),
            array($this, 'render_text_field'),
            'gform-spamfighter',
            'gform_spamfighter_openai',
            array(
                'field_id'    => 'openai_api_key',
                'type'        => 'password',
                'description' => sprintf(
                    /* translators: %s: OpenAI API URL */
                    __('Get your API key from <a href="%s" target="_blank">OpenAI Platform</a>', 'gform-spamfighter'),
                    'https://platform.openai.com/api-keys'
                ),
            )
        );

        add_settings_field(
            'openai_model',
            __('OpenAI Model', 'gform-spamfighter'),
            array($this, 'render_select_field'),
            'gform-spamfighter',
            'gform_spamfighter_openai',
            array(
                'field_id'    => 'openai_model',
                'options'     => array(
                    'gpt-4o-mini'   => 'GPT-4o Mini (recommended, ~$0.15/1000 checks)',
                    'gpt-5-mini'    => 'GPT-5 mini (new, faster, ~$0.30/1000 checks)',
                    'gpt-4o'        => 'GPT-4o (highest accuracy, ~$7.50/1000 checks)',
                    'gpt-5'         => 'GPT-5 (latest, most powerful, ~$15/1000 checks)',
                    'gpt-4-turbo'   => 'GPT-4 Turbo (legacy)',
                    'gpt-3.5-turbo' => 'GPT-3.5 Turbo (legacy, cheapest)',
                ),
                'description' => __('Which AI model to use for spam detection. GPT-4o Mini offers the best cost/performance ratio. <strong>Note:</strong> Cost estimates are approximate and may vary. We cannot guarantee pricing accuracy.', 'gform-spamfighter'),
            )
        );

        add_settings_field(
            'ai_threshold',
            __('AI Spam Threshold', 'gform-spamfighter'),
            array($this, 'render_number_field'),
            'gform-spamfighter',
            'gform_spamfighter_openai',
            array(
                'field_id'    => 'ai_threshold',
                'min'         => 0,
                'max'         => 1,
                'step'        => 0.1,
                'description' => __('Spam score threshold (0.0 - 1.0). Higher = stricter. Default: 0.7', 'gform-spamfighter'),
            )
        );

        if (apply_filters('gform_spamfighter_show_pattern_settings', false)) {
            add_settings_section(
                'gform_spamfighter_pattern',
                __('Pattern Detection', 'gform-spamfighter'),
                array($this, 'render_pattern_section'),
                'gform-spamfighter'
            );

            add_settings_field(
                'pattern_check_enabled',
                __('Enable Pattern Detection', 'gform-spamfighter'),
                array($this, 'render_checkbox_field'),
                'gform-spamfighter',
                'gform_spamfighter_pattern',
                array(
                    'field_id'    => 'pattern_check_enabled',
                    'description' => __('Check for suspicious patterns, keywords, and links', 'gform-spamfighter'),
                )
            );

            add_settings_field(
                'exclude_hidden_fields',
                __('Exclude Hidden Fields', 'gform-spamfighter'),
                array($this, 'render_checkbox_field'),
                'gform-spamfighter',
                'gform_spamfighter_pattern',
                array(
                    'field_id'    => 'exclude_hidden_fields',
                    'description' => __('Exclude all hidden fields from spam analysis (recommended for campaign/tracking fields)', 'gform-spamfighter'),
                )
            );
        }

        if (apply_filters('gform_spamfighter_show_behavior_settings', false)) {
            add_settings_section(
                'gform_spamfighter_behavior',
                __('Behavior Analysis', 'gform-spamfighter'),
                array($this, 'render_behavior_section'),
                'gform-spamfighter'
            );

            add_settings_field(
                'time_check_enabled',
                __('Enable Time Check', 'gform-spamfighter'),
                array($this, 'render_checkbox_field'),
                'gform-spamfighter',
                'gform_spamfighter_behavior',
                array(
                    'field_id'    => 'time_check_enabled',
                    'description' => __('Block submissions that are too fast', 'gform-spamfighter'),
                )
            );

            add_settings_field(
                'min_submission_time',
                __('Minimum Submission Time', 'gform-spamfighter'),
                array($this, 'render_number_field'),
                'gform-spamfighter',
                'gform_spamfighter_behavior',
                array(
                    'field_id'    => 'min_submission_time',
                    'min'         => 1,
                    'max'         => 60,
                    'step'        => 1,
                    'description' => __('Minimum seconds before submission is allowed (default: 3)', 'gform-spamfighter'),
                )
            );

            add_settings_field(
                'language_check_enabled',
                __('Enable Language Check', 'gform-spamfighter'),
                array($this, 'render_checkbox_field'),
                'gform-spamfighter',
                'gform_spamfighter_behavior',
                array(
                    'field_id'    => 'language_check_enabled',
                    'description' => __('Check if submission language matches site language', 'gform-spamfighter'),
                )
            );

            add_settings_field(
                'duplicate_check_enabled',
                __('Enable Duplicate Check', 'gform-spamfighter'),
                array($this, 'render_checkbox_field'),
                'gform-spamfighter',
                'gform_spamfighter_behavior',
                array(
                    'field_id'    => 'duplicate_check_enabled',
                    'description' => __('Block duplicate submissions from same IP', 'gform-spamfighter'),
                )
            );

            add_settings_field(
                'duplicate_check_timeframe',
                __('Duplicate Check Timeframe', 'gform-spamfighter'),
                array($this, 'render_number_field'),
                'gform-spamfighter',
                'gform_spamfighter_behavior',
                array(
                    'field_id'    => 'duplicate_check_timeframe',
                    'min'         => 1,
                    'max'         => 168,
                    'step'        => 1,
                    'description' => __('Hours to look back for duplicates (default: 24)', 'gform-spamfighter'),
                )
            );
        }

        // Notifications section.
        add_settings_section(
            'gform_spamfighter_notifications',
            __('Notifications', 'gform-spamfighter'),
            array($this, 'render_notifications_section'),
            'gform-spamfighter'
        );

        add_settings_field(
            'notify_on_spam',
            __('Email Notifications', 'gform-spamfighter'),
            array($this, 'render_checkbox_field'),
            'gform-spamfighter',
            'gform_spamfighter_notifications',
            array(
                'field_id'    => 'notify_on_spam',
                'description' => __('Send email when spam is detected', 'gform-spamfighter'),
            )
        );

        add_settings_field(
            'notification_email',
            __('Notification Email', 'gform-spamfighter'),
            array($this, 'render_text_field'),
            'gform-spamfighter',
            'gform_spamfighter_notifications',
            array(
                'field_id'    => 'notification_email',
                'type'        => 'email',
                'description' => __('Email address for notifications', 'gform-spamfighter'),
            )
        );

        // Maintenance section.
        add_settings_section(
            'gform_spamfighter_maintenance',
            __('Maintenance', 'gform-spamfighter'),
            array($this, 'render_maintenance_section'),
            'gform-spamfighter'
        );

        add_settings_field(
            'log_retention_days',
            __('Log Retention', 'gform-spamfighter'),
            array($this, 'render_number_field'),
            'gform-spamfighter',
            'gform_spamfighter_maintenance',
            array(
                'field_id'    => 'log_retention_days',
                'min'         => 7,
                'max'         => 365,
                'step'        => 1,
                'description' => __('Days to keep spam logs (default: 30)', 'gform-spamfighter'),
            )
        );
    }

    /**
     * Render general section.
     */
    public function render_general_section()
    {
        echo '<p>' . esc_html__('Configure general spam protection settings.', 'gform-spamfighter') . '</p>';
    }

    /**
     * Render OpenAI section.
     */
    public function render_openai_section()
    {
        echo '<p>' . esc_html__('Use OpenAI\'s GPT models to detect AI-generated spam with high accuracy.', 'gform-spamfighter') . '</p>';
    }

    /**
     * Render pattern section.
     */
    public function render_pattern_section()
    {
        echo '<p>' . esc_html__('Detect spam based on suspicious patterns, keywords, and links.', 'gform-spamfighter') . '</p>';
    }

    /**
     * Render behavior section.
     */
    public function render_behavior_section()
    {
        echo '<p>' . esc_html__('Analyze submission behavior to detect automated spam.', 'gform-spamfighter') . '</p>';
    }

    /**
     * Render notifications section.
     */
    public function render_notifications_section()
    {
        echo '<p>' . esc_html__('Configure email notifications for spam detection.', 'gform-spamfighter') . '</p>';
    }

    /**
     * Render maintenance section.
     */
    public function render_maintenance_section()
    {
        echo '<p>' . esc_html__('Manage database cleanup and maintenance.', 'gform-spamfighter') . '</p>';
    }

    /**
     * Render checkbox field.
     *
     * @param array $args Field arguments.
     */
    public function render_checkbox_field($args)
    {
        $settings = get_option($this->option_name, array());
        $value    = isset($settings[$args['field_id']]) ? $settings[$args['field_id']] : false;

        printf(
            '<label><input type="checkbox" name="%s[%s]" value="1" %s /> %s</label>',
            esc_attr($this->option_name),
            esc_attr($args['field_id']),
            checked($value, true, false),
            isset($args['description']) ? wp_kses_post($args['description']) : ''
        );
    }

    /**
     * Render text field.
     *
     * @param array $args Field arguments.
     */
    public function render_text_field($args)
    {
        $settings = get_option($this->option_name, array());
        $value    = isset($settings[$args['field_id']]) ? $settings[$args['field_id']] : '';
        $type     = isset($args['type']) ? $args['type'] : 'text';

        printf(
            '<input type="%s" name="%s[%s]" value="%s" class="regular-text" />',
            esc_attr($type),
            esc_attr($this->option_name),
            esc_attr($args['field_id']),
            esc_attr($value)
        );

        if (isset($args['description'])) {
            printf('<p class="description">%s</p>', wp_kses_post($args['description']));
        }
    }

    /**
     * Render number field.
     *
     * @param array $args Field arguments.
     */
    public function render_number_field($args)
    {
        $settings = get_option($this->option_name, array());
        $value    = isset($settings[$args['field_id']]) ? $settings[$args['field_id']] : '';

        printf(
            '<input type="number" name="%s[%s]" value="%s" min="%s" max="%s" step="%s" />',
            esc_attr($this->option_name),
            esc_attr($args['field_id']),
            esc_attr($value),
            esc_attr($args['min']),
            esc_attr($args['max']),
            esc_attr($args['step'])
        );

        if (isset($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    /**
     * Render select field.
     *
     * @param array $args Field arguments.
     */
    public function render_select_field($args)
    {
        $settings = get_option($this->option_name, array());
        $value    = isset($settings[$args['field_id']]) ? $settings[$args['field_id']] : '';

        printf(
            '<select name="%s[%s]">',
            esc_attr($this->option_name),
            esc_attr($args['field_id'])
        );

        foreach ($args['options'] as $option_value => $option_label) {
            printf(
                '<option value="%s" %s>%s</option>',
                esc_attr($option_value),
                selected($value, $option_value, false),
                esc_html($option_label)
            );
        }

        echo '</select>';

        if (isset($args['description'])) {
            printf('<p class="description">%s</p>', esc_html($args['description']));
        }
    }

    /**
     * Render settings page.
     */
    public function render_settings_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

        // Handle test API connection.
        if (isset($_POST['test_openai_connection']) && check_admin_referer('gform_spamfighter_test_api')) {
            $this->test_openai_connection();
        }

?>
        <div class="wrap">
            <h1><?php esc_html_e('Spamfighter Settings', 'gform-spamfighter'); ?></h1>

            <?php settings_errors(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('gform_spamfighter_settings_group');
                do_settings_sections('gform-spamfighter');
                submit_button();
                ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Test OpenAI Connection', 'gform-spamfighter'); ?></h2>
            <div>
                <p>
                    <?php esc_html_e('Test your OpenAI API connection and configuration.', 'gform-spamfighter'); ?>
                </p>
                <button type="button" id="gform-test-api-btn" class="button button-secondary">
                    <?php esc_html_e('Test Connection', 'gform-spamfighter'); ?>
                </button>
                <span id="gform-test-api-result" style="margin-left:15px;"></span>
            </div>
        </div>
<?php
    }

    /**
     * Test OpenAI connection.
     */
    private function test_openai_connection()
    {
        error_log('GFORM: test_openai_connection CALLED');

        // Additional capability check for security.
        if (! current_user_can('manage_options')) {
            error_log('GFORM: No manage_options capability');
            return;
        }

        $settings = get_option($this->option_name, array());
        $api_key  = $settings['openai_api_key'] ?? '';
        $model    = $settings['openai_model'] ?? 'gpt-4o-mini';

        error_log('GFORM: API Key present: ' . (empty($api_key) ? 'NO' : 'YES'));
        error_log('GFORM: Model: ' . $model);

        if (empty($api_key)) {
            add_settings_error(
                'gform_spamfighter_messages',
                'api_key_missing',
                __('Please configure your OpenAI API key first.', 'gform-spamfighter'),
                'error'
            );
            return;
        }

        error_log('GFORM: Creating OpenAI detector...');

        try {
            $detector = new \GformSpamfighter\Detection\OpenAI($api_key, $model);

            error_log('GFORM: Calling analyze...');

            $result = $detector->analyze(
                array(
                    'message' => 'This is a test message to verify the API connection.',
                ),
                'en'
            );

            error_log('GFORM: Analyze returned: ' . print_r($result, true));

            if (isset($result['error']) && $result['error']) {
                add_settings_error(
                    'gform_spamfighter_messages',
                    'api_test_failed',
                    sprintf(
                        /* translators: %s: Error message */
                        __('OpenAI API test failed: %s', 'gform-spamfighter'),
                        $result['reason']
                    ),
                    'error'
                );
            } else {
                add_settings_error(
                    'gform_spamfighter_messages',
                    'api_test_success',
                    sprintf(
                        /* translators: %s: Spam score */
                        __('OpenAI API connection successful! Test spam score: %.2f', 'gform-spamfighter'),
                        $result['score']
                    ),
                    'success'
                );
            }
        } catch (\Exception $e) {
            error_log('GFORM: EXCEPTION: ' . $e->getMessage());
            add_settings_error(
                'gform_spamfighter_messages',
                'api_test_exception',
                sprintf(
                    /* translators: %s: Error message */
                    __('OpenAI API test exception: %s', 'gform-spamfighter'),
                    $e->getMessage()
                ),
                'error'
            );
        }

        error_log('GFORM: test_openai_connection FINISHED');
    }

    /**
     * AJAX handler for testing OpenAI connection.
     */
    public function ajax_test_connection()
    {
        error_log('GFORM AJAX: test connection called');

        check_ajax_referer('gform_spamfighter_nonce', 'nonce');

        if (! current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $settings = get_option($this->option_name, array());
        $api_key  = $settings['openai_api_key'] ?? '';
        $model    = $settings['openai_model'] ?? 'gpt-4o-mini';

        error_log('GFORM AJAX: API Key present: ' . (empty($api_key) ? 'NO' : 'YES'));
        error_log('GFORM AJAX: Model: ' . $model);

        if (empty($api_key)) {
            wp_send_json_error(array('message' => 'Please configure your OpenAI API key first.'));
        }

        // Check if OpenAI class exists
        if (!class_exists('\GformSpamfighter\Detection\OpenAI')) {
            error_log('GFORM AJAX: OpenAI class NOT FOUND!');
            wp_send_json_error(array('message' => 'Error: OpenAI class not found. Check includes/detection/class-openai.php'));
            return;
        }

        error_log('GFORM AJAX: OpenAI class exists, creating instance...');

        try {
            $detector = new \GformSpamfighter\Detection\OpenAI($api_key, $model);
            error_log('GFORM AJAX: Detector created successfully');

            $result = $detector->analyze(
                array(
                    'message' => 'This is a test message to verify the API connection.',
                ),
                'en'
            );

            error_log('GFORM AJAX: Result: ' . print_r($result, true));

            if (isset($result['error']) && $result['error']) {
                wp_send_json_error(array(
                    'message' => sprintf('OpenAI API test failed: %s', $result['reason']),
                ));
            } else {
                wp_send_json_success(array(
                    'message' => sprintf('OpenAI API connection successful! Test spam score: %.2f', $result['score']),
                    'score'   => $result['score'],
                    'details' => $result,
                ));
            }
        } catch (\Exception $e) {
            error_log('GFORM AJAX: Exception: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'Exception: ' . $e->getMessage(),
            ));
        }
    }

    /**
     * Sanitize settings.
     *
     * @param array $input Input settings.
     * @return array Sanitized settings.
     */
    public function sanitize_settings($input)
    {
        $sanitized = array();

        // Boolean fields.
        $boolean_fields = array(
            'enabled',
            'openai_enabled',
            'time_check_enabled',
            'pattern_check_enabled',
            'exclude_hidden_fields',
            'language_check_enabled',
            'duplicate_check_enabled',
            'notify_on_spam',
        );

        foreach ($boolean_fields as $field) {
            $sanitized[$field] = isset($input[$field]) ? (bool) $input[$field] : false;
        }

        // Text fields.
        if (isset($input['openai_api_key'])) {
            $sanitized['openai_api_key'] = sanitize_text_field($input['openai_api_key']);
        }

        // Whitelist for OpenAI model.
        if (isset($input['openai_model'])) {
            $allowed_models = array('gpt-4o-mini', 'gpt-5-mini', 'gpt-4o', 'gpt-5', 'gpt-4-turbo', 'gpt-3.5-turbo');
            $model          = sanitize_text_field($input['openai_model']);
            $sanitized['openai_model'] = in_array($model, $allowed_models, true) ? $model : 'gpt-4o-mini';
        }

        // Whitelist for block action.
        if (isset($input['block_action'])) {
            $allowed_actions = array('reject', 'mark');
            $action          = sanitize_text_field($input['block_action']);
            $sanitized['block_action'] = in_array($action, $allowed_actions, true) ? $action : 'reject';
        }

        if (isset($input['notification_email'])) {
            $email = sanitize_email($input['notification_email']);
            if (is_email($email)) {
                $sanitized['notification_email'] = $email;
            }
        }

        // Numeric fields.
        if (isset($input['ai_threshold'])) {
            $sanitized['ai_threshold'] = min(max(floatval($input['ai_threshold']), 0), 1);
        }

        if (isset($input['min_submission_time'])) {
            $sanitized['min_submission_time'] = max(intval($input['min_submission_time']), 1);
        }

        if (isset($input['duplicate_check_timeframe'])) {
            $sanitized['duplicate_check_timeframe'] = max(intval($input['duplicate_check_timeframe']), 1);
        }

        if (isset($input['log_retention_days'])) {
            $sanitized['log_retention_days'] = max(intval($input['log_retention_days']), 7);
        }

        return $sanitized;
    }
}
