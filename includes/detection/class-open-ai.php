<?php

/**
 * OpenAI spam detection.
 *
 * @package GformSpamfighter
 */

namespace GformSpamfighter\Detection;

use GformSpamfighter\Core\Logger;

/**
 * OpenAI detector class.
 */
class OpenAI
{

    /**
     * API key.
     *
     * @var string
     */
    private $api_key;

    /**
     * Model to use.
     *
     * @var string
     */
    private $model;

    /**
     * API endpoint.
     *
     * @var string
     */
    private $api_endpoint = 'https://api.openai.com/v1/chat/completions';

    /**
     * Moderation API endpoint (FREE!).
     *
     * @var string
     */
    private $moderation_endpoint = 'https://api.openai.com/v1/moderations';

    /**
     * Use moderation API (free) instead of chat models.
     *
     * @var bool
     */
    private $use_moderation = false;

    /**
     * Constructor.
     *
     * @param string $api_key API key.
     * @param string $model Model name.
     */
    public function __construct($api_key, $model = 'gpt-4o-mini')
    {
        // Allow API key to be set via wp-config.php for better security.
        if (defined('GFORM_SPAMFIGHTER_OPENAI_KEY') && ! empty(GFORM_SPAMFIGHTER_OPENAI_KEY)) {
            $this->api_key = GFORM_SPAMFIGHTER_OPENAI_KEY;
        } else {
            $this->api_key = $api_key;
        }

        // Check if moderation API should be used (FREE and recommended!)
        if ($model === 'moderation' || $model === 'omni-moderation-latest') {
            $this->use_moderation = true;
            $this->model = 'omni-moderation-latest';
        } else {
            // Sanitize model to prevent injection.
            $allowed_models = array('gpt-4o-mini', 'gpt-4o', 'gpt-4-turbo', 'gpt-3.5-turbo');
            $this->model = in_array($model, $allowed_models, true) ? $model : 'gpt-4o-mini';
        }
    }

    /**
     * Analyze entry for spam.
     *
     * @param array  $entry Entry data.
     * @param string $expected_language Expected language code.
     * @return array Analysis result with score and reasoning.
     */
    public function analyze($entry, $expected_language = 'en')
    {
        if (empty($this->api_key)) {
            Logger::get_instance()->error('OpenAI API key not configured');
            return array(
                'score'   => 0,
                'is_spam' => false,
                'reason'  => 'API key not configured',
            );
        }

        // Rate limiting to prevent API abuse.
        $rate_limit_check = $this->check_rate_limit();
        if (! $rate_limit_check['allowed']) {
            Logger::get_instance()->warning(
                'OpenAI rate limit exceeded',
                array('ip' => $rate_limit_check['ip'])
            );
            return array(
                'score'   => 0.5, // Moderate score when rate limited.
                'is_spam' => false,
                'reason'  => 'Rate limit exceeded',
                'error'   => true,
            );
        }

        // Prepare entry data for analysis.
        $content = $this->prepare_content($entry);

        // Use moderation API (free) or chat API (paid)
        if ($this->use_moderation) {
            $response = $this->call_moderation_api($content);
        } else {
            // Build prompt.
            $prompt = $this->build_prompt($content, $expected_language);
            // Call OpenAI Chat API.
            $response = $this->call_api($prompt);
        }

        if (is_wp_error($response)) {
            Logger::get_instance()->error(
                'OpenAI API call failed',
                array(
                    'error' => $response->get_error_message(),
                )
            );

            return array(
                'score'   => 0,
                'is_spam' => false,
                'reason'  => $response->get_error_message(),
                'error'   => true,
            );
        }

        // Parse response based on API type
        if ($this->use_moderation) {
            return $this->parse_moderation_response($response);
        } else {
            return $this->parse_response($response);
        }
    }

    /**
     * Prepare content from entry data.
     *
     * @param array $entry Entry data.
     * @return string
     */
    private function prepare_content($entry)
    {
        $content_parts = array();

        foreach ($entry as $field_id => $value) {
            if (is_array($value)) {
                $value = implode(', ', $value);
            }

            // Skip empty values and technical fields.
            if (empty($value) || is_numeric($field_id)) {
                continue;
            }

            $content_parts[] = $value;
        }

        return implode("\n", $content_parts);
    }

