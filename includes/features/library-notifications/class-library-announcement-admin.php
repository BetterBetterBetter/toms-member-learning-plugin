<?php
/**
 * Guided, draft-only wp-admin experience for School announcements.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Announcement_Admin {

    const NONCE_ACTION = 'tsol_library_announcement_save';
    const NONCE_NAME = 'tsol_library_announcement_nonce';
    const AJAX_NONCE_ACTION = 'tsol_library_announcement_ajax';
    const PAYLOAD_NAME = 'tsol_announcement';
    const AJAX_PREVIEW = 'tsol_announcement_preview';
    const AJAX_USER_SEARCH = 'tsol_announcement_user_search';

    public function init() {
        add_filter('use_block_editor_for_post_type', array($this, 'use_block_editor'), 20, 2);
        add_filter('enter_title_here', array($this, 'title_placeholder'), 10, 2);
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'), 20, 2);
        add_action('save_post_' . TSOL_Library_Announcement_Model::POST_TYPE, array($this, 'save_post'), 20, 3);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_notices', array($this, 'render_notices'));
        add_action('wp_ajax_' . self::AJAX_PREVIEW, array($this, 'ajax_preview'));
        add_action('wp_ajax_' . self::AJAX_USER_SEARCH, array($this, 'ajax_user_search'));
        add_filter('manage_' . TSOL_Library_Announcement_Model::POST_TYPE . '_posts_columns', array($this, 'list_columns'));
        add_action('manage_' . TSOL_Library_Announcement_Model::POST_TYPE . '_posts_custom_column', array($this, 'render_list_column'), 10, 2);
        add_filter('post_row_actions', array($this, 'row_actions'), 10, 2);
        add_filter('bulk_actions-edit-' . TSOL_Library_Announcement_Model::POST_TYPE, array($this, 'bulk_actions'));
        add_filter('post_updated_messages', array($this, 'updated_messages'));
    }

    public function use_block_editor($use_block_editor, $post_type) {
        return TSOL_Library_Announcement_Model::POST_TYPE === $post_type ? false : $use_block_editor;
    }

    public function title_placeholder($placeholder, $post) {
        return $post instanceof WP_Post && TSOL_Library_Announcement_Model::POST_TYPE === $post->post_type
            ? __('Announcement subject', 'libertyclassroom-library')
            : $placeholder;
    }

    public function add_meta_boxes($post_type, $post) {
        if (TSOL_Library_Announcement_Model::POST_TYPE !== $post_type || !$post instanceof WP_Post) {
            return;
        }
        remove_meta_box('submitdiv', $post_type, 'side');
        remove_meta_box('slugdiv', $post_type, 'normal');
        remove_meta_box('authordiv', $post_type, 'normal');
        add_meta_box('tsol-announcement-message', __('1. Message', 'libertyclassroom-library'), array($this, 'render_message'), $post_type, 'normal', 'high');
        add_meta_box('tsol-announcement-destination', __('2. Destination', 'libertyclassroom-library'), array($this, 'render_destination'), $post_type, 'normal', 'high');
        add_meta_box('tsol-announcement-audience', __('3. Audience', 'libertyclassroom-library'), array($this, 'render_audience'), $post_type, 'normal', 'high');
        add_meta_box('tsol-announcement-save', __('Save announcement', 'libertyclassroom-library'), array($this, 'render_save'), $post_type, 'side', 'high');
        add_meta_box('tsol-announcement-delivery', __('4. Delivery', 'libertyclassroom-library'), array($this, 'render_delivery'), $post_type, 'side', 'default');
        add_meta_box('tsol-announcement-review', __('5. Review', 'libertyclassroom-library'), array($this, 'render_review'), $post_type, 'side', 'default');
        if (current_user_can(TSOL_Library_Announcement_Model::CAP_VIEW_DELIVERY)) {
            add_meta_box('tsol-announcement-audit', __('Editorial audit', 'libertyclassroom-library'), array($this, 'render_audit'), $post_type, 'normal', 'low');
        }
    }

    public function enqueue_assets() {
        $screen = get_current_screen();
        if (!$screen || TSOL_Library_Announcement_Model::POST_TYPE !== (string) $screen->post_type) {
            return;
        }
        wp_enqueue_style(
            'tsol-library-announcement-admin',
            TSOL_SITE_PLUGIN_URL . 'assets/features/library-notifications/announcement-admin.css',
            array(),
            TSOL_SITE_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'tsol-library-announcement-admin',
            TSOL_SITE_PLUGIN_URL . 'assets/features/library-notifications/announcement-admin.js',
            array(),
            TSOL_SITE_PLUGIN_VERSION,
            true
        );
        wp_localize_script('tsol-library-announcement-admin', 'tsolAnnouncementAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::AJAX_NONCE_ACTION),
            'previewEnabled' => TSOL_Library_Announcement_Flags::preview_enabled(),
            'strings' => array(
                'searching' => __('Searching…', 'libertyclassroom-library'),
                'noUsers' => __('No matching users found.', 'libertyclassroom-library'),
                'searchFailed' => __('User search is temporarily unavailable.', 'libertyclassroom-library'),
                'previewing' => __('Calculating the audience…', 'libertyclassroom-library'),
                'previewFailed' => __('The complete audience preview is temporarily unavailable.', 'libertyclassroom-library'),
                'saveBeforePreview' => __('Save the draft before previewing this changed audience.', 'libertyclassroom-library'),
                'removeUser' => __('Remove', 'libertyclassroom-library'),
                'membershipRequired' => __('Select at least one active membership.', 'libertyclassroom-library'),
                'specificUserRequired' => __('Select at least one WordPress user.', 'libertyclassroom-library'),
                'userLimit' => __('A maximum of 100 unique users can be selected across recipients and exclusions.', 'libertyclassroom-library'),
            ),
        ));
    }

    public function render_message($post) {
        $summary = (string) get_post_meta($post->ID, TSOL_Library_Announcement_Model::META_SUMMARY, true);
        ?>
        <div class="tsol-announcement-fields">
            <label class="tsol-announcement-field" for="tsol-announcement-summary">
                <span class="tsol-announcement-field__label"><?php esc_html_e('Summary', 'libertyclassroom-library'); ?></span>
                <textarea id="tsol-announcement-summary" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[summary]" rows="4" maxlength="500" required><?php echo esc_textarea($summary); ?></textarea>
                <span class="description"><?php esc_html_e('A concise plain-text preview shown in the notification list. Maximum 500 characters.', 'libertyclassroom-library'); ?></span>
            </label>
            <div class="tsol-announcement-field">
                <span class="tsol-announcement-field__label"><?php esc_html_e('Optional detail', 'libertyclassroom-library'); ?></span>
                <p class="description"><?php esc_html_e('Use simple headings, lists, emphasis, and quotes. Links, media, embeds, scripts, forms, and tracking markup are removed.', 'libertyclassroom-library'); ?></p>
                <?php wp_editor((string) $post->post_content, 'tsol_announcement_body', array(
                    'textarea_name' => 'content',
                    'textarea_rows' => 10,
                    'media_buttons' => false,
                    'teeny' => false,
                    'quicktags' => array('buttons' => 'strong,em,ul,ol,li,block'),
                    'tinymce' => array(
                        'toolbar1' => 'formatselect,bold,italic,bullist,numlist,blockquote,undo,redo',
                        'toolbar2' => '',
                        'block_formats' => 'Paragraph=p;Heading 2=h2;Heading 3=h3',
                    ),
                )); ?>
                <p class="description"><span data-announcement-body-count>0</span> / 5,000 <?php esc_html_e('characters', 'libertyclassroom-library'); ?></p>
            </div>
        </div>
        <?php
    }

    public function render_destination($post) {
        $selected = (int) get_post_meta($post->ID, TSOL_Library_Announcement_Model::META_DESTINATION_ID, true);
        $can_manage = current_user_can(TSOL_Library_Announcement_Model::CAP_MANAGE_AUDIENCE);
        $destinations = $this->destinations();
        $selected_destination = TSOL_Library_Announcement_Audience_Builder::destination($selected);
        $selected_unavailable = $selected > 0 && is_wp_error($selected_destination);
        ?>
        <p><?php esc_html_e('Choose where the notification opens. A protected destination automatically locks its live MemberPress access check into the audience.', 'libertyclassroom-library'); ?></p>
        <?php if ($can_manage) : ?>
            <label class="tsol-announcement-field" for="tsol-announcement-destination-select">
                <span class="tsol-announcement-field__label"><?php esc_html_e('Destination', 'libertyclassroom-library'); ?></span>
                <select id="tsol-announcement-destination-select" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[destination_id]">
                    <option value="0" <?php selected(0, $selected); ?>><?php esc_html_e('General announcement — Notifications page', 'libertyclassroom-library'); ?></option>
                    <?php if ($selected_unavailable) : ?>
                        <option value="<?php echo esc_attr($selected); ?>" selected data-unavailable-destination><?php esc_html_e('Unavailable destination — choose a replacement', 'libertyclassroom-library'); ?></option>
                    <?php endif; ?>
                    <?php foreach ($destinations as $type => $items) : ?>
                        <optgroup label="<?php echo esc_attr($type); ?>">
                            <?php foreach ($items as $item) : ?>
                                <option value="<?php echo esc_attr($item->ID); ?>" <?php selected((int) $item->ID, $selected); ?>><?php echo esc_html($item->post_title); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php if ($selected_unavailable) : ?>
                <div class="notice notice-error inline"><p><?php esc_html_e('The saved destination is no longer published or has lost its School identity. Choose a replacement before previewing this audience.', 'libertyclassroom-library'); ?></p></div>
            <?php endif; ?>
        <?php else : ?>
            <p><strong><?php echo esc_html($this->destination_label($selected)); ?></strong></p>
            <p class="description"><?php esc_html_e('An administrator will review the destination and audience before any future delivery.', 'libertyclassroom-library'); ?></p>
        <?php endif; ?>
        <div class="notice notice-info inline"><p><?php esc_html_e('Selecting a destination never grants access. WordPress and MemberPress remain the live authority.', 'libertyclassroom-library'); ?></p></div>
        <?php
    }

    public function render_audience($post) {
        $stored = $this->stored_audience($post->ID);
        $preset = (string) get_post_meta($post->ID, TSOL_Library_Announcement_Model::META_AUDIENCE_PRESET, true);
        $preset = isset(TSOL_Library_Announcement_Audience_Builder::presets()[$preset]) ? $preset : TSOL_Library_Announcement_Audience_Builder::PRESET_ALL_LINKED;
        $can_manage = current_user_can(TSOL_Library_Announcement_Model::CAP_MANAGE_AUDIENCE);
        if (!$can_manage) {
            $summary = (string) get_post_meta($post->ID, TSOL_Library_Announcement_Model::META_AUDIENCE_SUMMARY, true);
            ?>
            <div class="notice notice-info inline"><p><strong><?php esc_html_e('Audience review is administrator-only.', 'libertyclassroom-library'); ?></strong></p></div>
            <p><?php echo esc_html($summary !== '' ? $summary : __('Everyone signed in to the School (administrator review required)', 'libertyclassroom-library')); ?></p>
            <?php
            return;
        }

        $membership_ids = TSOL_Library_Announcement_Audience_Builder::ids_for_condition($stored, 'ACTIVE_MEMBERSHIP', 'membershipIds');
        $specific_ids = TSOL_Library_Announcement_Audience_Builder::ids_for_condition($stored, 'SPECIFIC_USERS', 'wordpressUserIds');
        $exclude_ids = TSOL_Library_Announcement_Audience_Builder::exclusion_ids($stored);
        $destination_id = (int) get_post_meta($post->ID, TSOL_Library_Announcement_Model::META_DESTINATION_ID, true);
        $destination = TSOL_Library_Announcement_Audience_Builder::destination($destination_id);
        $has_destination = !is_wp_error($destination) && 'general' !== $destination['type'];
        ?>
        <fieldset class="tsol-announcement-presets" data-announcement-presets>
            <legend class="screen-reader-text"><?php esc_html_e('Audience preset', 'libertyclassroom-library'); ?></legend>
            <?php foreach (TSOL_Library_Announcement_Audience_Builder::presets() as $value => $label) : ?>
                <?php $requires_destination = in_array($value, array(TSOL_Library_Announcement_Audience_Builder::PRESET_CONTENT_ACCESS, TSOL_Library_Announcement_Audience_Builder::PRESET_RELATIONSHIP), true); ?>
                <label class="tsol-announcement-preset">
                    <input type="radio" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[audience_preset]" value="<?php echo esc_attr($value); ?>" <?php checked($preset, $value); ?> <?php if ($requires_destination) : ?>data-requires-destination<?php disabled(!$has_destination); endif; ?>>
                    <span><strong><?php echo esc_html($label); ?></strong><small><?php echo esc_html($this->preset_authority($value)); ?></small></span>
                </label>
            <?php endforeach; ?>
        </fieldset>
        <p class="description" data-announcement-destination-guidance <?php if ($has_destination) : ?>hidden<?php endif; ?>><?php esc_html_e('Choose a published Course or Series destination to use content-access or enrollment/following audiences.', 'libertyclassroom-library'); ?></p>

        <div class="tsol-announcement-conditional" data-audience-fields="active_membership" <?php if (TSOL_Library_Announcement_Audience_Builder::PRESET_MEMBERSHIP !== $preset) : ?>hidden<?php endif; ?>>
            <h3><?php esc_html_e('Selected memberships', 'libertyclassroom-library'); ?></h3>
            <p class="description"><?php esc_html_e('This chooses a message audience; it does not edit the membership or its MemberPress rules.', 'libertyclassroom-library'); ?></p>
            <p class="description"><strong data-membership-count><?php echo esc_html(count($membership_ids)); ?></strong> <?php esc_html_e('/ 20 selected', 'libertyclassroom-library'); ?></p>
            <div class="tsol-announcement-checklist">
                <?php foreach ($this->memberships() as $membership) : ?>
                    <label><input type="checkbox" data-membership-option name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[membership_ids][]" value="<?php echo esc_attr($membership->ID); ?>" <?php checked(in_array((int) $membership->ID, $membership_ids, true)); ?>> <?php echo esc_html($membership->post_title); ?></label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="tsol-announcement-conditional" data-audience-fields="specific_users" <?php if (TSOL_Library_Announcement_Audience_Builder::PRESET_SPECIFIC_USERS !== $preset) : ?>hidden<?php endif; ?>>
            <?php $this->render_user_picker('specific_user_ids', __('Selected users', 'libertyclassroom-library'), $specific_ids); ?>
        </div>

        <details class="tsol-announcement-exclusions">
            <summary><?php esc_html_e('Exclude specific users', 'libertyclassroom-library'); ?></summary>
            <?php $this->render_user_picker('exclude_user_ids', __('Excluded users', 'libertyclassroom-library'), $exclude_ids); ?>
        </details>
        <div class="notice notice-warning inline"><p><?php esc_html_e('Audience choices select recipients only. Every protected destination is checked again against live MemberPress access.', 'libertyclassroom-library'); ?></p></div>
        <?php
    }

    public function render_delivery($post) {
        $expiry = (string) get_post_meta($post->ID, TSOL_Library_Announcement_Model::META_EXPIRY_GMT, true);
        $local_expiry = '';
        if ('' !== $expiry && false !== strtotime($expiry . ' UTC')) {
            $local_expiry = wp_date('Y-m-d\TH:i', strtotime($expiry . ' UTC'), wp_timezone());
        }
        ?>
        <p><label><input type="checkbox" checked disabled> <strong><?php esc_html_e('In-app notification', 'libertyclassroom-library'); ?></strong></label></p>
        <p class="description"><?php esc_html_e('Email is outside the approved scope.', 'libertyclassroom-library'); ?></p>
        <?php if (current_user_can(TSOL_Library_Announcement_Model::CAP_MANAGE_AUDIENCE)) : ?>
            <label class="tsol-announcement-field" for="tsol-announcement-expiry">
                <span class="tsol-announcement-field__label"><?php esc_html_e('Optional visibility expiry', 'libertyclassroom-library'); ?></span>
                <input id="tsol-announcement-expiry" type="datetime-local" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[expiry_local]" value="<?php echo esc_attr($local_expiry); ?>">
                <span class="description"><?php echo esc_html(sprintf(__('Displayed in %s. Expiry never changes content access.', 'libertyclassroom-library'), wp_timezone_string())); ?></span>
            </label>
        <?php else : ?>
            <p><strong><?php esc_html_e('Visibility expiry', 'libertyclassroom-library'); ?>:</strong> <?php echo esc_html($local_expiry !== '' ? $local_expiry : __('None', 'libertyclassroom-library')); ?></p>
            <p class="description"><?php esc_html_e('An administrator will configure visibility expiry.', 'libertyclassroom-library'); ?></p>
        <?php endif; ?>
        <div class="notice notice-warning inline"><p><strong><?php esc_html_e('Publishing and scheduling are disabled.', 'libertyclassroom-library'); ?></strong> <?php esc_html_e('This phase saves drafts only.', 'libertyclassroom-library'); ?></p></div>
        <?php
    }

    public function render_save($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?><input type="hidden" name="post_status" value="draft">
        <p><button type="submit" name="save" class="button button-primary button-large"><?php esc_html_e('Save draft', 'libertyclassroom-library'); ?></button></p>
        <p class="description"><?php esc_html_e('Saving cannot publish, schedule, fan out, email, or change access.', 'libertyclassroom-library'); ?></p>
        <?php if ('auto-draft' !== $post->post_status && current_user_can('delete_post', $post->ID)) : ?>
            <p><a class="submitdelete deletion" href="<?php echo esc_url(get_delete_post_link($post->ID)); ?>"><?php esc_html_e('Move to Trash', 'libertyclassroom-library'); ?></a></p>
        <?php endif;
    }

    public function render_review($post) {
        $summary = (string) get_post_meta($post->ID, TSOL_Library_Announcement_Model::META_AUDIENCE_SUMMARY, true);
        $hash = (string) get_post_meta($post->ID, TSOL_Library_Announcement_Model::META_AUDIENCE_HASH, true);
        $preview = get_post_meta($post->ID, TSOL_Library_Announcement_Model::META_PREVIEW, true);
        $preview = is_array($preview) ? $preview : array();
        $preview_current = $hash !== '' && hash_equals($hash, (string) ($preview['definitionHash'] ?? ''));
        $can_view_delivery = current_user_can(TSOL_Library_Announcement_Model::CAP_VIEW_DELIVERY);
        $destination = TSOL_Library_Announcement_Audience_Builder::destination((int) get_post_meta($post->ID, TSOL_Library_Announcement_Model::META_DESTINATION_ID, true));
        $destination_current = !is_wp_error($destination);
        ?>
        <dl class="tsol-announcement-review-list">
            <div><dt><?php esc_html_e('Destination', 'libertyclassroom-library'); ?></dt><dd><?php echo esc_html($this->destination_label((int) get_post_meta($post->ID, TSOL_Library_Announcement_Model::META_DESTINATION_ID, true))); ?></dd></div>
            <div><dt><?php esc_html_e('Audience', 'libertyclassroom-library'); ?></dt><dd><?php echo esc_html($summary !== '' ? $summary : __('Save the draft to validate its audience.', 'libertyclassroom-library')); ?></dd></div>
            <div><dt><?php esc_html_e('Definition', 'libertyclassroom-library'); ?></dt><dd><code><?php echo esc_html($hash !== '' ? substr($hash, 0, 12) . '…' : '—'); ?></code></dd></div>
        </dl>
        <div data-announcement-preview-result aria-live="polite">
            <?php if ($preview_current && $can_view_delivery) : ?>
                <?php $this->render_preview_counts($preview); ?>
            <?php elseif (!$can_view_delivery) : ?>
                <p class="description"><?php esc_html_e('An administrator will complete the aggregate audience review.', 'libertyclassroom-library'); ?></p>
            <?php else : ?>
                <p class="description"><?php esc_html_e('No current complete preview.', 'libertyclassroom-library'); ?></p>
            <?php endif; ?>
        </div>
        <?php if (current_user_can(TSOL_Library_Announcement_Model::CAP_MANAGE_AUDIENCE)) : ?>
            <p><button type="button" class="button" data-announcement-preview data-post-id="<?php echo esc_attr($post->ID); ?>" <?php disabled(!TSOL_Library_Announcement_Flags::preview_enabled() || $hash === '' || 'auto-draft' === $post->post_status || !$destination_current); ?>><?php esc_html_e('Preview audience', 'libertyclassroom-library'); ?></button></p>
            <p class="description" data-announcement-preview-guidance><?php esc_html_e('Save any destination or audience changes before previewing.', 'libertyclassroom-library'); ?></p>
        <?php endif; ?>
        <p><button type="button" class="button" disabled><?php esc_html_e('Send test to me', 'libertyclassroom-library'); ?></button></p>
        <p class="description"><?php esc_html_e('Self-test delivery becomes available only after private School notification persistence is reviewed and enabled.', 'libertyclassroom-library'); ?></p>
        <?php
    }

    public function render_audit($post) {
        $entries = array_reverse(array_slice(TSOL_Library_Announcement_Audit::entries($post->ID), -20));
        if (empty($entries)) {
            echo '<p class="description">' . esc_html__('No editorial audit events have been recorded.', 'libertyclassroom-library') . '</p>';
            return;
        }
        echo '<table class="widefat striped tsol-announcement-audit"><thead><tr><th>' . esc_html__('Time (UTC)', 'libertyclassroom-library') . '</th><th>' . esc_html__('Event', 'libertyclassroom-library') . '</th><th>' . esc_html__('Actor ID', 'libertyclassroom-library') . '</th></tr></thead><tbody>';
        foreach ($entries as $entry) {
            echo '<tr><td>' . esc_html((string) ($entry['occurredAt'] ?? '')) . '</td><td><code>' . esc_html((string) ($entry['event'] ?? '')) . '</code></td><td>' . esc_html((string) absint($entry['actorId'] ?? 0)) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    public function save_post($post_id, $post, $update) {
        if (!$post instanceof WP_Post || !isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return;
        }
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id) || !current_user_can(TSOL_Library_Announcement_Model::CAP_EDIT)) {
            return;
        }
        $payload = isset($_POST[self::PAYLOAD_NAME]) && is_array($_POST[self::PAYLOAD_NAME]) ? wp_unslash($_POST[self::PAYLOAD_NAME]) : array();
        $summary = sanitize_textarea_field((string) ($payload['summary'] ?? ''));
        if (TSOL_Library_Announcement_Model::text_length($summary) > TSOL_Library_Announcement_Model::MAX_SUMMARY_LENGTH) {
            $summary = TSOL_Library_Announcement_Model::text_slice($summary, TSOL_Library_Announcement_Model::MAX_SUMMARY_LENGTH);
            TSOL_Library_Announcement_Model::queue_notice('error', __('The summary was limited to 500 characters.', 'libertyclassroom-library'));
        }
        update_post_meta($post_id, TSOL_Library_Announcement_Model::META_SUMMARY, $summary);
        update_post_meta($post_id, TSOL_Library_Announcement_Model::META_UPDATED_BY, get_current_user_id());
        if (current_user_can(TSOL_Library_Announcement_Model::CAP_MANAGE_AUDIENCE)) {
            $this->save_expiry($post_id, (string) ($payload['expiry_local'] ?? ''));
        }

        $old_hash = (string) get_post_meta($post_id, TSOL_Library_Announcement_Model::META_AUDIENCE_HASH, true);
        if (current_user_can(TSOL_Library_Announcement_Model::CAP_MANAGE_AUDIENCE)) {
            $built = TSOL_Library_Announcement_Audience_Builder::build($payload);
            if (is_wp_error($built)) {
                TSOL_Library_Announcement_Model::queue_notice('error', $built->get_error_message());
            } else {
                $this->store_built_audience($post_id, $built);
            }
        } elseif ($old_hash === '') {
            $built = TSOL_Library_Announcement_Audience_Builder::default_build();
            if (!is_wp_error($built)) {
                $this->store_built_audience($post_id, $built);
            }
        }
        $new_hash = (string) get_post_meta($post_id, TSOL_Library_Announcement_Model::META_AUDIENCE_HASH, true);
        $event = empty(TSOL_Library_Announcement_Audit::entries($post_id)) ? 'draft_created' : 'draft_updated';
        TSOL_Library_Announcement_Audit::record($post_id, $event, array('definitionHash' => $new_hash));
        if ($old_hash !== '' && $new_hash !== $old_hash) {
            delete_post_meta($post_id, TSOL_Library_Announcement_Model::META_PREVIEW);
            TSOL_Library_Announcement_Audit::record($post_id, 'audience_changed', array('definitionHash' => $new_hash));
        }
    }

    public function ajax_preview() {
        check_ajax_referer(self::AJAX_NONCE_ACTION, 'nonce');
        if (!TSOL_Library_Announcement_Flags::preview_enabled() || !current_user_can(TSOL_Library_Announcement_Model::CAP_MANAGE_AUDIENCE)) {
            wp_send_json_error(array('message' => __('Audience preview is unavailable.', 'libertyclassroom-library')), 403);
        }
        $post_id = isset($_POST['postId']) ? absint($_POST['postId']) : 0;
        if ($post_id <= 0 || TSOL_Library_Announcement_Model::POST_TYPE !== get_post_type($post_id) || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(array('message' => __('The announcement could not be previewed.', 'libertyclassroom-library')), 403);
        }
        if (!$this->consume_rate_limit('preview', 10)) {
            wp_send_json_error(array('message' => __('Please wait before requesting another preview.', 'libertyclassroom-library')), 429);
        }
        $definition = $this->stored_audience($post_id, false);
        $stored_hash = (string) get_post_meta($post_id, TSOL_Library_Announcement_Model::META_AUDIENCE_HASH, true);
        $definition_hash = is_wp_error($definition) ? $definition : TSOL_Library_Announcement_Audience_Contract::hash($definition);
        $destination = TSOL_Library_Announcement_Audience_Builder::destination((int) get_post_meta($post_id, TSOL_Library_Announcement_Model::META_DESTINATION_ID, true));
        if (is_wp_error($definition) || is_wp_error($definition_hash) || $stored_hash === '' || !hash_equals($stored_hash, $definition_hash) || is_wp_error($destination) || !$this->definition_matches_destination($definition, $destination)) {
            TSOL_Library_Announcement_Audit::record($post_id, 'preview_failed', array('errorCode' => 'announcement_preview_stale'));
            wp_send_json_error(array('message' => __('Save a valid current destination and audience before previewing.', 'libertyclassroom-library')), 409);
        }
        $result = TSOL_Library_Announcement_Preview::run($definition);
        if (is_wp_error($result)) {
            TSOL_Library_Announcement_Audit::record($post_id, 'preview_failed', array('errorCode' => $result->get_error_code()));
            wp_send_json_error(array('message' => __('The complete audience preview is temporarily unavailable. No partial counts were saved.', 'libertyclassroom-library')), 503);
        }
        update_post_meta($post_id, TSOL_Library_Announcement_Model::META_PREVIEW, $result);
        TSOL_Library_Announcement_Audit::record($post_id, 'preview_completed', array(
            'definitionHash' => $result['definitionHash'],
            'eligible' => $result['counts']['eligible'],
        ));
        ob_start();
        $this->render_preview_counts($result);
        wp_send_json_success(array('html' => ob_get_clean()));
    }

    public function ajax_user_search() {
        check_ajax_referer(self::AJAX_NONCE_ACTION, 'nonce');
        if (!current_user_can(TSOL_Library_Announcement_Model::CAP_MANAGE_AUDIENCE)) {
            wp_send_json_error(array('message' => __('User search is unavailable.', 'libertyclassroom-library')), 403);
        }
        $term = isset($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        if (TSOL_Library_Announcement_Model::text_length($term) < 3 || !$this->consume_rate_limit('user_search', 30)) {
            wp_send_json_error(array('message' => __('Enter at least three characters.', 'libertyclassroom-library')), 400);
        }
        $users = get_users(array(
            'number' => 20,
            'search' => '*' . $term . '*',
            'search_columns' => array('user_login', 'user_nicename', 'user_email', 'display_name'),
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => array('ID', 'display_name', 'user_email'),
        ));
        $results = array_map(static function ($user) {
            return array(
                'id' => (int) $user->ID,
                'label' => sanitize_text_field($user->display_name . ' — ' . $user->user_email),
            );
        }, $users);
        wp_send_json_success(array('users' => $results));
    }

    public function render_notices() {
        $screen = get_current_screen();
        if (!$screen || TSOL_Library_Announcement_Model::POST_TYPE !== (string) $screen->post_type) {
            return;
        }
        foreach (TSOL_Library_Announcement_Model::pull_notices() as $notice) {
            printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr($notice['type']), esc_html($notice['message']));
        }
        echo '<div class="notice notice-info"><p><strong>' . esc_html__('Announcement authoring is in draft-only review.', 'libertyclassroom-library') . '</strong> ' . esc_html__('Nothing on this screen can publish, schedule, email, fan out, or change a member’s access.', 'libertyclassroom-library') . '</p></div>';
    }

    public function list_columns($columns) {
        $result = array(
            'cb' => $columns['cb'] ?? '<input type="checkbox">',
            'title' => __('Subject', 'libertyclassroom-library'),
            'tsol_announcement_state' => __('State', 'libertyclassroom-library'),
            'tsol_announcement_destination' => __('Destination', 'libertyclassroom-library'),
            'tsol_announcement_audience' => __('Audience', 'libertyclassroom-library'),
            'author' => __('Author', 'libertyclassroom-library'),
            'date' => __('Updated', 'libertyclassroom-library'),
        );
        if (current_user_can(TSOL_Library_Announcement_Model::CAP_VIEW_DELIVERY)) {
            $result = array_slice($result, 0, 5, true) + array(
                'tsol_announcement_schedule' => __('Scheduled / sent', 'libertyclassroom-library'),
                'tsol_announcement_recipients' => __('Recipients', 'libertyclassroom-library'),
                'tsol_announcement_health' => __('Delivery health', 'libertyclassroom-library'),
            ) + array_slice($result, 5, null, true);
        }
        return $result;
    }

    public function render_list_column($column, $post_id) {
        $preview = get_post_meta($post_id, TSOL_Library_Announcement_Model::META_PREVIEW, true);
        $preview = is_array($preview) ? $preview : array();
        $current_hash = (string) get_post_meta($post_id, TSOL_Library_Announcement_Model::META_AUDIENCE_HASH, true);
        $preview_current = $current_hash !== '' && hash_equals($current_hash, (string) ($preview['definitionHash'] ?? ''));
        switch ($column) {
            case 'tsol_announcement_state':
                echo '<span class="tsol-announcement-badge">' . esc_html__('Draft only', 'libertyclassroom-library') . '</span>';
                break;
            case 'tsol_announcement_destination':
                echo esc_html($this->destination_label((int) get_post_meta($post_id, TSOL_Library_Announcement_Model::META_DESTINATION_ID, true)));
                break;
            case 'tsol_announcement_audience':
                echo esc_html((string) get_post_meta($post_id, TSOL_Library_Announcement_Model::META_AUDIENCE_SUMMARY, true));
                break;
            case 'tsol_announcement_schedule':
                echo '—';
                break;
            case 'tsol_announcement_recipients':
                echo $preview_current ? esc_html(number_format_i18n((int) $preview['counts']['eligible'])) : '—';
                break;
            case 'tsol_announcement_health':
                echo esc_html__('Not sent', 'libertyclassroom-library');
                break;
        }
    }

    public function row_actions($actions, $post) {
        if ($post instanceof WP_Post && TSOL_Library_Announcement_Model::POST_TYPE === $post->post_type) {
            unset($actions['view'], $actions['inline hide-if-no-js']);
        }
        return $actions;
    }

    public function bulk_actions($actions) {
        unset($actions['edit']);
        return $actions;
    }

    public function updated_messages($messages) {
        $messages[TSOL_Library_Announcement_Model::POST_TYPE] = array_fill(0, 11, __('Announcement draft saved. Nothing was sent.', 'libertyclassroom-library'));
        return $messages;
    }

    private function render_user_picker($field, $label, $ids) {
        ?>
        <div class="tsol-announcement-user-picker" data-user-picker data-field="<?php echo esc_attr($field); ?>">
            <h3><?php echo esc_html($label); ?></h3>
            <p class="description"><strong data-user-count><?php echo esc_html(count($ids)); ?></strong> <?php esc_html_e('selected; 100 unique users maximum across recipients and exclusions.', 'libertyclassroom-library'); ?></p>
            <label class="screen-reader-text" for="tsol-<?php echo esc_attr($field); ?>-search"><?php echo esc_html(sprintf(__('Search for %s', 'libertyclassroom-library'), strtolower($label))); ?></label>
            <div class="tsol-announcement-user-search">
                <input id="tsol-<?php echo esc_attr($field); ?>-search" type="search" placeholder="<?php esc_attr_e('Search name or email (3+ characters)', 'libertyclassroom-library'); ?>" autocomplete="off" data-user-search>
                <div class="tsol-announcement-user-results" data-user-results hidden></div>
            </div>
            <div class="tsol-announcement-user-chips" data-user-chips>
                <?php foreach ($this->users_by_id($ids) as $user) : ?>
                    <?php $this->render_user_chip($field, $user->ID, $user->display_name . ' — ' . $user->user_email); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    private function render_user_chip($field, $user_id, $label) {
        ?><span class="tsol-announcement-user-chip" data-user-id="<?php echo esc_attr($user_id); ?>"><span><?php echo esc_html($label); ?></span><input type="hidden" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[<?php echo esc_attr($field); ?>][]" value="<?php echo esc_attr($user_id); ?>"><button type="button" class="button-link" data-remove-user aria-label="<?php echo esc_attr(sprintf(__('Remove %s', 'libertyclassroom-library'), $label)); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></span><?php
    }

    private function store_built_audience($post_id, $built) {
        update_post_meta($post_id, TSOL_Library_Announcement_Model::META_AUDIENCE_PRESET, $built['preset']);
        update_post_meta($post_id, TSOL_Library_Announcement_Model::META_AUDIENCE, wp_json_encode($built['definition'], JSON_UNESCAPED_SLASHES));
        update_post_meta($post_id, TSOL_Library_Announcement_Model::META_AUDIENCE_HASH, $built['hash']);
        update_post_meta($post_id, TSOL_Library_Announcement_Model::META_AUDIENCE_SUMMARY, $built['summary']);
        update_post_meta($post_id, TSOL_Library_Announcement_Model::META_DESTINATION_TYPE, $built['destination']['type']);
        update_post_meta($post_id, TSOL_Library_Announcement_Model::META_DESTINATION_ID, $built['destination']['id']);
        update_post_meta($post_id, TSOL_Library_Announcement_Model::META_DESTINATION_UUID, $built['destination']['uuid']);
    }

    private function save_expiry($post_id, $value) {
        $value = trim($value);
        if ('' === $value) {
            delete_post_meta($post_id, TSOL_Library_Announcement_Model::META_EXPIRY_GMT);
            return;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, wp_timezone());
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->getTimestamp() <= time()) {
            TSOL_Library_Announcement_Model::queue_notice('error', __('Choose a valid future expiry in the WordPress site timezone.', 'libertyclassroom-library'));
            return;
        }
        update_post_meta($post_id, TSOL_Library_Announcement_Model::META_EXPIRY_GMT, $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    private function stored_audience($post_id, $allow_default = true) {
        $value = json_decode((string) get_post_meta($post_id, TSOL_Library_Announcement_Model::META_AUDIENCE, true), true);
        $normalized = TSOL_Library_Announcement_Audience_Contract::normalize($value);
        if (!is_wp_error($normalized)) {
            return $normalized;
        }
        if (!$allow_default) {
            return new WP_Error('announcement_audience_stale');
        }
        $default = TSOL_Library_Announcement_Audience_Builder::default_build();
        return is_wp_error($default) ? array() : $default['definition'];
    }

    private function definition_matches_destination($definition, $destination) {
        if ('general' === $destination['type']) {
            foreach ((array) ($definition['groups'] ?? array()) as $group) {
                foreach ((array) ($group['all'] ?? array()) as $condition) {
                    if (in_array(($condition['type'] ?? ''), array('CAN_ACCESS_CONTENT', 'ACTIVE_RELATIONSHIP'), true)) {
                        return false;
                    }
                }
            }
            return true;
        }
        $groups = (array) ($definition['groups'] ?? array());
        if (empty($groups)) {
            return false;
        }
        foreach ($groups as $group) {
            $matches = false;
            foreach ((array) ($group['all'] ?? array()) as $condition) {
                if ('CAN_ACCESS_CONTENT' === ($condition['type'] ?? '') && $destination['uuid'] === ($condition['contentUuid'] ?? '')) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches) {
                return false;
            }
        }
        return true;
    }

    private function render_preview_counts($preview) {
        $counts = is_array($preview['counts'] ?? null) ? $preview['counts'] : array();
        ?>
        <div class="tsol-announcement-preview-summary">
            <p><strong><?php echo esc_html(number_format_i18n((int) ($counts['eligible'] ?? 0))); ?></strong> <?php esc_html_e('linked School accounts currently eligible', 'libertyclassroom-library'); ?></p>
            <ul>
                <li><?php echo esc_html(sprintf(__('%s WordPress candidates', 'libertyclassroom-library'), number_format_i18n((int) ($counts['wordpressCandidates'] ?? 0)))); ?></li>
                <li><?php echo esc_html(sprintf(__('%s unlinked', 'libertyclassroom-library'), number_format_i18n((int) ($counts['unlinked'] ?? 0)))); ?></li>
                <li><?php echo esc_html(sprintf(__('%s excluded', 'libertyclassroom-library'), number_format_i18n((int) ($counts['excluded'] ?? 0)))); ?></li>
                <li><?php echo esc_html(sprintf(__('%s relationship preference suppressed', 'libertyclassroom-library'), number_format_i18n((int) ($counts['relationshipSuppressed'] ?? 0)))); ?></li>
                <li><?php echo esc_html(sprintf(__('%s eligible administrators', 'libertyclassroom-library'), number_format_i18n((int) ($counts['eligibleAdministrators'] ?? 0)))); ?></li>
            </ul>
            <p class="description"><?php esc_html_e('Estimate only. Live access and School relationships will be checked again at any future dispatch.', 'libertyclassroom-library'); ?></p>
        </div>
        <?php
    }

    private function destinations() {
        $posts = get_posts(array(
            'post_type' => array(TSOL_Library_Content_Model::COURSE_POST_TYPE, TSOL_Library_Content_Model::SERIES_POST_TYPE),
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ));
        $result = array(__('Courses', 'libertyclassroom-library') => array(), __('Series', 'libertyclassroom-library') => array());
        foreach ($posts as $post) {
            $label = TSOL_Library_Content_Model::COURSE_POST_TYPE === $post->post_type ? __('Courses', 'libertyclassroom-library') : __('Series', 'libertyclassroom-library');
            $result[$label][] = $post;
        }
        return $result;
    }

    private function memberships() {
        return get_posts(array(
            'post_type' => 'memberpressproduct',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ));
    }

    private function users_by_id($ids) {
        if (empty($ids)) {
            return array();
        }
        return get_users(array('include' => array_map('intval', $ids), 'orderby' => 'include', 'fields' => array('ID', 'display_name', 'user_email')));
    }

    private function destination_label($post_id) {
        $destination = TSOL_Library_Announcement_Audience_Builder::destination($post_id);
        return is_wp_error($destination) ? __('Unavailable destination', 'libertyclassroom-library') : (string) $destination['title'];
    }

    private function preset_authority($preset) {
        if (TSOL_Library_Announcement_Audience_Builder::PRESET_RELATIONSHIP === $preset) {
            return __('School activity + MemberPress access', 'libertyclassroom-library');
        }
        if (TSOL_Library_Announcement_Audience_Builder::PRESET_MEMBERSHIP === $preset || TSOL_Library_Announcement_Audience_Builder::PRESET_CONTENT_ACCESS === $preset) {
            return __('MemberPress / WordPress authority', 'libertyclassroom-library');
        }
        return __('WordPress identity', 'libertyclassroom-library');
    }

    private function consume_rate_limit($scope, $limit) {
        $key = 'tsol_announcement_' . sanitize_key($scope) . '_' . get_current_user_id();
        $value = get_transient($key);
        $count = is_array($value) ? (int) ($value['count'] ?? 0) : 0;
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, array('count' => $count + 1), MINUTE_IN_SECONDS);
        return true;
    }
}
