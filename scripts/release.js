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

    // Add all changed files (package.json, plugin file, CHANGELOG.md)
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

    // Detect current branch
    let branch = 'main';
    try {
        branch = execSync('git rev-parse --abbrev-ref HEAD', { encoding: 'utf8' }).trim();
    } catch (e) {
        // Fallback to main
    }

    // Check if remote repository exists
    console.log('🔍 Checking if GitHub repository exists...');
    try {
        execSync('git ls-remote origin', { stdio: 'pipe' });
        console.log('✅ Repository is accessible');
    } catch (e) {
        console.error('');
        console.error('❌ ==========================================');
        console.error('❌ ERROR: GitHub repository not found!');
        console.error('❌ ==========================================');
        console.error('');
        console.error('The remote repository does not exist or is not accessible.');
        console.error('');
        console.error('Please check:');
        console.error('  1. Does the repository exist on GitHub?');
        console.error('  2. Is the remote URL correct? (Check with: git remote -v)');
        console.error('  3. Do you have access to the repository?');
        console.error('');
        console.error('To remove the remote, use: git remote remove origin');
        console.error('To add a new remote, use: git remote add origin <url>');
        console.error('');
        process.exit(1);
    }

    // Push to GitHub
    console.log('⬆️  Pushing to GitHub...');
    console.log(`   Pushing branch: ${branch}`);
    console.log(`   Pushing tag: v${newVersion}`);

    execSync(`git push origin ${branch}`, { stdio: 'inherit' });
    execSync(`git push origin v${newVersion}`, { stdio: 'inherit' });

    console.log('');
    console.log('✅ ==========================================');
    console.log(`✅ Release v${newVersion} successfully created!`);
    console.log('✅ ==========================================');
    console.log('');
    console.log('🎉 GitHub Actions will now:');
    console.log('   1. Run CI/CD checks (PHP, linting, etc.)');
    console.log('   2. Create gform-spamfighter.zip');
    console.log(`   3. Create GitHub Release v${newVersion}`);
    console.log('   4. Attach the ZIP file to the release');
    console.log('');
    console.log('🔗 Check progress at:');
    console.log('   https://github.com/gbyat/gform-spamfighter/actions');
    console.log('');

} catch (error) {
    console.error('❌ Error during release:', error.message);
    process.exit(1);
}
