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

            // Disallow contact info in single-line text fields
            'url_in_text'          => $this->check_url_in_text_fields($entry),
            'email_in_text'        => $this->check_email_in_text_fields($entry),
            'phone_in_text'        => $this->check_phone_in_text_fields($entry),

            // Length limits for single-line text fields
            'text_length_limits'   => $this->check_text_length_limits($entry),
            'text_min_length'      => $this->check_text_min_length($entry),

            // New advanced checks
            'duplicate_message'    => $this->check_duplicate_message($entry),
            'email_in_message'     => $this->check_email_in_message($entry),
            'all_caps_sentences'   => $this->check_all_caps_sentences($entry),
            'excessive_exclamations' => $this->check_excessive_exclamations($entry),
            'business_terminology' => $this->check_business_terminology($entry),

            // Email-specific
            'email_pattern'        => $this->check_email_pattern($entry),
            'email_field_validity' => $this->check_email_field_validity($entry),

            // URL-specific (runs strict rules)
            'url_pattern'          => $this->check_url_pattern($entry),
            'website_field_validity' => $this->check_website_field_validity($entry),

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

        // Debug logging (avoid direct constant reference for linters)
        if (defined('WP_DEBUG') && (bool) constant('WP_DEBUG') && $link_count > 0) {
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
     * Enforce sensible max lengths for single-line text fields.
     * Defaults via filters:
     * - gform_spamfighter_text_warn_chars (default 120)
     * - gform_spamfighter_text_block_chars (default 240)
     * - gform_spamfighter_text_warn_words (default 12)
     * - gform_spamfighter_text_block_words (default 24)
     *
     * Soft warning at warn thresholds (score 20), stronger at block thresholds (score 40).
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_text_length_limits($entry)
    {
        if (!isset($entry['_grouped']['text']) || !is_array($entry['_grouped']['text'])) {
            return array('detected' => false);
        }

        $text_values = array_filter((array) $entry['_grouped']['text'], function ($v) {
            return is_string($v) && $v !== '';
        });
        if (empty($text_values)) {
            return array('detected' => false);
        }

        $warn_chars  = (int) apply_filters('gform_spamfighter_text_warn_chars', 120);
        $block_chars = (int) apply_filters('gform_spamfighter_text_block_chars', 240);
        $warn_words  = (int) apply_filters('gform_spamfighter_text_warn_words', 12);
        $block_words = (int) apply_filters('gform_spamfighter_text_block_words', 24);

        $highest_level = 0; // 0 none, 1 warn, 2 block
        $hit_details   = array();

        foreach ($text_values as $value) {
            $length = strlen($value);
            $words  = preg_split('/\s+/u', trim($value));
            $word_count = is_array($words) ? count(array_filter($words)) : 0;

            if ($length > $block_chars || $word_count > $block_words) {
                $highest_level = max($highest_level, 2);
                $hit_details[] = sprintf('"%s" (%d chars, %d words) over block threshold', mb_substr($value, 0, 50), $length, $word_count);
            } elseif ($length > $warn_chars || $word_count > $warn_words) {
                $highest_level = max($highest_level, 1);
                $hit_details[] = sprintf('"%s" (%d chars, %d words) over warn threshold', mb_substr($value, 0, 50), $length, $word_count);
            }
        }

        if ($highest_level === 0) {
            return array('detected' => false);
        }

        if ($highest_level === 1) {
            return array(
                'detected'     => true,
                'score'        => 20,
                'reason'       => 'Long content in single-line text field (warning)',
                'soft_warning' => true,
                'details'      => $hit_details,
            );
        }

        return array(
            'detected' => true,
            'score'    => 40,
            'reason'   => 'Excessively long content in single-line text field',
            'details'  => $hit_details,
        );
    }

    /**
     * Enforce a minimum length for single-line text fields.
     * Filter: gform_spamfighter_text_min_chars (default 3)
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_text_min_length($entry)
    {
        if (!isset($entry['_grouped']['text']) || !is_array($entry['_grouped']['text'])) {
            return array('detected' => false);
        }

        $min_chars = (int) apply_filters('gform_spamfighter_text_min_chars', 3);
        if ($min_chars < 1) {
            $min_chars = 1;
        }

        $short_samples = array();
        foreach ((array) $entry['_grouped']['text'] as $value) {
            if (!is_string($value)) {
                continue;
            }
            $len = strlen(trim($value));
            if ($len > 0 && $len < $min_chars) {
                $short_samples[] = sprintf('"%s" (%d chars)', mb_substr($value, 0, 20), $len);
            }
        }

        if (empty($short_samples)) {
            return array('detected' => false);
        }

        // Soft warning to allow correction; combines with other signals if needed
        return array(
            'detected'     => true,
            'score'        => 20,
            'reason'       => sprintf('Single-line text too short (< %d chars)', $min_chars),
            'soft_warning' => true,
            'details'      => $short_samples,
        );
    }

    /**
     * Disallow URLs in single-line text fields.
     * One occurrence = soft warning (score 40). Multiple types will sum toward blocking.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_url_in_text_fields($entry)
    {
        if (!isset($entry['_grouped']['text']) || !is_array($entry['_grouped']['text'])) {
            return array('detected' => false);
        }

        $text_values = (array) $entry['_grouped']['text'];
        $content     = implode(' ', $text_values);

        $url_pattern = '/(https?:\/\/[^\s]+|www\.[^\s]+|(?:[a-z0-9-]+\.)+[a-z]{2,}(?:\/[^\s]*)?)/i';
        if (preg_match($url_pattern, $content)) {
            return array(
                'detected'     => true,
                'score'        => 40,
                'reason'       => 'URL found in single-line text field',
                'soft_warning' => true,
            );
        }

        return array('detected' => false);
    }

    /**
     * Disallow email addresses in single-line text fields.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_email_in_text_fields($entry)
    {
        if (!isset($entry['_grouped']['text']) || !is_array($entry['_grouped']['text'])) {
            return array('detected' => false);
        }

        $text_values = (array) $entry['_grouped']['text'];
        $content     = implode(' ', $text_values);

        if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $content)) {
            return array(
                'detected'     => true,
                'score'        => 40,
                'reason'       => 'Email address found in single-line text field',
                'soft_warning' => true,
            );
        }

        return array('detected' => false);
    }

    /**
     * Disallow phone numbers in single-line text fields.
     * Uses a conservative pattern: sequences with 7+ digits allowing separators.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_phone_in_text_fields($entry)
    {
        if (!isset($entry['_grouped']['text']) || !is_array($entry['_grouped']['text'])) {
            return array('detected' => false);
        }

        $text_values = (array) $entry['_grouped']['text'];
        $content     = implode(' ', $text_values);

        // Matches e.g. +43 660 1234567, (06151) 321509, 0688-205-181, 770 978 0991
        $phone_pattern = '/(?:(?:\+|00)?\d{1,3}[\s.-]?)?(?:\(?\d{2,4}\)?[\s.-]?)?\d(?:[\s.-]?\d){6,}/';
        if (preg_match($phone_pattern, $content)) {
            return array(
                'detected'     => true,
                'score'        => 40,
                'reason'       => 'Phone number found in single-line text field',
                'soft_warning' => true,
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
     * Validate website field(s): must be proper URLs and must not be email addresses.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_website_field_validity($entry)
    {
        if (!isset($entry['_grouped']['website']) || !is_array($entry['_grouped']['website'])) {
            return array('detected' => false);
        }

        $issues  = array();
        $score   = 0;
        foreach ((array) $entry['_grouped']['website'] as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }

            // Email mistakenly in website field
            if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $url)) {
                $issues[] = 'Email address provided in website field';
                $score    = max($score, 40);
                continue;
            }

            // Invalid URL format (allow missing scheme but must resemble domain.tld)
            $valid = filter_var($url, FILTER_VALIDATE_URL);
            if (!$valid) {
                // Accept bare domains like example.com optionally with path
                if (!preg_match('/^(?:https?:\/\/)?(?:[a-z0-9-]+\.)+[a-z]{2,}(?:\/[\S]*)?$/i', $url)) {
                    $issues[] = 'Invalid website URL format';
                    $score    = max($score, 20);
                }
            }
        }

        if (!empty($issues)) {
            return array(
                'detected'     => true,
                'score'        => min($score, 60),
                'reason'       => implode('; ', array_unique($issues)),
                'soft_warning' => $score <= 20,
            );
        }

        return array('detected' => false);
    }

    /**
     * Validate email field(s): must be valid email and not contain URLs.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_email_field_validity($entry)
    {
        // Collect email-like fields either from grouping or flat scan fallback
        $emails = array();
        if (isset($entry['_grouped']['email']) && is_array($entry['_grouped']['email'])) {
            $emails = (array) $entry['_grouped']['email'];
        } else {
            foreach ($entry as $value) {
                if (is_string($value) && is_email($value)) {
                    $emails[] = $value;
                }
            }
        }

        if (empty($emails)) {
            return array('detected' => false);
        }

        $issues = array();
        $score  = 0;
        foreach ($emails as $email) {
            if (!is_string($email) || $email === '') {
                continue;
            }
            // Contains URL pattern → clearly wrong
            if (preg_match('/https?:\/\//i', $email) || preg_match('/www\./i', $email)) {
                $issues[] = 'URL found in email field';
                $score    = max($score, 40);
            }
            // Validate email format
            if (!is_email($email)) {
                $issues[] = 'Invalid email address format';
                $score    = max($score, 40);
            }
        }

        if (!empty($issues)) {
            return array(
                'detected' => true,
                'score'    => min($score, 60),
                'reason'   => implode('; ', array_unique($issues)),
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

        // Check for repeated words based on density, not absolute count
        $words = preg_split('/\s+/', $content);
        $total_words = count($words);
        $word_counts = array_count_values($words);

        foreach ($word_counts as $word => $count) {
            if (strlen($word) > 3) {
                // Calculate word density (percentage of total words)
                $density = ($count / $total_words) * 100;

                // Flag if word appears in more than 15% of text (adjustable threshold)
                if ($density > 15) {
                    return array(
                        'detected' => true,
                        'score'    => 15,
                        'reason'   => sprintf('Word "%s" repeated %d times (%.1f%% of text)', $word, $count, $density),
                    );
                }
            }
        }

        return array('detected' => false);
    }

    /**
     * Check for duplicate messages.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_duplicate_message($entry)
    {
        // Only check textarea content for duplicates
        $message_content = '';
        if (isset($entry['_grouped']) && is_array($entry['_grouped'])) {
            $grouped = $entry['_grouped'];
            $message_content = isset($grouped['textarea']) ? implode(' ', (array) $grouped['textarea']) : '';
        }

        if (empty($message_content)) {
            return array('detected' => false);
        }

        // Determine submitter identifier to scope duplicates cautiously
        $submitter_id = '';
        // Prefer email if available
        if (isset($entry['_grouped']['email']) && is_array($entry['_grouped']['email']) && !empty($entry['_grouped']['email'][0])) {
            $submitter_id = strtolower(trim($entry['_grouped']['email'][0]));
        } else {
            // Fallback: scan flat entry for any email-like value
            foreach ($entry as $val) {
                if (is_string($val) && is_email($val)) {
                    $submitter_id = strtolower(trim($val));
                    break;
                }
            }
        }

        // If no email, cautiously fall back to IP if available
        if (empty($submitter_id)) {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
            $submitter_id = $ip;
        }

        // Create hash of message content and scope by submitter identifier
        $message_hash = md5(strtolower(trim($message_content)));
        $scope_hash   = md5((string) $submitter_id);
        $duplicate_key = 'gform_spamfighter_message_' . $message_hash . '_' . $scope_hash;

        // Check if this exact message was submitted before by the same submitter
        $previous_submission = get_transient($duplicate_key);

        if ($previous_submission) {
            // Lower score to avoid blocking solely on a single duplicate
            return array(
                'detected' => true,
                'score'    => 40,
                'reason'   => 'Identical message submitted previously by same submitter',
            );
        }

        // Store this message hash for 24 hours for this submitter
        $day_in_seconds = defined('DAY_IN_SECONDS') ? (int) constant('DAY_IN_SECONDS') : 86400;
        set_transient($duplicate_key, time(), $day_in_seconds);

        return array('detected' => false);
    }

    /**
     * Check for email addresses in message content (not allowed).
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_email_in_message($entry)
    {
        // Get message content
        $message_content = '';
        if (isset($entry['_grouped']) && is_array($entry['_grouped'])) {
            $grouped = $entry['_grouped'];
            $message_content = isset($grouped['textarea']) ? implode(' ', (array) $grouped['textarea']) : '';
        }

        if (empty($message_content)) {
            return array('detected' => false);
        }

        // Check for any email address pattern in message content
        if (preg_match('/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/', $message_content)) {
            return array(
                'detected' => true,
                'score'    => 20,
                'reason'   => 'Email address found in message content (not allowed)',
            );
        }

        return array('detected' => false);
    }

    /**
     * Check for all-caps sentences.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_all_caps_sentences($entry)
    {
        // Only check textarea content
        $message_content = '';
        if (isset($entry['_grouped']) && is_array($entry['_grouped'])) {
            $grouped = $entry['_grouped'];
            $message_content = isset($grouped['textarea']) ? implode(' ', (array) $grouped['textarea']) : '';
        }

        if (empty($message_content)) {
            return array('detected' => false);
        }

        // Split into sentences
        $sentences = preg_split('/[.!?]+/', $message_content);

        foreach ($sentences as $sentence) {
            $sentence = trim($sentence);
            if (strlen($sentence) > 10) { // Only check longer sentences
                // Check if sentence is all caps (excluding punctuation and spaces)
                $clean_sentence = preg_replace('/[^a-zA-Z]/', '', $sentence);
                if (!empty($clean_sentence) && $clean_sentence === strtoupper($clean_sentence)) {
                    return array(
                        'detected' => true,
                        'score'    => 25,
                        'reason'   => 'All-caps sentence detected',
                    );
                }
            }
        }

        return array('detected' => false);
    }

    /**
     * Check for excessive exclamation marks.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_excessive_exclamations($entry)
    {
        // Only check textarea content
        $message_content = '';
        if (isset($entry['_grouped']) && is_array($entry['_grouped'])) {
            $grouped = $entry['_grouped'];
            $message_content = isset($grouped['textarea']) ? implode(' ', (array) $grouped['textarea']) : '';
        }

        if (empty($message_content)) {
            return array('detected' => false);
        }

        // Check for 5+ exclamation marks in sequence
        if (preg_match('/!{5,}/', $message_content)) {
            return array(
                'detected' => true,
                'score'    => 15,
                'reason'   => 'Excessive exclamation marks (5+ in sequence)',
            );
        }

        return array('detected' => false);
    }

    /**
     * Check for business spam terminology.
     *
     * @param array $entry Entry data.
     * @return array
     */
    private function check_business_terminology($entry)
    {
        // Only check textarea content
        $message_content = '';
        if (isset($entry['_grouped']) && is_array($entry['_grouped'])) {
            $grouped = $entry['_grouped'];
            $message_content = isset($grouped['textarea']) ? implode(' ', (array) $grouped['textarea']) : '';
        }

        if (empty($message_content)) {
            return array('detected' => false);
        }

        $content_lower = strtolower($message_content);

        $business_terms = array(
            'net 30',
            'credit application',
            'purchasing officer',
            'payment term',
            'feasible',
            'dear sales team',
            'kind regards',
            'best regards',
            'ascent resources',
        );

        $matches = 0;
        $found_terms = array();

        foreach ($business_terms as $term) {
            if (strpos($content_lower, $term) !== false) {
                $matches++;
                $found_terms[] = $term;
            }
        }

        if ($matches > 0) {
            return array(
                'detected' => true,
                'score'    => min($matches * 5, 20), // Max 20 points
                'reason'   => sprintf('Business terminology found: %s', implode(', ', $found_terms)),
            );
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
