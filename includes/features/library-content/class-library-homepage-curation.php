<?php
/**
 * WordPress-owned curation for the Library homepage.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Homepage_Curation {

    const PAGE_SLUG = 'tsol-library-homepage';
    const OPTION_NAME = 'tsol_library_homepage_curation';
    const NONCE_ACTION = 'tsol_library_homepage_curation';
    const NONCE_NAME = 'tsol_library_homepage_nonce';
    const PAYLOAD_NAME = 'tsol_library_homepage';

    private static $layout_cache = null;
    private $page_hook = '';

    public function init() {
        add_action('admin_menu', array($this, 'add_menu_page'), 18);
        add_action('admin_post_tsol_library_save_homepage', array($this, 'save'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
    }

    public function add_menu_page() {
        $this->page_hook = (string) add_submenu_page(
            TSOL_Library_Admin_Navigation::MENU_SLUG,
            __('Library Homepage', 'tomschooloflife-plugin'),
            __('Homepage', 'tomschooloflife-plugin'),
            'edit_pages',
            self::PAGE_SLUG,
            array($this, 'render')
        );
    }

    public function enqueue_assets($hook) {
        if ('' === $this->page_hook || (string) $hook !== $this->page_hook) {
            return;
        }

        wp_enqueue_style(
            'tsol-library-homepage-curation',
            TSOL_SITE_PLUGIN_URL . 'assets/features/library-content/library-homepage-curation.css',
            array('tsol-library-content-admin'),
            TSOL_SITE_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'tsol-library-homepage-curation',
            TSOL_SITE_PLUGIN_URL . 'assets/features/library-content/library-homepage-curation.js',
            array('jquery', 'jquery-ui-sortable'),
            TSOL_SITE_PLUGIN_VERSION,
            true
        );
    }

    public static function rails() {
        return array(
            'featured' => array(
                'label' => __('Featured', 'tomschooloflife-plugin'),
                'description' => __('A deliberately promoted mix of Courses and Series.', 'tomschooloflife-plugin'),
            ),
            'courses' => array(
                'label' => __('Courses', 'tomschooloflife-plugin'),
                'description' => __('Published Courses that are not Masterclasses.', 'tomschooloflife-plugin'),
            ),
            'masterclasses' => array(
                'label' => __('Masterclasses', 'tomschooloflife-plugin'),
                'description' => __('Courses from the Masterclasses Collection.', 'tomschooloflife-plugin'),
            ),
            'series' => array(
                'label' => __('Series', 'tomschooloflife-plugin'),
                'description' => __('Ongoing or finite Series.', 'tomschooloflife-plugin'),
            ),
        );
    }

    public static function layout() {
        if (is_array(self::$layout_cache)) {
            return self::$layout_cache;
        }

        $stored = get_option(self::OPTION_NAME, null);
        if (!is_array($stored) || !isset($stored['rails']) || !is_array($stored['rails'])) {
            self::$layout_cache = self::default_layout();
            return self::$layout_cache;
        }

        self::$layout_cache = array(
            'version' => 1,
            'rails' => self::sanitize_rails($stored['rails']),
            'updated_at' => isset($stored['updated_at']) ? sanitize_text_field((string) $stored['updated_at']) : '',
        );
        return self::$layout_cache;
    }

    public static function reset_cache() {
        self::$layout_cache = null;
    }

    public static function placement($post_id) {
        $post_id = (int) $post_id;
        if ($post_id <= 0) {
            return null;
        }

        foreach (self::layout()['rails'] as $rail => $post_ids) {
            $position = array_search($post_id, array_map('intval', $post_ids), true);
            if (false !== $position) {
                return array(
                    'rail' => (string) $rail,
                    'position' => (int) $position + 1,
                );
            }
        }
        return null;
    }

    public static function sanitize_rails($raw_rails) {
        $raw_rails = is_array($raw_rails) ? $raw_rails : array();
        $seen = array();
        $sanitized = array();

        foreach (array_keys(self::rails()) as $rail) {
            $sanitized[$rail] = array();
            $post_ids = isset($raw_rails[$rail]) && is_array($raw_rails[$rail]) ? $raw_rails[$rail] : array();
            foreach ($post_ids as $post_id) {
                $post_id = absint($post_id);
                if ($post_id <= 0 || isset($seen[$post_id])) {
                    continue;
                }
                $post = get_post($post_id);
                if (!$post instanceof WP_Post
                    || !in_array((string) $post->post_type, self::supported_post_types(), true)
                    || 'trash' === (string) $post->post_status
                    || !in_array($rail, self::allowed_rails_for_post($post), true)
                ) {
                    continue;
                }
                $seen[$post_id] = true;
                $sanitized[$rail][] = $post_id;
            }
        }

        return $sanitized;
    }

    public function save() {
        if (!current_user_can('edit_pages')) {
            wp_die(esc_html__('You are not allowed to curate the Library homepage.', 'tomschooloflife-plugin'));
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_NAME);

        $payload = isset($_POST[self::PAYLOAD_NAME]) && is_array($_POST[self::PAYLOAD_NAME])
            ? wp_unslash($_POST[self::PAYLOAD_NAME])
            : array();
        $old_layout = self::layout();
        $submitted_token = isset($_POST['tsol_library_homepage_layout_token'])
            ? sanitize_text_field(wp_unslash($_POST['tsol_library_homepage_layout_token']))
            : '';
        if ('' === $submitted_token || !hash_equals(self::layout_token($old_layout), $submitted_token)) {
            wp_safe_redirect(add_query_arg(array(
                'page' => self::PAGE_SLUG,
                'conflict' => '1',
            ), admin_url('admin.php')));
            exit;
        }
        $new_layout = array(
            'version' => 1,
            'rails' => self::sanitize_rails(isset($payload['rails']) ? $payload['rails'] : array()),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        );

        update_option(self::OPTION_NAME, $new_layout, false);
        self::$layout_cache = $new_layout;

        $affected_ids = array_values(array_unique(array_merge(
            self::layout_post_ids($old_layout),
            self::layout_post_ids($new_layout)
        )));
        foreach ($affected_ids as $post_id) {
            TSOL_Library_Content_Changes::record_current_state((int) $post_id);
        }

        wp_safe_redirect(add_query_arg(array(
            'page' => self::PAGE_SLUG,
            'updated' => '1',
        ), admin_url('admin.php')));
        exit;
    }

    public function render() {
        if (!current_user_can('edit_pages')) {
            return;
        }

        $layout = self::layout();
        $records = $this->records();
        $record_lookup = array();
        foreach ($records as $record) {
            $record_lookup[(int) $record->ID] = $record;
        }

        $assigned = array();
        foreach ($layout['rails'] as $post_ids) {
            foreach ($post_ids as $post_id) {
                $assigned[(int) $post_id] = true;
            }
        }
        $available_ids = array_values(array_filter(array_keys($record_lookup), static function ($post_id) use ($assigned) {
            return !isset($assigned[(int) $post_id]);
        }));
        usort($available_ids, static function ($left, $right) use ($record_lookup) {
            return strcasecmp((string) $record_lookup[$left]->post_title, (string) $record_lookup[$right]->post_title);
        });
        ?>
        <div class="wrap tsol-library-admin-page tsol-library-homepage" data-homepage-curation>
            <h1><?php esc_html_e('Library Homepage', 'tomschooloflife-plugin'); ?></h1>
            <p class="tsol-library-admin-page__lead"><?php esc_html_e('Choose which Courses and Series belong on the homepage, move them between sections, and drag them into the intended order.', 'tomschooloflife-plugin'); ?></p>

            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e('Homepage curation saved.', 'tomschooloflife-plugin'); ?></p></div>
            <?php endif; ?>

            <?php if (isset($_GET['conflict'])) : ?>
                <div class="notice notice-error"><p><?php esc_html_e('The Homepage changed in another browser tab. Your save was not applied; review the current order and try again.', 'tomschooloflife-plugin'); ?></p></div>
            <?php endif; ?>

            <div class="notice notice-info inline">
                <p><?php esc_html_e('Only published records are visible to ordinary visitors. Drafts can be arranged now and will remain hidden until they are published. Homepage placement never grants access and never changes search eligibility.', 'tomschooloflife-plugin'); ?></p>
            </div>

            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" data-homepage-form>
                <input type="hidden" name="action" value="tsol_library_save_homepage" />
                <input type="hidden" name="tsol_library_homepage_layout_token" value="<?php echo esc_attr(self::layout_token($layout)); ?>" />
                <?php wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME); ?>

                <div class="tsol-library-homepage__toolbar">
                    <label for="tsol-library-homepage-search"><?php esc_html_e('Find content', 'tomschooloflife-plugin'); ?></label>
                    <input id="tsol-library-homepage-search" type="search" class="regular-text" placeholder="<?php esc_attr_e('Search Courses and Series', 'tomschooloflife-plugin'); ?>" data-homepage-search />
                    <span class="description"><?php esc_html_e('Search only filters this editing screen.', 'tomschooloflife-plugin'); ?></span>
                </div>

                <div class="tsol-library-homepage__rails">
                    <?php foreach (self::rails() as $rail => $config) : ?>
                        <section class="tsol-library-homepage__rail" aria-labelledby="tsol-homepage-rail-<?php echo esc_attr($rail); ?>">
                            <header>
                                <h2 id="tsol-homepage-rail-<?php echo esc_attr($rail); ?>"><?php echo esc_html($config['label']); ?></h2>
                                <p><?php echo esc_html($config['description']); ?></p>
                            </header>
                            <div class="tsol-library-homepage__list" data-homepage-list data-rail="<?php echo esc_attr($rail); ?>">
                                <?php foreach ($layout['rails'][$rail] as $post_id) : ?>
                                    <?php if (isset($record_lookup[(int) $post_id])) : ?>
                                        <?php $this->render_record($record_lookup[(int) $post_id], $rail); ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <p class="tsol-library-homepage__empty" data-homepage-empty><?php esc_html_e('No content assigned.', 'tomschooloflife-plugin'); ?></p>
                            </div>
                        </section>
                    <?php endforeach; ?>
                </div>

                <section class="tsol-library-homepage__available" aria-labelledby="tsol-homepage-available-heading">
                    <header>
                        <h2 id="tsol-homepage-available-heading"><?php esc_html_e('Not on the homepage', 'tomschooloflife-plugin'); ?></h2>
                        <p><?php esc_html_e('These records remain available through their public URLs and search when published.', 'tomschooloflife-plugin'); ?></p>
                    </header>
                    <div class="tsol-library-homepage__list" data-homepage-list data-rail="">
                        <?php foreach ($available_ids as $post_id) : ?>
                            <?php $this->render_record($record_lookup[(int) $post_id], ''); ?>
                        <?php endforeach; ?>
                        <p class="tsol-library-homepage__empty" data-homepage-empty><?php esc_html_e('Every Course and Series is assigned to a homepage section.', 'tomschooloflife-plugin'); ?></p>
                    </div>
                </section>

                <div class="tsol-library-homepage__actions">
                    <?php submit_button(__('Save homepage', 'tomschooloflife-plugin'), 'primary', 'submit', false); ?>
                    <span class="description" aria-live="polite" data-homepage-status></span>
                </div>
            </form>
        </div>
        <?php
    }

    private function render_record(WP_Post $post, $rail) {
        $post_id = (int) $post->ID;
        $status = get_post_status_object((string) $post->post_status);
        $status_label = $status ? (string) $status->label : ucfirst((string) $post->post_status);
        $kind = TSOL_Library_Content_Model::SERIES_POST_TYPE === $post->post_type
            ? __('Series', 'tomschooloflife-plugin')
            : (has_term('masterclasses', TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, $post_id)
                ? __('Masterclass', 'tomschooloflife-plugin')
                : __('Course', 'tomschooloflife-plugin'));
        $edit_url = get_edit_post_link($post_id, 'raw');
        ?>
        <article class="tsol-library-homepage-record" data-homepage-record data-title="<?php echo esc_attr(strtolower((string) $post->post_title)); ?>" data-post-id="<?php echo esc_attr((string) $post_id); ?>">
            <span class="tsol-library-homepage-record__handle" data-homepage-handle title="<?php esc_attr_e('Drag to reorder', 'tomschooloflife-plugin'); ?>" aria-hidden="true">
                <span class="dashicons dashicons-menu" aria-hidden="true"></span>
            </span>
            <div class="tsol-library-homepage-record__identity">
                <strong><?php echo esc_html($post->post_title ?: __('(no title)', 'tomschooloflife-plugin')); ?></strong>
                <span><?php echo esc_html($kind); ?> · <?php echo esc_html($status_label); ?> <span data-homepage-position></span></span>
            </div>
            <label class="screen-reader-text" for="tsol-homepage-record-rail-<?php echo esc_attr((string) $post_id); ?>"><?php esc_html_e('Homepage section', 'tomschooloflife-plugin'); ?></label>
            <select id="tsol-homepage-record-rail-<?php echo esc_attr((string) $post_id); ?>" data-homepage-rail-select>
                <option value="" <?php selected('', $rail); ?>><?php esc_html_e('Not on homepage', 'tomschooloflife-plugin'); ?></option>
                <?php foreach (self::rails() as $rail_key => $config) : ?>
                    <?php if (in_array($rail_key, self::allowed_rails_for_post($post), true)) : ?>
                        <option value="<?php echo esc_attr($rail_key); ?>" <?php selected($rail_key, $rail); ?>><?php echo esc_html($config['label']); ?></option>
                    <?php endif; ?>
                <?php endforeach; ?>
            </select>
            <div class="tsol-library-homepage-record__actions">
                <button type="button" class="button button-small" data-homepage-move="up"><?php esc_html_e('Up', 'tomschooloflife-plugin'); ?></button>
                <button type="button" class="button button-small" data-homepage-move="down"><?php esc_html_e('Down', 'tomschooloflife-plugin'); ?></button>
                <?php if ($edit_url) : ?><a class="button button-small" href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'tomschooloflife-plugin'); ?></a><?php endif; ?>
            </div>
            <input type="hidden" value="<?php echo esc_attr((string) $post_id); ?>" data-homepage-input <?php disabled('' === $rail); ?> />
        </article>
        <?php
    }

    private function records() {
        return get_posts(array(
            'post_type' => self::supported_post_types(),
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ));
    }

    private static function default_layout() {
        $rails = array_fill_keys(array_keys(self::rails()), array());
        $records = get_posts(array(
            'post_type' => self::supported_post_types(),
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'orderby' => 'date',
            'order' => 'DESC',
            'suppress_filters' => true,
        ));

        foreach ($records as $record) {
            if ((bool) get_post_meta($record->ID, TSOL_Library_Content_Model::META_FEATURED, true)) {
                $rails['featured'][] = (int) $record->ID;
            } elseif (TSOL_Library_Content_Model::SERIES_POST_TYPE === $record->post_type) {
                $rails['series'][] = (int) $record->ID;
            } elseif (has_term('masterclasses', TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, $record->ID)) {
                $rails['masterclasses'][] = (int) $record->ID;
            } else {
                $rails['courses'][] = (int) $record->ID;
            }
        }

        return array(
            'version' => 1,
            'rails' => $rails,
            'updated_at' => '',
        );
    }

    private static function layout_post_ids($layout) {
        $post_ids = array();
        foreach ((array) ($layout['rails'] ?? array()) as $rail_ids) {
            $post_ids = array_merge($post_ids, array_map('intval', (array) $rail_ids));
        }
        return array_values(array_unique(array_filter($post_ids)));
    }

    private static function layout_token($layout) {
        return hash('sha256', (string) wp_json_encode($layout));
    }

    private static function supported_post_types() {
        return array(
            TSOL_Library_Content_Model::COURSE_POST_TYPE,
            TSOL_Library_Content_Model::SERIES_POST_TYPE,
        );
    }

    private static function allowed_rails_for_post(WP_Post $post) {
        if (TSOL_Library_Content_Model::SERIES_POST_TYPE === $post->post_type) {
            return array('featured', 'series');
        }
        if (has_term('masterclasses', TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, $post->ID)) {
            return array('featured', 'masterclasses');
        }
        return array('featured', 'courses');
    }
}
