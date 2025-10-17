const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

// Get release type from command line argument (patch, minor, major)
const releaseType = process.argv[2] || 'patch';

if (!['patch', 'minor', 'major'].includes(releaseType)) {
    console.error('❌ Invalid release type. Use: patch, minor, or major');
    process.exit(1);
}

console.log(`🚀 Creating ${releaseType} release for GFORM Spamfighter...`);

try {
    // Read current version
    const packagePath = path.join(__dirname, '..', 'package.json');
    const packageData = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
    const currentVersion = packageData.version;

    // Bump version in package.json
    console.log(`⬆️  Bumping ${releaseType} version from ${currentVersion}...`);
    execSync(`npm version ${releaseType} --no-git-tag-version`, { stdio: 'inherit' });

    // Re-read new version
    const newPackageData = JSON.parse(fs.readFileSync(packagePath, 'utf8'));
    const newVersion = newPackageData.version;
    console.log(`✅ New version: ${newVersion}`);

    // Sync version to plugin file and update CHANGELOG
    console.log('🔄 Syncing version to plugin file...');
    execSync('node scripts/sync-version.js', { stdio: 'inherit' });

    // Add all changed files (package.json, plugin file, README.md, CHANGELOG.md)
    console.log('📦 Adding all changes to git...');
    execSync('git add -A', { stdio: 'inherit' });

    // Commit all changes
    console.log('💾 Committing changes...');
    try {
        execSync(`git commit -m "Release v${newVersion}"`, { stdio: 'inherit' });
    } catch (e) {
        console.log('ℹ️  Nothing to commit (that\'s okay)');
    }

    // Delete existing tag if it exists (local and remote)
    try {
        console.log('🗑️  Removing existing tag if it exists...');
        execSync(`git tag -d v${newVersion}`, { stdio: 'pipe' });
        execSync(`git push origin :refs/tags/v${newVersion}`, { stdio: 'pipe' });
    } catch (e) {
        // Tag doesn't exist, that's fine
    }

    // Create annotated tag
    console.log('🏷️  Creating tag...');
    execSync(`git tag -a "v${newVersion}" -m "Release v${newVersion}"`, { stdio: 'inherit' });

    // Push to GitHub
    console.log('⬆️  Pushing to GitHub...');
    execSync('git push origin main', { stdio: 'inherit' });
    execSync(`git push origin v${newVersion}`, { stdio: 'inherit' });

    console.log('');
    console.log('✅ ==========================================');
    console.log(`✅ Release v${newVersion} successfully created!`);
    console.log('✅ ==========================================');
    console.log('');
    console.log('🎉 GitHub Actions will now:');
    console.log('   1. Create gform-spamfighter.zip');
    console.log(`   2. Create GitHub Release v${newVersion}`);
    console.log('   3. Attach the ZIP file to the release');
    console.log('');
    console.log('🔗 Check progress at:');
    console.log('   https://github.com/gbyat/gform-spamfighter/actions');
    console.log('');

} catch (error) {
    console.error('❌ Error during release:', error.message);
    process.exit(1);
}