    /**
     * Build AI prompt.
     *
     * @param string $content Content to analyze.
     * @param string $expected_language Expected language.
     * @return string
     */
    private function build_prompt($content, $expected_language)
    {
        $language_names = array(
            'en'    => 'English',
            'de'    => 'German',
            'fr'    => 'French',
            'es'    => 'Spanish',
            'it'    => 'Italian',
            'pt'    => 'Portuguese',
            'nl'    => 'Dutch',
            'pl'    => 'Polish',
            'cs'    => 'Czech',
            'ru'    => 'Russian',
            'tr'    => 'Turkish',
            'zh'    => 'Chinese',
            'ja'    => 'Japanese',
            'ko'    => 'Korean',
        );

        $language_name = $language_names[$expected_language] ?? 'English';

        return sprintf(
            'Analyze the following form submission and determine if it is spam. Consider these factors:

1. Is the content coherent and meaningful?
2. Does it appear to be generated by AI or a bot (repetitive patterns, unnatural phrasing)?
3. Does it contain suspicious links or promotional content?
4. Is the language appropriate and consistent (expected language: %s)?
5. Does it seem like a genuine inquiry or message?
6. Check for common spam patterns (excessive keywords, weird character usage, SEO spam)

Form submission content:
---
%s
---

Respond ONLY with a JSON object in this exact format:
{
  "spam_score": 0.0,
  "is_spam": false,
  "reasoning": "Brief explanation",
  "confidence": "high/medium/low",
  "detected_language": "language code"
}

spam_score should be between 0.0 (definitely not spam) and 1.0 (definitely spam).
is_spam should be true if spam_score >= 0.7',
            esc_html($language_name),
            esc_html($content)
        );
    }

    /**
     * Call OpenAI API.
     *
     * @param string $prompt Prompt.
     * @return array|\WP_Error
     */
    private function call_api($prompt)
    {
        error_log('GFORM OpenAI: call_api started with model: ' . $this->model);

        $body = array(
            'model'       => $this->model,
            'messages'    => array(
                array(
                    'role'    => 'system',
                    'content' => 'You are a spam detection expert. Analyze form submissions and provide accurate spam scores with reasoning.',
                ),
                array(
                    'role'    => 'user',
                    'content' => $prompt,
                ),
            ),
            'temperature' => 0.3,
            'max_tokens'  => 500,
        );

        error_log('GFORM OpenAI: Sending request to OpenAI...');

        $response = wp_remote_post(
            $this->api_endpoint,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode($body),
                'timeout' => 45, // Increased timeout for OpenAI API
                'httpversion' => '1.1',
                'sslverify' => true,
            )
        );

