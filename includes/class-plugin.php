<?php
/**
 * Main plugin loader.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TomsSchoolOfLifePlugin {

    private static $instance = null;
    private $library_auth = null;
    private $features = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'), 20);
        add_action('admin_notices', array($this, 'render_dependency_admin_notice'));
        add_filter('plugin_action_links_' . TSOL_SITE_PLUGIN_BASENAME, array($this, 'plugin_action_links'));
    }

    public function init() {
        load_plugin_textdomain('tomschooloflife-plugin', false, dirname(TSOL_SITE_PLUGIN_BASENAME) . '/languages');

        $this->library_auth = new TSOL_Library_Auth();
        $this->library_auth->init();

        $this->register_features();
        $this->init_features();

        /**
         * Fires after the library plugin has loaded.
         *
         * @param TomsSchoolOfLifePlugin $plugin Plugin instance.
         */
        do_action('tsol_site_plugin_loaded', $this);
    }

    public function render_dependency_admin_notice() {
        if ($this->dependencies_met() || !current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-info"><p>';
        echo esc_html__('Access Platform SSO is not active. The Library authentication bridge still works, but Access-origin members need another way to establish their WordPress login.', 'tomschooloflife-plugin');
        echo '</p></div>';
    }

    public function plugin_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=tsol-library')),
            esc_html__('Settings', 'tomschooloflife-plugin')
        );

        array_unshift($links, $settings_link);

        return $links;
    }

    public function dependencies_met() {
        return self::is_access_sso_available();
    }

    public static function is_access_sso_available() {
        return TSOL_Site_Dependencies::access_sso_available();
    }

    public static function activate() {
        TSOL_Library_Auth::activate();
        TSOL_Library_Content::activate();
        TSOL_Library_Announcements::activate();
    }

    public static function deactivate() {
        TSOL_Library_Auth::deactivate();
        TSOL_Library_Content::deactivate();
    }

    private function register_features() {
        // The shared plugin is the library core only. Site-specific features
        // (accountability modal, cookie consent) live in the separate TSOL
        // companion plugin. See docs/plans/plugin-consolidation-plan.md.
        $this->features = array(
            new TSOL_Library_Content(),
            new TSOL_Library_Announcements(),
        );

        /**
         * Filters library plugin features before they are initialized.
         *
         * Each feature should implement TSOL_Site_Feature.
         *
         * @param array $features Feature instances.
         */
        $this->features = apply_filters('tsol_site_plugin_features', $this->features);
    }

    private function init_features() {
        foreach ($this->features as $feature) {
            if ($feature instanceof TSOL_Site_Feature) {
                $feature->init();
            }
        }
    }
}
