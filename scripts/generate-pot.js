const fs = require('fs');
const path = require('path');
const wpPot = require('wp-pot');

const pluginRoot = path.join(__dirname, '..');
const destDir = path.join(pluginRoot, 'languages');
const destFile = path.join(destDir, 'gform-spamfighter.pot');

if (!fs.existsSync(destDir)) {
    fs.mkdirSync(destDir, { recursive: true });
}

console.log('📝 Generating POT at', destFile);

wpPot({
    package: 'GForm Spamfighter',
    domain: 'gform-spamfighter',
    src: [
        path.join(pluginRoot, '**/*.php'),
    ],
    destFile,
    headers: {
        'Report-Msgid-Bugs-To': 'https://github.com/gbyat/gform-spamfighter/issues',
        'Last-Translator': 'webentwicklerin, Gabriele Laesser <mail@webentwicklerin.at>',
        'Language-Team': 'webentwicklerin.at',
        'X-Domain': 'gform-spamfighter',
    },
});

if (fs.existsSync(destFile)) {
    console.log('✅ POT generated');
} else {
    console.error('❌ Failed to generate POT');
    process.exit(1);
}