        error_log('GFORM OpenAI: Got response');

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            error_log('GFORM OpenAI: WP_Error - ' . $error_message);
            Logger::get_instance()->error(
                'OpenAI API request failed',
                array(
                    'error' => $error_message,
                )
            );
            return $response;
        }

        error_log('GFORM OpenAI: Response is not WP_Error');

        $response_code = wp_remote_retrieve_response_code($response);
        if (200 !== $response_code) {
            $body = wp_remote_retrieve_body($response);

            // Try to parse error details
            $error_detail = json_decode($body, true);
            $error_message = 'Unknown error';

            if (isset($error_detail['error']['message'])) {
                $error_message = $error_detail['error']['message'];
            }

            Logger::get_instance()->error(
                'OpenAI API returned error',
                array(
                    'code' => $response_code,
                    'error' => $error_message,
                    'body' => substr($body, 0, 500),
                )
            );

            return new \WP_Error(
                'api_error',
                sprintf('API error (%d): %s', $response_code, $error_message)
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data;
    }

    /**
     * Parse API response.
     *
     * @param array $response API response.
     * @return array
     */
    private function parse_response($response)
    {
        if (! isset($response['choices'][0]['message']['content'])) {
            return array(
                'score'   => 0,
                'is_spam' => false,
                'reason'  => 'Invalid API response',
                'error'   => true,
            );
        }

        $content = $response['choices'][0]['message']['content'];

        // Extract JSON from response.
        preg_match('/\{[^}]+\}/', $content, $matches);

        if (empty($matches)) {
            Logger::get_instance()->warning(
                'Could not parse OpenAI response',
                array('response' => $content)
            );

            return array(
                'score'   => 0,
                'is_spam' => false,
                'reason'  => 'Could not parse AI response',
                'error'   => true,
            );
        }

        $result = json_decode($matches[0], true);

        if (! $result) {
            return array(
                'score'   => 0,
                'is_spam' => false,
                'reason'  => 'Invalid JSON in response',
                'error'   => true,
            );
        }

        return array(
            'score'             => floatval($result['spam_score'] ?? 0),
            'is_spam'           => (bool) ($result['is_spam'] ?? false),
            'reason'            => sanitize_text_field($result['reasoning'] ?? ''),
            'confidence'        => sanitize_text_field($result['confidence'] ?? 'low'),
            'detected_language' => sanitize_text_field($result['detected_language'] ?? 'unknown'),
            'raw_response'      => $content,
        );
    }

    /**
     * Parse Moderation API response.
     *
     * @param array $response Moderation API response.
     * @return array
     */
    private function parse_moderation_response($response)
    {
        $spam_score = $response['spam_score'] ?? 0;
        $is_spam = $response['is_spam'] ?? false;
        $reasoning = $response['reasoning'] ?? 'Unknown';

        return array(
            'score'      => floatval($spam_score),
            'is_spam'    => (bool) $is_spam,
            'reason'     => sanitize_text_field($reasoning),
            'confidence' => $is_spam ? 'high' : 'low',
            'method'     => 'moderation_api',
        );
    }

    /**
     * Check rate limit for API calls.
     *
     * @return array Rate limit check result.
     */
    private function check_rate_limit()
    {
        $ip = $this->get_user_ip();

        // Use transients for rate limiting (60 requests per hour per IP).
        $transient_key = 'gform_spam_rate_limit_' . md5($ip);
        $current_count = get_transient($transient_key);

        if (false === $current_count) {
            // First request in this hour.
            set_transient($transient_key, 1, HOUR_IN_SECONDS);
            return array(
                'allowed' => true,
                'ip'      => $ip,
                'count'   => 1,
            );
        }

        // Maximum 60 requests per hour per IP.
        $max_requests = apply_filters('gform_spamfighter_rate_limit_max', 60);

        if ($current_count >= $max_requests) {
            return array(
                'allowed' => false,
                'ip'      => $ip,
                'count'   => $current_count,
                'limit'   => $max_requests,
            );
        }

        // Increment counter.
        set_transient($transient_key, $current_count + 1, HOUR_IN_SECONDS);

        return array(
            'allowed' => true,
            'ip'      => $ip,
            'count'   => $current_count + 1,
        );
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

    /**
     * Call OpenAI Moderation API (FREE!).
     *
     * @param string $content Content to moderate.
     * @return array|\WP_Error
     */
    private function call_moderation_api($content)
    {
        error_log('GFORM OpenAI: Using Moderation API (FREE)');

        $body = array(
            'input' => $content,
            'model' => 'omni-moderation-latest',
        );

        $response = wp_remote_post(
            $this->moderation_endpoint,
            array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ),
                'body'    => wp_json_encode($body),
                'timeout' => 30,
                'httpversion' => '1.1',
                'sslverify' => true,
            )
        );

        if (is_wp_error($response)) {
            error_log('GFORM Moderation: Error - ' . $response->get_error_message());
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if (200 !== $response_code) {
            $body_text = wp_remote_retrieve_body($response);
            error_log('GFORM Moderation: Bad response code ' . $response_code);

            return new \WP_Error(
                'api_error',
                sprintf('Moderation API error (%d)', $response_code)
            );
        }

        $body_text = wp_remote_retrieve_body($response);
        $data = json_decode($body_text, true);

        if (!isset($data['results'][0])) {
            return new \WP_Error('parse_error', 'Invalid moderation response');
        }

        // Convert moderation result to our format
        $result = $data['results'][0];
        $flagged = $result['flagged'] ?? false;

        // Calculate spam score from category scores
        $scores = $result['category_scores'] ?? array();
        $max_score = 0;
        $flagged_categories = array();

        foreach ($scores as $category => $score) {
            if ($score > $max_score) {
                $max_score = $score;
            }
            if ($score > 0.5) {
                $flagged_categories[] = $category;
            }
        }

        error_log('GFORM Moderation: Flagged=' . ($flagged ? 'YES' : 'NO') . ', Max score=' . $max_score);

        return array(
            'flagged'    => $flagged,
            'spam_score' => $max_score,
            'categories' => $flagged_categories,
            'is_spam'    => $flagged || $max_score > 0.7,
            'reasoning'  => $flagged ? implode(', ', $flagged_categories) : 'Content appears safe',
        );
    }
}
