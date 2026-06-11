<?php
/**
 * Main plugin loader.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TomsSchoolOfLifePlugin {

    private static $instance = null;
    private $admin_settings = null;
    private $accountability_modal_admin = null;
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
        load_plugin_textdomain('tomschooloflife-plugin', false, dirname(TSOL_SITE_PLUGIN_BASENAME) . '/languages');

        if (!$this->dependencies_met()) {
            return;
        }

        $this->admin_settings = new TSOL_Site_Admin_Settings();
        $this->admin_settings->init();
        $this->accountability_modal_admin = new TSOL_Accountability_Modal_Admin();
        $this->accountability_modal_admin->init();

        $this->register_features();
        $this->init_features();

        /**
         * Fires after the site plugin has loaded and dependency checks passed.
         *
         * @param TomsSchoolOfLifePlugin $plugin Plugin instance.
         */
        do_action('tsol_site_plugin_loaded', $this);
    }

    public function add_admin_menu() {
        if (!$this->dependencies_met()) {
            return;
        }

        $this->admin_page_hooks[] = add_menu_page(
            __('TSOL (Tom\'s School Of Life)', 'tomschooloflife-plugin'),
            __('TSOL', 'tomschooloflife-plugin'),
            'manage_options',
            'tsol-site',
            array($this, 'render_settings_page'),
            'dashicons-welcome-learn-more',
            58
        );

        $this->admin_page_hooks[] = add_submenu_page(
            'tsol-site',
            __('TSOL Dashboard', 'tomschooloflife-plugin'),
            __('Dashboard', 'tomschooloflife-plugin'),
            'manage_options',
            'tsol-site',
            array($this, 'render_settings_page')
        );

        $this->admin_page_hooks[] = add_submenu_page(
            'tsol-site',
            __('Accountability Modal', 'tomschooloflife-plugin'),
            __('Accountability Modal', 'tomschooloflife-plugin'),
            'manage_options',
            TSOL_Accountability_Modal_Admin::PAGE_SLUG,
            array($this, 'render_accountability_modal_page')
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

        wp_enqueue_script(
            'tsol-site-admin',
            TSOL_SITE_PLUGIN_URL . 'assets/admin/admin.js',
            array('jquery'),
            TSOL_SITE_PLUGIN_VERSION,
            true
        );

        wp_localize_script('tsol-site-admin', 'tsolSitePluginAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tsol_site_plugin_nonce'),
        ));
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

    public function render_accountability_modal_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!$this->accountability_modal_admin) {
            $this->accountability_modal_admin = new TSOL_Accountability_Modal_Admin();
        }

        $this->accountability_modal_admin->display_page();
    }

    public function render_dependency_admin_notice() {
        if ($this->dependencies_met() || !current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>';
        echo esc_html__('Tom\'s School Of Life Plugin requires Access Platform SSO to be installed and active.', 'tomschooloflife-plugin');
        echo '</p></div>';
    }

    public function plugin_action_links($links) {
        if ($this->dependencies_met()) {
            $settings_link = sprintf(
                '<a href="%s">%s</a>',
                esc_url(admin_url('admin.php?page=tsol-site')),
                esc_html__('Settings', 'tomschooloflife-plugin')
            );

            array_unshift($links, $settings_link);
        }

        return $links;
    }

    public function dependencies_met() {
        return self::is_access_sso_available();
    }

    public static function is_access_sso_available() {
        return TSOL_Site_Dependencies::access_sso_available();
    }

    public static function activate() {
        if (!self::is_access_sso_available()) {
            if (function_exists('deactivate_plugins')) {
                deactivate_plugins(TSOL_SITE_PLUGIN_BASENAME);
            }

            wp_die(
                esc_html__('Tom\'s School Of Life Plugin requires Access Platform SSO to be installed and active before activation.', 'tomschooloflife-plugin'),
                esc_html__('Plugin dependency missing', 'tomschooloflife-plugin'),
                array('back_link' => true)
            );
        }
    }

    public static function deactivate() {
        // Reserved for future cleanup. Do not delete settings on deactivation.
    }

    private function register_features() {
        $this->features = array(
            new TSOL_Accountability_Modal(),
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
