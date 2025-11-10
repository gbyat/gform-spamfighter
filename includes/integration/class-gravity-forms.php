<?php

namespace GformSpamfighter\Integration;

use GformSpamfighter\Core\Database;
use GformSpamfighter\Detection\OpenAI;

/**
 * Gravity Forms integration (OpenAI-only).
 */
class GravityForms
{
    private static $instance = null;

    private $settings = array();

    private $flagged_forms = array();

    private $flagged_entries = array();

    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->settings = get_option('gform_spamfighter_settings', array());

        add_filter('gform_validation', array($this, 'validate_submission'), 999, 1);
        add_action('gform_after_submission', array($this, 'handle_after_submission'), 10, 2);
        add_filter('gform_disable_notification', array($this, 'disable_notifications_for_spam'), 10, 4);
        add_filter('gform_confirmation', array($this, 'maybe_override_confirmation'), 10, 4);
        add_filter('gform_entry_is_spam', array($this, 'mark_entry_as_spam'), 10, 3);
    }

    public function validate_submission($validation_result)
    {
        if (! $this->is_enabled() || ! $this->is_openai_enabled()) {
            return $validation_result;
        }

        if (isset($validation_result['is_valid']) && false === $validation_result['is_valid']) {
            return $validation_result;
        }

        $form    = $validation_result['form'];
        $form_id = isset($form['id']) ? (int) $form['id'] : 0;
        if ($form_id <= 0) {
            return $validation_result;
        }

        $entry_data    = $this->get_entry_data($form);
        $openai_result = $this->check_with_openai($entry_data, $form_id);
        $score         = isset($openai_result['score']) ? (float) $openai_result['score'] : 0.0;
        $threshold     = (float) ($this->settings['ai_threshold'] ?? 0.7);

        $flag = ! empty($openai_result['is_spam']);
        if (! $flag && $score >= $threshold) {
            $flag = true;
        }

        if ($flag) {
            $details = array('openai' => $openai_result);
            $this->flagged_forms[$form_id] = array(
                'entry'   => $entry_data,
                'score'   => $score,
                'results' => $details,
            );

            $this->log_spam_attempt($form_id, $entry_data, $score, $details, 'marked');

            if (! empty($this->settings['notify_on_spam'])) {
                $this->send_notification($form, $entry_data, $score, $details, 'marked');
            }
        } elseif (! empty($this->settings['log_all_submissions'])) {
            $this->log_spam_attempt($form_id, $entry_data, $score, array('openai' => $openai_result), 'allowed');
        }

        $validation_result['form'] = $form;
        return $validation_result;
    }

    public function handle_after_submission($entry, $form)
    {
        $form_id  = isset($form['id']) ? (int) $form['id'] : (isset($entry['form_id']) ? (int) $entry['form_id'] : 0);
        $entry_id = isset($entry['id']) ? (int) $entry['id'] : 0;

        if ($form_id <= 0 || $entry_id <= 0) {
            return;
        }

        if (isset($this->flagged_forms[$form_id]) && class_exists('\GFFormsModel')) {
            \call_user_func(array('\GFFormsModel', 'update_lead_property'), $entry['id'], 'is_spam', 1);
            \call_user_func(array('\GFFormsModel', 'update_lead_property'), $entry['id'], 'status', 'spam');

            $this->flagged_entries[$entry_id] = true;

            if ('mark' === $this->get_block_action()) {
                unset($this->flagged_forms[$form_id]);
            }
        }
    }

    public function disable_notifications_for_spam($is_disabled, $notification, $form, $entry)
    {
        $form_id  = isset($entry['form_id']) ? (int) $entry['form_id'] : (isset($form['id']) ? (int) $form['id'] : 0);
        $entry_id = isset($entry['id']) ? (int) $entry['id'] : 0;

        if (($form_id > 0 && isset($this->flagged_forms[$form_id])) || isset($this->flagged_entries[$entry_id])) {
            return true;
        }

        if ($entry_id > 0 && class_exists('\GFFormsModel')) {
            $lead = \call_user_func(array('\GFFormsModel', 'get_lead'), $entry_id);
            if ($lead && isset($lead['status']) && 'spam' === $lead['status']) {
                return true;
            }
        }

        return $is_disabled;
    }

    public function maybe_override_confirmation($confirmation, $form, $entry, $ajax)
    {
        if ('reject' !== $this->get_block_action()) {
            return $confirmation;
        }

        $form_id  = isset($form['id']) ? (int) $form['id'] : 0;
        $entry_id = isset($entry['id']) ? (int) $entry['id'] : 0;

        $is_spam = ($form_id > 0 && isset($this->flagged_forms[$form_id])) || ($entry_id > 0 && isset($this->flagged_entries[$entry_id]));

        if (! $is_spam && $entry_id > 0 && class_exists('\GFFormsModel')) {
            $lead = \call_user_func(array('\GFFormsModel', 'get_lead'), $entry_id);
            $is_spam = ($lead && isset($lead['status']) && 'spam' === $lead['status']);
        }

        if (! $is_spam) {
            return $confirmation;
        }

        $message = apply_filters(
            'gform_spamfighter_validation_message',
            __('Your submission has been identified as spam. If you believe this is an error, please contact us directly.', 'gform-spamfighter')
        );

        if ($form_id > 0) {
            unset($this->flagged_forms[$form_id]);
        }

        if ($entry_id > 0) {
            unset($this->flagged_entries[$entry_id]);
        }

        return array(
            'type'    => 'message',
            'message' => '<div class="gform_spamfighter_error" style="color: #d63638; padding: 10px; background: #fcf0f1; border: 1px solid #d63638; border-radius: 4px; margin: 10px 0;">' . esc_html($message) . '</div>',
        );
    }

    public function mark_entry_as_spam($is_spam, $entry, $form)
    {
        $form_id  = isset($entry['form_id']) ? (int) $entry['form_id'] : (isset($form['id']) ? (int) $form['id'] : 0);
        $entry_id = isset($entry['id']) ? (int) $entry['id'] : 0;

        if (($form_id > 0 && isset($this->flagged_forms[$form_id])) || ($entry_id > 0 && isset($this->flagged_entries[$entry_id]))) {
            return true;
        }

        if (! $is_spam && $entry_id > 0 && class_exists('\GFFormsModel')) {
            $lead = \call_user_func(array('\GFFormsModel', 'get_lead'), $entry_id);
            $is_spam = ($lead && isset($lead['status']) && 'spam' === $lead['status']);
        }

        if (! $is_spam && isset($entry['id']) && class_exists('\GFFormsModel')) {
            $lead = \call_user_func(array('\GFFormsModel', 'get_lead'), $entry['id']);
            $is_spam = ($lead && isset($lead['status']) && 'spam' === $lead['status']);
        }

        return $is_spam;
    }

    private function is_enabled()
    {
        return ! empty($this->settings['enabled']);
    }

    private function is_openai_enabled()
    {
        return ! empty($this->settings['openai_enabled']) && ! empty($this->settings['openai_api_key']);
    }

    private function get_block_action()
    {
        $action = $this->settings['block_action'] ?? 'mark';
        return in_array($action, array('mark', 'reject'), true) ? strtolower($action) : 'mark';
    }

    private function get_entry_data($form)
    {
        $entry   = array();
        $grouped = array(
            'text'     => array(),
            'textarea' => array(),
            'email'    => array(),
            'website'  => array(),
            'phone'    => array(),
        );

        foreach ($form['fields'] as $field) {
            $type = method_exists($field, 'get_input_type') ? $field->get_input_type() : (property_exists($field, 'type') ? $field->type : 'text');

            if ('hidden' === $type && ! empty($this->settings['exclude_hidden_fields'])) {
                continue;
            }

            $value      = null;
            $input_name = 'input_' . $field->id;

            if (isset($_POST[$input_name])) {
                $value = $_POST[$input_name];
            }

            if (empty($value) && class_exists('\GFFormsModel')) {
                $value = \call_user_func(array('\GFFormsModel', 'get_field_value'), $field);
            }

            if (empty($value) && isset($field->inputs) && is_array($field->inputs)) {
                $combined = array();
                foreach ($field->inputs as $input) {
                    $input_id = str_replace('.', '_', $input['id']);
                    if (isset($_POST['input_' . $input_id])) {
                        $combined[] = $_POST['input_' . $input_id];
                    }
                }
                if (! empty($combined)) {
                    $value = implode(' ', $combined);
                }
            }

            if (empty($value)) {
                continue;
            }

            if (is_array($value)) {
                $value = implode(' ', array_map('sanitize_text_field', $value));
            } else {
                $value = sanitize_text_field(wp_unslash($value));
            }

            $label = ! empty($field->label) ? $field->label : 'field_' . $field->id;
            $entry[$label . ' (ID: ' . $field->id . ')'] = $value;

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
                case 'phone':
                    $grouped['phone'][] = $value;
                    break;
                default:
                    $grouped['text'][] = $value;
                    break;
            }
        }

        $entry['_grouped'] = $grouped;
        return $entry;
    }

    private function check_with_openai($entry, $form_id)
    {
        $api_key = $this->settings['openai_api_key'] ?? '';
        $model   = $this->settings['openai_model'] ?? 'gpt-4o-mini';

        $detector = new OpenAI($api_key, $model);
        $locale   = get_locale();
        $lang     = substr($locale, 0, 2);

        return $detector->analyze($entry, $lang);
    }

    private function log_spam_attempt($form_id, $entry, $score, $results, $action_taken)
    {
        $db = Database::get_instance();

        $detection_methods = array();
        foreach ($results as $method => $result) {
            if (isset($result['is_spam']) && $result['is_spam']) {
                $detection_methods[] = $method;
            }
        }

        $db->log_spam(
            array(
                'form_id'           => $form_id,
                'entry_data'        => $entry,
                'spam_score'        => $score,
                'detection_method'  => implode(', ', $detection_methods),
                'detection_details' => $results,
                'action_taken'      => $action_taken,
            )
        );
    }

    private function send_notification($form, $entry, $score, $results, $action_type)
    {
        $to = $this->settings['notification_email'] ?? get_option('admin_email');

        $subject = sprintf(__(' [BLOCKED] Spam detected in form: %s', 'gform-spamfighter'), $form['title']);
        $message = sprintf(
            __("A spam submission was BLOCKED with a score of %1\$.2f.\n\nForm: %2\$s\nSite: %3\$s\nTime: %4\$s\n\n=== Submitted Data ===\n%5\$s\n\n=== Detection Details ===\n%6\$s", 'gform-spamfighter'),
            $score,
            $form['title'],
            get_bloginfo('name'),
            gmdate('Y-m-d H:i:s'),
            print_r($entry, true),
            print_r($results, true)
        );

        wp_mail($to, $subject, $message);
    }

    private function is_debug_enabled()
    {
        return defined('WP_DEBUG') && (bool) constant('WP_DEBUG');
    }
}
