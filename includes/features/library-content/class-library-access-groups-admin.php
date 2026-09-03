<?php
/** WordPress admin UI for Library Access Groups. */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Access_Groups_Admin {

    const PAGE_SLUG = 'tsol-library-access-groups';
    const NONCE_ACTION = 'tsol_library_access_groups';
    const NONCE_NAME = 'tsol_library_access_groups_nonce';
    const MEMBERSHIP_NONCE_ACTION = 'tsol_library_access_groups_membership';
    const MEMBERSHIP_NONCE_NAME = 'tsol_library_access_groups_membership_nonce';

    private $service;
    private $page_hook = '';

    public function __construct() {
        $this->service = new MemberLibrary_Access_Groups();
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
            MemberLibrary_Admin_Navigation::MENU_SLUG,
            __('Library Access Groups', 'member-library'),
            __('Access Groups', 'member-library'),
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
            MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-access-groups-admin.css',
            array('tsol-library-content-admin'),
            MEMBER_LIBRARY_PLUGIN_VERSION
        );
        if (!$is_membership_editor) {
            wp_enqueue_script(
                'tsol-library-access-groups-admin',
                MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-access-groups-admin.js',
                array(),
                MEMBER_LIBRARY_PLUGIN_VERSION,
                true
            );
        }
    }

    public function handle_action() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to manage Library access.', 'member-library'));
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
            } elseif ('publish' === $operation) {
                $result = $this->service->publish();
                $operation = !empty($result['published']) ? 'publish' : 'publish_blocked';
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
            __('Library Access Groups', 'member-library'),
            array($this, 'render_membership_meta_box'),
            'memberpressproduct',
            'side',
            'default'
        );
    }

    public function render_membership_meta_box($post) {
        if (!$this->service->is_bootstrapped()) {
            echo '<p>' . esc_html__('Import the current policy under Library → Access Groups before assigning memberships.', 'member-library') . '</p>';
            return;
        }
        $configuration = $this->service->configuration();
        $groups = $this->service->groups();
        $selected = $this->service->membership_group_ids($post->ID);
        wp_nonce_field(self::MEMBERSHIP_NONCE_ACTION, self::MEMBERSHIP_NONCE_NAME);
        ?>
        <input type="hidden" name="tsol_library_access_groups_revision" value="<?php echo esc_attr((string) $configuration['revision']); ?>">
        <p><?php esc_html_e('Tick the Library groups this membership includes. Saving updates the draft only; publish from the Access Groups page to make it live.', 'member-library'); ?></p>
        <?php $pending = $this->service->changes_since_publish($configuration); $membership_pending = (array) ($pending['assignments'][(int) $post->ID] ?? array()); ?>
        <?php if (!empty($membership_pending)) : ?>
            <p class="tsol-membership-access-groups__pending" data-access-membership-pending><strong><?php esc_html_e('Draft differs from live:', 'member-library'); ?></strong>
                <?php if (!empty($membership_pending['added'])) : ?><?php echo esc_html(sprintf(__('gains %s', 'member-library'), implode(', ', $membership_pending['added']))); ?><?php endif; ?>
                <?php if (!empty($membership_pending['added']) && !empty($membership_pending['removed'])) : ?> · <?php endif; ?>
                <?php if (!empty($membership_pending['removed'])) : ?><?php echo esc_html(sprintf(__('loses %s', 'member-library'), implode(', ', $membership_pending['removed']))); ?><?php endif; ?>
            </p>
        <?php endif; ?>
        <fieldset class="tsol-membership-access-groups">
            <legend class="screen-reader-text"><?php esc_html_e('Assigned Library Access Groups', 'member-library'); ?></legend>
            <?php if (empty($groups)) : ?>
                <p><?php esc_html_e('No Access Groups have been created.', 'member-library'); ?></p>
            <?php else : ?>
                <?php foreach ($groups as $group_id => $group) : ?>
                    <label>
                        <input type="checkbox" name="tsol_library_access_group_ids[]" value="<?php echo esc_attr($group_id); ?>" <?php checked(in_array($group_id, $selected, true)); ?>>
                        <span><strong><?php echo esc_html($group['name']); ?></strong><?php if ('' !== (string) $group['description']) : ?><small><?php echo esc_html($group['description']); ?></small><?php endif; ?></span>
                    </label>
                <?php endforeach; ?>
            <?php endif; ?>
        </fieldset>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG)); ?>"><?php esc_html_e('Manage Access Groups', 'member-library'); ?></a></p>
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
            $this->set_membership_notice(__('Draft saved. Publish from Library → Access Groups to make it live.', 'member-library'), 'success');
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
        $changes = $preview['bootstrapped'] ? $this->service->changes_since_publish($configuration) : array();
        $states = $preview['bootstrapped'] ? $this->service->group_states($configuration) : array();
        $error = isset($_GET['tsol_error']) ? sanitize_text_field(wp_unslash($_GET['tsol_error'])) : '';
        ?>
        <div class="wrap tsol-library-admin-page tsol-library-access-groups-page">
            <h1><?php esc_html_e('Library Access Groups', 'member-library'); ?></h1>
            <p class="tsol-library-admin-page__lead"><?php esc_html_e('An Access Group is a package of Library content. Assign groups to MemberPress memberships, then publish. Until you publish, members keep exactly the access they have today.', 'member-library'); ?></p>

            <?php if ('' !== $error) : ?>
                <div class="notice notice-error"><p><?php echo esc_html($error); ?></p></div>
            <?php elseif (isset($_GET['updated'])) : ?>
                <div class="notice <?php echo 'publish_blocked' === sanitize_key(wp_unslash($_GET['updated'])) ? 'notice-warning' : 'notice-success'; ?>"><p><?php echo esc_html($this->success_message(sanitize_key(wp_unslash($_GET['updated'])))); ?></p></div>
            <?php endif; ?>

            <?php if (!$preview['bootstrapped']) : ?>
                <section class="card tsol-library-admin-card--wide">
                    <h2><?php esc_html_e('Start from the access members have today', 'member-library'); ?></h2>
                    <p><?php esc_html_e('This reads the current MemberPress rules and turns them into named draft groups. Nothing changes for members.', 'member-library'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tsol_library_access_groups">
                        <input type="hidden" name="operation" value="bootstrap">
                        <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                        <?php submit_button(__('Import current access as a draft', 'member-library'), 'primary', 'submit', false); ?>
                    </form>
                </section>
            <?php else : ?>
                <?php if (!empty($preview['unmanaged_rule_ids'])) : ?>
                    <div class="notice notice-warning inline">
                        <p><strong><?php esc_html_e('A live Library rule is not managed here yet.', 'member-library'); ?></strong> <?php esc_html_e('Publishing stays off until every Library rule is part of Access Groups.', 'member-library'); ?></p>
                        <?php if (count($preview['unmanaged_rule_ids']) === count($preview['reconcilable_rule_ids'])) : ?>
                            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                <input type="hidden" name="action" value="tsol_library_access_groups">
                                <input type="hidden" name="operation" value="reconcile">
                                <input type="hidden" name="revision" value="<?php echo esc_attr((string) $configuration['revision']); ?>">
                                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                                <?php submit_button(__('Bring it into Access Groups', 'member-library'), 'secondary', 'submit', false); ?>
                            </form>
                        <?php else : ?>
                            <p><?php esc_html_e('Ask a developer to review this rule; MemberPress rules that were not created here are never changed automatically.', 'member-library'); ?></p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php $this->state_panels($preview, $changes, $groups); ?>

                <?php $editing_locked = !empty($preview['stage']) && 'active' !== (string) ($preview['stage']['phase'] ?? ''); ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-access-groups-form>
                    <input type="hidden" name="action" value="tsol_library_access_groups">
                    <input type="hidden" name="operation" value="save_groups">
                    <input type="hidden" name="revision" value="<?php echo esc_attr((string) $configuration['revision']); ?>">
                    <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                    <fieldset class="tsol-access-groups-form-fields" <?php disabled($editing_locked); ?>>
                    <?php if ($editing_locked) : ?><legend class="screen-reader-text"><?php esc_html_e('Editing is paused while a review is waiting for a decision.', 'member-library'); ?></legend><?php endif; ?>
                    <div class="tsol-access-groups-heading">
                        <div>
                            <h2><?php esc_html_e('Groups', 'member-library'); ?></h2>
                            <p><?php esc_html_e('Each badge says whether the group matches what is live. Edits are saved to the draft; publish from the panel above.', 'member-library'); ?></p>
                        </div>
                        <button type="button" class="button" data-add-access-group><?php esc_html_e('Add group', 'member-library'); ?></button>
                    </div>
                    <div class="tsol-access-groups-list" data-access-groups-list>
                        <?php $index = 0; foreach ($groups as $group_id => $group) : ?>
                            <?php $this->group_editor($index++, $group_id, $group, $definitions, (array) ($group_memberships[$group_id] ?? array()), (string) ($states[$group_id] ?? 'draft')); ?>
                        <?php endforeach; ?>
                    </div>
                    <template data-access-group-template>
                        <?php $this->group_editor('__INDEX__', '', array('name' => '', 'description' => '', 'scopes' => array()), $definitions, array(), 'new'); ?>
                    </template>
                    <div class="tsol-access-groups-save">
                        <?php submit_button(__('Save draft', 'member-library'), 'primary', 'submit', false); ?>
                        <span><?php esc_html_e('Saving never changes member access. Only Publish does.', 'member-library'); ?></span>
                    </div>
                    </fieldset>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    private function success_message($operation) {
        $messages = array(
            'bootstrap' => __('Current access was imported as a draft. Nothing changed for members.', 'member-library'),
            'save_groups' => __('Draft saved. Nothing changed for members.', 'member-library'),
            'reconcile' => __('The rule was added to the draft. Nothing changed for members.', 'member-library'),
            'stage' => __('Review complete. Publish when you are happy with the summary.', 'member-library'),
            'activate' => __('Published. Members now have this access.', 'member-library'),
            'publish' => __('Published. Every current member keeps their access, and the draft is now live.', 'member-library'),
            'publish_blocked' => __('Not published. Some members would have lost access; who and why is shown below, and nothing changed for them.', 'member-library'),
            'rollback' => __('Done. Members have the access that was live before.', 'member-library'),
        );
        return $messages[$operation] ?? __('Access Groups were updated.', 'member-library');
    }

    /**
     * Live on the left, Draft on the right. The draft panel carries the one
     * action that is appropriate for its state: Review, Publish or Discard.
     */
    private function state_panels($preview, $changes, $groups) {
        $stage = (array) ($preview['stage'] ?? array());
        $phase = (string) ($stage['phase'] ?? '');
        $verification = (array) ($stage['verification'] ?? array());
        $matrix = (array) ($verification['matrix'] ?? array());
        $has_published = !empty($changes['has_published']);
        $published = $this->service->published_configuration();
        $published_at = (string) ($changes['published_at'] ?? '');
        $live_group_count = count((array) ($published['groups'] ?? array()));
        $live_membership_count = count((array) ($published['assignments'] ?? array()));
        $counts = (array) ($changes['counts'] ?? array());
        $total_changes = (int) ($counts['total'] ?? 0);
        $publish_blocked = !empty($preview['unmanaged_rule_ids']);
        ?>
        <div class="tsol-access-state" aria-label="<?php esc_attr_e('Live and draft access', 'member-library'); ?>">
            <section class="tsol-access-state__panel tsol-access-state__panel--live" data-access-state-panel="live" aria-labelledby="tsol-access-live-heading">
                <span class="tsol-access-state__badge tsol-access-state__badge--live"><?php esc_html_e('Live', 'member-library'); ?></span>
                <h2 id="tsol-access-live-heading"><?php esc_html_e('What members have now', 'member-library'); ?></h2>
                <?php if ($has_published) : ?>
                    <p class="tsol-access-state__figures">
                        <strong><?php echo esc_html(number_format_i18n($live_group_count)); ?></strong> <?php echo esc_html(_n('group', 'groups', $live_group_count, 'member-library')); ?>
                        <span aria-hidden="true">·</span>
                        <strong><?php echo esc_html(number_format_i18n($live_membership_count)); ?></strong> <?php echo esc_html(_n('membership assigned', 'memberships assigned', $live_membership_count, 'member-library')); ?>
                    </p>
                    <?php if ('' !== $published_at) : ?>
                        <p class="description"><?php echo esc_html(sprintf(__('Published %s', 'member-library'), wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($published_at . ' UTC')))); ?></p>
                    <?php endif; ?>
                    <?php if ('active' === $phase) : ?>
                        <?php $this->action_form('rollback', __('Undo this publish', 'member-library'), 'secondary', __('Undo the last publish? Members go back to the access rules that were live before it.', 'member-library')); ?>
                    <?php endif; ?>
                <?php else : ?>
                    <p><?php esc_html_e('Nothing from Access Groups is live yet. Members are governed by the MemberPress rules that already exist.', 'member-library'); ?></p>
                <?php endif; ?>
            </section>

            <section class="tsol-access-state__panel tsol-access-state__panel--draft tsol-access-state__panel--<?php echo esc_attr('' === $phase ? 'draft' : $phase); ?>" data-access-state-panel="draft" aria-labelledby="tsol-access-draft-heading">
                <?php if ('staged' === $phase) : ?>
                    <?php $blocked = (int) ($matrix['allow_to_deny'] ?? 0) > 0; ?>
                    <?php if ($blocked) : ?>
                        <span class="tsol-access-state__badge tsol-access-state__badge--problem"><?php esc_html_e('Blocked', 'member-library'); ?></span>
                        <h2 id="tsol-access-draft-heading"><?php echo esc_html(sprintf(_n('%s member would lose access', '%s members would lose access', (int) ($matrix['losing_users'] ?? 0), 'member-library'), number_format_i18n((int) ($matrix['losing_users'] ?? 0)))); ?></h2>
                        <p><?php esc_html_e('These people can reach Library content today but no group in the draft covers them. Nothing has been published. Give their membership a group, or accept the loss by removing them from LearnDash, then publish again.', 'member-library'); ?></p>
                        <?php if (!empty($matrix['losses_by_membership'])) : ?>
                            <ul class="tsol-access-state__losses" data-access-losses>
                                <?php foreach ((array) $matrix['losses_by_membership'] as $label => $count) : ?>
                                    <li><strong><?php echo esc_html(number_format_i18n((int) $count)); ?></strong> <?php echo esc_html($label); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <?php if (!empty($matrix['losing_sample'])) : ?>
                            <p class="description"><?php echo esc_html(sprintf(__('For example: %s', 'member-library'), implode(', ', array_map('strval', (array) $matrix['losing_sample'])))); ?></p>
                        <?php endif; ?>
                    <?php else : ?>
                        <span class="tsol-access-state__badge tsol-access-state__badge--review"><?php esc_html_e('Reviewed', 'member-library'); ?></span>
                        <h2 id="tsol-access-draft-heading"><?php esc_html_e('Ready to publish', 'member-library'); ?></h2>
                    <?php endif; ?>
                    <?php if (!empty($matrix)) : ?>
                        <ul class="tsol-access-state__matrix">
                            <li><strong><?php echo esc_html(number_format_i18n((int) ($matrix['decisions_checked'] ?? 0))); ?></strong> <?php esc_html_e('member-and-content combinations compared', 'member-library'); ?></li>
                            <li class="<?php echo (int) ($matrix['allow_to_deny'] ?? 0) > 0 ? 'is-blocking' : ''; ?>"><strong><?php echo esc_html(number_format_i18n((int) ($matrix['allow_to_deny'] ?? 0))); ?></strong> <?php esc_html_e('member-and-item combinations that would be lost', 'member-library'); ?></li>
                            <li><strong><?php echo esc_html(number_format_i18n((int) ($matrix['deny_to_allow'] ?? 0))); ?></strong> <?php esc_html_e('member-and-item combinations that would be gained', 'member-library'); ?></li>
                            <?php if (!empty($matrix['baseline_sources']['learndash'])) : ?>
                                <li class="description"><?php echo esc_html(sprintf(__('Today\'s access for %d items was read from LearnDash enrolment because no MemberPress rule protects them.', 'member-library'), (int) $matrix['baseline_sources']['learndash'])); ?></li>
                            <?php endif; ?>
                        </ul>
                    <?php endif; ?>
                    <?php $this->change_list($changes); ?>
                    <div class="tsol-access-state__actions">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-access-confirm="<?php echo esc_attr(sprintf(__('Publish now? %d change(s) go live for members immediately. You can undo from the Live panel afterwards.', 'member-library'), $total_changes)); ?>">
                            <input type="hidden" name="action" value="tsol_library_access_groups">
                            <input type="hidden" name="operation" value="activate">
                            <input type="hidden" name="confirmation" value="<?php echo esc_attr(MemberLibrary_Access_Groups::ACTIVATE_CONFIRMATION); ?>">
                            <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>
                            <?php if (!$blocked) : ?><?php submit_button(__('Publish', 'member-library'), 'primary', 'submit', false); ?><?php endif; ?>
                        </form>
                        <?php $this->action_form('rollback', __('Back to editing', 'member-library'), $blocked ? 'primary' : 'secondary'); ?>
                    </div>
                    <p class="description"><?php esc_html_e('Nothing has changed for members yet. "Back to editing" keeps your draft and drops this review.', 'member-library'); ?></p>
                <?php elseif (in_array($phase, array('staging', 'failed'), true)) : ?>
                    <span class="tsol-access-state__badge tsol-access-state__badge--problem"><?php esc_html_e('Review stopped', 'member-library'); ?></span>
                    <h2 id="tsol-access-draft-heading"><?php esc_html_e('The review did not finish', 'member-library'); ?></h2>
                    <p><?php esc_html_e('Nothing changed for members. Clear it and run the review again.', 'member-library'); ?></p>
                    <?php if (!empty($stage['error'])) : ?><p class="description"><?php echo esc_html((string) $stage['error']); ?></p><?php endif; ?>
                    <?php $this->action_form('rollback', __('Clear and go back to the draft', 'member-library'), 'secondary'); ?>
                <?php else : ?>
                    <span class="tsol-access-state__badge tsol-access-state__badge--draft"><?php esc_html_e('Draft', 'member-library'); ?></span>
                    <?php if (!$has_published) : ?>
                        <h2 id="tsol-access-draft-heading"><?php echo esc_html(sprintf(_n('%d group waiting to go live', '%d groups waiting to go live', count($groups), 'member-library'), count($groups))); ?></h2>
                    <?php elseif (!empty($changes['has_changes'])) : ?>
                        <h2 id="tsol-access-draft-heading"><?php echo esc_html(sprintf(_n('%d change not yet live', '%d changes not yet live', $total_changes, 'member-library'), $total_changes)); ?></h2>
                    <?php else : ?>
                        <h2 id="tsol-access-draft-heading"><?php esc_html_e('Same as live', 'member-library'); ?></h2>
                        <p><?php esc_html_e('Edit the groups below or assign them on a membership. Changes stay here as a draft until you review and publish.', 'member-library'); ?></p>
                    <?php endif; ?>
                    <?php $this->change_list($changes); ?>
                    <?php if ((!empty($changes['has_changes']) || !$has_published) && !empty($groups)) : ?>
                        <div class="tsol-access-state__actions">
                            <?php if ($publish_blocked) : ?>
                                <p class="description"><?php esc_html_e('Bring the unmanaged rule above into Access Groups first.', 'member-library'); ?></p>
                            <?php else : ?>
                                <?php $this->action_form('publish', __('Publish', 'member-library'), 'primary', sprintf(__('Publish the draft now? Every current member is checked first; if anyone would lose access, nothing is published and you will see who. %d change(s) would go live.', 'member-library'), $total_changes)); ?>
                                <?php $this->action_form('stage', __('Preview the check first', 'member-library'), 'secondary'); ?>
                                <span class="description"><?php esc_html_e('Publishing checks every current member first. If anyone would lose access, nothing changes and you see exactly who.', 'member-library'); ?></span>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    private function change_list($changes) {
        if (empty($changes['has_published']) || empty($changes['has_changes'])) {
            return;
        }
        $groups = (array) ($changes['groups'] ?? array());
        ?>
        <ul class="tsol-access-state__changes" data-access-change-list>
            <?php foreach ((array) ($groups['added'] ?? array()) as $name) : ?>
                <li><span class="tsol-access-chip tsol-access-chip--new"><?php esc_html_e('New', 'member-library'); ?></span> <?php echo esc_html($name); ?></li>
            <?php endforeach; ?>
            <?php foreach ((array) ($groups['changed'] ?? array()) as $change) : ?>
                <li><span class="tsol-access-chip tsol-access-chip--changed"><?php esc_html_e('Changed', 'member-library'); ?></span> <?php echo esc_html($change['name']); ?>
                    <?php
                    $details = array();
                    if (!empty($change['renamed'])) {
                        $details[] = sprintf(__('renamed from “%s”', 'member-library'), $change['previous_name']);
                    }
                    if (!empty($change['scopes_added'])) {
                        $details[] = sprintf(__('adds %s', 'member-library'), implode(', ', $change['scopes_added']));
                    }
                    if (!empty($change['scopes_removed'])) {
                        $details[] = sprintf(__('removes %s', 'member-library'), implode(', ', $change['scopes_removed']));
                    }
                    if (!empty($change['description_changed']) && empty($details)) {
                        $details[] = __('description edited', 'member-library');
                    }
                    ?>
                    <?php if (!empty($details)) : ?><small><?php echo esc_html(implode(' · ', $details)); ?></small><?php endif; ?>
                </li>
            <?php endforeach; ?>
            <?php foreach ((array) ($groups['removed'] ?? array()) as $name) : ?>
                <li><span class="tsol-access-chip tsol-access-chip--removed"><?php esc_html_e('Removed', 'member-library'); ?></span> <?php echo esc_html($name); ?></li>
            <?php endforeach; ?>
            <?php foreach ((array) ($changes['assignments'] ?? array()) as $membership) : ?>
                <li><span class="tsol-access-chip tsol-access-chip--membership"><?php esc_html_e('Membership', 'member-library'); ?></span> <?php echo esc_html($membership['name']); ?>
                    <small>
                        <?php if (!empty($membership['added'])) : ?><?php echo esc_html(sprintf(__('gains %s', 'member-library'), implode(', ', $membership['added']))); ?><?php endif; ?>
                        <?php if (!empty($membership['added']) && !empty($membership['removed'])) : ?> · <?php endif; ?>
                        <?php if (!empty($membership['removed'])) : ?><?php echo esc_html(sprintf(__('loses %s', 'member-library'), implode(', ', $membership['removed']))); ?><?php endif; ?>
                    </small>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    private function group_editor($index, $group_id, $group, $definitions, $memberships, $state = 'draft') {
        $field_prefix = 'groups[' . $index . ']';
        $selected = array_map('strval', (array) ($group['scopes'] ?? array()));
        $state_labels = array(
            'live' => __('Live', 'member-library'),
            'changed' => __('Changed', 'member-library'),
            'new' => __('New', 'member-library'),
            'draft' => __('Not live yet', 'member-library'),
        );
        ?>
        <details class="tsol-access-group-card" data-access-group-card data-access-group-state="<?php echo esc_attr($state); ?>" <?php echo '' === $group_id ? 'open' : ''; ?>>
            <summary>
                <span class="tsol-access-group-card__title"><span class="tsol-access-chip tsol-access-chip--<?php echo esc_attr($state); ?>" data-access-group-badge><?php echo esc_html($state_labels[$state] ?? $state); ?></span><strong data-access-group-summary><?php echo esc_html((string) ($group['name'] ?: __('New group', 'member-library'))); ?></strong></span>
                <span><?php echo esc_html(sprintf(_n('%d membership', '%d memberships', count($memberships), 'member-library'), count($memberships))); ?></span>
            </summary>
            <div class="tsol-access-group-card__body">
                <input type="hidden" name="<?php echo esc_attr($field_prefix . '[id]'); ?>" value="<?php echo esc_attr($group_id); ?>">
                <input type="hidden" name="<?php echo esc_attr($field_prefix . '[remove]'); ?>" value="0" data-access-group-remove-value>
                <div class="tsol-access-group-card__identity">
                    <label><span><?php esc_html_e('Group name', 'member-library'); ?></span><input type="text" name="<?php echo esc_attr($field_prefix . '[name]'); ?>" value="<?php echo esc_attr((string) $group['name']); ?>" required data-access-group-name></label>
                    <label><span><?php esc_html_e('Note for administrators', 'member-library'); ?></span><textarea name="<?php echo esc_attr($field_prefix . '[description]'); ?>" rows="2"><?php echo esc_textarea((string) $group['description']); ?></textarea></label>
                </div>
                <fieldset>
                    <legend><?php esc_html_e('What this group unlocks', 'member-library'); ?></legend>
                    <p class="description"><?php esc_html_e('Collection and all-Series choices also cover future matching content.', 'member-library'); ?></p>
                    <div class="tsol-access-group-scopes">
                        <?php foreach (array('broad' => __('Broad access', 'member-library'), 'course' => __('Individual Courses', 'member-library'), 'series' => __('Individual Series', 'member-library')) as $section => $label) : ?>
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
                        <summary><?php echo esc_html(sprintf(_n('%d membership includes this group', '%d memberships include this group', count($memberships), 'member-library'), count($memberships))); ?></summary>
                        <p class="description"><?php esc_html_e('Change assignments from each MemberPress membership.', 'member-library'); ?></p>
                        <ul tabindex="0" aria-label="<?php echo esc_attr(sprintf(__('%s assigned memberships', 'member-library'), (string) $group['name'])); ?>">
                            <?php foreach ($memberships as $membership_id => $membership_name) : ?>
                                <li><a href="<?php echo esc_url(admin_url('post.php?post=' . (int) $membership_id . '&action=edit')); ?>"><?php echo esc_html($membership_name); ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
                <button type="button" class="button-link-delete" data-remove-access-group><?php esc_html_e('Remove this group from the draft', 'member-library'); ?></button>
            </div>
        </details>
        <?php
    }

    private function action_form($operation, $label, $class, $confirm = '') {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="tsol-access-action" <?php if ('' !== $confirm) : ?>data-access-confirm="<?php echo esc_attr($confirm); ?>"<?php endif; ?>>
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
