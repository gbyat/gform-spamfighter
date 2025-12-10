<?php

/**
 * GitHub updater for automatic plugin updates.
 *
 * @package GformSpamfighter
 */

namespace GformSpamfighter\Core;

/**
 * GitHub Updater class.
 */
class Updater
{
    /**
     * Plugin file path.
     *
     * @var string
     */
    private $file;

    /**
     * Plugin data.
     *
     * @var array
     */
    private $plugin;

    /**
     * Plugin basename.
     *
     * @var string
     */
    private $basename;

    /**
     * Is plugin active.
     *
     * @var bool
     */
    private $active;

    /**
     * GitHub API response.
     *
     * @var object
     */
    private $github_response;

    /**
     * GitHub access token.
     *
     * @var string
     */
    private $access_token;

    /**
     * Constructor.
     *
     * @param string $file Plugin file path.
     */
    public function __construct($file)
    {
        $this->file     = $file;
        $this->basename = plugin_basename($this->file);
        $this->active   = is_plugin_active($this->basename);

        add_action('admin_init', array($this, 'set_plugin_properties'));
        add_filter('pre_set_site_transient_update_plugins', array($this, 'modify_transient'), 10, 1);
        add_filter('plugins_api', array($this, 'plugin_popup'), 10, 3);
        add_filter('upgrader_post_install', array($this, 'after_install'), 10, 3);
        add_action('upgrader_process_complete', array($this, 'purge'), 10, 2);
        add_action('admin_init', array($this, 'get_github_response'));
    }

    /**
     * Set plugin properties.
     */
    public function set_plugin_properties()
    {
        $this->plugin = get_plugin_data($this->file);
    }

    /**
     * Get GitHub API response.
     *
     * Note: GitHub token is NOT required for public repositories.
     * Without token: 60 requests/hour per IP.
     * With token: 5000 requests/hour.
     */
    public function get_github_response()
    {
        // Token is optional - only used to increase rate limits.
        $this->access_token = get_option('gform_spamfighter_github_token');

        $args = array(
            'timeout' => 30,
        );

        // Add token if available (increases rate limit from 60 to 5000/hour).
        if ($this->access_token) {
            $args['headers'] = array(
                'Authorization' => 'token ' . $this->access_token,
                'Accept'        => 'application/vnd.github.v3+json',
            );
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . GFORM_SPAMFIGHTER_GITHUB_REPO . '/releases/latest',
            $args
        );

        if (is_wp_error($response)) {
            return;
        }

        $this->github_response = json_decode(wp_remote_retrieve_body($response));
    }

    /**
     * Modify transient to show update.
     *
     * @param object $transient Update transient.
     * @return object
     */
    public function modify_transient($transient)
    {
        if (! $this->github_response || ! $this->active) {
            return $transient;
        }

        // Ensure plugin data is available.
        if (empty($this->plugin) || ! isset($this->plugin['Version'])) {
            $this->set_plugin_properties();
        }

        if (! isset($this->plugin['Version'])) {
            return $transient;
        }

        // Get current version and new version.
        $current_version = $this->plugin['Version'];
        $new_version     = ltrim($this->github_response->tag_name, 'v');

        // Only show update if new version is actually newer.
        if (version_compare($current_version, $new_version, '>=')) {
            return $transient;
        }

        // Find the ZIP asset (gform-spamfighter.zip).
        $download_url = null;
        if (isset($this->github_response->assets) && is_array($this->github_response->assets)) {
            foreach ($this->github_response->assets as $asset) {
                if ($asset->name === 'gform-spamfighter.zip') {
                    $download_url = $asset->browser_download_url;
                    break;
                }
            }
        }

        // Fallback to zipball if no ZIP asset found.
        if (! $download_url) {
            $download_url = $this->github_response->zipball_url;
        }

        $plugin_data = array(
            'slug'        => $this->basename,
            'new_version' => $new_version,
            'url'         => $this->plugin['PluginURI'],
            'package'     => $download_url,
        );

        $transient->response[$this->basename] = (object) $plugin_data;
        return $transient;
    }

