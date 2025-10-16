<?php

/**
 * Gravity Forms integration.
 *
 * @package GformSpamfighter
 */

namespace GformSpamfighter\Integration;

use GformSpamfighter\Core\Database;
use GformSpamfighter\Core\Logger;
use GformSpamfighter\Detection\OpenAI;
use GformSpamfighter\Detection\PatternAnalyzer;
use GformSpamfighter\Detection\BehaviorAnalyzer;

/**
 * GravityForms integration class.
 */
class GravityForms
{

    /**
     * Instance.
     *
     * @var GravityForms
     */
    private static $instance = null;

    /**
     * Settings.
     *
     * @var array
     */
    private $settings;

    /**
     * Get instance.
     *
     * @return GravityForms
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
        $this->settings = get_option('gform_spamfighter_settings', array());

        // Add validation hook with higher priority to run after GF's built-in spam checks.
        add_filter('gform_validation', array($this, 'validate_submission'), 20, 1);

        // Also hook into AJAX validation for AJAX-enabled forms.
        add_filter('gform_pre_submission', array($this, 'pre_submission_check'), 5, 1);

        // Add form render hook for timing.
        add_action('gform_enqueue_scripts', array($this, 'enqueue_form_scripts'), 10, 2);

        // Add custom field to track form render time.
        add_filter('gform_pre_render', array($this, 'add_timer_field'));

        // Debug: Log that integration is loaded (only if Logger is available).
        if (defined('WP_DEBUG') && WP_DEBUG && class_exists('GformSpamfighter\Core\Logger')) {
            Logger::get_instance()->info('Gravity Forms integration initialized');
        }
    }

    /**
     * Add timer field to form.
     *
     * @param array $form Form object.
     * @return array Modified form.
     */
    public function add_timer_field($form)
    {
        if (! $this->is_enabled()) {
            return $form;
        }

        return $form;
    }

    /**
     * Enqueue form scripts.
     *
     * @param array $form Form object.
     * @param bool  $is_ajax Is AJAX.
     */
    public function enqueue_form_scripts($form, $is_ajax)
    {
        if (! $this->is_enabled()) {
            return;
        }

        wp_enqueue_script(
            'gform-spamfighter-form',
            GFORM_SPAMFIGHTER_PLUGIN_URL . 'assets/js/form.js',
            array('jquery'),
            GFORM_SPAMFIGHTER_VERSION,
            true
        );

        // Check if user has strikes for this form
        $strikes = $this->get_strike_count($form['id']);

        wp_localize_script(
            'gform-spamfighter-form',
            'gformSpamfighterForm',
            array(
                'formId'       => $form['id'],
                'startTime'    => time(),
                'hasStrikes'   => $strikes >= 1,
                'strikeMessage' => __('This form has been temporarily locked due to a previous spam warning. Please wait 15 minutes or reload the page to try again.', 'gform-spamfighter'),
            )
        );
    }

    /**
     * Pre-submission check (runs before validation for AJAX forms).
     *
     * @param array $form Form object.
     */
    public function pre_submission_check($form)
    {
        if (class_exists('GformSpamfighter\Core\Logger')) {
            Logger::get_instance()->info('Pre-submission check called (AJAX forms)');
        }
        // This hook doesn't allow us to stop submission, but we log it
        // The actual blocking happens in validate_submission
    }

