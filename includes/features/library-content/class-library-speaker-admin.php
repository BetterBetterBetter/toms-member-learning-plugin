<?php
/**
 * Native post editor enhancements for Library Speaker profiles.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Speaker_Admin {

    const NONCE_ACTION = 'tsol_library_speaker_profile';
    const NONCE_NAME = 'tsol_library_speaker_nonce';
    const PAYLOAD_NAME = 'tsol_speaker_profile';
    const NOTICE_PREFIX = 'tsol_library_speaker_notice_';
    const IMAGE_COLUMN = 'tsol-speaker-headshot';
    const ROLE_COLUMN = 'tsol-speaker-role';
    const CONTENT_COLUMN = 'tsol-speaker-content';

    private $content_count_cache = null;

    public function init() {
        $post_type = MemberLibrary_Content_Model::SPEAKER_POST_TYPE;
        add_action('add_meta_boxes_' . $post_type, array($this, 'add_meta_boxes'));
        add_action('save_post_' . $post_type, array($this, 'save_post'), 30, 3);
        add_action('edit_form_after_title', array($this, 'render_about_heading'));
        add_action('post_submitbox_misc_actions', array($this, 'render_publication_guidance'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_notices', array($this, 'render_admin_notice'));
        add_action('load-post.php', array($this, 'isolate_private_editor_integrations'), 99);
        add_action('load-post-new.php', array($this, 'isolate_private_editor_integrations'), 99);
        add_filter('admin_post_thumbnail_html', array($this, 'filter_thumbnail_html'), 10, 3);
        add_filter('enter_title_here', array($this, 'filter_title_placeholder'), 10, 2);
        add_filter('manage_edit-' . $post_type . '_columns', array($this, 'filter_columns'));
        add_action('manage_' . $post_type . '_posts_custom_column', array($this, 'render_column'), 10, 2);
        add_filter('default_hidden_columns', array($this, 'default_hidden_columns'), 10, 2);
    }

    public function add_meta_boxes($post) {
        remove_meta_box('leadbox-select', MemberLibrary_Content_Model::SPEAKER_POST_TYPE, 'side');
        remove_meta_box('postexcerpt', MemberLibrary_Content_Model::SPEAKER_POST_TYPE, 'normal');
        add_meta_box(
            'tsol-library-speaker-details',
            __('Speaker details', 'member-library'),
            array($this, 'render_details_meta_box'),
            MemberLibrary_Content_Model::SPEAKER_POST_TYPE,
            'normal',
            'high'
        );
    }

    /**
     * Speaker profiles have no WordPress frontend, so page-popup integrations
     * are inapplicable. Keeping their generic TinyMCE hooks off this one screen
     * also avoids remote LeadPages response warnings inside the editor.
     */
    public function isolate_private_editor_integrations() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || MemberLibrary_Content_Model::SPEAKER_POST_TYPE !== (string) $screen->post_type) {
            return;
        }
        foreach (array('admin_head-post.php', 'admin_head-post-new.php', 'mce_external_plugins', 'mce_buttons') as $hook_name) {
            $this->remove_object_callbacks($hook_name, 'LeadpagesWP\\Admin\\TinyMCE\\LeadboxTinyMCE');
        }
    }

    public function render_about_heading($post) {
        if (!$post instanceof WP_Post || MemberLibrary_Content_Model::SPEAKER_POST_TYPE !== $post->post_type) {
            return;
        }
        ?>
        <div class="tsol-speaker-about-heading">
            <h2><?php esc_html_e('About', 'member-library'); ?></h2>
            <p><?php esc_html_e('Use the WordPress editor below for the speaker’s public biography.', 'member-library'); ?></p>
        </div>
        <?php
    }

    public function render_publication_guidance($post) {
        if (!$post instanceof WP_Post || MemberLibrary_Content_Model::SPEAKER_POST_TYPE !== $post->post_type) {
            return;
        }
        ?>
        <div class="misc-pub-section tsol-speaker-catalogue-publication">
            <?php esc_html_e('Publishing makes this speaker available in the Library catalogue.', 'member-library'); ?>
        </div>
        <?php
    }

    public function filter_title_placeholder($title, $post) {
        if ($post instanceof WP_Post && MemberLibrary_Content_Model::SPEAKER_POST_TYPE === $post->post_type) {
            return __('Full name', 'member-library');
        }
        return $title;
    }

    public function render_details_meta_box($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $job_title = (string) get_post_meta($post->ID, MemberLibrary_Content_Model::SPEAKER_META_JOB_TITLE, true);
        $organization = (string) get_post_meta($post->ID, MemberLibrary_Content_Model::SPEAKER_META_ORGANIZATION, true);
        $website_url = (string) get_post_meta($post->ID, MemberLibrary_Content_Model::SPEAKER_META_WEBSITE_URL, true);
        $social_links = get_post_meta($post->ID, MemberLibrary_Content_Model::SPEAKER_META_SOCIAL_LINKS, true);
        if (!is_array($social_links) || empty($social_links)) {
            $social_links = array(array('platform' => 'linkedin', 'url' => ''));
        }
        ?>
        <div class="tsol-speaker-profile-editor">
            <label class="tsol-speaker-profile-field tsol-speaker-profile-field--first">
                <span><?php esc_html_e('Short bio', 'member-library'); ?></span>
                <textarea id="excerpt" name="excerpt" rows="4" aria-label="<?php esc_attr_e('Short bio', 'member-library'); ?>"><?php echo esc_textarea((string) $post->post_excerpt); ?></textarea>
                <small class="description"><?php esc_html_e('Optional. Write two or three plain-text sentences for course instructor sections. If left blank, the Library creates a shortened summary from About.', 'member-library'); ?></small>
            </label>
            <div class="tsol-speaker-profile-grid">
                <label>
                    <span><?php esc_html_e('Job title', 'member-library'); ?></span>
                    <input type="text" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[job_title]" value="<?php echo esc_attr($job_title); ?>" />
                </label>
                <label>
                    <span><?php esc_html_e('Organisation / company', 'member-library'); ?></span>
                    <input type="text" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[organization]" value="<?php echo esc_attr($organization); ?>" />
                </label>
            </div>
            <label class="tsol-speaker-profile-field">
                <span><?php esc_html_e('Website', 'member-library'); ?></span>
                <input type="url" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[website_url]" value="<?php echo esc_attr($website_url); ?>" placeholder="https://" />
            </label>
            <div class="tsol-speaker-profile-field">
                <strong><?php esc_html_e('Social links', 'member-library'); ?></strong>
                <div class="tsol-speaker-social-editor" data-speaker-social-editor>
                    <div class="tsol-speaker-social-rows" data-speaker-social-rows>
                        <?php foreach (array_values($social_links) as $index => $link) : ?>
                            <?php $this->render_social_row($index, $link); ?>
                        <?php endforeach; ?>
                    </div>
                    <p><button type="button" class="button" data-speaker-social-add><?php esc_html_e('Add social link', 'member-library'); ?></button></p>
                    <script type="text/html" data-speaker-social-template><?php $this->render_social_row('__index__', array('platform' => 'linkedin', 'url' => '')); ?></script>
                </div>
            </div>
        </div>
        <?php
    }

    public function save_post($post_id, $post, $update) {
        unset($update);
        if (!$post instanceof WP_Post
            || MemberLibrary_Content_Model::SPEAKER_POST_TYPE !== $post->post_type
            || wp_is_post_autosave($post_id)
            || wp_is_post_revision($post_id)
            || !isset($_POST[self::NONCE_NAME])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
            || !current_user_can('edit_post', $post_id)
        ) {
            return;
        }

        $payload = isset($_POST[self::PAYLOAD_NAME]) && is_array($_POST[self::PAYLOAD_NAME])
            ? wp_unslash($_POST[self::PAYLOAD_NAME])
            : array();
        $errors = array();
        $job_title = sanitize_text_field((string) ($payload['job_title'] ?? ''));
        $organization = sanitize_text_field((string) ($payload['organization'] ?? ''));
        $requested_website = trim((string) ($payload['website_url'] ?? ''));
        $website_url = MemberLibrary_Content_Model::sanitize_speaker_url($requested_website);
        if ('' !== $requested_website && '' === $website_url) {
            $errors[] = __('The speaker website must be a valid HTTP or HTTPS URL.', 'member-library');
        }

        $requested_links = isset($payload['social_links']) && is_array($payload['social_links'])
            ? array_values($payload['social_links'])
            : array();
        foreach ($requested_links as $index => $row) {
            $requested_url = is_array($row) ? trim((string) ($row['url'] ?? '')) : '';
            if ('' !== $requested_url && '' === MemberLibrary_Content_Model::sanitize_speaker_url($requested_url)) {
                $errors[] = sprintf(__('Social link %d must be a valid HTTP or HTTPS URL.', 'member-library'), $index + 1);
            }
        }
        $social_links = MemberLibrary_Content_Model::sanitize_speaker_social_links($requested_links);

        $uuid = (string) get_post_meta($post_id, MemberLibrary_Content_Model::SPEAKER_META_UUID, true);
        if ('' === $uuid) {
            update_post_meta($post_id, MemberLibrary_Content_Model::SPEAKER_META_UUID, wp_generate_uuid4());
        }
        $this->store_or_delete_meta($post_id, MemberLibrary_Content_Model::SPEAKER_META_JOB_TITLE, $job_title);
        $this->store_or_delete_meta($post_id, MemberLibrary_Content_Model::SPEAKER_META_ORGANIZATION, $organization);
        $this->store_or_delete_meta($post_id, MemberLibrary_Content_Model::SPEAKER_META_WEBSITE_URL, $website_url);
        $this->store_or_delete_meta($post_id, MemberLibrary_Content_Model::SPEAKER_META_SOCIAL_LINKS, $social_links);

        $thumbnail_id = (int) get_post_thumbnail_id($post_id);
        if ($thumbnail_id > 0 && !MemberLibrary_Content_Model::ensure_speaker_image_size($thumbnail_id)) {
            $errors[] = __('WordPress could not create the square headshot rendition. The original image remains unchanged.', 'member-library');
        }
        if (!empty($errors)) {
            set_transient($this->notice_key($post_id), array_values(array_unique($errors)), 5 * MINUTE_IN_SECONDS);
        }
    }

    public function filter_thumbnail_html($content, $post_id, $thumbnail_id) {
        unset($thumbnail_id);
        if (MemberLibrary_Content_Model::SPEAKER_POST_TYPE !== get_post_type((int) $post_id)) {
            return $content;
        }
        $content .= '<p class="description">' . esc_html__('Choose or upload an image, then position the required square crop. WordPress keeps the original image.', 'member-library') . '</p>';
        return $content;
    }

    public function enqueue_assets($hook) {
        if (!in_array((string) $hook, array('edit.php', 'post.php', 'post-new.php'), true)) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || MemberLibrary_Content_Model::SPEAKER_POST_TYPE !== (string) $screen->post_type) {
            return;
        }
        wp_enqueue_style('tsol-library-speaker-admin', MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-speaker-admin.css', array(), MEMBER_LIBRARY_PLUGIN_VERSION);
        if ('edit.php' === (string) $hook) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_script('tsol-library-speaker-admin', MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-speaker-admin.js', array('jquery', 'media-editor'), MEMBER_LIBRARY_PLUGIN_VERSION, true);
        wp_localize_script('tsol-library-speaker-admin', 'tsolLibrarySpeakerAdmin', array(
            'cropSize' => 640,
            'strings' => array(
                'frameTitle' => __('Select and crop headshot', 'member-library'),
                'selectAndCrop' => __('Select and crop', 'member-library'),
                'shortBioCountTemplate' => __('%1$d / %2$d recommended', 'member-library'),
                'shortBioLongWarning' => __('Longer bios may be shortened in compact Library displays.', 'member-library'),
            ),
        ));
    }

    public function render_admin_notice() {
        $screen = get_current_screen();
        if (!$screen || MemberLibrary_Content_Model::SPEAKER_POST_TYPE !== (string) $screen->post_type) {
            return;
        }
        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        $errors = $post_id > 0 ? get_transient($this->notice_key($post_id)) : false;
        if (!is_array($errors) || empty($errors)) {
            return;
        }
        delete_transient($this->notice_key($post_id));
        ?>
        <div class="notice notice-error is-dismissible"><p><strong><?php esc_html_e('Some speaker profile fields were not saved.', 'member-library'); ?></strong></p><ul class="tsol-speaker-notice-list">
            <?php foreach ($errors as $error) : ?><li><?php echo esc_html($error); ?></li><?php endforeach; ?>
        </ul></div>
        <?php
    }

    public function filter_columns($columns) {
        $filtered = array();
        foreach ((array) $columns as $column => $label) {
            $filtered[$column] = $label;
            if ('cb' === $column) {
                $filtered[self::IMAGE_COLUMN] = __('Headshot', 'member-library');
            }
            if ('title' === $column) {
                $filtered[self::ROLE_COLUMN] = __('Role', 'member-library');
                $filtered[self::CONTENT_COLUMN] = __('Content', 'member-library');
            }
        }
        return $filtered;
    }

    public function render_column($column, $post_id) {
        if (self::IMAGE_COLUMN === $column) {
            $image = get_the_post_thumbnail($post_id, array(48, 48), array('class' => 'tsol-speaker-list-headshot', 'alt' => ''));
            echo $image ?: '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__('No headshot', 'member-library') . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }
        if (self::ROLE_COLUMN === $column) {
            $job_title = sanitize_text_field((string) get_post_meta($post_id, MemberLibrary_Content_Model::SPEAKER_META_JOB_TITLE, true));
            $organization = sanitize_text_field((string) get_post_meta($post_id, MemberLibrary_Content_Model::SPEAKER_META_ORGANIZATION, true));
            if ('' === $job_title && '' === $organization) {
                echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__('No role details', 'member-library') . '</span>';
                return;
            }
            echo '<span class="tsol-speaker-list-role">';
            if ('' !== $job_title) {
                echo '<strong class="tsol-speaker-list-role__job-title">' . esc_html($job_title) . '</strong>';
            }
            if ('' !== $organization) {
                echo '<span class="tsol-speaker-list-role__organization">' . esc_html($organization) . '</span>';
            }
            echo '</span>';
            return;
        }
        if (self::CONTENT_COLUMN === $column) {
            $counts = $this->content_counts();
            echo esc_html(number_format_i18n($counts[(int) $post_id] ?? 0));
        }
    }

    public function default_hidden_columns($hidden, $screen) {
        if (!is_array($hidden) || !$screen || 'edit' !== (string) $screen->base || MemberLibrary_Content_Model::SPEAKER_POST_TYPE !== (string) $screen->post_type) {
            return $hidden;
        }
        return array_values(array_unique(array_merge($hidden, array('date'))));
    }

    private function render_social_row($index, $link) {
        $platform = sanitize_key((string) ($link['platform'] ?? 'other'));
        $url = (string) ($link['url'] ?? '');
        $name = self::PAYLOAD_NAME . '[social_links][' . $index . ']';
        ?>
        <div class="tsol-speaker-social-row" data-speaker-social-row>
            <label><span><?php esc_html_e('Platform', 'member-library'); ?></span><select name="<?php echo esc_attr($name); ?>[platform]" data-speaker-social-platform>
                <?php foreach (MemberLibrary_Content_Model::speaker_social_platforms() as $key => $label) : ?><option value="<?php echo esc_attr($key); ?>" <?php selected($platform, $key); ?>><?php echo esc_html($label); ?></option><?php endforeach; ?>
            </select></label>
            <label class="tsol-speaker-social-row__url"><span><?php esc_html_e('Profile URL', 'member-library'); ?></span><input type="url" name="<?php echo esc_attr($name); ?>[url]" value="<?php echo esc_attr($url); ?>" placeholder="https://" data-speaker-social-url /></label>
            <button type="button" class="button-link-delete" data-speaker-social-remove><?php esc_html_e('Remove', 'member-library'); ?></button>
        </div>
        <?php
    }

    private function content_counts() {
        if (is_array($this->content_count_cache)) {
            return $this->content_count_cache;
        }
        $counts = array();
        $ids = get_posts(array('post_type' => MemberLibrary_Content_Model::post_types(), 'post_status' => array('publish', 'draft', 'private', 'pending', 'future'), 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true));
        foreach (array_map('intval', $ids) as $content_id) {
            $speaker_context = MemberLibrary_Content_Model::effective_speaker_context($content_id);
            foreach (array_unique(array_map('intval', $speaker_context['speaker_ids'])) as $speaker_id) {
                if ($speaker_id > 0) {
                    $counts[$speaker_id] = ($counts[$speaker_id] ?? 0) + 1;
                }
            }
        }
        $this->content_count_cache = $counts;
        return $counts;
    }

    private function store_or_delete_meta($post_id, $key, $value) {
        if ('' === $value || array() === $value) {
            delete_post_meta($post_id, $key);
            return;
        }
        update_post_meta($post_id, $key, $value);
    }

    private function notice_key($post_id) {
        return self::NOTICE_PREFIX . get_current_user_id() . '_' . absint($post_id);
    }

    private function remove_object_callbacks($hook_name, $class_name) {
        global $wp_filter;
        if (!isset($wp_filter[$hook_name]) || !$wp_filter[$hook_name] instanceof WP_Hook) {
            return;
        }
        foreach ($wp_filter[$hook_name]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'] ?? null;
                if (is_array($function) && isset($function[0]) && is_object($function[0]) && is_a($function[0], $class_name)) {
                    remove_filter($hook_name, $function, (int) $priority);
                }
            }
        }
    }
}
