# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.2.9] - 2025-12-16

- Refactor dashboard styles and functionality: update CSS for improved layout and typography, remove unused clear strikes button, implement bulk delete and pagination for spam logs, and enhance database methods for log management.
- Update CHANGELOG for version 1.2.8: Fix PHP warnings, database errors, and enhance Multisite compatibility; add new methods for table verification and error handling.


## [1.2.8] - 2025-12-15

### Fixed

- Fixed PHP warning: `GFFormsModel::get_field_value()` now correctly passes field by reference
- Fixed database error: `Unknown column 'is_spam'` - now uses `GFAPI::mark_entry_spam()` when available, with fallback to status-only update
- Fixed missing database tables after plugin updates - tables are now automatically created/verified on plugin initialization
- Fixed database table errors in Multisite environments - tables are automatically repaired when errors occur

### Changed

- Improved database table management for Multisite compatibility
  - Dynamic table name generation using `get_table_name()` method instead of static property
  - Fully compatible with `switch_to_blog()` operations
  - Each subsite automatically gets its own database table (e.g., `wp_2_gform_spam_logs`, `wp_3_gform_spam_logs`)
- Enhanced automatic table maintenance
  - Tables are automatically repaired when database errors occur (missing table, unknown column, etc.)
  - No proactive checks on every request - only repairs when needed
  - Self-healing: automatically recovers from database issues without manual intervention

### Added

- `verify_and_repair_table()` method for automatic database table structure verification and repair
- `table_exists()` helper method for checking table existence
- Automatic error detection and recovery in `log_spam()` and `get_spam_logs()` methods
- Improved error handling in `handle_after_submission()` with try/catch blocks

## [1.2.7] - 2025-12-15

- Version update

## [1.2.6] - 2025-12-10

- Update release process to include languages directory and modify .gitignore to exclude package-lock.json

## [1.2.5] - 2025-12-10

- Update tested compatibility to WordPress 6.9 in README and readme.txt files

## [1.2.4] - 2025-12-10

- Add sync-version script and enhance release process with branch detection and remote repository check

## [1.2.3] - 2025-12-09

- Ensure plugin data is available before processing version updates in the Updater class

## [1.2.2] - 2025-12-08

- Refactor README.md to emphasize AI-powered spam detection and simplify feature descriptions

## [1.2.1] - 2025-11-10

- Version update

## [1.2.0] - 2025-11-10

- Version update

## [1.1.5] - 2025-11-10

- Version update

## [1.1.4] - 2025-11-10

- Version update

## [1.1.3] - 2025-11-08

- Version update

## [1.1.2] - 2025-11-08

- Version update

## [1.1.1] - 2025-11-08

- Version update

## [1.1.0] - 2025-11-07

### Added

- Documented full detection rule set (field-specific behavior, soft warning handling) in `README.md`.

### Changed

- Treat OpenAI spam verdicts as hard detections, bypassing the soft-warning path.
- Soft warnings are now silent: submissions continue, warnings are only logged for admins.
- Allow a single link/email/phone number inside textarea content while keeping strict rules for single-line fields.
- Updated plugin header metadata (`Tested up to 6.8`, version bump to 1.1.0).

### Fixed

- Prevent ISO date/time strings from being flagged as phone numbers in text fields.
- Ensure all submissions (even when rejected) are stored as Gravity Forms entries and logged in the spam table.

## [1.0.15] - 2025-10-30

- Version update

## [1.0.14] - 2025-10-30

- Version update

## [1.0.13] - 2025-10-30

- Version update

## [1.0.12] - 2025-10-30

- Version update

## [1.0.11] - 2025-10-28

- Version update

## [1.0.10] - 2025-10-28

- Update CHANGELOG to remove added features from v1.0.9
- Revert "Implement database migration support and update logging structure"
- Revert "Release v1.0.10"

## [1.0.9] - 2025-10-23

- Add advanced spam detection patterns
- Update CHANGELOG for v1.0.8 with detailed changes

## [1.0.8] - 2025-10-22

### Fixed

- Suspicious number sequence excludes phone/number fields