    /**
     * Plugin popup for update details.
     *
     * @param false|object|array $result Plugin info.
     * @param string             $action Action.
     * @param object             $args   Arguments.
     * @return false|object|array
     */
    public function plugin_popup($result, $action, $args)
    {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (! isset($args->slug) || $args->slug !== $this->basename) {
            return $result;
        }

        if (! $this->github_response) {
            return $result;
        }

        // Get changelog from CHANGELOG.md if available.
        $changelog      = '';
        $changelog_file = GFORM_SPAMFIGHTER_PLUGIN_DIR . 'CHANGELOG.md';
        if (file_exists($changelog_file)) {
            $changelog_content = file_get_contents($changelog_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ($changelog_content) {
                $changelog = $this->format_changelog_for_popup($changelog_content);
            }
        }

        // If no changelog from file, use GitHub release body.
        if (empty($changelog)) {
            $changelog = $this->github_response->body ?: esc_html__('No changelog available.', 'gform-spamfighter');
        }

        // Get README content for description.
        $description = $this->plugin['Description'];
        $readme_file = GFORM_SPAMFIGHTER_PLUGIN_DIR . 'README.md';
        if (file_exists($readme_file)) {
            $readme_content = file_get_contents($readme_file); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
            if ($readme_content) {
                $description = $this->format_readme_for_popup($readme_content);
            }
        }

        $plugin_data = array(
            'name'              => $this->plugin['Name'],
            'slug'              => $this->basename,
            'version'           => $this->github_response->tag_name,
            'author'            => $this->plugin['AuthorName'],
            'author_profile'    => $this->plugin['AuthorURI'],
            'last_updated'      => $this->github_response->published_at,
            'homepage'          => $this->plugin['PluginURI'],
            'short_description' => $this->plugin['Description'],
            'sections'          => array(
                'description'  => $description,
                'changelog'    => $changelog,
                'installation' => $this->get_installation_instructions(),
            ),
            'download_link'     => $this->github_response->zipball_url,
            'requires'          => $this->plugin['Requires at least'] ?? '6.0',
            'tested'            => $this->plugin['Tested up to'] ?? '6.9',
            'requires_php'      => $this->plugin['Requires PHP'] ?? '8.0',
        );

        return (object) $plugin_data;
    }

    /**
     * Format changelog content for WordPress plugin popup.
     *
     * @param string $changelog_content Changelog markdown content.
     * @return string
     */
    private function format_changelog_for_popup($changelog_content)
    {
        $changelog = $changelog_content;

        // Convert headers.
        $changelog = preg_replace('/^### (.*)$/m', '<strong>$1</strong>', $changelog);
        $changelog = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $changelog);
        $changelog = preg_replace('/^# (.*)$/m', '<h2>$1</h2>', $changelog);

        // Convert lists.
        $changelog = preg_replace('/^- (.*)$/m', '<li>$1</li>', $changelog);
        $changelog = preg_replace('/^\* (.*)$/m', '<li>$1</li>', $changelog);

        // Wrap lists in ul tags.
        $changelog = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $changelog);

        // Convert bold text.
        $changelog = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $changelog);

        // Convert code.
        $changelog = preg_replace('/`(.*?)`/', '<code>$1</code>', $changelog);

        // Convert line breaks.
        $changelog = nl2br($changelog);

        return $changelog;
    }

    /**
     * Format README content for WordPress plugin popup.
     *
     * @param string $readme_content README markdown content.
     * @return string
     */
    private function format_readme_for_popup($readme_content)
    {
        $readme = $readme_content;

        // Convert headers.
        $readme = preg_replace('/^### (.*)$/m', '<strong>$1</strong>', $readme);
        $readme = preg_replace('/^## (.*)$/m', '<h3>$1</h3>', $readme);
        $readme = preg_replace('/^# (.*)$/m', '<h2>$1</h2>', $readme);

        // Convert lists.
        $readme = preg_replace('/^- (.*)$/m', '<li>$1</li>', $readme);
        $readme = preg_replace('/^\* (.*)$/m', '<li>$1</li>', $readme);

        // Wrap lists in ul tags.
        $readme = preg_replace('/(<li>.*<\/li>)/s', '<ul>$1</ul>', $readme);

        // Convert bold text.
        $readme = preg_replace('/\*\*(.*?)\*\*/', '<strong>$1</strong>', $readme);

        // Convert code.
        $readme = preg_replace('/`(.*?)`/', '<code>$1</code>', $readme);

        // Convert line breaks.
        $readme = nl2br($readme);

        return $readme;
    }

    /**
     * Get installation instructions.
     *
     * @return string
     */
    private function get_installation_instructions()
    {
        return '<h3>' . esc_html__('Installation', 'gform-spamfighter') . '</h3>
        <ol>
            <li>' . esc_html__('Download the latest release ZIP file', 'gform-spamfighter') . '</li>
            <li>' . esc_html__('Go to WordPress Admin → Plugins → Add New → Upload Plugin', 'gform-spamfighter') . '</li>
            <li>' . esc_html__('Upload the ZIP file and click Install Now', 'gform-spamfighter') . '</li>
            <li>' . esc_html__('Activate the plugin', 'gform-spamfighter') . '</li>
            <li>' . esc_html__('Configure settings under Settings → Spamfighter', 'gform-spamfighter') . '</li>
        </ol>';
    }

    /**
     * After install callback.
     *
     * @param bool  $response   Installation response.
     * @param array $hook_extra Extra hook data.
     * @param array $result     Installation result.
     * @return array
     */
    public function after_install($response, $hook_extra, $result)
    {
        global $wp_filesystem;

        $install_directory = plugin_dir_path($this->file);
        $wp_filesystem->move($result['destination'], $install_directory);
        $result['destination'] = $install_directory;

        // Refresh plugin data.
        $this->set_plugin_properties();

        // Reactivate if was active.
        if ($this->active) {
            activate_plugin($this->basename);
        }

        return $result;
    }

    /**
     * Purge cache after update.
     */
    public function purge()
    {
        if ($this->active) {
            delete_transient('gform_spamfighter_update_' . $this->basename);
        }
    }
}