    /**
     * Validate submission.
     *
     * @param array $validation_result Validation result.
     * @return array Modified validation result.
     */
    public function validate_submission($validation_result)
    {
        // Debug: Log that validation hook was called.
        if (defined('WP_DEBUG') && WP_DEBUG && class_exists('GformSpamfighter\Core\Logger')) {
            Logger::get_instance()->info('Validation hook called');
        }

        if (! $this->is_enabled()) {
            if (class_exists('GformSpamfighter\Core\Logger')) {
                Logger::get_instance()->info('Spam protection is DISABLED in settings');
            }
            return $validation_result;
        }

        $form  = $validation_result['form'];
        $entry = $this->get_entry_data($form);

        if (class_exists('GformSpamfighter\Core\Logger')) {
            Logger::get_instance()->info(
                'Starting spam validation',
                array(
                    'form_id' => $form['id'],
                    'entry'   => $entry,
                )
            );
        }

        $spam_scores = array();
        $all_results = array();

        // Step 1: Run free/fast checks first (Pattern + Behavior)
        if ($this->is_pattern_check_enabled()) {
            $pattern_result = $this->check_patterns($entry);
            $spam_scores[]  = $pattern_result['score'];
            $all_results['pattern'] = $pattern_result;

            // Debug: Show what pattern check found
            error_log('GFORM: Pattern result: ' . print_r($pattern_result, true));

            if (class_exists('GformSpamfighter\Core\Logger')) {
                Logger::get_instance()->info(
                    'Pattern check completed',
                    array(
                        'score'   => $pattern_result['score'],
                        'is_spam' => $pattern_result['is_spam'],
                    )
                );
            }
        }

        if ($this->is_behavior_check_enabled()) {
            $behavior_result = $this->check_behavior($entry, $form['id']);
            $spam_scores[]   = $behavior_result['score'];
            $all_results['behavior'] = $behavior_result;

            if (class_exists('GformSpamfighter\Core\Logger')) {
                Logger::get_instance()->info(
                    'Behavior check completed',
                    array(
                        'score'   => $behavior_result['score'],
                        'is_spam' => $behavior_result['is_spam'],
                    )
                );
            }
        }

        if ($this->is_duplicate_check_enabled()) {
            $duplicate_result = $this->check_duplicate($entry, $form['id']);
            if ($duplicate_result['is_duplicate']) {
                $spam_scores[] = 1.0;
                $all_results['duplicate'] = $duplicate_result;

                if (class_exists('GformSpamfighter\Core\Logger')) {
                    Logger::get_instance()->info('Duplicate submission detected');
                }
            }
        }

        // Calculate score from free checks first
        $preliminary_score = empty($spam_scores) ? 0 : max($spam_scores);

        error_log('GFORM: Preliminary score (before OpenAI): ' . $preliminary_score);

        // Step 2: Only call OpenAI if preliminary checks are uncertain (score < threshold)
        // This saves API costs and time!
        $threshold = floatval($this->settings['ai_threshold'] ?? 0.7);

        if ($this->is_openai_enabled() && $preliminary_score < $threshold) {
            error_log('GFORM: Preliminary score uncertain (' . $preliminary_score . ' < ' . $threshold . ') - calling OpenAI...');

            $openai_result = $this->check_with_openai($entry, $form['id']);
            $spam_scores[] = $openai_result['score'];
            $all_results['openai'] = $openai_result;

            if (class_exists('GformSpamfighter\Core\Logger')) {
                Logger::get_instance()->info(
                    'OpenAI check completed',
                    array(
                        'score'   => $openai_result['score'],
                        'is_spam' => $openai_result['is_spam'],
                    )
                );
            }
        } elseif ($this->is_openai_enabled()) {
            error_log('GFORM: Pattern/Behavior already detected spam (' . $preliminary_score . ' >= ' . $threshold . ') - SKIPPING OpenAI! (Cost savings)');
        }

        // Calculate final score - use MAXIMUM score (if any method detects spam, it's spam!)
        $final_score = empty($spam_scores) ? 0 : max($spam_scores);

        $threshold = floatval($this->settings['ai_threshold'] ?? 0.7);
        $is_spam   = $final_score >= $threshold;

        // Check if ONLY soft warning (single link) was detected
        $only_soft_warning = $this->is_only_soft_warning($all_results);

        // Check strike count for this session/IP
        $strikes = $this->get_strike_count($form['id']);

        error_log('GFORM: only_soft_warning=' . ($only_soft_warning ? 'YES' : 'NO') . ', strikes=' . $strikes);

        // Decision logic:
        // 1) If only soft warning + no strikes yet → friendly warning, allow correction
        // 2) If soft warning + already has strike → block
        // 3) If hard spam (referrer + other OR high score) → block immediately
        if ($only_soft_warning && $strikes < 1) {
            // Log this soft warning for review
            $this->log_spam_attempt($form['id'], $entry, $final_score, $all_results, 'soft_warning');

            // Send notification if enabled (separate setting for soft warnings)
            if ($this->settings['notify_on_spam'] ?? false) {
                $this->send_notification($form, $entry, $final_score, $all_results, 'soft_warning');
            }

            // Give user a chance to correct
            $validation_result['is_valid'] = false;

            foreach ($form['fields'] as &$field) {
                if (!$field->failed_validation && $field->type === 'textarea') {
                    $field->failed_validation  = true;
                    $field->validation_message = apply_filters(
                        'gform_spamfighter_soft_warning_message',
                        __('Please remove any links from your message. Links are not allowed in this field.', 'gform-spamfighter')
                    );
                    break;
                }
            }

            // Record soft warning (add strike)
            $this->add_strike($form['id']);

            $validation_result['form'] = $form;
            return $validation_result;
        }

        // If has strikes → force block (2nd attempt after soft warning)
        if ($strikes >= 1) {
            $is_spam = true;
            error_log('GFORM: User has strikes (' . $strikes . ') - FORCING BLOCK!');
        }

        if (class_exists('GformSpamfighter\Core\Logger')) {
            Logger::get_instance()->info(
                'Final spam verdict',
                array(
                    'final_score' => $final_score,
                    'threshold'   => $threshold,
                    'is_spam'     => $is_spam,
                    'strikes'     => $strikes,
                )
            );
        }

        if ($is_spam) {
            error_log('GFORM: BLOCKING submission as spam!');
            // Log spam attempt to database.
            $log_result = $this->log_spam_attempt($form['id'], $entry, $final_score, $all_results);

            // Debug: Verify logging worked
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('GFORM: Spam logged to database. Insert ID: ' . ($log_result ? $log_result : 'FAILED'));
            }

            // Send notification if enabled.
            if ($this->settings['notify_on_spam'] ?? false) {
                $this->send_notification($form, $entry, $final_score, $all_results);
            }

            // Handle based on block action.
            $block_action = $this->settings['block_action'] ?? 'reject';

            if ('reject' === $block_action) {
                $validation_result['is_valid'] = false;

                foreach ($form['fields'] as &$field) {
                    if (! $field->failed_validation) {
                        $field->failed_validation  = true;
                        $field->validation_message = apply_filters(
                            'gform_spamfighter_validation_message',
                            __('Your submission has been identified as spam. If you believe this is an error, please contact us directly.', 'gform-spamfighter')
                        );
                        break; // Only show error on first field.
                    }
                }
            } else {
                // Mark as spam but allow submission.
                add_filter(
                    'gform_entry_is_spam',
                    function ($is_spam, $form_obj, $entry_obj) use ($form) {
                        if ($form_obj['id'] === $form['id']) {
                            return true;
                        }
                        return $is_spam;
                    },
                    10,
                    3
                );
            }
        }

