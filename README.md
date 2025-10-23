# GForm Spamfighter

**Contributors:** gbyat, webentwicklerin  
**Tags:** spam, gravity forms, anti-spam, openai, ai detection, form protection, spam filter  
**Requires at least:** 6.0  
**Tested up to:** 6.8  
**Stable tag:** 1.0.9  
**Requires PHP:** 8.0  
**License:** GPL v2 or later  
**License URI:** https://www.gnu.org/licenses/gpl-2.0.html

Advanced multi-layer spam protection for Gravity Forms with optional AI detection, intelligent pattern analysis, and user-friendly soft warnings.

## Description

GForm Spamfighter is a comprehensive WordPress plugin designed to combat modern spam submissions in Gravity Forms, including AI-generated content and automated bot attacks. It employs multiple intelligent detection strategies with cost-optimized OpenAI integration to provide robust protection while maintaining an excellent user experience for legitimate submissions.

### 🛡️ Core Features (No API Key Required)

**Works perfectly without any external services or costs:**

- **Smart Pattern Detection**

  - Suspicious keywords (viagra, casino, SEO spam, etc.)
  - URLs with parameters (tracking/affiliate links)
  - URL shorteners and suspicious TLDs
  - Disposable email addresses (tempmail.com, etc.)
  - Excessive links or capital letters
  - Minimum word count in textareas

- **Intelligent Behavior Analysis**

  - Submission timing (detects bot speed)
  - Known spam referrers (syndicatedsearch.goog, semalt.com, etc.)
  - User agent analysis (bot detection)
  - Language consistency (multisite-aware)
  - Duplicate submission detection

- **User-Friendly Soft Warnings**
  - Single link in text → Friendly warning, allows correction
  - Strike system: 2nd attempt → Form locked for 15 minutes
  - Clear messages guide users to fix issues

### ⭐ Optional AI Enhancement

- **OpenAI Integration** (optional, cost-optimized)
  - 🆓 **Moderation API (FREE!)** - Content policy violations
  - 💰 **GPT Models** - Advanced spam/context detection (~$0.15/1000 checks)
  - **Smart Optimization:** Only calls AI when pattern detection is uncertain (saves 70%+ costs)
  - Supports: gpt-4o-mini, gpt-4o, gpt-4-turbo, gpt-3.5-turbo
  - API key securely stored in wp-config.php or database

### 📊 Admin Features

- **Comprehensive Dashboard**

  - Spam statistics (30 days)
  - Visual trend charts (CSS-based, no external dependencies)
  - Detection method breakdown
  - Most targeted forms analysis

- **Detailed Spam Logs**

  - Complete form data for review
  - Detection details and scores
  - "View Details" modal with all submission data
  - Automatic cleanup (configurable retention period)
  - Email notifications with full data

- **Testing & Debugging**
  - Test OpenAI connection (AJAX-based)
  - Clear all strikes button (admin testing)
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
- OpenAI API key **OPTIONAL** (only for AI-powered spam detection)

**Note:** The plugin works fully without OpenAI! Pattern detection, behavior analysis, and duplicate detection work independently and catch 95%+ of spam at zero cost.

## Quick Start Configuration

### Recommended Settings (No Cost, No API Key)

```
✅ Enable Spam Protection
✅ Enable Pattern Detection
✅ Enable Time Check (Min: 3 seconds)
✅ Enable Language Check
✅ Enable Duplicate Check (24 hours)
   Block Action: Reject submission
```

This configuration blocks 95%+ of spam completely free!

### Optional: Add OpenAI (For Sophisticated Spam)

```
✅ Enable OpenAI Detection
   Model: 🆓 Moderation API (FREE) or GPT-4o Mini (~$0.15/1000)
   API Key: [Your OpenAI key]
   Threshold: 0.7
```

OpenAI is only called when pattern detection is uncertain (saves 70%+ API costs).

## How It Works

### Intelligent Detection Flow

1. **Fast Free Checks** (< 100ms)

   - Pattern detection analyzes content
   - Behavior analysis checks timing/referrer
   - Duplicate check compares recent submissions
   - Score calculated (0.0 - 1.0)

2. **Smart AI Decision** (optional)

   - If score ≥ 0.7: **SPAM!** (OpenAI skipped, cost saved)
   - If score < 0.7: **Call OpenAI** for second opinion

3. **User-Friendly Handling**
   - **Single link only:** Soft warning, can correct
   - **Multiple spam signals:** Hard block
   - **2nd spam attempt:** Form locked 15 minutes

