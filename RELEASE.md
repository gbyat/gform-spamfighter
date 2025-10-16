# Release System

This plugin uses an automated release system based on Node.js scripts and GitHub Actions.

## Prerequisites

- Node.js 16+ installed
- Git repository configured
- GitHub repository: https://github.com/gbyat/gform-spamfighter

## Installation

```bash
npm install
```

## Release Process

### 1. Check Current Status

```bash
npm run status
```

This shows:

- Current version in package.json and plugin file
- Git status
- File structure check
- Next steps

### 2. Create a Release

```bash
# Patch release (1.0.0 → 1.0.1)
npm run release:patch

# Minor release (1.0.0 → 1.1.0)
npm run release:minor

# Major release (1.0.0 → 2.0.0)
npm run release:major
```

### What Happens Automatically:

1. **Version Bump**: Updates version in `package.json`
2. **Sync Version**: Updates version in `gform-spamfighter.php` (Version header + constant)
3. **Update CHANGELOG**: Adds new version entry with git commit messages
4. **Git Add**: Adds all changes to staging
5. **Git Commit**: Commits with message "Release vX.Y.Z"
6. **Git Tag**: Creates annotated tag `vX.Y.Z`
7. **Git Push**: Pushes commit and tag to GitHub
8. **GitHub Actions**: Automatically triggered by tag push

### GitHub Actions Workflow

When a tag is pushed, GitHub Actions will:

1. **Create ZIP**: Bundles plugin files into `gform-spamfighter.zip`

   - Includes: `gform-spamfighter.php`, `includes/`, `assets/`, `uninstall.php`
   - Includes: `README.md`, `CHANGELOG.md`, documentation files
   - Excludes: Development files (scripts, .github, node_modules, etc.)

2. **Create GitHub Release**:

   - Release name: "GFORM Spamfighter vX.Y.Z"
   - Includes auto-generated release notes
   - Attaches `gform-spamfighter.zip` (always the same filename!)

3. **WordPress Updates**: Users can update directly from WordPress dashboard

## CHANGELOG.md

The CHANGELOG is automatically updated during the release process:

- **Auto-populated**: Git commit messages since last release
- **Manual editing**: You can edit `CHANGELOG.md` manually before releasing
- **Format**: Follows [Keep a Changelog](https://keepachangelog.com/) format

### Example Entry:

```markdown
## [1.0.1] - 2025-10-16

- Fix: Settings page hook name corrected
- Fix: WordPress Coding Standards compliance
- Added: Translators comments for i18n strings
```

## Important Files

- `package.json` - Version source of truth
- `scripts/release.js` - Main release automation script
- `scripts/sync-version.js` - Syncs version to plugin file and CHANGELOG
- `scripts/status.js` - Status report script
- `.github/workflows/release.yml` - GitHub Actions workflow
- `CHANGELOG.md` - Changelog with release notes

## Manual Release (if needed)

If you need to create a release manually:

```bash
# 1. Bump version
npm version patch  # or minor, major

# 2. Sync version
node scripts/sync-version.js

# 3. Commit and tag
git add -A
git commit -m "Release vX.Y.Z"
git tag -a "vX.Y.Z" -m "Release vX.Y.Z"

# 4. Push
git push origin main
git push origin vX.Y.Z
```

## Troubleshooting

### "Nothing to commit"

This is normal if no files changed. The script continues.

### "Tag already exists"

The script automatically removes old tags before creating new ones.

### GitHub Actions failed

Check: https://github.com/gbyat/gform-spamfighter/actions

### Version mismatch

Run: `node scripts/sync-version.js`

## Workflow Example

```bash
# Make some changes to the plugin
# ...edit files...

# Check status
npm run status

# Create patch release
npm run release:patch

# ✅ Done!
# - Version bumped to 1.0.1
# - CHANGELOG.md updated
# - Git commit and tag created
# - Pushed to GitHub
# - GitHub Actions creates ZIP and release
```

## GitHub Release URL

After release, find it at:
https://github.com/gbyat/gform-spamfighter/releases

## 🔄 Automatic WordPress Updates

Das Plugin hat ein **eigenes GitHub Update-System**:

### ✅ Features

- Update-Benachrichtigungen im WordPress Dashboard
- Ein-Klick-Update direkt in WordPress (wie WordPress.org Plugins)
- Zeigt CHANGELOG.md im Update-Dialog
- Zeigt README.md als Plugin-Beschreibung
- **Kein GitHub Token notwendig** (öffentliches Repo)
  - Ohne Token: 60 Requests/Stunde (völlig ausreichend!)
  - Mit Token: 5000 Requests/Stunde (optional)

### 🔧 Wie es funktioniert

1. Plugin prüft automatisch GitHub Releases
2. Wenn neue Version verfügbar: Badge im Dashboard
3. User klickt auf "Update" → WordPress lädt `gform-spamfighter.zip`
4. Installation automatisch
5. Plugin bleibt aktiviert

### 📝 Update-Dialog

- **Beschreibung**: Aus README.md
- **Changelog**: Aus CHANGELOG.md (automatisch formatiert)
- **Installation**: Schritt-für-Schritt Anleitung

## Notes

- **ZIP filename**: Always `gform-spamfighter.zip` (never `v1.0.0.zip`)
- **Semantic Versioning**: Follow semver.org guidelines
- **No Build Step**: This is a pure PHP plugin (no webpack/npm build)
- **Auto-updates**: Built-in GitHub updater (see `includes/core/class-github-updater.php`)
