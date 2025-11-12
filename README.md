# GForm Spamfighter

**Contributors:** gbyat, webentwicklerin  
**Tags:** spam, gravity forms, anti-spam, openai, ai detection, form protection, spam filter  
**Requires at least:** 6.0  
**Tested up to:** 6.8  
**Stable tag:** 1.2.1  
**Requires PHP:** 8.0  
**License:** GPL v2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

AI-powered spam protection for Gravity Forms using OpenAI's advanced detection models.

## Description

GForm Spamfighter is a WordPress plugin designed to combat modern spam submissions in Gravity Forms, including AI-generated content and automated bot attacks. It uses OpenAI's sophisticated detection models to provide robust protection while maintaining an excellent user experience for legitimate submissions.

### 🛡️ Core Features

- **OpenAI-Powered Detection**
  - 🆓 **Moderation API (FREE!)** - Content policy violations
  - 💰 **GPT Models** - Advanced spam/context detection (~$0.15/1000 checks)
  - Supports: gpt-4o-mini, gpt-4o, gpt-4-turbo, gpt-3.5-turbo
  - API key securely stored in wp-config.php or database
  - Intelligent context analysis of entire form submissions
  - Language detection and consistency checks
  - Confidence scoring with configurable thresholds

### 📊 Admin Features

- **Comprehensive Dashboard**

  - Spam statistics (30 days)
  - Visual trend charts (CSS-based, no external dependencies)
  - Detection method breakdown
  - Most targeted forms analysis

- **Detailed Spam Logs**

  - Complete form data for review
  - OpenAI detection details and scores
  - "View Details" modal with all submission data
  - Automatic cleanup (configurable retention period)
  - Email notifications with full data

- **Testing & Debugging**
  - Test OpenAI connection (AJAX-based)
  - Debug logging (when WP_DEBUG enabled)

### 🌍 Multisite Support

- Fully compatible with WordPress Multisite
- Per-site configuration
- Language detection adapts to each subsite's locale
- Statistics filterable by site
- Perfect for international websites with multiple language versions

### 🔒 Security First

- WordPress Coding Standards compliant
- No external CDN dependencies (all assets local)
- SQL injection protected (prepared statements, whitelists)
- XSS protected (proper escaping everywhere)
- CSRF protected (nonces on all forms)
- Capability checks (`manage_options`)
- WP_Filesystem for file operations
- Rate limiting (60 API calls/hour per IP)
- Secure API key storage (wp-config.php support)

## Installation

1. Upload the `gform-spamfighter` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Navigate to **Settings → Spamfighter** to configure
4. (Optional) Add your OpenAI API key for AI-powered detection

For detailed installation instructions, see [INSTALL.md](INSTALL.md)

## Requirements

- WordPress 6.0 or higher
- PHP 8.0 or higher
- Gravity Forms plugin (latest version recommended)
- OpenAI API key **REQUIRED** (for AI-powered spam detection)

**Note:** The plugin requires an OpenAI API key to function. You can use the free Moderation API or any GPT model for advanced detection.

## Quick Start Configuration

### Recommended Settings

```
✅ Enable Spam Protection
✅ Enable OpenAI Detection
   Model: 🆓 Moderation API (FREE) or GPT-4o Mini (~$0.15/1000)
   API Key: [Your OpenAI key]
   Threshold: 0.7
   Block Action: Mark as spam (recommended) or Reject submission
```

This configuration uses OpenAI's advanced AI models to detect spam with high accuracy.

## How It Works

### AI-Powered Detection Flow

1. **Form Submission Received**

   - Gravity Forms validates required fields and basic format
   - Plugin intercepts submission before entry is saved

2. **OpenAI Analysis**

   - Complete form data is sent to OpenAI for analysis
   - OpenAI evaluates content context, language, patterns, and intent
   - Returns spam score (0.0 - 1.0) and reasoning

3. **Spam Decision**

   - If score ≥ threshold: **SPAM!**
   - Entry is marked as spam in Gravity Forms spam tab
   - Notifications are disabled for spam entries
   - User sees confirmation message (if block action is "mark")

4. **Legitimate Submission**

   - If score < threshold: **LEGITIMATE**
   - Entry is saved normally
   - All notifications and webhooks proceed as configured

## Configuration Options

### General Settings

- Enable/disable spam protection globally
- Choose block action: Reject or Mark as spam

### OpenAI Integration

- Multiple model options (Moderation API free!)
- Configurable spam threshold (0.0 - 1.0)
- API key via wp-config.php or settings
- Rate limiting (60 API calls/hour per IP)

### Advanced

- Log retention period (7-365 days)
- Email notifications for blocked spam (optional)

## Dashboard & Reporting

Access comprehensive analytics at **Spamfighter → Dashboard**:

- Total spam blocked (30 days)
- Daily spam trends (CSS bar charts)
- Detection method breakdown
- Most targeted forms
- Detailed spam logs with "View Details" modal
- Clear strikes button (admin testing)

All spam attempts logged with:

