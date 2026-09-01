<?php
/**
 * Canonical Library URL editor for private WordPress content types.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_URL_Admin {

    const NONCE_ACTION = 'tsol_library_url_editor';
    const NONCE_NAME = 'tsol_library_url_nonce';
    const SLUG_FIELD = 'tsol_library_slug';

    public function init() {
        add_action('edit_form_after_title', array($this, 'render_editor'), 5);
        add_filter('wp_insert_post_data', array($this, 'filter_post_data'), 20, 4);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public static function supported_post_types() {
        return array(
            MemberLibrary_Content_Model::COURSE_POST_TYPE,
            MemberLibrary_Content_Model::SERIES_POST_TYPE,
            MemberLibrary_Content_Model::ITEM_POST_TYPE,
            MemberLibrary_Content_Model::SPEAKER_POST_TYPE,
        );
    }

    public static function supports_post_type($post_type) {
        return in_array((string) $post_type, self::supported_post_types(), true);
    }

    public function enqueue_assets($hook) {
        if (!in_array($hook, array('post.php', 'post-new.php'), true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || !self::supports_post_type($screen->post_type)) {
            return;
        }

        wp_enqueue_style(
            'tsol-library-url-admin',
            MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-url-admin.css',
            array(),
            MEMBER_LIBRARY_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'tsol-library-url-admin',
            MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-url-admin.js',
            array(),
            MEMBER_LIBRARY_PLUGIN_VERSION,
            true
        );
    }

    public function render_editor($post) {
        if (!$post instanceof WP_Post || !self::supports_post_type($post->post_type)) {
            return;
        }

        $stored_slug = (string) $post->post_name;
        $display_slug = '' !== $stored_slug
            ? $stored_slug
            : sanitize_title((string) $post->post_title);
        $path = self::public_path($post, $display_slug);
        $is_published = 'publish' === (string) $post->post_status;

        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?>
        <div
            class="tsol-library-url-editor"
            data-library-url-editor
            data-library-post-type="<?php echo esc_attr((string) $post->post_type); ?>"
            data-library-auto-slug="<?php echo '' === $stored_slug ? '1' : '0'; ?>"
        >
            <strong><?php esc_html_e('Library path:', 'member-library'); ?></strong>
            <span class="tsol-library-url-editor__path" data-library-path>
                <span data-library-url-prefix><?php echo esc_html(self::url_prefix($path, $display_slug)); ?></span><span data-library-slug-text><?php echo esc_html($display_slug); ?></span>
            </span>
            <span data-library-slug-view-controls>
                <button type="button" class="edit-slug button button-small" data-library-slug-edit><?php esc_html_e('Edit', 'member-library'); ?></button>
            </span>
            <span class="tsol-library-url-editor__edit-controls" data-library-slug-edit-controls hidden>
                <label class="screen-reader-text" for="tsol-library-slug"><?php esc_html_e('Library slug', 'member-library'); ?></label>
                <input
                    type="text"
                    id="tsol-library-slug"
                    name="<?php echo esc_attr(self::SLUG_FIELD); ?>"
                    value="<?php echo esc_attr($display_slug); ?>"
                    maxlength="200"
                    autocomplete="off"
                    spellcheck="false"
                    aria-describedby="tsol-library-url-description"
                    data-library-slug
                />
                <button type="button" class="button button-small" data-library-slug-confirm><?php esc_html_e('OK', 'member-library'); ?></button>
                <button type="button" class="button-link" data-library-slug-cancel><?php esc_html_e('Cancel', 'member-library'); ?></button>
            </span>
            <span id="tsol-library-url-description" class="screen-reader-text">
                <?php esc_html_e('Only the final path segment is editable. WordPress normalizes it when you save.', 'member-library'); ?>
            </span>
            <?php if ($is_published) : ?>
                <span class="tsol-library-url-editor__warning" data-library-slug-warning hidden>
                    <?php esc_html_e('Changing a published slug changes its sharing URL. Existing links will not redirect automatically.', 'member-library'); ?>
                </span>
            <?php endif; ?>
        </div>
        <?php
    }

    public function filter_post_data($data, $postarr, $unsanitized_postarr, $update) {
        if (!is_array($data) || !self::supports_post_type($data['post_type'] ?? '')) {
            return $data;
        }
        if ((defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) || !isset($_POST[self::SLUG_FIELD], $_POST[self::NONCE_NAME])) {
            return $data;
        }

        $nonce = sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME]));
        if (!wp_verify_nonce($nonce, self::NONCE_ACTION)) {
            return $data;
        }

        $post_id = absint($postarr['ID'] ?? ($unsanitized_postarr['ID'] ?? 0));
        if (!$this->can_edit($post_id, (string) $data['post_type'])) {
            return $data;
        }

        $submitted_slug = trim((string) wp_unslash($_POST[self::SLUG_FIELD]));
        $title = isset($data['post_title']) ? (string) wp_unslash($data['post_title']) : '';
        $slug = sanitize_title('' !== $submitted_slug ? $submitted_slug : $title, '', 'save');
        if ('' === $slug) {
            return $data;
        }

        $data['post_name'] = wp_unique_post_slug(
            $slug,
            $post_id,
            (string) ($data['post_status'] ?? 'draft'),
            (string) $data['post_type'],
            absint($data['post_parent'] ?? 0)
        );

        return $data;
    }

    public static function public_path($post, $slug = null) {
        if (!$post instanceof WP_Post || !self::supports_post_type($post->post_type)) {
            return '';
        }

        $resolved_slug = null === $slug ? (string) $post->post_name : (string) $slug;
        $resolved_slug = sanitize_title($resolved_slug);
        $prefix = self::path_prefix($post);
        return $prefix . ('' !== $resolved_slug ? '/' . $resolved_slug : '');
    }

    public static function path_prefix($post) {
        if (!$post instanceof WP_Post) {
            return '';
        }
        if (MemberLibrary_Content_Model::COURSE_POST_TYPE === $post->post_type) {
            return '/courses';
        }
        if (MemberLibrary_Content_Model::SERIES_POST_TYPE === $post->post_type) {
            return '/series';
        }
        if (MemberLibrary_Content_Model::SPEAKER_POST_TYPE === $post->post_type) {
            return '/speakers';
        }
        if (MemberLibrary_Content_Model::ITEM_POST_TYPE !== $post->post_type) {
            return '';
        }

        $course = self::item_parent($post, 'course');
        if ($course instanceof WP_Post) {
            return '/courses/' . self::post_slug($course);
        }
        $series = self::item_parent($post, 'series');
        if ($series instanceof WP_Post) {
            return '/series/' . self::post_slug($series);
        }
        return '/recordings';
    }

    private static function item_parent($post, $kind) {
        $is_course = 'course' === $kind;
        $meta_key = $is_course
            ? MemberLibrary_Content_Model::META_COURSE_ID
            : MemberLibrary_Content_Model::META_SERIES_ID;
        $query_key = $is_course ? 'tsol_course_id' : 'tsol_series_id';
        $expected_type = $is_course
            ? MemberLibrary_Content_Model::COURSE_POST_TYPE
            : MemberLibrary_Content_Model::SERIES_POST_TYPE;
        $parent_id = (int) get_post_meta((int) $post->ID, $meta_key, true);

        if ($parent_id <= 0 && isset($_GET[$query_key])) {
            $parent_id = absint(wp_unslash($_GET[$query_key]));
        }

        $parent = $parent_id > 0 ? get_post($parent_id) : null;
        return $parent instanceof WP_Post && $expected_type === $parent->post_type
            ? $parent
            : null;
    }

    private static function url_prefix($url, $slug) {
        $url = (string) $url;
        $slug = (string) $slug;
        if ('' === $slug || !str_ends_with($url, '/' . $slug)) {
            return trailingslashit($url);
        }
        return substr($url, 0, -strlen($slug));
    }

    private static function post_slug($post) {
        $source = '' !== (string) $post->post_name
            ? (string) $post->post_name
            : (string) $post->post_title;
        return sanitize_title($source);
    }

    private function can_edit($post_id, $post_type) {
        if ($post_id > 0) {
            return current_user_can('edit_post', $post_id);
        }
        $post_type_object = get_post_type_object($post_type);
        return $post_type_object instanceof WP_Post_Type
            && current_user_can($post_type_object->cap->edit_posts);
    }
}
