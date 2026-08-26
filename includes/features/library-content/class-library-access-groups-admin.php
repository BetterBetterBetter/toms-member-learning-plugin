<?php
/** WordPress admin UI for TSOL Library Access Groups. */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Access_Groups_Admin {

    const PAGE_SLUG = 'tsol-library-access-groups';
    const NONCE_ACTION = 'tsol_library_access_groups';
    const NONCE_NAME = 'tsol_library_access_groups_nonce';
    const MEMBERSHIP_NONCE_ACTION = 'tsol_library_access_groups_membership';
    const MEMBERSHIP_NONCE_NAME = 'tsol_library_access_groups_membership_nonce';

    private $service;
    private $page_hook = '';

    public function __construct() {
        $this->service = new TSOL_Library_Access_Groups();
    }

    public function init() {
        add_action('admin_init', array($this, 'maybe_upgrade'));
        add_action('admin_menu', array($this, 'add_menu_page'), 18);
        add_action('admin_post_tsol_library_access_groups', array($this, 'handle_action'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('add_meta_boxes_memberpressproduct', array($this, 'add_membership_meta_box'));
        add_action('save_post_memberpressproduct', array($this, 'save_membership_meta_box'), 20, 2);
        add_action('admin_notices', array($this, 'membership_notice'));
    }

    public function maybe_upgrade() {
        if (current_user_can('manage_options')) {
            $this->service->maybe_upgrade();
        }
    }

    public function add_menu_page() {
        $this->page_hook = (string) add_submenu_page(
            TSOL_Library_Admin_Navigation::MENU_SLUG,
            __('Library Access Groups', 'tomschooloflife-plugin'),
            __('Access Groups', 'tomschooloflife-plugin'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render')
        );
    }

    public function enqueue_assets($hook) {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $is_membership_editor = $screen instanceof WP_Screen && 'memberpressproduct' === $screen->post_type;
        if (('' === $this->page_hook || (string) $hook !== $this->page_hook) && !$is_membership_editor) {
            return;
        }
        wp_enqueue_style(
            'tsol-library-access-groups-admin',
            TSOL_SITE_PLUGIN_URL . 'assets/features/library-content/library-access-groups-admin.css',
            array('tsol-library-content-admin'),
            TSOL_SITE_PLUGIN_VERSION
        );
        if (!$is_membership_editor) {
            wp_enqueue_script(
                'tsol-library-access-groups-admin',
                TSOL_SITE_PLUGIN_URL . 'assets/features/library-content/library-access-groups-admin.js',
                array(),
                TSOL_SITE_PLUGIN_VERSION,
                true
            );
        }
    }

    public function handle_action() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage Library access.', 'tomschooloflife-plugin'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);
        $operation = isset($_POST['operation']) ? sanitize_key(wp_unslash($_POST['operation'])) : '';
        try {
            if ('bootstrap' === $operation) {
                $this->service->bootstrap();
            } elseif ('save_groups' === $operation) {
                $groups = isset($_POST['groups']) && is_array($_POST['groups']) ? $_POST['groups'] : array();
                $revision = isset($_POST['revision']) ? sanitize_text_field(wp_unslash($_POST['revision'])) : '';
                $this->service->save_groups($groups, $revision);
            } elseif ('reconcile' === $operation) {
                $revision = isset($_POST['revision']) ? sanitize_text_field(wp_unslash($_POST['revision'])) : '';
                $this->service->reconcile_owned_rules($revision);
            } elseif ('stage' === $operation) {
                $this->service->stage();
            } elseif ('activate' === $operation) {
                $confirmation = isset($_POST['confirmation']) ? sanitize_text_field(wp_unslash($_POST['confirmation'])) : '';
                $this->service->activate($confirmation);
            } elseif ('rollback' === $operation) {
                $this->service->rollback();
            } else {
                throw new RuntimeException('Unknown Access Groups operation.');
            }
            $args = array('page' => self::PAGE_SLUG, 'updated' => $operation);
        } catch (Throwable $exception) {
            $args = array('page' => self::PAGE_SLUG, 'tsol_error' => $exception->getMessage());
        }
        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function add_membership_meta_box() {
        if (!current_user_can('manage_options')) {
            return;
        }
        add_meta_box(
            'tsol-library-access-groups-membership',
            __('Library Access Groups', 'tomschooloflife-plugin'),
            array($this, 'render_membership_meta_box'),
            'memberpressproduct',
            'side',
            'default'
        );
    }

    public function render_membership_meta_box($post) {
        if (!$this->service->is_bootstrapped()) {
            echo '<p>' . esc_html__('Import the current policy under TSOL Library → Access Groups before assigning memberships.', 'tomschooloflife-plugin') . '</p>';
            return;
        }
        $configuration = $this->service->configuration();
        $groups = $this->service->groups();
        $selected = $this->service->membership_group_ids($post->ID);
        wp_nonce_field(self::MEMBERSHIP_NONCE_ACTION, self::MEMBERSHIP_NONCE_NAME);
        ?>
        <input type="hidden" name="tsol_library_access_groups_revision" value="<?php echo esc_attr((string) $configuration['revision']); ?>">
        <p><?php esc_html_e('Choose the reusable Library packages included with this membership. Saving records a pending change only; nothing becomes live until it is checked and published from the Access Groups page.', 'tomschooloflife-plugin'); ?></p>
        <fieldset class="tsol-membership-access-groups">
            <legend class="screen-reader-text"><?php esc_html_e('Assigned Library Access Groups', 'tomschooloflife-plugin'); ?></legend>
            <?php if (empty($groups)) : ?>
                <p><?php esc_html_e('No Access Groups have been created.', 'tomschooloflife-plugin'); ?></p>
            <?php else : ?>
                <?php foreach ($groups as $group_id => $group) : ?>
                    <label>
                        <input type="checkbox" name="tsol_library_access_group_ids[]" value="<?php echo esc_attr($group_id); ?>" <?php checked(in_array($group_id, $selected, true)); ?>>
                        <span><strong><?php echo esc_html($group['name']); ?></strong><?php if ('' !== (string) $group['description']) : ?><small><?php echo esc_html($group['description']); ?></small><?php endif; ?></span>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </fieldset>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>"><?php esc_html_e('Manage Access Groups', 'tomschooloflife-plugin'); ?></a></p>
        <?php
    }

    public function save_membership_meta_box($post_id, $post) {
        static $handled_post_ids = array();
        if (!$post instanceof WP_Post
            || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
            || wp_is_post_revision($post_id)
            || !current_user_can('manage_options')
            || isset($handled_post_ids[(int) $post_id])
            || !isset($_POST[self::MEMBERSHIP_NONCE_NAME])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::MEMBERSHIP_NONCE_NAME])), self::MEMBERSHIP_NONCE_ACTION)
        ) {
            return;
        }
        $handled_post_ids[(int) $post_id] = true;
        $group_ids = isset($_POST['tsol_library_access_group_ids']) && is_array($_POST['tsol_library_access_group_ids'])
            ? $_POST['tsol_library_access_group_ids']
            : array();
        $revision = isset($_POST['tsol_library_access_groups_revision'])
            ? sanitize_text_field(wp_unslash($_POST['tsol_library_access_groups_revision']))
            : '';
        try {
            $this->service->save_membership_assignments($post_id, $group_ids, $revision);
            $this->set_membership_notice(__('Library Access Groups were saved as a draft.', 'tomschooloflife-plugin'), 'success');
        } catch (Throwable $exception) {
            $this->set_membership_notice($exception->getMessage(), 'error');
        }
    }

    private function set_membership_notice($message, $type) {
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            set_transient('tsol_library_access_groups_notice_' . $user_id, array(
                'message' => sanitize_text_field((string) $message),
                'type' => 'success' === $type ? 'success' : 'error',
            ), MINUTE_IN_SECONDS);
        }
    }

    public function membership_notice() {
        $user_id = get_current_user_id();
        $notice = $user_id > 0 ? get_transient('tsol_library_access_groups_notice_' . $user_id) : false;
        if (!is_array($notice)) {
            return;
        }
        delete_transient('tsol_library_access_groups_notice_' . $user_id);
        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr((string) $notice['type']),
            esc_html((string) $notice['message'])
        );
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $configuration = $this->service->configuration();
        $preview = $this->service->preview();
        $definitions = $this->service->definitions();
        $groups = (array) ($configuration['groups'] ?? array());
        $assignments = (array) ($configuration['assignments'] ?? array());
        $group_memberships = array_fill_keys(array_keys($groups), array());
        $membership_names = array();
        foreach ($this->service->memberships() as $membership) {
            $membership_names[(int) $membership->ID] = (string) $membership->post_title;
        }
        foreach ($assignments as $membership_id => $group_ids) {
            foreach ((array) $group_ids as $group_id) {
                if (isset($group_memberships[$group_id])) {
                    $group_memberships[$group_id][(int) $membership_id] = $membership_names[(int) $membership_id] ?? ('#' . (int) $membership_id);
                }
            }
        }
        $error = isset($_GET['tsol_error']) ? sanitize_text_field(wp_unslash($_GET['tsol_error'])) : '';
        ?>
        <div class="wrap tsol-library-admin-page tsol-library-access-groups-page">
            <h1><?php esc_html_e('Library Access Groups', 'tomschooloflife-plugin'); ?></h1>
            <p class="tsol-library-admin-page__lead"><?php esc_html_e('Create reusable Library access packages here, then assign them from each MemberPress membership. MemberPress remains the live authority.', 'tomschooloflife-plugin'); ?></p>
            <ol class="tsol-access-groups-steps" aria-label="<?php esc_attr_e('How Library Access Groups work', 'tomschooloflife-plugin'); ?>">
                <li><strong><?php esc_html_e('Define access', 'tomschooloflife-plugin'); ?></strong><span><?php esc_html_e('Create a group and choose what it unlocks.', 'tomschooloflife-plugin'); ?></span></li>
                <li><strong><?php esc_html_e('Assign memberships', 'tomschooloflife-plugin'); ?></strong><span><?php esc_html_e('Select groups while editing a MemberPress membership.', 'tomschooloflife-plugin'); ?></span></li>
                <li><strong><?php esc_html_e('Check and publish', 'tomschooloflife-plugin'); ?></strong><span><?php esc_html_e('Return here to verify access before anything goes live.', 'tomschooloflife-plugin'); ?></span></li>
            </ol>

            <?php if ('' !== $error) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php elseif (isset($_GET['updated'])) : ?>
                <div class="notice notice-success"><p><?php echo esc_html($this->success_message(sanitize_key(wp_unslash($_GET['updated'])))); ?></p></div>
            <?php endif; ?>

            <?php if (!$preview['bootstrapped']) : ?>
                <section class="card tsol-library-admin-card--wide">
                    <h2><?php esc_html_e('Import current access safely', 'tomschooloflife-plugin'); ?></h2>
                    <p><?php esc_html_e('Turn the current TSOL-native MemberPress policy into named draft groups without changing memberships, subscriptions, legacy courses, rules, or live member access.', 'tomschooloflife-plugin'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tsol_library_access_groups">
                        <input type="hidden" name="operation" value="bootstrap">
                        <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                        <?php submit_button(__('Import current access into groups', 'tomschooloflife-plugin'), 'primary', 'submit', false); ?>
                    </form>
                </section>
            <?php else : ?>
                <div class="tsol-library-admin-stats">
                    <?php $this->stat(__('Access Groups', 'tomschooloflife-plugin'), $preview['group_count']); ?>
                    <?php $this->stat(__('Assigned memberships', 'tomschooloflife-plugin'), $preview['assigned_memberships']); ?>
                    <?php $this->stat(__('Unassigned memberships', 'tomschooloflife-plugin'), count($preview['unassigned_membership_ids'])); ?>
                </div>

                <?php if (!empty($preview['unassigned_membership_ids'])) : ?>
                    <div class="notice notice-warning inline"><p><strong><?php esc_html_e('Some memberships are unassigned.', 'tomschooloflife-plugin'); ?></strong> <?php esc_html_e('Assign a group from the relevant MemberPress membership only when that product should include Library access.', 'tomschooloflife-plugin'); ?></p></div>
                <?php endif; ?>

                <?php if (!empty($preview['unmanaged_rule_ids'])) : ?>
                    <div class="notice notice-warning inline">
                        <p><strong><?php esc_html_e('A published Library rule is still outside Access Groups.', 'tomschooloflife-plugin'); ?></strong> <?php esc_html_e('Safety checks and publishing stay blocked until every Library rule is managed here.', 'tomschooloflife-plugin'); ?></p>
                        <?php if (count($preview['unmanaged_rule_ids']) === count($preview['reconcilable_rule_ids'])) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="tsol_library_access_groups">
                                <input type="hidden" name="operation" value="reconcile">
                                <input type="hidden" name="revision" value="<?php echo esc_attr((string) $configuration['revision']); ?>">
                                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                                <?php submit_button(__('Bring it into Access Groups', 'tomschooloflife-plugin'), 'secondary', 'submit', false); ?>
                            </form>
                        <?php else : ?>
                            <p><?php esc_html_e('Review the unmanaged rule inventory with a developer before continuing; arbitrary MemberPress rules are never changed automatically.', 'tomschooloflife-plugin'); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php $this->publish_status($preview); ?>

                <?php $editing_locked = !empty($preview['stage']) && 'active' !== (string) ($preview['stage']['phase'] ?? ''); ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-access-groups-form>
                    <input type="hidden" name="action" value="tsol_library_access_groups">
                    <input type="hidden" name="operation" value="save_groups">
                    <input type="hidden" name="revision" value="<?php echo esc_attr((string) $configuration['revision']); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                    <?php if ('active' === (string) ($preview['stage']['phase'] ?? '')) : ?>
                        <div class="notice notice-info inline"><p><?php esc_html_e('The published access remains live when you save. Your edits stay pending until you check and publish them.', 'tomschooloflife-plugin'); ?></p></div>
                    <?php endif; ?>
                    <fieldset class="tsol-access-groups-form-fields" <?php disabled($editing_locked); ?>>
                    <?php if ($editing_locked) : ?><legend class="screen-reader-text"><?php esc_html_e('Editing is locked while checked changes await a decision.', 'tomschooloflife-plugin'); ?></legend><?php endif; ?>
                    <div class="tsol-access-groups-heading">
                        <div><h2><?php esc_html_e('Defined access packages', 'tomschooloflife-plugin'); ?></h2><p><?php esc_html_e('A group can unlock broad Library areas or a deliberate combination of individual Courses and Series.', 'tomschooloflife-plugin'); ?></p></div>
                        <button type="button" class="button" data-add-access-group><?php esc_html_e('Add Access Group', 'tomschooloflife-plugin'); ?></button>
                    </div>
                    <div class="tsol-access-groups-list" data-access-groups-list>
                        <?php $index = 0; foreach ($groups as $group_id => $group) : ?>
                            <?php $this->group_editor($index++, $group_id, $group, $definitions, (array) ($group_memberships[$group_id] ?? array())); ?>
                        <?php endforeach; ?>
                    </div>
                    <template data-access-group-template>
                        <?php $this->group_editor('__INDEX__', '', array('name' => '', 'description' => '', 'scopes' => array()), $definitions, array()); ?>
                    </template>
                    <div class="tsol-access-groups-save">
                        <?php submit_button(__('Save changes', 'tomschooloflife-plugin'), 'primary', 'submit', false); ?>
                        <span><?php esc_html_e('This saves your setup only. Member access does not change until you check and publish it above.', 'tomschooloflife-plugin'); ?></span>
                    </div>
                    </fieldset>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function success_message($operation) {
        $messages = array(
            'bootstrap' => __('Current access was imported safely. Nothing has been published.', 'tomschooloflife-plugin'),
            'save_groups' => __('Changes saved. Member access has not changed.', 'tomschooloflife-plugin'),
            'reconcile' => __('The existing plugin-owned rule was added to the Access Groups draft. Live access has not changed.', 'tomschooloflife-plugin'),
            'stage' => __('Safety checks passed. Review the result before publishing.', 'tomschooloflife-plugin'),
            'activate' => __('Access Group changes are now live.', 'tomschooloflife-plugin'),
            'rollback' => __('The checked changes were discarded or the previous access rules were restored.', 'tomschooloflife-plugin'),
        );
        return $messages[$operation] ?? __('Access Groups were updated.', 'tomschooloflife-plugin');
    }

    private function publish_status($preview) {
        $stage = (array) ($preview['stage'] ?? array());
        $phase = (string) ($stage['phase'] ?? 'draft');
        $verification = (array) ($stage['verification'] ?? array());
        $matrix = (array) ($verification['matrix'] ?? array());
        ?>
        <section class="card tsol-library-admin-card--wide tsol-access-publish-status tsol-access-publish-status--<?php echo esc_attr($phase); ?>" aria-labelledby="tsol-access-publish-heading">
            <?php if ('active' === $phase) : ?>
                <span class="tsol-access-publish-status__label"><?php esc_html_e('Live', 'tomschooloflife-plugin'); ?></span>
                <h2 id="tsol-access-publish-heading"><?php esc_html_e('Access Groups are published', 'tomschooloflife-plugin'); ?></h2>
                <p><?php esc_html_e('Members are using these generated MemberPress rules. You can continue editing safely; saved edits become pending changes while the current rules stay live.', 'tomschooloflife-plugin'); ?></p>
                <?php $this->action_form('rollback', __('Restore previous access rules', 'tomschooloflife-plugin'), 'secondary'); ?>
            <?php elseif ('staged' === $phase) : ?>
                <span class="tsol-access-publish-status__label"><?php esc_html_e('Checks passed', 'tomschooloflife-plugin'); ?></span>
                <h2 id="tsol-access-publish-heading"><?php esc_html_e('Ready to publish', 'tomschooloflife-plugin'); ?></h2>
                <p><?php esc_html_e('The proposed MemberPress rules are still inactive. No member access has changed.', 'tomschooloflife-plugin'); ?></p>
                <p><?php esc_html_e('Publish or discard these checked changes before editing the groups again.', 'tomschooloflife-plugin'); ?></p>
                <?php if (!empty($matrix)) : ?>
                    <p class="tsol-access-publish-status__result"><strong><?php echo esc_html(number_format_i18n((int) ($matrix['decisions_checked'] ?? 0))); ?></strong> <?php esc_html_e('member-and-content checks completed', 'tomschooloflife-plugin'); ?> · <strong><?php echo esc_html(number_format_i18n((int) ($matrix['allow_to_deny'] ?? 0))); ?></strong> <?php esc_html_e('members losing access', 'tomschooloflife-plugin'); ?> · <strong><?php echo esc_html(number_format_i18n((int) ($matrix['deny_to_allow'] ?? 0))); ?></strong> <?php esc_html_e('unexpected access grants', 'tomschooloflife-plugin'); ?></p>
                <?php endif; ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tsol-access-publish-status__confirm">
                    <input type="hidden" name="action" value="tsol_library_access_groups">
                    <input type="hidden" name="operation" value="activate">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                    <label><strong><?php esc_html_e('To publish, type publish-access-groups', 'tomschooloflife-plugin'); ?></strong><input type="text" name="confirmation" autocomplete="off"></label>
                    <?php submit_button(__('Publish access changes', 'tomschooloflife-plugin'), 'primary', 'submit', false); ?>
                </form>
                <?php $this->action_form('rollback', __('Discard checked changes', 'tomschooloflife-plugin'), 'secondary'); ?>
            <?php elseif (in_array($phase, array('staging', 'failed'), true)) : ?>
                <span class="tsol-access-publish-status__label"><?php esc_html_e('Check incomplete', 'tomschooloflife-plugin'); ?></span>
                <h2 id="tsol-access-publish-heading"><?php esc_html_e('No access changes were published', 'tomschooloflife-plugin'); ?></h2>
                <p><?php esc_html_e('Remove the incomplete check, review your setup, and try again.', 'tomschooloflife-plugin'); ?></p>
                <?php $this->action_form('rollback', __('Clear incomplete check', 'tomschooloflife-plugin'), 'secondary'); ?>
            <?php else : ?>
                <span class="tsol-access-publish-status__label"><?php esc_html_e('Pending changes', 'tomschooloflife-plugin'); ?></span>
                <h2 id="tsol-access-publish-heading"><?php esc_html_e('Your saved setup is not live yet', 'tomschooloflife-plugin'); ?></h2>
                <?php if (!empty($preview['unmanaged_rule_ids'])) : ?>
                    <p><?php esc_html_e('Resolve the published Library rule outside Access Groups before running the safety check. Current member access remains unchanged.', 'tomschooloflife-plugin'); ?></p>
                <?php else : ?>
                    <p><?php esc_html_e('Keep editing as needed. When ready, run the safety check to compare the proposed access for every current member and Library item. Current member access remains unchanged.', 'tomschooloflife-plugin'); ?></p>
                    <?php $this->action_form('stage', __('Check changes before publishing', 'tomschooloflife-plugin'), 'primary'); ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>
        <?php
    }

    private function group_editor($index, $group_id, $group, $definitions, $memberships) {
        $field_prefix = 'groups[' . $index . ']';
        $selected = array_map('strval', (array) ($group['scopes'] ?? array()));
        ?>
        <details class="tsol-access-group-card" data-access-group-card <?php echo '' === $group_id ? 'open' : ''; ?>>
            <summary><strong data-access-group-summary><?php echo esc_html((string) ($group['name'] ?: __('New Access Group', 'tomschooloflife-plugin'))); ?></strong><span><?php echo esc_html(sprintf(_n('%d membership', '%d memberships', count($memberships), 'tomschooloflife-plugin'), count($memberships))); ?></span></summary>
            <div class="tsol-access-group-card__body">
                <input type="hidden" name="<?php echo esc_attr($field_prefix . '[id]'); ?>" value="<?php echo esc_attr($group_id); ?>">
                <input type="hidden" name="<?php echo esc_attr($field_prefix . '[remove]'); ?>" value="0" data-access-group-remove-value>
                <div class="tsol-access-group-card__identity">
                    <label><span><?php esc_html_e('Group name', 'tomschooloflife-plugin'); ?></span><input type="text" name="<?php echo esc_attr($field_prefix . '[name]'); ?>" value="<?php echo esc_attr((string) $group['name']); ?>" required data-access-group-name></label>
                    <label><span><?php esc_html_e('Admin description', 'tomschooloflife-plugin'); ?></span><textarea name="<?php echo esc_attr($field_prefix . '[description]'); ?>" rows="2"><?php echo esc_textarea((string) $group['description']); ?></textarea></label>
                </div>
                <fieldset>
                    <legend><?php esc_html_e('Library access', 'tomschooloflife-plugin'); ?></legend>
                    <p class="description"><?php esc_html_e('Select everything this package unlocks. Collection and all-Series selections also cover future matching content.', 'tomschooloflife-plugin'); ?></p>
                    <div class="tsol-access-group-scopes">
                        <?php foreach (array('broad' => __('Broad access', 'tomschooloflife-plugin'), 'course' => __('Individual Courses', 'tomschooloflife-plugin'), 'series' => __('Individual Series', 'tomschooloflife-plugin')) as $section => $label) : ?>
                            <div><h4><?php echo esc_html($label); ?></h4>
                                <?php foreach ($definitions as $scope_key => $definition) : ?>
                                    <?php $definition_section = in_array($definition['kind'], array('library', 'collection', 'all_series'), true) ? 'broad' : $definition['kind']; ?>
                                    <?php if ($section !== $definition_section) { continue; } ?>
                                    <label><input type="checkbox" name="<?php echo esc_attr($field_prefix . '[scopes][]'); ?>" value="<?php echo esc_attr($scope_key); ?>" <?php checked(in_array($scope_key, $selected, true)); ?>><span><strong><?php echo esc_html($definition['label']); ?></strong><small><?php echo esc_html($definition['description']); ?></small></span></label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <?php if (!empty($memberships)) : ?>
                    <details class="tsol-access-group-card__memberships" data-access-group-memberships>
                        <summary><?php echo esc_html(sprintf(_n('View %d assigned membership', 'View %d assigned memberships', count($memberships), 'tomschooloflife-plugin'), count($memberships))); ?></summary>
                        <p class="description"><?php esc_html_e('Assignments are changed from each MemberPress membership.', 'tomschooloflife-plugin'); ?></p>
                        <ul tabindex="0" aria-label="<?php echo esc_attr(sprintf(__('%s assigned memberships', 'tomschooloflife-plugin'), (string) $group['name'])); ?>">
                            <?php foreach ($memberships as $membership_id => $membership_name) : ?>
                                <li><a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $membership_id . '&action=edit')); ?>"><?php echo esc_html($membership_name); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
                <button type="button" class="button-link-delete" data-remove-access-group><?php esc_html_e('Delete this Access Group', 'tomschooloflife-plugin'); ?></button>
            </div>
        </details>
        <?php
    }

    private function action_form($operation, $label, $class) {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:8px">
            <input type="hidden" name="action" value="tsol_library_access_groups">
            <input type="hidden" name="operation" value="<?php echo esc_attr($operation); ?>">
            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
            <?php submit_button($label, $class, 'submit', false); ?>
        </form>
        <?php
    }

    private function stat($label, $value) {
        ?><div class="tsol-library-admin-stat"><span class="tsol-library-admin-stat__value"><?php echo esc_html(number_format_i18n((int) $value)); ?></span><span class="tsol-library-admin-stat__label"><?php echo esc_html($label); ?></span></div><?php
    }
}
