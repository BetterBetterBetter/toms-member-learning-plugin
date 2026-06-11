<?php
/**
 * Accountability modal.
 * Shows a prompt on the accountability page to logged-in users who are not in an accountability group.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Accountability_Modal implements TSOL_Site_Feature {

    private $should_show = null;
    private $repository = null;
    private $renderer = null;
    private $submission_handler = null;

    public function init() {
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_footer', array($this, 'render_modal'));
        add_action('admin_bar_menu', array($this, 'add_admin_bar_button'), 100);

        $this->get_submission_handler()->init();
    }

    public function enqueue_assets() {
        if (!$this->should_load_modal()) {
            return;
        }

        $display_rules = TSOL_Accountability_Modal_Settings::get_display_rules();
        $auto_open = $this->should_show_modal();
        $content = TSOL_Accountability_Modal_Settings::get_content();

        wp_enqueue_style(
            'tsol-accountability-modal',
            TSOL_SITE_PLUGIN_URL . 'assets/features/accountability-modal/accountability-modal.css',
            array(),
            TSOL_SITE_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'tsol-accountability-modal',
            TSOL_SITE_PLUGIN_URL . 'assets/features/accountability-modal/accountability-modal.js',
            array(),
            TSOL_SITE_PLUGIN_VERSION,
            true
        );

        wp_localize_script('tsol-accountability-modal', 'tsolAccountabilityModal', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(TSOL_Accountability_Modal_Submission_Handler::NONCE_ACTION),
            'scrollThreshold' => (int) $display_rules['scroll_threshold'],
            'draftKey' => $this->get_draft_storage_key(),
            'draftSchema' => $this->get_draft_schema($content),
            'draftTtl' => (int) apply_filters('tsol_site_accountability_modal_draft_ttl', DAY_IN_SECONDS * (int) $display_rules['draft_ttl_days']),
            'draftEnabled' => $display_rules['save_draft_answers'] === '1',
            'launcherEnabled' => $auto_open && $display_rules['show_resume_launcher'] === '1',
            'launcherAnimationEnabled' => $display_rules['animate_resume_launcher'] === '1',
            'messages' => array(
                'callRequired' => __('Please choose at least one call you can attend weekly.', 'tomschooloflife-plugin'),
                'submitError' => __('Something went wrong. Please try again.', 'tomschooloflife-plugin'),
            ),
        ));
    }

    public function render_modal() {
        if (!$this->should_load_modal()) {
            return;
        }

        $auto_open = $this->should_show_modal();
        $joinable_calls = $this->get_repository()->get_joinable_calls();
        $content = TSOL_Accountability_Modal_Settings::get_content();
        $display_rules = TSOL_Accountability_Modal_Settings::get_display_rules();

        $this->get_renderer()->render($joinable_calls, $auto_open, $content, $display_rules);
    }

    public function add_admin_bar_button($wp_admin_bar) {
        if (!$this->can_manually_open_modal()) {
            return;
        }

        $wp_admin_bar->add_node(array(
            'id' => 'tsol-accountability-modal-open',
            'title' => __('Show Accountability Modal', 'tomschooloflife-plugin'),
            'href' => '#',
            'meta' => array(
                'class' => 'tsol-accountability-modal-admin-bar',
            ),
        ));
    }

    private function should_load_modal() {
        return $this->should_show_modal() || $this->can_manually_open_modal();
    }

    public function should_show_modal() {
        if ($this->should_show !== null) {
            return $this->should_show;
        }

        $display_rules = TSOL_Accountability_Modal_Settings::get_display_rules();

        $should_show = (
            get_option('tsol_site_plugin_enabled', '1') === '1'
            && $display_rules['enabled'] === '1'
            && is_user_logged_in()
            && !is_admin()
            && $this->is_target_content()
            && !$this->is_json_or_ajax_request()
            && ($display_rules['hide_if_current_member'] !== '1' || !$this->current_user_has_accountability_group())
            && ($display_rules['hide_after_submission'] !== '1' || !$this->current_user_completed_intake())
        );

        /**
         * Filters whether the accountability modal should be shown.
         *
         * @param bool $should_show Whether the modal should render.
         * @param int  $user_id     Current user ID, or 0.
         */
        $this->should_show = (bool) apply_filters('tsol_site_accountability_modal_should_show', $should_show, get_current_user_id());

        return $this->should_show;
    }

    private function can_manually_open_modal() {
        $display_rules = TSOL_Accountability_Modal_Settings::get_display_rules();

        return (
            get_option('tsol_site_plugin_enabled', '1') === '1'
            && $display_rules['enabled'] === '1'
            && $display_rules['show_admin_bar_button'] === '1'
            && is_user_logged_in()
            && current_user_can('manage_options')
            && !is_admin()
            && $this->is_target_content()
            && !$this->is_json_or_ajax_request()
        );
    }

    private function is_target_content() {
        if (!function_exists('is_singular') || !is_singular()) {
            return false;
        }

        /**
         * Filters which singular content IDs can show the accountability modal.
         *
         * @param array $target_page_ids Post IDs.
         */
        $target_page_ids = apply_filters('tsol_site_accountability_modal_page', TSOL_Accountability_Modal_Settings::get_target_page_ids());
        $target_page_ids = array_map('absint', (array) $target_page_ids);

        if (!$target_page_ids) {
            return false;
        }

        return in_array((int) get_queried_object_id(), $target_page_ids, true);
    }

    public function current_user_has_accountability_group() {
        $user_id = get_current_user_id();

        return $this->get_repository()->user_has_accountability_group($user_id);
    }

    public function current_user_completed_intake() {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return false;
        }

        return $this->get_submission_handler()->user_completed_intake($user_id);
    }

    public function handle_submission() {
        $this->get_submission_handler()->handle_submission();
    }

    private function get_repository() {
        if (!$this->repository) {
            $this->repository = new TSOL_Accountability_Modal_Repository();
        }

        return $this->repository;
    }

    private function get_renderer() {
        if (!$this->renderer) {
            $this->renderer = new TSOL_Accountability_Modal_Renderer();
        }

        return $this->renderer;
    }

    private function get_submission_handler() {
        if (!$this->submission_handler) {
            $this->submission_handler = new TSOL_Accountability_Modal_Submission_Handler($this->get_repository());
        }

        return $this->submission_handler;
    }

    private function get_draft_storage_key() {
        return 'tsol_accountability_modal_draft_' . md5(home_url('/') . ':' . get_current_user_id());
    }

    private function get_draft_schema($content) {
        $schema = array_map(function($question) {
            return array(
                'key' => isset($question['key']) ? $question['key'] : '',
                'type' => isset($question['type']) ? $question['type'] : '',
                'enabled' => isset($question['enabled']) ? $question['enabled'] : '',
                'options' => isset($question['options']) ? $question['options'] : '',
            );
        }, TSOL_Accountability_Modal_Settings::get_enabled_questions($content));

        return md5(wp_json_encode($schema));
    }

    private function is_json_or_ajax_request() {
        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            return true;
        }

        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return true;
        }

        return defined('REST_REQUEST') && REST_REQUEST;
    }
}
