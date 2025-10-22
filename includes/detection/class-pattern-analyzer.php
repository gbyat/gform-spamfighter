<?php

/**
 * Pattern-based spam detection.
 *
 * @package GformSpamfighter
 */

namespace GformSpamfighter\Detection;

/**
 * Pattern analyzer class.
 */
class PatternAnalyzer
{

    /**
     * Analyze entry for spam patterns.
     *
     * @param array $entry Entry data.
     * @return array Analysis result.
     */
    public function analyze($entry)
    {
        // Apply field exclusion filter (e.g., for campaign/tracking fields).
        $excluded_fields = apply_filters('gform_spamfighter_excluded_fields', array());

        // Remove excluded fields from analysis.
        if (!empty($excluded_fields) && is_array($excluded_fields)) {
            foreach ($excluded_fields as $field_name) {
                unset($entry[$field_name]);
                // Also remove from _grouped if present.
                if (isset($entry['_grouped'])) {
                    foreach ($entry['_grouped'] as $type => &$values) {
                        if (is_array($values)) {
                            $values = array_filter($values, function ($key) use ($field_name) {
                                return $key !== $field_name;
                            }, ARRAY_FILTER_USE_KEY);
                        }
                    }
                }
            }
        }

        $score   = 0;
        $reasons = array();

        // Check for suspicious patterns.
        $checks = array(
            // Text-oriented checks
            'min_words'            => $this->check_min_words($entry),
            'excessive_caps'       => $this->check_excessive_caps($entry),
            'character_repetition' => $this->check_character_repetition($entry),
            'suspicious_keywords'  => $this->check_suspicious_keywords($entry),
            'suspicious_patterns'  => $this->check_suspicious_patterns($entry),

            // Email-specific
            'email_pattern'        => $this->check_email_pattern($entry),

            // URL-specific (runs strict rules)
            'url_pattern'          => $this->check_url_pattern($entry),

            // Generic
            'excessive_links'      => $this->check_excessive_links($entry),
        );

        foreach ($checks as $check_name => $result) {
            if ($result['detected']) {
                $score += $result['score'];
                $reasons[] = $result['reason'];
            }
        }

        // Normalize score to 0-1 range.
        $normalized_score = min($score / 100, 1.0);

        return array(
            'score'   => $normalized_score,
            'is_spam' => $normalized_score >= 0.7,
            'reasons' => $reasons,
            'details' => $checks,
        );
    }