## [1.0.7] - 2025-10-21

### Fixed

- URL parameter detection now only checks website fields
- Hidden fields can be excluded via settings

### Changed

- Default block action changed to "mark" instead of "reject"
- Minimum submission time increased to 5 seconds
- Hidden fields excluded by default

### Added

- GPT-5 and GPT-5 mini model options
- Exclude hidden fields setting checkbox
- Cost estimate disclaimer in OpenAI settings
- POT file generation system (npm run pot)
- Languages directory included in releases

### Fixed

- URL parameter detection now only checks website fields
- Hidden fields can be excluded via settings

### Changed

- Default block action changed to "mark" instead of "reject"
- Minimum submission time increased to 5 seconds
- Hidden fields excluded by default

### Added

- GPT-5 and GPT-5 mini model options
- Exclude hidden fields setting
- Cost estimate disclaimer in settings

## [1.0.6] - 2025-10-20

- Version update

## [1.0.5] - 2025-10-17

- Version update

## [1.0.4] - 2025-10-17

HEAD~10)..HEAD --oneline --pretty=format:"- %s"

## [1.0.3] - 2025-10-17

HEAD~10)..HEAD --oneline --pretty=format:"- %s"

## [1.0.2] - 2025-10-16

HEAD~10)..HEAD --oneline --pretty=format:"- %s"

## [1.0.1] - 2025-10-16

HEAD~10)..HEAD --oneline --pretty=format:"- %s"

## [1.0.0] - 2025-10-16

### Added

- Initial release of GFORM Spamfighter
- Multi-layer spam detection system
  - Pattern-based detection (links, keywords, suspicious URLs)
  - Behavior analysis (submission time, referrer, user agent)
  - OpenAI integration (GPT models and free Moderation API)
- Soft warning system for single links with strike lockout
- WordPress admin dashboard with statistics
- Real-time spam log viewing with detailed modal
- Configurable spam threshold and detection methods
- Email notifications for spam detection
- Automatic log cleanup via WP-Cron
- Multisite compatibility (8 language versions support)
- WordPress Coding Standards compliant
- Security hardened (prepared statements, nonces, capability checks)
- No external dependencies (no CDN resources)

### Features

- **Smart AI Detection**: Only calls OpenAI API when pattern/behavior detection is uncertain (70% cost savings)
- **Spam Referrer Detection**: Blocks known spam referrers like `syndicatedsearch.goog`
- **URL Parameter Detection**: Automatically flags URLs with tracking parameters
- **Duplicate Detection**: Prevents duplicate submissions from same IP
- **Language Consistency Check**: Compares submission language with site locale
- **Strike System**: Locks form after ignoring soft warning
- **Custom Database Table**: Efficient spam log storage with indexes
- **WordPress Dashboard Integration**: Settings page and statistics dashboard

### Technical

- Namespace: `GformSpamfighter\`
- Custom table: `wp_gform_spam_logs`
- Hooks into Gravity Forms validation pipeline
- Uses WP_Filesystem API for file operations
- Follows WordPress Coding Standards
- kebab-case file naming convention
- Prepared statements for all database queries

### Documentation

- README.md with feature overview
- INSTALL.md with installation instructions
- SECURITY.md with security best practices
- TEST.md with testing checklist
- TROUBLESHOOTING.md with common issues
- AGENT.md with complete technical documentation

[1.0.0]: https://github.com/gbyat/gform-spamfighter/releases/tag/v1.0.0
[1.2.4]: https://github.com/gbyat/gform-spamfighter/releases/tag/v1.2.4
[1.2.5]: https://github.com/gbyat/gform-spamfighter/releases/tag/v1.2.5
[1.2.6]: https://github.com/gbyat/gform-spamfighter/releases/tag/v1.2.6
[1.2.7]: https://github.com/gbyat/gform-spamfighter/releases/tag/v1.2.7
[1.2.8]: https://github.com/gbyat/gform-spamfighter/releases/tag/v1.2.8
[1.2.9]: https://github.com/gbyat/gform-spamfighter/releases/tag/v1.2.9
