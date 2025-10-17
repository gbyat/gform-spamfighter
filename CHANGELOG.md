# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