    /**
     * Check for links in textarea fields.
     * Single link = soft warning (can be corrected), multiple = hard spam.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_excessive_links($entry)
    {
        // Check in text AND textarea fields (not in dedicated URL fields)
        $text_content = '';
        if (isset($entry['_grouped']) && is_array($entry['_grouped'])) {
            $text_values = array_merge(
                isset($entry['_grouped']['text']) ? (array) $entry['_grouped']['text'] : array(),
                isset($entry['_grouped']['textarea']) ? (array) $entry['_grouped']['textarea'] : array()
            );
            $text_content = implode(' ', $text_values);
        }

        // Fallback: all content except URL fields
        if (empty($text_content)) {
            foreach ($entry as $key => $value) {
                if ($key === '_grouped' || !is_string($value)) {
                    continue;
                }
                // Skip values that are pure URLs or emails
                if (filter_var($value, FILTER_VALIDATE_URL) && strlen($value) < 100) {
                    continue; // likely a URL field
                }
                $text_content .= ' ' . $value;
            }
        }

        // Extended pattern: catch http://, https://, www., and domain-like patterns (e.g. gby.at)
        $link_pattern = '/(https?:\/\/[^\s]+|www\.[^\s]+|(?:[a-z0-9-]+\.)+[a-z]{2,}(?:\/[^\s]*)?)/i';

        // Count all links in text/textarea content
        $link_count = preg_match_all($link_pattern, $text_content);

        // Debug logging
        if (defined('WP_DEBUG') && WP_DEBUG && $link_count > 0) {
            error_log('GFORM: Link detection - Count: ' . $link_count . ', Content: ' . substr($text_content, 0, 200));
        }

        // Single link = soft warning (allow correction)
        if ($link_count === 1) {
            return array(
                'detected'     => true,
                'score'        => 20, // Low score - allows for other checks to override
                'reason'       => 'Single link in text field',
                'soft_warning' => true, // Special flag for lenient handling
            );
        }

        // Multiple links = hard spam
        if ($link_count > 1) {
            return array(
                'detected' => true,
                'score'    => 30,
                'reason'   => sprintf('Multiple links detected (%d)', $link_count),
            );
        }

        return array('detected' => false);
    }

    /**
     * Check that text contains at least a minimum number of words.
     *
     * This is a simple heuristic to block submissions like "asdf qwe" or
     * random characters. We count tokens that look like words (>=2 letters)
     * across all text fields.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_min_words($entry)
    {
        // Allow integrators to change the minimum via filter (default 5)
        $min_words = apply_filters('gform_spamfighter_min_words', 5);

        // Prefer grouped textarea inputs (provided by integration)
        $text_values = array();
        if (isset($entry['_grouped']) && is_array($entry['_grouped'])) {
            $grouped     = $entry['_grouped'];
            $text_values = isset($grouped['textarea']) ? (array) $grouped['textarea'] : array();
        }

        // Fallback: if grouping not available, scan all values but only long-ish strings
        if (empty($text_values)) {
            foreach ($entry as $key => $value) {
                if ($key === '_grouped' || ! is_string($value)) {
                    continue;
                }
                if (is_email($value) || filter_var($value, FILTER_VALIDATE_URL)) {
                    continue; // skip non-text
                }
                if (strlen($value) >= 20) { // heuristic for textarea-like input
                    $text_values[] = $value;
                }
            }
        }

        $word_count = 0;
        foreach ($text_values as $value) {
            $tokens = preg_split('/\s+/u', trim($value));
            if (! is_array($tokens)) {
                continue;
            }
            foreach ($tokens as $token) {
                // Count tokens that look like words (>=2 letters). Unicode-aware.
                if (preg_match('/\p{L}{2,}/u', $token)) {
                    $word_count++;
                }
            }
        }

        if ($word_count < $min_words) {
            return array(
                'detected' => true,
                'score'    => 80, // decisive for too-short text content
                'reason'   => sprintf('Not enough words in text fields (%d < %d)', $word_count, $min_words),
            );
        }

        return array('detected' => false);
    }

    /**
     * Check for suspicious keywords.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_suspicious_keywords($entry)
    {
        $content = strtolower($this->get_text_content($entry));

        $spam_keywords = array(
            'viagra',
            'cialis',
            'casino',
            'poker',
            'lottery',
            'winner',
            'click here',
            'buy now',
            'limited time',
            'act now',
            'order now',
            'free money',
            'double your',
            'guarantee',
            'no risk',
            'discount',
            'pharmacy',
            'replica',
            'rolex',
            'weight loss',
            'make money',
            'work from home',
            'earn $',
            'seo service',
            'backlinks',
            'cheap',
        );

        $matches = 0;
        $found_keywords = array();

        foreach ($spam_keywords as $keyword) {
            if (strpos($content, $keyword) !== false) {
                $matches++;
                $found_keywords[] = $keyword;
            }
        }

        if ($matches > 0) {
            return array(
                'detected' => true,
                'score'    => min($matches * 15, 50),
                'reason'   => sprintf('Suspicious keywords found: %s', implode(', ', $found_keywords)),
            );
        }

        return array('detected' => false);
    }

    /**
     * Check for excessive capital letters.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_excessive_caps($entry)
    {
        $content = $this->get_text_content($entry);

        if (strlen($content) < 20) {
            return array('detected' => false);
        }

        preg_match_all('/[A-Z]/', $content, $caps);
        preg_match_all('/[a-z]/', $content, $lower);

        $caps_count  = count($caps[0]);
        $lower_count = count($lower[0]);
        $total       = $caps_count + $lower_count;

        if ($total > 0) {
            $caps_ratio = $caps_count / $total;

            if ($caps_ratio > 0.5) {
                return array(
                    'detected' => true,
                    'score'    => 20,
                    'reason'   => sprintf('Excessive capital letters (%.0f%%)', $caps_ratio * 100),
                );
            }
        }

        return array('detected' => false);
    }

    /**
     * Check for suspicious patterns.
     * Only checks text/textarea fields to avoid false positives from phone numbers, etc.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_suspicious_patterns($entry)
    {
        // Only check text/textarea content (not phone, number, or other fields).
        $text_content = '';
        if (isset($entry['_grouped']) && is_array($entry['_grouped'])) {
            $grouped      = $entry['_grouped'];
            $text_values  = isset($grouped['text']) ? (array) $grouped['text'] : array();
            $text_values  = array_merge($text_values, isset($grouped['textarea']) ? (array) $grouped['textarea'] : array());
            $text_content = implode(' ', $text_values);
        }

        // Fallback if no grouped data.
        if (empty($text_content)) {
            $text_content = $this->get_text_content($entry);
        }

        $patterns = array(
            '/(\w)\1{5,}/'           => 'Excessive character repetition',
            '/[^\w\s]{5,}/'          => 'Excessive special characters',
            '/\d{10,}/'              => 'Suspicious number sequence',
            '/<script/i'             => 'Script tag detected',
            '/\[url=/i'              => 'BBCode link detected',
            '/\{link:/i'             => 'Malformed link syntax',
        );

        foreach ($patterns as $pattern => $description) {
            if (preg_match($pattern, $text_content)) {
                return array(
                    'detected' => true,
                    'score'    => 25,
                    'reason'   => $description,
                );
            }
        }

        return array('detected' => false);
    }

    /**
     * Check email pattern.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_email_pattern($entry)
    {
        $email = '';

        // Find email field.
        foreach ($entry as $value) {
            if (is_email($value)) {
                $email = $value;
                break;
            }
        }

        if (empty($email)) {
            return array('detected' => false);
        }

        // Check for disposable email domains.
        $disposable_domains = array(
            'tempmail.com',
            'throwaway.email',
            'guerrillamail.com',
            'mailinator.com',
            '10minutemail.com',
            'temp-mail.org',
            'yopmail.com',
            'maildrop.cc',
            'trashmail.com',
        );

        $domain = substr(strrchr($email, '@'), 1);

        if (in_array(strtolower($domain), $disposable_domains, true)) {
            return array(
                'detected' => true,
                'score'    => 40,
                'reason'   => 'Disposable email address detected',
            );
        }

        return array('detected' => false);
    }

    /**
     * Check URL/Website field patterns.
     * Treat URLs with query parameters as spam; also flag shorteners, suspicious TLDs, raw IPs.
     * Only checks actual URL/website fields from user input, not text/textarea fields.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_url_pattern($entry)
    {
        // Only check URL/website fields, not text/textarea content.
        $url_values = array();
        if (isset($entry['_grouped']) && is_array($entry['_grouped'])) {
            $grouped    = $entry['_grouped'];
            $url_values = isset($grouped['website']) ? (array) $grouped['website'] : array();
        }

        if (empty($url_values)) {
            return array('detected' => false);
        }

        $score   = 0;
        $reasons = array();

        foreach ($url_values as $url) {
            if (empty($url) || !is_string($url)) {
                continue;
            }

            // Parameters in URL fields → suspicious
            if (strpos($url, '?') !== false) {
                $score    = max($score, 80);
                $reasons[] = 'URL with parameters in website field';
            }

            // Shorteners
            $shorteners = array('bit.ly', 'tinyurl.com', 'goo.gl', 't.co', 'ow.ly', 'is.gd', 'buff.ly', 'adf.ly');
            foreach ($shorteners as $s) {
                if (stripos($url, $s) !== false) {
                    $score    = max($score, 60);
                    $reasons[] = 'URL shortener detected (' . $s . ')';
                    break;
                }
            }

            // Suspicious TLDs
            $tlds = array('.xyz', '.top', '.work', '.click', '.link', '.gq', '.ml', '.ga', '.cf', '.tk');
            foreach ($tlds as $tld) {
                if (strtolower(substr($url, -strlen($tld))) === $tld) {
                    $score    = max($score, 50);
                    $reasons[] = 'Suspicious TLD (' . $tld . ')';
                    break;
                }
            }

            // Raw IP address in URL
            if (preg_match('#https?://\d{1,3}(?:\.\d{1,3}){3}#', $url)) {
                $score    = max($score, 60);
                $reasons[] = 'IP address in URL';
            }
        }

        if ($score > 0) {
            return array(
                'detected' => true,
                'score'    => min($score, 100),
                'reason'   => implode(', ', array_unique($reasons)),
            );
        }

        return array('detected' => false);
    }

    /**
     * Check for length anomalies.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_length_anomalies($entry)
    {
        foreach ($entry as $value) {
            if (is_string($value) && strlen($value) > 5000) {
                return array(
                    'detected' => true,
                    'score'    => 15,
                    'reason'   => 'Unusually long field content',
                );
            }
        }

        return array('detected' => false);
    }

    /**
     * Check for character repetition.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_character_repetition($entry)
    {
        $content = $this->get_text_content($entry);

        // Check for repeated words.
        $words = preg_split('/\s+/', $content);
        $word_counts = array_count_values($words);

        foreach ($word_counts as $word => $count) {
            if (strlen($word) > 3 && $count > 5) {
                return array(
                    'detected' => true,
                    'score'    => 15,
                    'reason'   => sprintf('Word "%s" repeated %d times', $word, $count),
                );
            }
        }

        return array('detected' => false);
    }

    /**
     * Get all text content from entry.
     *
     * @param array $entry Entry data.
     * @return string
     */
    private function get_text_content($entry)
    {
        $content_parts = array();

        foreach ($entry as $value) {
            if (is_array($value)) {
                $value = implode(' ', $value);
            }

            if (is_string($value)) {
                $content_parts[] = $value;
            }
        }

        return implode(' ', $content_parts);
    }
}
