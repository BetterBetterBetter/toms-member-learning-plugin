<?php
/**
 * Main plugin loader.
 */

if (!defined('ABSPATH')) {
    exit;
}

class LibertyClassroomLibraryPlugin {

    private static $instance = null;
    private $admin_settings = null;
    private $library_auth = null;
    private $features = array();
    private $admin_page_hooks = array();

    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'), 20);
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('admin_notices', array($this, 'render_dependency_admin_notice'));
        add_filter('plugin_action_links_' . TSOL_SITE_PLUGIN_BASENAME, array($this, 'plugin_action_links'));
    }

    public function init() {
        load_plugin_textdomain('libertyclassroom-library', false, dirname(TSOL_SITE_PLUGIN_BASENAME) . '/languages');

        $this->admin_settings = new TSOL_Site_Admin_Settings();
        $this->admin_settings->init();
        $this->library_auth = new TSOL_Library_Auth();
        $this->library_auth->init();

        $this->register_features();
        $this->init_features();

        /**
         * Fires after the site plugin has loaded.
         *
         * @param LibertyClassroomLibraryPlugin $plugin Plugin instance.
         */
        do_action('tsol_site_plugin_loaded', $this);
    }

    public function add_admin_menu() {
        $this->admin_page_hooks[] = add_menu_page(
            __('Liberty Classroom Library', 'libertyclassroom-library'),
            __('Liberty Library', 'libertyclassroom-library'),
            'manage_options',
            'tsol-site',
            array($this, 'render_settings_page'),
            'dashicons-welcome-learn-more',
            58
        );

        $this->admin_page_hooks[] = add_submenu_page(
            'tsol-site',
            __('Liberty Classroom Library', 'libertyclassroom-library'),
            __('Dashboard', 'libertyclassroom-library'),
            'manage_options',
            'tsol-site',
            array($this, 'render_settings_page')
        );

    }

    public function admin_enqueue_scripts($hook) {
        if (!in_array($hook, $this->admin_page_hooks, true)) {
            return;
        }

        wp_enqueue_style(
            'tsol-site-admin',
            TSOL_SITE_PLUGIN_URL . 'assets/admin/admin.css',
            array(),
            TSOL_SITE_PLUGIN_VERSION
        );

    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!$this->admin_settings) {
            $this->admin_settings = new TSOL_Site_Admin_Settings();
        }

        $this->admin_settings->display_page();
    }

    public function render_dependency_admin_notice() {
        if ($this->dependencies_met() || !current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-info"><p>';
        echo esc_html__('Access Platform SSO is not active. The Library authentication bridge remains available, but Access-origin members need another way to establish their WordPress login.', 'libertyclassroom-library');
        echo '</p></div>';
    }

    public function plugin_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=tsol-site')),
            esc_html__('Settings', 'libertyclassroom-library')
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
        $this->features = array(
            new TSOL_Library_Content(),
            new TSOL_Library_Announcements(),
        );

        /**
         * Filters site plugin features before they are initialized.
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