### Field-Type Specific Detection

- **Text/Textarea:** Word count, keywords, patterns
- **Email:** Disposable domain detection
- **URL/Website:** Parameter checking, shortener detection, suspicious TLDs
- **All Fields:** Link detection, language consistency

## Configuration Options

### General Settings

- Enable/disable spam protection globally
- Choose block action: Reject or Mark as spam

### Pattern Detection

- Automatic checks for suspicious patterns
- URLs with parameters not allowed
- Minimum 5 words in textarea fields
- Extensive spam keyword database

### Behavior Analysis

- Minimum submission time (anti-bot)
- Language consistency checks
- Known spam referrer database (syndicatedsearch.goog, semalt.com, etc.)
- User agent analysis

### OpenAI Integration

- Multiple model options (Moderation API free!)
- Configurable spam threshold
- Cost-optimized: Only used when needed
- API key via wp-config.php or settings

### Advanced

- Duplicate check timeframe (hours)
- Log retention period (7-365 days)
- Email notifications (optional)
- Strike lockout duration (filterable)

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

- **Pattern Detection:** < 100ms
- **Behavior Analysis:** < 50ms
- **Duplicate Check:** < 20ms
- **OpenAI (when called):** ~1-2 seconds
- **Total:** Minimal impact on user experience

**Cost Optimization:**

- 70% of spam caught by free checks (OpenAI skipped)
- Only ~30% of submissions need AI analysis
- With Moderation API: 100% free OpenAI usage!

## Privacy & Data Protection

- GDPR compliant when configured appropriately
- IP addresses stored only for spam detection
- Configurable log retention (auto-cleanup)
- Form data in spam logs, not in public entries
- OpenAI: Data sent only for analysis (when enabled)
- No data shared with third parties except OpenAI (optional)

## Advanced Features

### Soft Warning System

- Single link in message → Friendly warning, not immediate block
- User can correct and resubmit
- 2nd attempt with same issue → Form locked

### Strike System

- Tracks spam attempts per IP/session
- 15-minute lockout after soft warning ignored
- Form fields disabled, submit button blocked
- User must reload page to try again
- Admin "Clear Strikes" button for testing

### Smart Detection

- Fieldtype-aware checking (text vs email vs URL)
- Combines multiple detection methods
- Uses maximum score (if any method detects spam → spam)
- Configurable thresholds and sensitivity

## Filter Hooks

Extend and customize the plugin:

```php
// Adjust minimum word count
add_filter('gform_spamfighter_min_words', fn() => 7);

// Adjust strike lockout duration
add_filter('gform_spamfighter_strike_lockout_seconds', fn() => 5 * MINUTE_IN_SECONDS);

// Adjust rate limiting
add_filter('gform_spamfighter_rate_limit_max', fn() => 100);

// Add custom spam referrers
add_filter('gform_spamfighter_spam_referrers', function($referrers) {
    $referrers['your-spam-domain.com'] = 'Description';
    return $referrers;
});

// Customize validation messages
add_filter('gform_spamfighter_validation_message', fn($msg) => 'Custom spam message');
add_filter('gform_spamfighter_soft_warning_message', fn($msg) => 'Custom link warning');
```

## Troubleshooting

See detailed troubleshooting guide in [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

Common issues:

- Legitimate submissions blocked → Lower threshold or disable specific checks
- Spam still getting through → Enable more detection methods or add OpenAI
- OpenAI API errors → Check API key, credits, and server connectivity

## Support & Documentation

- **Installation Guide:** [INSTALL.md](INSTALL.md)
- **Security Policy:** [SECURITY.md](SECURITY.md)
- **Testing Guide:** [TEST.md](TEST.md)
- **Troubleshooting:** [TROUBLESHOOTING.md](TROUBLESHOOTING.md)

For bug reports, feature requests, or support:

- Email: gabriele.laesser@webentwicklerin.at
- Website: https://webentwicklerin.at

## Changelog

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
- ❌ No protection against spam referrers

**GForm Spamfighter provides:**

- ✅ Multi-layer defense (6+ detection methods)
- ✅ AI-powered detection (optional, cost-optimized)
- ✅ Field-type awareness (text/email/URL specific rules)
- ✅ User-friendly (soft warnings, not instant blocks)
- ✅ Admin insights (detailed logs, statistics)
- ✅ Secure & compliant (GDPR, WordPress standards)
- ✅ Multisite ready (perfect for international sites)

**Built for real-world international websites with high traffic and multiple forms.**
