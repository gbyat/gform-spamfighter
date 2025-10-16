const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

console.log('📊 GFORM Spamfighter - Status Report');
console.log('=====================================\n');

// Read current version
const packagePath = path.join(__dirname, '..', 'package.json');
const packageData = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
const version = packageData.version;

console.log(`📦 Current Version: ${version}`);

// Read plugin file version
const pluginPath = path.join(__dirname, '..', 'gform-spamfighter.php');
const pluginContent = fs.readFileSync(pluginPath, 'utf8');
const versionMatch = pluginContent.match(/Version:\s*(\d+\.\d+\.\d+)/);
const pluginVersion = versionMatch ? versionMatch[1] : 'unknown';

console.log(`📄 Plugin File Version: ${pluginVersion}`);

if (version !== pluginVersion) {
    console.log('⚠️  WARNING: Version mismatch! Run: node scripts/sync-version.js\n');
} else {
    console.log('✅ Version synchronized\n');
}

// Git status
try {
    const gitStatus = execSync('git status --porcelain', { encoding: 'utf8' });
    if (gitStatus.trim()) {
        console.log('📝 Uncommitted changes:');
        console.log(gitStatus);
    } else {
        console.log('✅ Working tree clean\n');
    }
} catch (e) {
    console.log('❌ Not a git repository\n');
}

// Last commit
try {
    const lastCommit = execSync('git log -1 --oneline', { encoding: 'utf8' }).trim();
    console.log(`💬 Last commit: ${lastCommit}\n`);
} catch (e) {
    // Ignore
}

// Last tag
try {
    const lastTag = execSync('git describe --tags --abbrev=0', { encoding: 'utf8' }).trim();
    console.log(`🏷️  Last tag: ${lastTag}\n`);
} catch (e) {
    console.log('🏷️  No tags yet\n');
}

// File structure check
console.log('📂 File Structure:');
const requiredFiles = [
    'gform-spamfighter.php',
    'includes/admin/class-dashboard.php',
    'includes/admin/class-settings.php',
    'includes/core/class-database.php',
    'includes/core/class-logger.php',
    'includes/detection/class-open-ai.php',
    'includes/detection/class-pattern-analyzer.php',
    'includes/detection/class-behavior-analyzer.php',
    'includes/integration/class-gravity-forms.php',
    'assets/css/admin.css',
    'assets/js/admin.js',
    'README.md',
    'CHANGELOG.md'
];

const rootDir = path.join(__dirname, '..');
let allFilesExist = true;

requiredFiles.forEach(file => {
    const filePath = path.join(rootDir, file);
    const exists = fs.existsSync(filePath);
    console.log(`   ${exists ? '✅' : '❌'} ${file}`);
    if (!exists) allFilesExist = false;
});

console.log('');

if (allFilesExist) {
    console.log('✅ All required files present');
} else {
    console.log('⚠️  Some files are missing!');
}

console.log('\n📋 Next Steps:');
console.log('   npm run release:patch  - Bump patch version (1.0.0 → 1.0.1)');
console.log('   npm run release:minor  - Bump minor version (1.0.0 → 1.1.0)');
console.log('   npm run release:major  - Bump major version (1.0.0 → 2.0.0)');
console.log('');

