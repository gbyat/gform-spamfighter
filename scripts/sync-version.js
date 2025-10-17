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
    /define\(\s*'GFORM_SPAMFIGHTER_VERSION',\s*'[^']*'\s*\);/,
    `define('GFORM_SPAMFIGHTER_VERSION', '${version}');`
);

// Write updated plugin file
fs.writeFileSync(pluginPath, pluginContent);
console.log(`✅ Updated gform-spamfighter.php`);

// Update README.md stable tag
const readmePath = path.join(__dirname, '..', 'README.md');
if (fs.existsSync(readmePath)) {
    let readmeContent = fs.readFileSync(readmePath, 'utf8');

    // Update stable tag
    readmeContent = readmeContent.replace(
        /\*\*Stable tag:\*\*\s*\d+\.\d+\.\d+/,
        `**Stable tag:** ${version}`
    );

    fs.writeFileSync(readmePath, readmeContent);
    console.log(`✅ Updated README.md stable tag`);
}

// Update CHANGELOG.md
const changelogPath = path.join(__dirname, '..', 'CHANGELOG.md');
if (!fs.existsSync(changelogPath)) {
    // Create initial CHANGELOG.md
    const initialContent = `# Changelog

All notable changes to this project will be documented in this file.

## [${version}] - ${new Date().toISOString().split('T')[0]}

### Added
- Initial release of GFORM Spamfighter
- Multi-layer spam detection (Pattern, Behavior, OpenAI)
- WordPress Dashboard integration
- Soft warning system with strike lockout
- Statistics and logging

`;
    fs.writeFileSync(changelogPath, initialContent);
    console.log(`📝 Created CHANGELOG.md`);
} else {
    let changelogContent = fs.readFileSync(changelogPath, 'utf8');

    // Check if this version already exists in changelog
    const versionPattern = new RegExp(`## \\[${version.replace(/\./g, '\\.')}\\]`);
    if (!versionPattern.test(changelogContent)) {
        // Get git log since last tag
        let gitLog = '';
        try {
            // Get commits since last tag
            gitLog = execSync('git log $(git describe --tags --abbrev=0 2>/dev/null || echo HEAD~10)..HEAD --oneline --pretty=format:"- %s"', {
                encoding: 'utf8',
                stdio: ['pipe', 'pipe', 'ignore']
            }).trim();
        } catch (e) {
            // No previous tags, get last 10 commits
            try {
                gitLog = execSync('git log -10 --oneline --pretty=format:"- %s"', {
                    encoding: 'utf8',
                    stdio: ['pipe', 'pipe', 'ignore']
                }).trim();
            } catch (e2) {
                gitLog = '- Version bump';
            }
        }

        // Get current date
        const dateStr = new Date().toISOString().split('T')[0];

        // Create new changelog entry
        const newEntry = `## [${version}] - ${dateStr}

${gitLog ? gitLog : '- Version update'}

`;

        // Insert after the first heading
        const lines = changelogContent.split('\n');
        const firstHeadingIndex = lines.findIndex(line => line.startsWith('## '));

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

        fs.writeFileSync(changelogPath, changelogContent);
        console.log(`📝 Updated CHANGELOG.md with version ${version}`);
    } else {
        console.log(`ℹ️  Version ${version} already exists in CHANGELOG.md`);
    }
}

console.log(`✅ Version synchronized to ${version}`);

