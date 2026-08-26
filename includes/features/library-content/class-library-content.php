<?php
/**
 * Permanent WordPress content-model registration for the TSOL Library.
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-structure.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-structure-admin.php';

class TSOL_Library_Content implements TSOL_Site_Feature {

    private $admin = null;
    private $access_column = null;
    private $access_groups_admin = null;
    private $collection_admin = null;
    private $homepage_curation = null;
    private $environment_migration_admin = null;
    private $navigation = null;
    private $speaker_admin = null;
    private $structure_admin = null;
    private $sync_status = null;
    private $url_admin = null;

    public function init() {
        add_filter('mepr_rules_cpts', array('TSOL_Library_Content_Model', 'add_memberpress_rule_post_types'));
        add_action('init', array('TSOL_Library_Content_Model', 'register'), 30);
        add_action('init', array('TSOL_Library_Content_Changes', 'maybe_install'), 31);
        add_action('init', array('TSOL_Library_Catalogue_Webhook', 'maybe_install'), 32);
        add_action('init', array('TSOL_Library_Structure', 'maybe_migrate'), 33);
        add_filter('wp_insert_post_data', array('TSOL_Library_Content_HTML_Sanitizer', 'sanitize_post_data'), 20, 2);
        TSOL_Library_Content_Changes::register_hooks();
        TSOL_Library_Catalogue_Webhook::register_hooks();

        if (is_admin()) {
            $this->sync_status = new TSOL_Library_Catalogue_Sync_Status();
            $this->sync_status->init();
            $this->navigation = new TSOL_Library_Admin_Navigation();
            $this->navigation->init();
            $this->homepage_curation = new TSOL_Library_Homepage_Curation();
            $this->homepage_curation->init();
            $this->environment_migration_admin = new TSOL_Library_Environment_Migration_Admin();
            $this->environment_migration_admin->init();
            $this->access_column = new TSOL_Library_Content_Access_Column();
            $this->access_column->init();
            $this->access_groups_admin = new TSOL_Library_Access_Groups_Admin();
            $this->access_groups_admin->init();
            $this->collection_admin = new TSOL_Library_Collection_Admin();
            $this->collection_admin->init();
            $this->url_admin = new TSOL_Library_URL_Admin();
            $this->url_admin->init();
            $this->admin = new TSOL_Library_Content_Admin();
            $this->admin->init();
            $this->speaker_admin = new TSOL_Library_Speaker_Admin();
            $this->speaker_admin->init();
            $this->structure_admin = new TSOL_Library_Structure_Admin();
            $this->structure_admin->init();
        }
    }

    public static function activate() {
        TSOL_Library_Content_Changes::install();
        TSOL_Library_Catalogue_Webhook::install();
        TSOL_Library_Catalogue_Webhook::activate();
    }

    public static function deactivate() {
        TSOL_Library_Catalogue_Webhook::deactivate();
    }
}
