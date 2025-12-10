const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

// Read package.json
const packagePath = path.join(__dirname, '..', 'package.json');
const packageData = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
const version = packageData.version;

console.log(`📦 Syncing version to ${version}...`);

// Read plugin file
const pluginPath = path.join(__dirname, '..', 'gform-spamfighter.php');
let pluginContent = fs.readFileSync(pluginPath, 'utf8');

// Update version in plugin file header
pluginContent = pluginContent.replace(
    /Version:\s*\d+\.\d+\.\d+/,
    `Version: ${version}`
);

// Update GFORM_SPAMFIGHTER_VERSION constant
pluginContent = pluginContent.replace(
    /define\(\s*'GFORM_SPAMFIGHTER_VERSION',\s*'[^']*'\s*\);?/,
    `define('GFORM_SPAMFIGHTER_VERSION', '${version}');`
);

// Write updated plugin file
fs.writeFileSync(pluginPath, pluginContent);
console.log(`✅ Updated gform-spamfighter.php`);

// Update README.md stable tag (WordPress standard format) and strip accidental changelog noise
const readmePath = path.join(__dirname, '..', 'README.md');
if (fs.existsSync(readmePath)) {
    let readmeContent = fs.readFileSync(readmePath, 'utf8');

    // ALWAYS remove everything before "# GFORM Spamfighter" - no exceptions
    // This ensures no changelog noise, release notes, or any other content gets into README
    const h1Pattern = /^# GFORM Spamfighter/m;
    const match = readmeContent.match(h1Pattern);
    if (match && match.index > 0) {
        // Cut everything before the H1
        readmeContent = readmeContent.slice(match.index);
    } else if (!match) {
        // If H1 not found, this is a problem - but don't fail silently
        console.warn('⚠️  Warning: "# GFORM Spamfighter" heading not found in README.md');
    }

    // Update Stable tag line (Markdown format: "**Stable tag:** X.Y.Z")
    readmeContent = readmeContent.replace(
        /\*\*Stable tag:\*\*\s*\d+\.\d+\.\d+/,
        `**Stable tag:** ${version}`
    );

    fs.writeFileSync(readmePath, readmeContent);
    console.log(`✅ Updated README.md stable tag to ${version}`);
}

