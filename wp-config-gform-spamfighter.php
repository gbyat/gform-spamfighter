<?php

/**
 * GForm Spamfighter Configuration Example
 * 
 * Add these lines to your wp-config.php file (before "That's all, stop editing!")
 * for advanced configuration.
 *
 * NOTE: Hidden fields can be excluded globally via Settings → Exclude Hidden Fields checkbox.
 *       This filter is for excluding specific field NAMES (by label or field_X ID).
 *
 * @package GformSpamfighter
 */

// Exclude specific field names from spam analysis
add_filter('gform_spamfighter_excluded_fields', function ($fields) {
    // Exclude common campaign tracking fields by name
    $fields[] = 'utm_source';
    $fields[] = 'utm_campaign';
    $fields[] = 'utm_medium';
    $fields[] = 'utm_term';
    $fields[] = 'utm_content';

    // Google Click ID
    $fields[] = 'gclid';

    // Facebook Click ID
    $fields[] = 'fbclid';

    // Other tracking parameters
    $fields[] = 'ref';
    $fields[] = 'source';
    $fields[] = 'campaign_id';

    // Add your custom hidden field names here
    // $fields[] = 'your_custom_field_name';

    return $fields;
});

// Optional: Set OpenAI API key securely (recommended over database storage)
// define('GFORM_SPAMFIGHTER_OPENAI_KEY', 'sk-your-api-key-here');