        $validation_result['form'] = $form;

        return $validation_result;
    }

    /**
     * Get entry data from form.
     *
     * @param array $form Form object.
     * @return array Entry data.
     */
    private function get_entry_data($form)
    {
        $entry = array();
        $grouped = array(
            'text'     => array(),
            'textarea' => array(),
            'email'    => array(),
            'website'  => array(),
        );

        foreach ($form['fields'] as $field) {
            // During validation, we need to get values from POST, not from GFFormsModel
            $input_name = 'input_' . $field->id;

            // Try multiple methods to get the value
            $value = null;

            // Method 1: Direct POST value
            if (isset($_POST[$input_name])) {
                $value = $_POST[$input_name];
            }

            // Method 2: Try GF's method
            if (empty($value)) {
                $value = \GFFormsModel::get_field_value($field);
            }

            // Method 3: Check for specific field types
            if (empty($value) && isset($field->inputs) && is_array($field->inputs)) {
                // Complex field (name, address, etc.)
                $complex_value = array();
                foreach ($field->inputs as $input) {
                    $input_id = str_replace('.', '_', $input['id']);
                    if (isset($_POST['input_' . $input_id])) {
                        $complex_value[] = $_POST['input_' . $input_id];
                    }
                }
                if (!empty($complex_value)) {
                    $value = implode(' ', $complex_value);
                }
            }

            // Sanitize and store
            if (!empty($value)) {
                if (is_array($value)) {
                    $value = implode(' ', array_map('sanitize_text_field', $value));
                } else {
                    $value = sanitize_text_field(wp_unslash($value));
                }

                // Store with field label for better spam detection
                $field_label = !empty($field->label) ? $field->label : 'field_' . $field->id;
                $entry[$field_label] = $value;
                $entry['field_' . $field->id] = $value; // Also store by ID

                // Group by GF field type for targeted checks
                $type = method_exists($field, 'get_input_type') ? $field->get_input_type() : (property_exists($field, 'type') ? $field->type : 'text');
                switch ($type) {
                    case 'email':
                        $grouped['email'][] = $value;
                        break;
                    case 'website':
                    case 'url':
                        $grouped['website'][] = $value;
                        break;
                    case 'textarea':
                        $grouped['textarea'][] = $value;
                        break;
                    case 'text':
                    default:
                        $grouped['text'][] = $value;
                        break;
                }
            }
        }

        // Attach grouped values for analyzers
        $entry['_grouped'] = $grouped;

        // Debug: Log what we got
        if (defined('WP_DEBUG') && WP_DEBUG && class_exists('GformSpamfighter\Core\Logger')) {
            Logger::get_instance()->info(
                'Extracted entry data',
                array(
                    'entry_count' => count($entry),
                    'entry_data'  => $entry,
                )
            );
        }

        return $entry;
    }

    /**
     * Check with OpenAI.
     *
     * @param array $entry Entry data.
     * @param int   $form_id Form ID.
     * @return array Result.
     */
    private function check_with_openai($entry, $form_id)
    {
        $api_key = $this->settings['openai_api_key'] ?? '';
        $model   = $this->settings['openai_model'] ?? 'gpt-4o-mini';

        $detector = new OpenAI($api_key, $model);

        $locale = get_locale();
        $lang   = substr($locale, 0, 2);

        return $detector->analyze($entry, $lang);
    }

    /**
     * Check patterns.
     *
     * @param array $entry Entry data.
     * @return array Result.
     */
    private function check_patterns($entry)
    {
        $analyzer = new PatternAnalyzer();
        return $analyzer->analyze($entry);
    }

    /**
     * Check behavior.
     *
     * @param array $entry Entry data.
     * @param int   $form_id Form ID.
     * @return array Result.
     */
    private function check_behavior($entry, $form_id)
    {
        $analyzer = new BehaviorAnalyzer();
        return $analyzer->analyze($entry, $form_id);
    }

    /**
     * Check for duplicates.
     *
     * @param array $entry Entry data.
     * @param int   $form_id Form ID.
     * @return array Result.
     */
    private function check_duplicate($entry, $form_id)
    {
        $db        = Database::get_instance();
        $hours     = intval($this->settings['duplicate_check_timeframe'] ?? 24);
        $is_duplicate = $db->check_duplicate($entry, $form_id, $hours);

        return array(
            'is_duplicate' => $is_duplicate,
            'timeframe'    => $hours,
        );
    }

    /**
     * Log spam attempt.
     *
     * @param int    $form_id Form ID.
     * @param array  $entry Entry data.
     * @param float  $score Spam score.
     * @param array  $results Detection results.
     * @param string $action_taken Action taken (rejected, soft_warning, marked).
     */
    private function log_spam_attempt($form_id, $entry, $score, $results, $action_taken = null)
    {
        $db = Database::get_instance();

        $detection_methods = array();
        foreach ($results as $method => $result) {
            if (isset($result['is_spam']) && $result['is_spam']) {
                $detection_methods[] = $method;
            }
        }

        // Determine action if not explicitly set
        if (null === $action_taken) {
            $action_taken = $this->settings['block_action'] ?? 'rejected';
        }

        $insert_id = $db->log_spam(
            array(
                'form_id'           => $form_id,
                'entry_data'        => $entry,
                'spam_score'        => $score,
                'detection_method'  => implode(', ', $detection_methods),
                'detection_details' => $results,
                'action_taken'      => $action_taken,
            )
        );

        return $insert_id;
    }

    /**
     * Send notification.
     *
     * @param array  $form Form object.
     * @param array  $entry Entry data.
     * @param float  $score Spam score.
     * @param array  $results Detection results.
     * @param string $action_type Type of action (rejected, soft_warning).
     */
    private function send_notification($form, $entry, $score, $results, $action_type = 'rejected')
    {
        $to = $this->settings['notification_email'] ?? get_option('admin_email');

        if ('soft_warning' === $action_type) {
            $subject = sprintf(
                /* translators: %s: Form title */
                __('[Warning] Link in form submission: %s', 'gform-spamfighter'),
                $form['title']
            );

            /* translators: 1: Form title, 2: Site name, 3: Timestamp, 4: Spam score, 5: Submitted data, 6: Detection details */
            $message = sprintf(
                __("A form submission received a warning (user can still correct).\n\nForm: %1\$s\nSite: %2\$s\nTime: %3\$s\nScore: %4\$.2f\nWarning: Single link in message field\n\n=== Submitted Data ===\n%5\$s\n\n=== Detection Details ===\n%6\$s", 'gform-spamfighter'),
                $form['title'],
                get_bloginfo('name'),
                gmdate('Y-m-d H:i:s'),
                $score,
                print_r($entry, true), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
                print_r($results, true) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
            );
        } else {
            $subject = sprintf(
                /* translators: %s: Form title */
                __('[BLOCKED] Spam detected in form: %s', 'gform-spamfighter'),
                $form['title']
            );

            /* translators: 1: Spam score, 2: Form title, 3: Site name, 4: Timestamp, 5: Submitted data, 6: Detection details */
            $message = sprintf(
                __("A spam submission was BLOCKED with a score of %1\$.2f.\n\nForm: %2\$s\nSite: %3\$s\nTime: %4\$s\n\n=== Submitted Data ===\n%5\$s\n\n=== Detection Details ===\n%6\$s", 'gform-spamfighter'),
                $score,
                $form['title'],
                get_bloginfo('name'),
                gmdate('Y-m-d H:i:s'),
                print_r($entry, true), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
                print_r($results, true) // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
            );
        }

        wp_mail($to, $subject, $message);
    }

    /**
     * Check if plugin is enabled.
     *
     * @return bool
     */
    private function is_enabled()
    {
        $enabled = ! empty($this->settings['enabled']);

        // Debug logging.
        if (defined('WP_DEBUG') && WP_DEBUG && class_exists('GformSpamfighter\Core\Logger')) {
            Logger::get_instance()->info(
                'Checking if spam protection is enabled',
                array(
                    'enabled_setting' => $this->settings['enabled'] ?? 'NOT SET',
                    'result'          => $enabled ? 'YES' : 'NO',
                    'all_settings'    => $this->settings,
                )
            );
        }

        return $enabled;
    }

    /**
     * Check if OpenAI is enabled.
     *
     * @return bool
     */
    private function is_openai_enabled()
    {
        return ! empty($this->settings['openai_enabled']) && ! empty($this->settings['openai_api_key']);
    }

    /**
     * Check if pattern check is enabled.
     *
     * @return bool
     */
    private function is_pattern_check_enabled()
    {
        return ! empty($this->settings['pattern_check_enabled']);
    }

    /**
     * Check if behavior check is enabled.
     *
     * @return bool
     */
    private function is_behavior_check_enabled()
    {
        return ! empty($this->settings['time_check_enabled']) || ! empty($this->settings['language_check_enabled']);
    }

    /**
     * Check if duplicate check is enabled.
     *
     * @return bool
     */
    private function is_duplicate_check_enabled()
    {
        return ! empty($this->settings['duplicate_check_enabled']);
    }

    /**
     * Check if only soft warning was detected (single link in textarea).
     *
     * @param array $results All detection results.
     * @return bool
     */
    private function is_only_soft_warning($results)
    {
        $has_soft_warning = false;
        $has_hard_spam    = false;

        foreach ($results as $method => $result) {
            // Check in details array for soft_warning flag
            if (isset($result['details']) && is_array($result['details'])) {
                foreach ($result['details'] as $check_name => $check_result) {
                    if (isset($check_result['soft_warning']) && $check_result['soft_warning']) {
                        $has_soft_warning = true;
                    }

                    // Any other detection with high score = hard spam
                    if (
                        isset($check_result['detected']) && $check_result['detected'] &&
                        (!isset($check_result['soft_warning']) || !$check_result['soft_warning'])
                    ) {
                        // Check if score is high enough to be considered hard spam
                        if (isset($check_result['score']) && $check_result['score'] >= 30) {
                            $has_hard_spam = true;
                        }
                    }
                }
            }
        }

        error_log('GFORM: is_only_soft_warning - has_soft=' . ($has_soft_warning ? 'YES' : 'NO') . ', has_hard=' . ($has_hard_spam ? 'YES' : 'NO'));

        // Only soft warning if we have soft warning and NO hard spam
        return $has_soft_warning && !$has_hard_spam;
    }

    /**
     * Get strike count for current session/IP.
     *
     * @param int $form_id Form ID.
     * @return int Number of strikes.
     */
    private function get_strike_count($form_id)
    {
        $key = $this->get_strike_key($form_id);
        $strikes = get_transient($key);

        return $strikes ? intval($strikes) : 0;
    }

    /**
     * Add a strike for current session/IP.
     *
     * @param int $form_id Form ID.
     */
    private function add_strike($form_id)
    {
        $key     = $this->get_strike_key($form_id);
        $strikes = $this->get_strike_count($form_id);

        // Increment and store for 15 minutes (good balance: deters spammers, allows real users to retry)
        $lockout_time = apply_filters('gform_spamfighter_strike_lockout_seconds', 15 * MINUTE_IN_SECONDS);
        set_transient($key, $strikes + 1, $lockout_time);
    }

    /**
     * Get unique strike key for session/IP/form combination.
     *
     * @param int $form_id Form ID.
     * @return string
     */
    private function get_strike_key($form_id)
    {
        $ip = '';
        if (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
        }

        $session_id = session_id();
        if (empty($session_id) && function_exists('wp_get_session_token')) {
            $session_id = wp_get_session_token();
        }

        return 'gform_spam_strike_' . md5($form_id . $ip . $session_id);
    }
}