// Update CHANGELOG.md
const changelogPath = path.join(__dirname, '..', 'CHANGELOG.md');
if (!fs.existsSync(changelogPath)) {
    // Create initial CHANGELOG.md
    const initialContent = `# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [${version}] - ${new Date().toISOString().split('T')[0]}

### Added
- Initial release of GFORM Spamfighter

`;
    fs.writeFileSync(changelogPath, initialContent);
    console.log(`📝 Created CHANGELOG.md`);
} else {
    let changelogContent = fs.readFileSync(changelogPath, 'utf8');

    // Check if this version already exists in changelog
    const versionPattern = new RegExp(`## \\[${version.replace(/\./g, '\\.')}\\]`);
    if (!versionPattern.test(changelogContent)) {
        // Get current date
        const dateStr = new Date().toISOString().split('T')[0];

        // Extract all commit messages already in CHANGELOG to avoid duplicates
        const existingCommits = new Set();
        const changelogLines = changelogContent.split('\n');
        changelogLines.forEach(line => {
            // Match lines that start with "- " (changelog entries)
            const match = line.match(/^-\s+(.+)$/);
            if (match) {
                const commitMsg = match[1].trim();
                existingCommits.add(commitMsg);
            }
        });

        // Get git commits since last tag
        let gitLog = '';
        try {
            // First, try to get the last tag
            let lastTag = '';
            try {
                lastTag = execSync('git describe --tags --abbrev=0', {
                    encoding: 'utf8',
                    stdio: ['pipe', 'pipe', 'ignore']
                }).trim();
            } catch (e) {
                // No tags yet, use all commits
                lastTag = '';
            }

            // Get commits since last tag (or last 20 if no tags)
            // Exclude release commits and merge commits
            // Use a unique separator to split commits, then get full message
            const separator = '|||COMMIT_SEPARATOR|||';
            const gitCommand = lastTag
                ? `git log ${lastTag}..HEAD --pretty=format:"${separator}%B" --no-merges`
                : `git log -20 --pretty=format:"${separator}%B" --no-merges`;

            let commitMessages = execSync(gitCommand, {
                encoding: 'utf8',
                stdio: ['pipe', 'pipe', 'ignore']
            }).trim();

            // Split by our unique separator
            let allCommits = commitMessages.split(separator)
                .map(commit => commit.trim())
                .filter(commit => commit.length > 0)
                .map(commit => {
                    // Clean up the commit message
                    const lines = commit.split('\n');
                    // Remove empty lines at start/end, but preserve structure
                    const cleaned = lines.filter(line => line.trim().length > 0);
                    return cleaned.join('\n').trim();
                })
                .filter(commit => {
                    // Filter out release commits and empty commits
                    const trimmed = commit.trim();
                    return trimmed &&
                        !trimmed.match(/^Release v\d+\.\d+\.\d+$/i) &&
                        !trimmed.match(/^Bump version/i) &&
                        !trimmed.match(/^Version update$/i);
                });

            // Filter out commits that are already in CHANGELOG
            // Check only the first line (subject) for duplicates
            const newCommits = allCommits.filter(commit => {
                const firstLine = commit.split('\n')[0].trim();
                return !existingCommits.has(firstLine);
            });

            // Format as changelog entries
            // For multi-line commits, format as: "Subject     Body line 1     Body line 2" (5 spaces separator)
            if (newCommits.length > 0) {
                gitLog = newCommits.map(commit => {
                    const lines = commit.split('\n').map(l => l.trim()).filter(l => l.length > 0);
                    const subject = lines[0];
                    const body = lines.slice(1);

                    if (body.length > 0) {
                        // Multi-line commit: all in one line separated by 5 spaces
                        return `- ${subject}     ${body.join('     ')}`;
                    } else {
                        // Single-line commit
                        return `- ${subject}`;
                    }
                }).join('\n');
            } else {
                gitLog = '';
            }
        } catch (e) {
            // Fallback if git fails
            gitLog = '- Version update';
        }

        // Get unreleased changes if they exist
        const unreleasedMatch = changelogContent.match(/## \[Unreleased\]([\s\S]*?)(?=## \[|$)/);
        let unreleasedContent = '';
        if (unreleasedMatch && unreleasedMatch[1]) {
            unreleasedContent = unreleasedMatch[1].trim();
        }

        // Combine unreleased content and git log, prioritizing unreleased
        let changelogEntry = '';
        if (unreleasedContent) {
            changelogEntry = unreleasedContent;
        } else if (gitLog) {
            changelogEntry = gitLog;
        } else {
            // If no commits found, try to get commits from the last 20 commits
            try {
                const separator = '|||COMMIT_SEPARATOR|||';
                const commitMessages = execSync(`git log -20 --pretty=format:"${separator}%B" --no-merges`, {
                    encoding: 'utf8',
                    stdio: ['pipe', 'pipe', 'ignore']
                }).trim();

                let allCommits = commitMessages.split(separator)
                    .map(commit => commit.trim())
                    .filter(commit => commit.length > 0)
                    .map(commit => {
                        const lines = commit.split('\n');
                        const cleaned = lines.filter(line => line.trim().length > 0);
                        return cleaned.join('\n').trim();
                    })
                    .filter(commit => {
                        const trimmed = commit.trim();
                        return trimmed &&
                            !trimmed.match(/^Release v\d+\.\d+\.\d+$/i) &&
                            !trimmed.match(/^Bump version/i) &&
                            !trimmed.match(/^Version update$/i);
                    });

                // Filter out commits that are already in CHANGELOG
                const newCommits = allCommits.filter(commit => {
                    const firstLine = commit.split('\n')[0].trim();
                    return !existingCommits.has(firstLine);
                });

                if (newCommits.length > 0) {
                    changelogEntry = newCommits.slice(0, 10).map(commit => {
                        const lines = commit.split('\n').map(l => l.trim()).filter(l => l.length > 0);
                        const subject = lines[0];
                        const body = lines.slice(1);

                        if (body.length > 0) {
                            return `- ${subject}     ${body.join('     ')}`;
                        } else {
                            return `- ${subject}`;
                        }
                    }).join('\n');
                } else {
                    changelogEntry = '- Version update';
                }
            } catch (e) {
                changelogEntry = '- Version update';
            }
        }

        // Create new changelog entry
        const newEntry = `## [${version}] - ${dateStr}

${changelogEntry}

`;

        // Insert after the first heading (main title)
        const lines = changelogContent.split('\n');
        const firstHeadingIndex = lines.findIndex(line => line.startsWith('## ['));

        if (firstHeadingIndex !== -1) {
            lines.splice(firstHeadingIndex, 0, newEntry);
            changelogContent = lines.join('\n');
        } else {
            // No existing entries, add after main heading
            changelogContent = changelogContent.replace(
                /(# Changelog.*?\n\n)/s,
                `$1${newEntry}`
            );
        }

        // Remove unreleased section if it was used
        changelogContent = changelogContent.replace(/## \[Unreleased\][\s\S]*?(?=## \[|$)/, '');

        // Add release link at the bottom if it doesn't exist
        if (!changelogContent.includes(`[${version}]:`)) {
            const releaseLink = `\n[${version}]: https://github.com/gbyat/gform-spamfighter/releases/tag/v${version}\n`;
            changelogContent = changelogContent.trim() + releaseLink;
        }

        fs.writeFileSync(changelogPath, changelogContent);
        console.log(`📝 Updated CHANGELOG.md with version ${version}`);
    } else {
        console.log(`ℹ️  Version ${version} already exists in CHANGELOG.md`);
    }
}

console.log(`✅ Version synchronized to ${version}`);
