<?php

/**
 * Behavior-based spam detection.
 *
 * @package GformSpamfighter
 */

namespace GformSpamfighter\Detection;

/**
 * Behavior analyzer class.
 */
class BehaviorAnalyzer
{

    /**
     * Analyze submission behavior.
     *
     * @param array $entry Entry data.
     * @param int   $form_id Form ID.
     * @return array Analysis result.
     */
    public function analyze($entry, $form_id)
    {
        $score   = 0;
        $reasons = array();

        // Check submission time.
        $time_check = $this->check_submission_time($form_id);
        if ($time_check['detected']) {
            $score += $time_check['score'];
            $reasons[] = $time_check['reason'];
        }

        // Check language consistency.
        $lang_check = $this->check_language_consistency($entry);
        if ($lang_check['detected']) {
            $score += $lang_check['score'];
            $reasons[] = $lang_check['reason'];
        }

        // Check user agent.
        $ua_check = $this->check_user_agent();
        if ($ua_check['detected']) {
            $score += $ua_check['score'];
            $reasons[] = $ua_check['reason'];
        }

        // Check referrer.
        $ref_check = $this->check_referrer();
        if ($ref_check['detected']) {
            $score += $ref_check['score'];
            $reasons[] = $ref_check['reason'];
        }

        // Check for spam referrer patterns.
        $spam_ref_check = $this->check_spam_referrer();
        if ($spam_ref_check['detected']) {
            $score += $spam_ref_check['score'];
            $reasons[] = $spam_ref_check['reason'];
        }

        // Normalize score.
        $normalized_score = min($score / 100, 1.0);

        return array(
            'score'   => $normalized_score,
            'is_spam' => $normalized_score >= 0.7,
            'reasons' => $reasons,
            'details' => array(
                'time'         => $time_check,
                'language'     => $lang_check,
                'user_agent'   => $ua_check,
                'referrer'     => $ref_check,
                'spam_referrer' => $spam_ref_check,
            ),
        );
    }

    /**
     * Check submission time.
     *
     * @param int $form_id Form ID.
     * @return array
     */
    private function check_submission_time($form_id)
    {
        // Get form render time if available.
        $form_start_time = isset($_POST['gform_field_values']) ? filter_input(INPUT_POST, 'gform_timer_' . $form_id, FILTER_SANITIZE_NUMBER_INT) : null;

        if (! $form_start_time) {
            return array('detected' => false);
        }

        $submission_time = time() - intval($form_start_time);

        $settings = get_option('gform_spamfighter_settings', array());
        $min_time = intval($settings['min_submission_time'] ?? 3);

        if ($submission_time < $min_time) {
            return array(
                'detected' => true,
                'score'    => 50,
                'reason'   => sprintf('Form submitted too quickly (%d seconds)', $submission_time),
            );
        }

        // Also check if submitted suspiciously fast (< 1 second).
        if ($submission_time < 1) {
            return array(
                'detected' => true,
                'score'    => 70,
                'reason'   => 'Form submitted in less than 1 second',
            );
        }

        return array('detected' => false);
    }

    /**
     * Check language consistency.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_language_consistency($entry)
    {
        $current_locale = get_locale();
        $expected_lang  = substr($current_locale, 0, 2);

        $content = '';
        foreach ($entry as $value) {
            if (is_string($value) && strlen($value) > 10) {
                $content .= ' ' . $value;
            }
        }

        if (empty(trim($content))) {
            return array('detected' => false);
        }

        // Simple language detection based on character sets and common words.
        $detected_lang = $this->detect_language($content);

        if ($detected_lang && $detected_lang !== $expected_lang && $detected_lang !== 'unknown') {
            return array(
                'detected' => true,
                'score'    => 20,
                'reason'   => sprintf(
                    'Language mismatch (expected: %s, detected: %s)',
                    $expected_lang,
                    $detected_lang
                ),
            );
        }

        return array('detected' => false);
    }

    /**
     * Simple language detection.
     *
     * @param string $text Text to analyze.
     * @return string|null Language code or null.
     */
    private function detect_language($text)
    {
        $text = strtolower($text);

        // Common words in different languages.
        $language_patterns = array(
            'de' => array('und', 'der', 'die', 'das', 'ich', 'ist', 'nicht', 'mit', 'für', 'auf'),
            'en' => array('the', 'and', 'for', 'are', 'but', 'not', 'you', 'with', 'from', 'this'),
            'fr' => array('le', 'la', 'les', 'et', 'de', 'un', 'une', 'est', 'pour', 'dans'),
            'es' => array('el', 'la', 'los', 'las', 'de', 'un', 'una', 'es', 'en', 'para'),
            'it' => array('il', 'la', 'di', 'e', 'un', 'una', 'per', 'con', 'non', 'che'),
        );

        $scores = array();

        foreach ($language_patterns as $lang => $words) {
            $count = 0;
            foreach ($words as $word) {
                $count += substr_count($text, ' ' . $word . ' ');
            }
            $scores[$lang] = $count;
        }

        if (empty($scores) || max($scores) < 2) {
            return 'unknown';
        }

        arsort($scores);
        return key($scores);
    }

