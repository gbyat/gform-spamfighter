=== GForm Spamfighter ===
Contributors: gbyat, webentwicklerin
Author URI: https://webentwicklerin.at
Plugin URI: https://github.com/gbyat/gform-spamfighter
Tags: spam, gravity forms, anti-spam, openai, ai detection, form protection, spam filter
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.1.0
Requires PHP: 8.0
License: GPL v2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Advanced multi-layer spam protection for Gravity Forms with optional AI detection, intelligent pattern analysis, and user-friendly handling of legitimate submissions.

== Description ==

GForm Spamfighter adds multiple layers of protection to Gravity Forms so that spam never reaches your inbox. Pattern recognition, behaviour analysis, duplicate detection, and (optionally) OpenAI checks collaborate to block malicious submissions without frustrating legitimate visitors.

= Highlights =
* Field-aware pattern detection (different rules for single-line text, textarea, email, and website fields)
* Behaviour analysis (submission timing, language consistency, referrer, and user agent checks)
* Duplicate detection scoped to the submitter (email/IP)
* Optional OpenAI verdict respected as hard spam-only when other checks are unsure
* Silent soft warnings: low risk signals are logged for admins but do not block the user
* Complete spam log with full submission data, detection scores, and actions

For a full rule reference see the README.md shipped with the plugin.

== Installation ==

1. Upload the `gform-spamfighter` folder to `/wp-content/plugins/` or install from a ZIP.
2. Activate the plugin via **Plugins → Installed Plugins**.
3. Visit **Spamfighter** in the WordPress admin menu to configure detection options.
4. (Optional) Add an OpenAI API key if you want AI-assisted decisions.

== Frequently Asked Questions ==

= Does the plugin require an OpenAI key? =
No. Pattern, behaviour, and duplicate detection work on their own. OpenAI is only used when enabled.

= Are soft warnings shown to visitors? =
No. Soft warnings are now silent. They are recorded in the spam log so admins can audit signals, but the submission continues so the visitor never sees an interruption.

= Are legitimate submissions stored even when flagged? =
Yes. Every submission is stored as a Gravity Forms entry. Spam entries are marked accordingly, so you can recover false positives.

== Screenshots ==
1. Spam dashboard with statistics and recent detections.
2. Detailed spam log including detection reasons and scores.

== Changelog ==

= 1.1.0 - 2025-11-07 =
* Document the full detection rule set in README.md and WordPress readme.
* Treat OpenAI spam verdicts as hard detections, bypassing the soft-warning path.
* Soft warnings are now silent; submissions continue while warnings are logged for admins.
* Allow a single link/email/phone number inside textarea content while keeping single-line fields strict.
* Ensure every submission is stored as a Gravity Forms entry and logged as spam when blocked.
* Update plugin header metadata (tested up to 6.8, version 1.1.0).

= 1.0.15 - 2025-10-30 =
* Maintenance release.

== Upgrade Notice ==

= 1.1.0 =
Improves detection accuracy, documents all rules, and ensures every submission is recoverable while keeping soft warnings silent for visitors.
*** End Patch
