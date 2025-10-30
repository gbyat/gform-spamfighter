<?php

/**
 * Admin Classifier page (stub).
 *
 * @package GformSpamfighter
 */

namespace GformSpamfighter\Admin;

/**
 * Classifier class.
 */
class Classifier
{

    /**
     * Instance.
     *
     * @var Classifier
     */
    private static $instance = null;

    /**
     * Get instance.
     *
     * @return Classifier
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct()
    {
        add_action('admin_menu', array($this, 'add_menu'));
    }

    /**
     * Add submenu for Classifier.
     */
    public function add_menu()
    {
        add_submenu_page(
            'gform-spamfighter-dashboard',
            __('Classifier', 'gform-spamfighter'),
            __('Classifier', 'gform-spamfighter'),
            'manage_options',
            'gform-spamfighter-classifier',
            array($this, 'render_page')
        );
    }

    /**
     * Render stub page.
     */
    public function render_page()
    {
        if (! current_user_can('manage_options')) {
            return;
        }

?>
        <div class="wrap">
            <h1><?php esc_html_e('Classifier (Preview)', 'gform-spamfighter'); ?></h1>
            <p><?php esc_html_e('This page will allow you to analyze sample submissions and provide feedback (Spam / Not spam).', 'gform-spamfighter'); ?></p>
            <p><?php esc_html_e('Coming soon in the next minor/major release.', 'gform-spamfighter'); ?></p>
        </div>
<?php
    }
}