    /**
     * Check user agent.
     *
     * @return array
     */
    private function check_user_agent()
    {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';

        if (empty($user_agent)) {
            return array(
                'detected' => true,
                'score'    => 30,
                'reason'   => 'No user agent provided',
            );
        }

        // Check for bot signatures.
        $bot_patterns = array(
            'bot',
            'crawler',
            'spider',
            'scraper',
            'curl',
            'wget',
            'python',
            'java',
            'perl',
            'ruby',
            'go-http',
        );

        foreach ($bot_patterns as $pattern) {
            if (stripos($user_agent, $pattern) !== false) {
                return array(
                    'detected' => true,
                    'score'    => 40,
                    'reason'   => sprintf('Bot user agent detected (%s)', $pattern),
                );
            }
        }

        return array('detected' => false);
    }

    /**
     * Check referrer.
     *
     * @return array
     */
    private function check_referrer()
    {
        $referrer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';

        if (empty($referrer)) {
            return array(
                'detected' => true,
                'score'    => 10,
                'reason'   => 'No referrer provided',
            );
        }

        // Check if referrer is from same domain.
        $site_url = get_site_url();
        if (strpos($referrer, $site_url) !== 0) {
            return array(
                'detected' => true,
                'score'    => 15,
                'reason'   => 'External referrer',
            );
        }

        return array('detected' => false);
    }

    /**
     * Check for known spam referrer patterns.
     *
     * @return array
     */
    private function check_spam_referrer()
    {
        $referrer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';

        if (empty($referrer)) {
            return array('detected' => false);
        }

        // Known spam referrer patterns
        $spam_referrers = array(
            'syndicatedsearch.goog'     => 'Google syndicated search (spam indicator)',
            'free-share-buttons'        => 'Share button spam',
            'social-buttons.com'        => 'Social button spam',
            'buttons-for-website.com'   => 'Button spam',
            'semalt.com'                => 'Semalt referrer spam',
            'kambanat.com'              => 'Kambanat spam',
            'ranksonic.com'             => 'RankSonic spam',
            'get-free-traffic'          => 'Free traffic spam',
            'free-social-buttons'       => 'Social button spam',
            'darodar.com'               => 'Darodar spam',
            'bestwebsitesawards.com'    => 'Fake awards spam',
            'buttons-for-your-website'  => 'Button spam',
            'seo-platform.com'          => 'SEO spam',
            'simple-share-buttons'      => 'Share button spam',
        );

        // Allow custom spam referrers to be added via filter
        $spam_referrers = apply_filters('gform_spamfighter_spam_referrers', $spam_referrers);

        foreach ($spam_referrers as $pattern => $description) {
            if (stripos($referrer, $pattern) !== false) {
                return array(
                    'detected' => true,
                    'score'    => 60, // High score - very suspicious
                    'reason'   => sprintf('Spam referrer detected: %s', $description),
                    'referrer' => $referrer,
                );
            }
        }

        // Check for referrers with suspicious patterns in URL
        $suspicious_patterns = array(
            'free-'        => 'Contains "free-" (often spam)',
            'get-free'     => 'Contains "get-free" (spam)',
            'best-seo'     => 'Contains "best-seo" (SEO spam)',
            'seo-service'  => 'Contains "seo-service" (SEO spam)',
            'social-button' => 'Contains "social-button" (button spam)',
            'share-button' => 'Contains "share-button" (button spam)',
        );

        foreach ($suspicious_patterns as $pattern => $description) {
            if (stripos($referrer, $pattern) !== false) {
                return array(
                    'detected' => true,
                    'score'    => 40,
                    'reason'   => sprintf('Suspicious referrer pattern: %s', $description),
                    'referrer' => $referrer,
                );
            }
        }

        return array('detected' => false);
    }
}
