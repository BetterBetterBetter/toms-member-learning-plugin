<?php
/**
 * School announcement feature bootstrap.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Announcements implements MemberLibrary_Feature {

    private $admin = null;

    public function init() {
        add_action('init', array('MemberLibrary_Announcement_Model', 'register'), 34);
        add_action('init', array('MemberLibrary_Announcement_Model', 'maybe_install_capabilities'), 35);
        add_filter('wp_insert_post_data', array('MemberLibrary_Announcement_Model', 'filter_post_data'), 30, 2);
        add_filter('wp_post_revision_meta_keys', array('MemberLibrary_Announcement_Model', 'revision_meta_keys'));

        if (is_admin() && MemberLibrary_Announcement_Flags::admin_enabled()) {
            $this->admin = new MemberLibrary_Announcement_Admin();
            $this->admin->init();
        }
    }

    public static function activate() {
        MemberLibrary_Announcement_Model::install_capabilities();
        MemberLibrary_Announcement_Model::register();
    }
}