- Complete form data
- Detection scores and methods
- IP address, user agent, referrer
- Timestamp and site information
- Action taken (rejected, soft_warning, marked)

## Multisite Usage

Perfect for international websites with multiple language versions:

- Network activate for all sites or per-site activation
- Independent settings per subsite
- Automatic language detection per locale
- Statistics filterable by site
- Works seamlessly across 8+ language subsites

## Performance

- **OpenAI API Call:** ~1-2 seconds
- **Total:** Minimal impact on user experience
- **Rate Limiting:** 60 API calls/hour per IP to prevent abuse

**Cost Optimization:**

- Moderation API: 100% free OpenAI usage!
- GPT-4o Mini: ~$0.15 per 1000 submissions
- GPT-4o: ~$0.30 per 1000 submissions

## Privacy & Data Protection

- GDPR compliant when configured appropriately
- IP addresses stored only for spam detection
- Configurable log retention (auto-cleanup)
- Form data in spam logs, not in public entries
- OpenAI: Data sent only for analysis (when enabled)
- No data shared with third parties except OpenAI (optional)

## Advanced Features

### Spam Entry Handling

- All spam entries are saved in Gravity Forms spam tab for review
- Allows recovery of false positives
- No data loss - all submissions are preserved
- Notifications and webhooks are automatically disabled for spam

### Smart Detection

- Context-aware analysis of entire form submission
- Language detection and consistency checks
- Pattern recognition for promotional content, bot-generated text, and suspicious links
- Configurable thresholds and sensitivity

## Filter Hooks

Extend and customize the plugin:

```php
// Adjust rate limiting
add_filter('gform_spamfighter_rate_limit_max', fn() => 100);

// Customize validation messages
add_filter('gform_spamfighter_validation_message', fn($msg) => 'Custom spam message');

// Modify OpenAI request before sending
add_filter('gform_spamfighter_openai_request', function($request, $form_data) {
    // Modify $request array before API call
    return $request;
}, 10, 2);
```

## Troubleshooting

See detailed troubleshooting guide in [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

Common issues:

- Legitimate submissions blocked → Lower threshold (try 0.8 or 0.9)
- Spam still getting through → Lower threshold or switch to GPT-4o for better detection
- OpenAI API errors → Check API key, credits, and server connectivity
- Form submission hangs → Check server error logs for PHP warnings/errors

## Support & Documentation

- **Installation Guide:** [INSTALL.md](INSTALL.md)
- **Security Policy:** [SECURITY.md](SECURITY.md)
- **Testing Guide:** [TEST.md](TEST.md)
- **Troubleshooting:** [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

For bug reports, feature requests, or support:

- Email: gabriele.laesser@webentwicklerin.at
- Website: https://webentwicklerin.at

## Changelog

### 1.2.1 (2025-11-10)

- **Major Refactor: OpenAI-Only Mode**
  - Removed all pattern detection and behavior analysis checks
  - Simplified to pure OpenAI-based spam detection
  - Improved reliability and reduced complexity
  - All spam entries saved in Gravity Forms spam tab
  - Notifications automatically disabled for spam entries
  - Removed debug logging from production code
  - Hidden pattern/behavior settings from admin UI

### 1.0.0 (2025-10-16)

- Initial release
- Multi-layer spam detection (Pattern, Behavior, Duplicate)
- Optional OpenAI integration with cost optimization
- Soft warning system for single links
- Strike-based form lockout (15 minutes)
- Field-type specific detection rules
- Spam referrer database (syndicatedsearch.goog, etc.)
- URL parameter blocking
- Minimum word count validation
- Dashboard with statistics and charts
- Spam logs with "View Details" modal
- Admin "Clear Strikes" testing button
- Daily automatic log cleanup (WP-Cron)
- Comprehensive security (SQL injection, XSS, CSRF protection)
- No external CDN dependencies
- Multisite support
- WordPress Coding Standards compliant

## Credits

- **Author:** webentwicklerin, Gabriele Laesser
- **Author URI:** https://webentwicklerin.at
- **License:** GPL v2 or later
- **GitHub:** https://github.com/gbyat/gform-spamfighter

## License

This plugin is licensed under the GPL v2 or later.

```
Copyright (C) 2025 Gabriele Laesser

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.
```

---

## Why GForm Spamfighter?

**Traditional spam filters miss modern threats:**

- ❌ Honeypots alone can't stop AI-generated spam
- ❌ Simple keyword filters miss sophisticated attacks
- ❌ Pattern-based rules create false positives

**GForm Spamfighter provides:**

- ✅ AI-powered detection using OpenAI's advanced models
- ✅ Context-aware analysis of entire form submissions
- ✅ High accuracy with minimal false positives
- ✅ All spam entries saved for review (no data loss)
- ✅ Admin insights (detailed logs, statistics)
- ✅ Secure & compliant (GDPR, WordPress standards)
- ✅ Multisite ready (perfect for international sites)

**Built for real-world international websites with high traffic and multiple forms.**
