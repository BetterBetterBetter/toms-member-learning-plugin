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
    private $cookie_consent_admin = null;
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
        load_plugin_textdomain('tomschooloflife-plugin', false, dirname(TSOL_SITE_PLUGIN_BASENAME) . '/languages');

        $this->admin_settings = new TSOL_Site_Admin_Settings();
        $this->admin_settings->init();
        $this->accountability_modal_admin = new TSOL_Accountability_Modal_Admin();
        $this->accountability_modal_admin->init();
        $this->cookie_consent_admin = new TSOL_Cookie_Consent_Admin();
        $this->cookie_consent_admin->init();
        $this->library_auth = new TSOL_Library_Auth();
        $this->library_auth->init();

        $this->register_features();
        $this->init_features();

        /**
         * Fires after the site plugin has loaded.
         *
         * @param TomsSchoolOfLifePlugin $plugin Plugin instance.
         */
        do_action('tsol_site_plugin_loaded', $this);
    }

    public function add_admin_menu() {
        $this->admin_page_hooks[] = add_menu_page(
            __('TSOL (Tom\'s School Of Life)', 'tomschooloflife-plugin'),
            TSOL_Library_Brand::menu_label(),
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

        $this->admin_page_hooks[] = add_submenu_page(
            'tsol-site',
            __('Cookie Consent', 'tomschooloflife-plugin'),
            __('Cookie Consent', 'tomschooloflife-plugin'),
            'manage_options',
            TSOL_Cookie_Consent_Admin::PAGE_SLUG,
            array($this, 'render_cookie_consent_page')
        );
    }

    public function admin_enqueue_scripts($hook) {
        if (!in_array($hook, $this->admin_page_hooks, true)) {
            return;
        }

        $code_editor_settings = null;
        $admin_script_dependencies = array('jquery');

        if (strpos((string) $hook, TSOL_Cookie_Consent_Admin::PAGE_SLUG) !== false && function_exists('wp_enqueue_code_editor')) {
            $code_editor_settings = wp_enqueue_code_editor(array(
                'type' => 'application/javascript',
                'codemirror' => array(
                    'indentUnit' => 4,
                    'indentWithTabs' => false,
                    'lint' => false,
                    'lineNumbers' => true,
                    'lineWrapping' => true,
                    'styleActiveLine' => true,
                ),
            ));

            if ($code_editor_settings) {
                $admin_script_dependencies[] = 'code-editor';
            }
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
            $admin_script_dependencies,
            TSOL_SITE_PLUGIN_VERSION,
            true
        );

        wp_localize_script('tsol-site-admin', 'tsolSitePluginAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tsol_site_plugin_nonce'),
            'codeEditor' => $code_editor_settings ? $code_editor_settings : null,
            'strings' => array(
                'scriptUrlPlaceholder' => __('https://example.com/script.js', 'tomschooloflife-plugin'),
                'removeUrl' => __('Remove URL', 'tomschooloflife-plugin'),
                'removeScriptUrl' => __('Remove this script URL', 'tomschooloflife-plugin'),
                'scriptUrlCountSingular' => __('1 URL', 'tomschooloflife-plugin'),
                'scriptUrlCountPlural' => __('%d URLs', 'tomschooloflife-plugin'),
                'snippetLabel' => __('Snippet', 'tomschooloflife-plugin'),
                'snippetCountSingular' => __('1 snippet', 'tomschooloflife-plugin'),
                'snippetCountPlural' => __('%d snippets', 'tomschooloflife-plugin'),
                'formatSnippet' => __('Format', 'tomschooloflife-plugin'),
                'removeSnippet' => __('Remove snippet', 'tomschooloflife-plugin'),
                'removeSnippetAria' => __('Remove this JavaScript snippet', 'tomschooloflife-plugin'),
                'snippetSyntaxError' => __('JavaScript syntax error:', 'tomschooloflife-plugin'),
            ),
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

    public function render_cookie_consent_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (!$this->cookie_consent_admin) {
            $this->cookie_consent_admin = new TSOL_Cookie_Consent_Admin();
        }

        $this->cookie_consent_admin->display_page();
    }

    public function render_dependency_admin_notice() {
        if ($this->dependencies_met() || !current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-info"><p>';
        echo esc_html__('Access Platform SSO is not active. The TSOL plugin and Library authentication bridge still work, but Access-origin members need another way to establish their WordPress login.', 'tomschooloflife-plugin');
        echo '</p></div>';
    }

    public function plugin_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url(admin_url('admin.php?page=tsol-site')),
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
        $this->features = array(
            new TSOL_Accountability_Modal(),
            new TSOL_Cookie_Consent(),
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
