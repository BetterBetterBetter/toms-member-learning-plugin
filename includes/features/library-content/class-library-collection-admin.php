<?php
/**
 * Editorial fields for public Library Course Collection landing pages.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Collection_Admin {

    const NONCE_ACTION = 'tsol_library_collection_editorial';
    const NONCE_NAME = 'tsol_library_collection_nonce';
    const PAYLOAD_NAME = 'tsol_library_collection';

    public function init() {
        $taxonomy = TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY;
        add_action($taxonomy . '_add_form_fields', array($this, 'render_add_fields'));
        add_action($taxonomy . '_edit_form_fields', array($this, 'render_edit_fields'), 10, 2);
        // Save before catalogue change tracking runs at priority 100 so the
        // resulting wake projects the new editorial values.
        add_action('created_term', array($this, 'save_fields'), 20, 3);
        add_action('edited_term', array($this, 'save_fields'), 20, 3);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_notices', array($this, 'render_color_notice'));
    }

    public function render_add_fields($taxonomy) {
        unset($taxonomy);
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?>
        <div class="form-field tsol-library-collection-editor" data-collection-editor>
            <label for="tsol-library-collection-overview"><?php esc_html_e('Landing page overview', 'tomschooloflife-plugin'); ?></label>
            <textarea id="tsol-library-collection-overview" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[overview_html]" rows="7"></textarea>
            <p><?php esc_html_e('Optional long-form introduction shown above the Course directory. Use the native Description field for the short hero introduction.', 'tomschooloflife-plugin'); ?></p>
            <?php $this->render_image_control(0); ?>
            <?php $this->render_color_control(0); ?>
            <p><?php esc_html_e('After creating the Collection and assigning Courses, edit it again to choose a featured Course.', 'tomschooloflife-plugin'); ?></p>
        </div>
        <?php
    }

    public function render_edit_fields($term, $taxonomy) {
        unset($taxonomy);
        $term_id = $term instanceof WP_Term ? (int) $term->term_id : 0;
        $overview = (string) get_term_meta($term_id, TSOL_Library_Content_Model::COLLECTION_META_OVERVIEW, true);
        $hero_image_id = (int) get_term_meta($term_id, TSOL_Library_Content_Model::COLLECTION_META_HERO_IMAGE_ID, true);
        $featured_course_id = (int) get_term_meta($term_id, TSOL_Library_Content_Model::COLLECTION_META_FEATURED_COURSE_ID, true);
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?>
        <tr class="form-field tsol-library-collection-editor-wrap">
            <th scope="row"><label for="tsol-library-collection-overview-<?php echo esc_attr($term_id); ?>"><?php esc_html_e('Landing page overview', 'tomschooloflife-plugin'); ?></label></th>
            <td>
                <?php wp_editor($overview, 'tsol-library-collection-overview-' . $term_id, array(
                    'textarea_name' => self::PAYLOAD_NAME . '[overview_html]',
                    'textarea_rows' => 9,
                    'media_buttons' => false,
                    'teeny' => true,
                    'quicktags' => true,
                )); ?>
                <p class="description"><?php esc_html_e('Long-form public introduction shown above the Course directory. Use the native Description field for the short hero introduction.', 'tomschooloflife-plugin'); ?></p>
            </td>
        </tr>
        <tr class="form-field tsol-library-collection-editor-wrap" data-collection-editor>
            <th scope="row"><?php esc_html_e('Hero artwork', 'tomschooloflife-plugin'); ?></th>
            <td><?php $this->render_image_control($hero_image_id); ?></td>
        </tr>
        <tr class="form-field tsol-library-collection-editor-wrap">
            <th scope="row"><?php esc_html_e('Collection colors', 'tomschooloflife-plugin'); ?></th>
            <td><?php $this->render_color_control($term_id); ?></td>
        </tr>
        <tr class="form-field tsol-library-collection-editor-wrap">
            <th scope="row"><label for="tsol-library-collection-featured-course"><?php esc_html_e('Featured Course', 'tomschooloflife-plugin'); ?></label></th>
            <td>
                <select id="tsol-library-collection-featured-course" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[featured_course_id]">
                    <option value="0"><?php esc_html_e('No featured Course', 'tomschooloflife-plugin'); ?></option>
                    <?php foreach ($this->collection_courses($term_id) as $course) : ?>
                        <option value="<?php echo esc_attr($course->ID); ?>" <?php selected($featured_course_id, (int) $course->ID); ?>><?php echo esc_html($course->post_title); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php esc_html_e('Optional. The Course must already belong to this Collection.', 'tomschooloflife-plugin'); ?></p>
            </td>
        </tr>
        <?php
    }

    private function render_image_control($image_id) {
        $image_id = (int) $image_id;
        ?>
        <div class="tsol-library-collection-image" data-collection-image>
            <input type="hidden" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[hero_image_id]" value="<?php echo esc_attr($image_id); ?>" data-collection-image-id />
            <div class="tsol-library-collection-image__preview" data-collection-image-preview>
                <?php if ($image_id > 0) : ?>
                    <?php echo wp_get_attachment_image($image_id, 'medium', false, array('alt' => '')); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php endif; ?>
            </div>
            <p class="tsol-library-collection-image__actions">
                <button type="button" class="button" data-collection-image-choose><?php esc_html_e('Choose hero artwork', 'tomschooloflife-plugin'); ?></button>
                <button type="button" class="button-link-delete<?php echo $image_id > 0 ? '' : ' hidden'; ?>" data-collection-image-remove><?php esc_html_e('Remove', 'tomschooloflife-plugin'); ?></button>
            </p>
            <p class="description"><?php esc_html_e('Optional wide artwork used in the public Collection hero.', 'tomschooloflife-plugin'); ?></p>
        </div>
        <?php
    }

    private function render_color_control($term_id) {
        $appearance = $term_id > 0 ? TSOL_Library_Content_Model::collection_appearance($term_id) : null;
        $colors = $appearance ?: array(
            'light_background' => '#1f4e79',
            'light_foreground' => '#ffffff',
            'dark_background' => '#8fc8f0',
            'dark_foreground' => '#10263a',
        );
        ?>
        <fieldset class="tsol-library-collection-colors" data-collection-colors>
            <legend class="screen-reader-text"><?php esc_html_e('Collection colors', 'tomschooloflife-plugin'); ?></legend>
            <label class="tsol-library-collection-colors__toggle">
                <input type="checkbox" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[appearance_enabled]" value="1" data-collection-colors-enabled <?php checked(null !== $appearance); ?> />
                <?php esc_html_e('Use custom colors for this Collection', 'tomschooloflife-plugin'); ?>
            </label>
            <div class="tsol-library-collection-colors__themes" data-collection-colors-fields>
                <?php foreach (array('light' => __('Light mode', 'tomschooloflife-plugin'), 'dark' => __('Dark mode', 'tomschooloflife-plugin')) as $theme => $label) : ?>
                    <div class="tsol-library-collection-colors__theme" data-collection-color-pair="<?php echo esc_attr($theme); ?>">
                        <strong><?php echo esc_html($label); ?></strong>
                        <label>
                            <span><?php esc_html_e('Background', 'tomschooloflife-plugin'); ?></span>
                            <input type="color" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[<?php echo esc_attr($theme); ?>_background]" value="<?php echo esc_attr($colors[$theme . '_background']); ?>" data-collection-color="background" />
                        </label>
                        <label>
                            <span><?php esc_html_e('Text', 'tomschooloflife-plugin'); ?></span>
                            <input type="color" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[<?php echo esc_attr($theme); ?>_foreground]" value="<?php echo esc_attr($colors[$theme . '_foreground']); ?>" data-collection-color="foreground" />
                        </label>
                        <output class="tsol-library-collection-colors__contrast" data-collection-contrast></output>
                    </div>
                <?php endforeach; ?>
            </div>
            <p class="description"><?php esc_html_e('Applied to Collection pages, Course badges, breadcrumbs, and Course-card labels. Each pair must meet WCAG AA contrast (4.5:1). Disable this setting to use the School brand colors.', 'tomschooloflife-plugin'); ?></p>
        </fieldset>
        <?php
    }

    public function save_fields($term_id, $tt_id, $taxonomy) {
        unset($tt_id);
        if (TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY !== (string) $taxonomy
            || !isset($_POST[self::NONCE_NAME])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)
            || !current_user_can('manage_categories')
        ) {
            return;
        }

        $payload = isset($_POST[self::PAYLOAD_NAME]) && is_array($_POST[self::PAYLOAD_NAME])
            ? wp_unslash($_POST[self::PAYLOAD_NAME])
            : array();
        $overview = TSOL_Library_Content_HTML_Sanitizer::sanitize((string) ($payload['overview_html'] ?? ''));
        $hero_image_id = absint($payload['hero_image_id'] ?? 0);
        if ($hero_image_id > 0 && !wp_attachment_is_image($hero_image_id)) {
            $hero_image_id = 0;
        }
        $featured_course_id = absint($payload['featured_course_id'] ?? 0);
        if ($featured_course_id > 0
            && (TSOL_Library_Content_Model::COURSE_POST_TYPE !== get_post_type($featured_course_id)
                || !has_term((int) $term_id, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, $featured_course_id))
        ) {
            $featured_course_id = 0;
        }

        $this->store_or_delete($term_id, TSOL_Library_Content_Model::COLLECTION_META_OVERVIEW, $overview);
        $this->store_or_delete($term_id, TSOL_Library_Content_Model::COLLECTION_META_HERO_IMAGE_ID, $hero_image_id);
        $this->store_or_delete($term_id, TSOL_Library_Content_Model::COLLECTION_META_FEATURED_COURSE_ID, $featured_course_id);
        $this->save_color_fields($term_id, $payload);
    }

    private function save_color_fields($term_id, $payload) {
        $keys = TSOL_Library_Content_Model::collection_appearance_meta_keys();
        if (empty($payload['appearance_enabled'])) {
            foreach ($keys as $key) {
                delete_term_meta((int) $term_id, $key);
            }
            return;
        }
        $values = array(
            sanitize_hex_color((string) ($payload['light_background'] ?? '')),
            sanitize_hex_color((string) ($payload['light_foreground'] ?? '')),
            sanitize_hex_color((string) ($payload['dark_background'] ?? '')),
            sanitize_hex_color((string) ($payload['dark_foreground'] ?? '')),
        );
        if (in_array('', $values, true)
            || TSOL_Library_Content_Model::collection_color_contrast($values[0], $values[1]) < 4.5
            || TSOL_Library_Content_Model::collection_color_contrast($values[2], $values[3]) < 4.5
        ) {
            set_transient('tsol_library_collection_color_error_' . get_current_user_id(), 1, 60);
            return;
        }
        foreach ($keys as $index => $key) {
            update_term_meta((int) $term_id, $key, strtolower($values[$index]));
        }
    }

    public function render_color_notice() {
        $key = 'tsol_library_collection_color_error_' . get_current_user_id();
        if (!get_transient($key)) {
            return;
        }
        delete_transient($key);
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Collection colors were not changed because both light and dark color pairs must be valid and meet WCAG AA contrast (4.5:1).', 'tomschooloflife-plugin') . '</p></div>';
    }

    public function enqueue_assets($hook) {
        if (!in_array((string) $hook, array('edit-tags.php', 'term.php'), true)) {
            return;
        }
        $screen = get_current_screen();
        if (!$screen || TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY !== (string) $screen->taxonomy) {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style('tsol-library-collection-admin', TSOL_SITE_PLUGIN_URL . 'assets/features/library-content/library-collection-admin.css', array(), TSOL_SITE_PLUGIN_VERSION);
        wp_enqueue_script('tsol-library-collection-admin', TSOL_SITE_PLUGIN_URL . 'assets/features/library-content/library-collection-admin.js', array('jquery', 'media-editor'), TSOL_SITE_PLUGIN_VERSION, true);
        wp_localize_script('tsol-library-collection-admin', 'tsolLibraryCollectionAdmin', array(
            'frameTitle' => __('Choose Collection hero artwork', 'tomschooloflife-plugin'),
            'useImage' => __('Use this artwork', 'tomschooloflife-plugin'),
            'contrastPass' => __('Pass', 'tomschooloflife-plugin'),
            'contrastFail' => __('Needs more contrast', 'tomschooloflife-plugin'),
            'contrastError' => __('Choose colors with at least 4.5:1 contrast.', 'tomschooloflife-plugin'),
        ));
    }

    private function collection_courses($term_id) {
        return get_posts(array(
            'post_type' => TSOL_Library_Content_Model::COURSE_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'tax_query' => array(array(
                'taxonomy' => TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY,
                'field' => 'term_id',
                'terms' => array((int) $term_id),
            )),
        ));
    }

    private function store_or_delete($term_id, $key, $value) {
        if ('' === $value || 0 === $value || array() === $value) {
            delete_term_meta((int) $term_id, $key);
            return;
        }
        update_term_meta((int) $term_id, $key, $value);
    }
}
