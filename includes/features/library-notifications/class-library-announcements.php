<?php
/**
 * School announcement feature bootstrap.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Announcements implements TSOL_Site_Feature {

    private $admin = null;

    public function init() {
        add_action('init', array('TSOL_Library_Announcement_Model', 'register'), 34);
        add_action('init', array('TSOL_Library_Announcement_Model', 'maybe_install_capabilities'), 35);
        add_filter('wp_insert_post_data', array('TSOL_Library_Announcement_Model', 'filter_post_data'), 30, 2);
        add_filter('wp_post_revision_meta_keys', array('TSOL_Library_Announcement_Model', 'revision_meta_keys'));

        if (is_admin() && TSOL_Library_Announcement_Flags::admin_enabled()) {
            $this->admin = new TSOL_Library_Announcement_Admin();
            $this->admin->init();
        }
    }

    public static function activate() {
        TSOL_Library_Announcement_Model::install_capabilities();
        TSOL_Library_Announcement_Model::register();
    }
}
