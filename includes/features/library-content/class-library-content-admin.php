<?php
/**
 * WordPress-native Library metadata editor.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Content_Admin {

    const NONCE_ACTION = 'tsol_library_content_editor';
    const NONCE_NAME = 'tsol_library_content_nonce';
    const PAYLOAD_NAME = 'tsol_library';
    const AJAX_ACTION = 'tsol_library_normalize_media_url';
    const NOTICE_PREFIX = 'tsol_library_editor_notice_';
    const COURSE_COLUMN = 'tsol-course';
    const SERIES_COLUMN = 'tsol-series';
    const SPEAKERS_COLUMN = 'tsol-speakers';
    const CONTENT_COUNT_COLUMN = 'tsol-content-count';
    const CONTENT_SCOPE_FILTER = 'tsol_content_scope';
    const PARENT_FILTER = 'tsol_library_parent';
    const CONTENT_SCOPE_STANDALONE = 'standalone';
    const CONTENT_SCOPE_COURSE = 'course';
    const CONTENT_SCOPE_SERIES = 'series';
    const CONTENT_SCOPE_ALL = 'all';

    private static $updating_status = false;
    private $content_scope_count_cache = null;
    private $content_status_count_cache = array();
    private $parent_content_count_cache = array();

    public function init() {
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'), 20, 2);
        add_action('save_post', array($this, 'save_post'), 30, 2);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_notices', array($this, 'render_admin_notice'));
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'ajax_normalize_media_url'));
        add_filter('manage_edit-' . TSOL_Library_Content_Model::ITEM_POST_TYPE . '_columns', array($this, 'add_course_column'));
        add_filter('manage_edit-' . TSOL_Library_Content_Model::ITEM_POST_TYPE . '_columns', array($this, 'add_series_column'), 11);
        add_filter('views_edit-' . TSOL_Library_Content_Model::ITEM_POST_TYPE, array($this, 'filter_content_status_views'));
        add_action('manage_' . TSOL_Library_Content_Model::ITEM_POST_TYPE . '_posts_custom_column', array($this, 'render_course_column'), 10, 2);
        add_action('manage_' . TSOL_Library_Content_Model::ITEM_POST_TYPE . '_posts_custom_column', array($this, 'render_series_column'), 10, 2);
        foreach (array(TSOL_Library_Content_Model::COURSE_POST_TYPE, TSOL_Library_Content_Model::SERIES_POST_TYPE) as $parent_post_type) {
            add_filter('manage_edit-' . $parent_post_type . '_columns', array($this, 'add_content_count_column'), 12);
            add_action('manage_' . $parent_post_type . '_posts_custom_column', array($this, 'render_content_count_column'), 10, 2);
        }
        add_action('pre_get_posts', array($this, 'filter_content_list_query'));
        add_action('restrict_manage_posts', array($this, 'render_content_scope_filter'), 10, 2);
        foreach (TSOL_Library_Content_Model::post_types() as $post_type) {
            add_filter('manage_edit-' . $post_type . '_columns', array($this, 'add_speakers_column'), 13);
            add_action('manage_' . $post_type . '_posts_custom_column', array($this, 'render_speakers_column'), 10, 2);
            add_filter('manage_edit-' . $post_type . '_columns', array($this, 'shorten_taxonomy_column_labels'), 20);
            add_filter('postbox_classes_' . $post_type . '_tsol-library-provenance', array($this, 'collapse_provenance_box'));
        }
        add_filter('default_hidden_columns', array($this, 'default_hidden_columns'), 10, 2);
    }

    public function add_course_column($columns) {
        if (!is_array($columns) || isset($columns[self::COURSE_COLUMN])) {
            return $columns;
        }

        $with_course = array();
        foreach ($columns as $column => $label) {
            $with_course[$column] = $label;
            if ('title' === $column) {
                $with_course[self::COURSE_COLUMN] = __('Course', 'tomschooloflife-plugin');
            }
        }

        if (!isset($with_course[self::COURSE_COLUMN])) {
            $with_course[self::COURSE_COLUMN] = __('Course', 'tomschooloflife-plugin');
        }

        return $with_course;
    }

    public function add_content_count_column($columns) {
        if (!is_array($columns) || isset($columns[self::CONTENT_COUNT_COLUMN])) {
            return $columns;
        }
        $columns[self::CONTENT_COUNT_COLUMN] = __('Content', 'tomschooloflife-plugin');
        return $columns;
    }

    public function render_content_count_column($column, $post_id) {
        if (self::CONTENT_COUNT_COLUMN !== $column) {
            return;
        }
        $post_type = get_post_type((int) $post_id);
        if (!in_array($post_type, array(TSOL_Library_Content_Model::COURSE_POST_TYPE, TSOL_Library_Content_Model::SERIES_POST_TYPE), true)) {
            return;
        }
        $counts = $this->parent_content_counts($post_type);
        $count = isset($counts[(int) $post_id]) ? (int) $counts[(int) $post_id] : 0;
        $scope = TSOL_Library_Content_Model::COURSE_POST_TYPE === $post_type
            ? self::CONTENT_SCOPE_COURSE
            : self::CONTENT_SCOPE_SERIES;
        $url = add_query_arg(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            self::CONTENT_SCOPE_FILTER => $scope,
            self::PARENT_FILTER => (int) $post_id,
        ), admin_url('edit.php'));
        $parent_title = get_the_title((int) $post_id);
        printf(
            '<a href="%s" aria-label="%s">%s</a>',
            esc_url($url),
            esc_attr(sprintf(
                _n('View %1$s content item in %2$s', 'View %1$s content items in %2$s', $count, 'tomschooloflife-plugin'),
                number_format_i18n($count),
                $parent_title
            )),
            esc_html(number_format_i18n($count))
        );
    }

    public function render_course_column($column, $post_id) {
        if (self::COURSE_COLUMN !== $column) {
            return;
        }

        $course_id = (int) get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_COURSE_ID, true);
        if ($course_id <= 0) {
            echo '<span class="tsol-library-course-column__empty" aria-hidden="true">&#8212;</span>';
            echo '<span class="screen-reader-text">';
            esc_html_e('No course', 'tomschooloflife-plugin');
            echo '</span>';
            return;
        }

        $course = get_post($course_id);
        if (!$course instanceof WP_Post || TSOL_Library_Content_Model::COURSE_POST_TYPE !== $course->post_type) {
            echo '<span class="tsol-library-course-column__unavailable">';
            esc_html_e('Unavailable', 'tomschooloflife-plugin');
            echo '</span>';
            return;
        }

        $title = '' !== trim((string) $course->post_title)
            ? (string) $course->post_title
            : __('(no title)', 'tomschooloflife-plugin');
        $edit_url = get_edit_post_link($course_id, 'raw');
        if ('' === (string) $edit_url) {
            echo esc_html($title);
            return;
        }

        printf(
            '<a href="%s">%s</a>',
            esc_url($edit_url),
            esc_html($title)
        );
    }

    public function add_series_column($columns) {
        if (!is_array($columns) || isset($columns[self::SERIES_COLUMN])) {
            return $columns;
        }

        $with_series = array();
        foreach ($columns as $column => $label) {
            $with_series[$column] = $label;
            if (self::COURSE_COLUMN === $column) {
                $with_series[self::SERIES_COLUMN] = __('Series', 'tomschooloflife-plugin');
            }
        }
        if (!isset($with_series[self::SERIES_COLUMN])) {
            $with_series[self::SERIES_COLUMN] = __('Series', 'tomschooloflife-plugin');
        }
        return $with_series;
    }

    public function render_series_column($column, $post_id) {
        if (self::SERIES_COLUMN !== $column) {
            return;
        }
        $series_id = (int) get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_SERIES_ID, true);
        $series = $series_id > 0 ? get_post($series_id) : null;
        if (!$series instanceof WP_Post || TSOL_Library_Content_Model::SERIES_POST_TYPE !== $series->post_type) {
            echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">';
            esc_html_e('No series', 'tomschooloflife-plugin');
            echo '</span>';
            return;
        }
        $title = '' !== trim((string) $series->post_title) ? (string) $series->post_title : __('(no title)', 'tomschooloflife-plugin');
        $edit_url = get_edit_post_link($series_id, 'raw');
        echo $edit_url ? '<a href="' . esc_url($edit_url) . '">' . esc_html($title) . '</a>' : esc_html($title);
    }

    public function shorten_taxonomy_column_labels($columns) {
        if (!is_array($columns)) {
            return $columns;
        }

        $labels = array(
            'taxonomy-' . TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY => __('Collections', 'tomschooloflife-plugin'),
            'taxonomy-' . TSOL_Library_Content_Model::TOPIC_TAXONOMY => __('Topics', 'tomschooloflife-plugin'),
        );
        foreach ($labels as $column => $label) {
            if (isset($columns[$column])) {
                $columns[$column] = $label;
            }
        }

        return $columns;
    }

    public function add_speakers_column($columns) {
        if (!is_array($columns) || isset($columns[self::SPEAKERS_COLUMN])) {
            return $columns;
        }

        $with_speakers = array();
        foreach ($columns as $column => $label) {
            $with_speakers[$column] = $label;
            if ('title' === $column) {
                $with_speakers[self::SPEAKERS_COLUMN] = __('Speakers', 'tomschooloflife-plugin');
            }
        }
        if (!isset($with_speakers[self::SPEAKERS_COLUMN])) {
            $with_speakers[self::SPEAKERS_COLUMN] = __('Speakers', 'tomschooloflife-plugin');
        }
        return $with_speakers;
    }

    public function render_speakers_column($column, $post_id) {
        if (self::SPEAKERS_COLUMN !== $column) {
            return;
        }

        $speaker_context = TSOL_Library_Content_Model::effective_speaker_context((int) $post_id);
        $speaker_ids = $speaker_context['speaker_ids'];
        $links = array();
        foreach ($speaker_ids as $speaker_id) {
            $speaker = get_post($speaker_id);
            if (!$speaker instanceof WP_Post || TSOL_Library_Content_Model::SPEAKER_POST_TYPE !== $speaker->post_type || 'trash' === $speaker->post_status) {
                continue;
            }
            $name = '' !== trim((string) $speaker->post_title) ? (string) $speaker->post_title : __('(no name)', 'tomschooloflife-plugin');
            $edit_url = get_edit_post_link($speaker_id, 'raw');
            $links[] = $edit_url
                ? '<a href="' . esc_url($edit_url) . '">' . esc_html($name) . '</a>'
                : esc_html($name);
        }

        if (empty($links)) {
            echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__('No speakers', 'tomschooloflife-plugin') . '</span>';
            return;
        }
        echo wp_kses_post(implode(', ', $links));
        if (in_array((string) $speaker_context['source'], array('course', 'series'), true)) {
            $parent_title = get_the_title((int) $speaker_context['parent_id']);
            printf(
                '<span class="tsol-library-speaker-source" title="%1$s">%2$s</span>',
                esc_attr(sprintf(
                    __('Inherited from %1$s: %2$s', 'tomschooloflife-plugin'),
                    (string) $speaker_context['parent_label'],
                    $parent_title
                )),
                esc_html__('Inherited', 'tomschooloflife-plugin')
            );
        }
    }

    public function filter_content_list_query($query) {
        if (!is_admin()
            || !$query instanceof WP_Query
            || !$query->is_main_query()
            || TSOL_Library_Content_Model::ITEM_POST_TYPE !== (string) $query->get('post_type')
        ) {
            return;
        }

        $scope = $this->requested_content_scope();
        if (self::CONTENT_SCOPE_STANDALONE === $scope) {
            $this->append_meta_query($query, array(
                'relation' => 'AND',
                array(
                    'relation' => 'OR',
                    array(
                        'key' => TSOL_Library_Content_Model::META_COURSE_ID,
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key' => TSOL_Library_Content_Model::META_COURSE_ID,
                        'value' => 0,
                        'compare' => '=',
                        'type' => 'NUMERIC',
                    ),
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key' => TSOL_Library_Content_Model::META_SERIES_ID,
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key' => TSOL_Library_Content_Model::META_SERIES_ID,
                        'value' => 0,
                        'compare' => '=',
                        'type' => 'NUMERIC',
                    ),
                ),
            ));
        } elseif (self::CONTENT_SCOPE_COURSE === $scope) {
            $this->append_meta_query($query, array(
                'key' => TSOL_Library_Content_Model::META_COURSE_ID,
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ));
        } elseif (self::CONTENT_SCOPE_SERIES === $scope) {
            $this->append_meta_query($query, array(
                'key' => TSOL_Library_Content_Model::META_SERIES_ID,
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ));
        }

        $parent_id = $this->requested_parent_content_id($scope);
        if ($parent_id > 0) {
            $this->append_meta_query($query, array(
                'key' => self::CONTENT_SCOPE_COURSE === $scope
                    ? TSOL_Library_Content_Model::META_COURSE_ID
                    : TSOL_Library_Content_Model::META_SERIES_ID,
                'value' => $parent_id,
                'compare' => '=',
                'type' => 'NUMERIC',
            ));
        }

    }

    public function render_content_scope_filter($post_type, $which = 'top') {
        if (TSOL_Library_Content_Model::ITEM_POST_TYPE !== (string) $post_type || 'top' !== (string) $which) {
            return;
        }

        $counts = $this->content_scope_counts();
        $scope = $this->requested_content_scope();
        $parent_id = $this->requested_parent_content_id($scope);
        ?>
        <label class="screen-reader-text" for="tsol-content-scope"><?php esc_html_e('Filter by content scope', 'tomschooloflife-plugin'); ?></label>
        <select name="<?php echo esc_attr(self::CONTENT_SCOPE_FILTER); ?>" id="tsol-content-scope">
            <option value="<?php echo esc_attr(self::CONTENT_SCOPE_STANDALONE); ?>" <?php selected($scope, self::CONTENT_SCOPE_STANDALONE); ?>><?php echo esc_html(sprintf(__('Standalone content (%s)', 'tomschooloflife-plugin'), number_format_i18n($counts['standalone']))); ?></option>
            <option value="<?php echo esc_attr(self::CONTENT_SCOPE_COURSE); ?>" <?php selected($scope, self::CONTENT_SCOPE_COURSE); ?>><?php echo esc_html(sprintf(__('Course lessons (%s)', 'tomschooloflife-plugin'), number_format_i18n($counts['course']))); ?></option>
            <option value="<?php echo esc_attr(self::CONTENT_SCOPE_SERIES); ?>" <?php selected($scope, self::CONTENT_SCOPE_SERIES); ?>><?php echo esc_html(sprintf(__('Series episodes (%s)', 'tomschooloflife-plugin'), number_format_i18n($counts['series']))); ?></option>
            <option value="<?php echo esc_attr(self::CONTENT_SCOPE_ALL); ?>" <?php selected($scope, self::CONTENT_SCOPE_ALL); ?>><?php echo esc_html(sprintf(__('All content (%s)', 'tomschooloflife-plugin'), number_format_i18n($counts['all']))); ?></option>
        </select>
        <?php if ($parent_id > 0) : ?>
            <input type="hidden" name="<?php echo esc_attr(self::PARENT_FILTER); ?>" value="<?php echo esc_attr($parent_id); ?>" />
            <span class="description"><?php echo esc_html(sprintf(__('Filtered by: %s', 'tomschooloflife-plugin'), get_the_title($parent_id))); ?></span>
        <?php endif; ?>
        <?php
    }

    public function filter_content_status_views($views) {
        if (!is_array($views)) {
            return $views;
        }

        $scope = $this->requested_content_scope();
        $parent_id = $this->requested_parent_content_id($scope);
        $counts = $this->content_status_counts($scope, $parent_id);
        foreach ($views as $key => $view) {
            $views[$key] = preg_replace_callback(
                '/href=(["\'])(.*?)\1/',
                static function ($matches) use ($scope, $parent_id) {
                    $url = html_entity_decode((string) $matches[2], ENT_QUOTES, get_bloginfo('charset'));
                    $url = add_query_arg(self::CONTENT_SCOPE_FILTER, $scope, $url);
                    if ($parent_id > 0) {
                        $url = add_query_arg(self::PARENT_FILTER, $parent_id, $url);
                    }
                    return 'href=' . $matches[1] . esc_url($url) . $matches[1];
                },
                (string) $view,
                1
            );
            if (isset($counts[$key])) {
                $views[$key] = preg_replace(
                    '/<span class="count">\([^<]*\)<\/span>/',
                    '<span class="count">(' . esc_html(number_format_i18n($counts[$key])) . ')</span>',
                    $views[$key],
                    1
                );
            }
        }

        return $views;
    }

    public function default_hidden_columns($hidden, $screen) {
        if (!is_array($hidden)
            || !$screen
            || 'edit' !== (string) $screen->base
            || !in_array((string) $screen->post_type, TSOL_Library_Content_Model::post_types(), true)
        ) {
            return $hidden;
        }

        return array_values(array_unique(array_merge($hidden, array('date'))));
    }

    public function add_meta_boxes($post_type, $post) {
        if (!self::supports_post_type($post_type)) {
            return;
        }

        // Library records have no WordPress frontend, so LeadPages' globally
        // registered page-specific pop-up control is not applicable here.
        remove_meta_box('leadbox-select', $post_type, 'side');

        add_meta_box(
            'tsol-library-details',
            __('TSOL Library details', 'tomschooloflife-plugin'),
            array($this, 'render_details_meta_box'),
            $post_type,
            'normal',
            'high'
        );

        if (TSOL_Library_Content_Model::ITEM_POST_TYPE === $post_type) {
            add_meta_box(
                'tsol-library-media',
                __('Media', 'tomschooloflife-plugin'),
                array($this, 'render_media_meta_box'),
                $post_type,
                'normal',
                'high'
            );

            add_meta_box(
                'tsol-library-resources',
                __('Library resources', 'tomschooloflife-plugin'),
                array($this, 'render_resources_meta_box'),
                $post_type,
                'normal',
                'default'
            );
        }

        if (TSOL_Library_Content_Model::COURSE_POST_TYPE === $post_type) {
            add_meta_box(
                'tsol-library-curriculum',
                __('Course curriculum', 'tomschooloflife-plugin'),
                array($this, 'render_curriculum_meta_box'),
                $post_type,
                'normal',
                'high'
            );
        }

        if (TSOL_Library_Content_Model::SERIES_POST_TYPE === $post_type) {
            add_meta_box(
                'tsol-library-series-episodes',
                __('Series episodes', 'tomschooloflife-plugin'),
                array($this, 'render_series_episodes_meta_box'),
                $post_type,
                'normal',
                'high'
            );
        }

        add_meta_box(
            'tsol-library-protection',
            __('Library access', 'tomschooloflife-plugin'),
            array($this, 'render_protection_meta_box'),
            $post_type,
            'side',
            'high'
        );

        add_meta_box(
            'tsol-library-speakers',
            __('Speakers', 'tomschooloflife-plugin'),
            array($this, 'render_speakers_meta_box'),
            $post_type,
            'side',
            'default'
        );

        if ($this->has_provenance($post->ID)) {
            add_meta_box(
                'tsol-library-provenance',
                __('Legacy import source', 'tomschooloflife-plugin'),
                array($this, 'render_provenance_meta_box'),
                $post_type,
                'side',
                'low'
            );
        }
    }

    public function collapse_provenance_box($classes) {
        $classes = is_array($classes) ? $classes : array();
        if (!in_array('closed', $classes, true)) {
            $classes[] = 'closed';
        }
        return $classes;
    }

    public function enqueue_assets($hook) {
        if (!in_array($hook, array('edit.php', 'post.php', 'post-new.php'), true)) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || !self::supports_post_type($screen->post_type)) {
            return;
        }

        global $post;
        $post_id = $post instanceof WP_Post ? (int) $post->ID : 0;

        if (TSOL_Library_Content_Model::ITEM_POST_TYPE === $screen->post_type) {
            wp_enqueue_media();
        }
        wp_enqueue_style(
            'tsol-library-content-admin',
            TSOL_SITE_PLUGIN_URL . 'assets/features/library-content/library-content-admin.css',
            array(),
            TSOL_SITE_PLUGIN_VERSION
        );
        wp_enqueue_style(
            'tsol-library-structure-summary',
            TSOL_SITE_PLUGIN_URL . 'assets/features/library-content/library-structure-builder.css',
            array('tsol-library-content-admin'),
            TSOL_SITE_PLUGIN_VERSION
        );

        if ('edit.php' === $hook) {
            return;
        }

        if (TSOL_Library_Content_Model::ITEM_POST_TYPE === $screen->post_type) {
            wp_enqueue_script(
                'tsol-library-structure-placement',
                TSOL_SITE_PLUGIN_URL . 'assets/features/library-content/library-structure-placement.js',
                array(),
                TSOL_SITE_PLUGIN_VERSION,
                true
            );
        }

        $script_dependencies = array('jquery', 'jquery-ui-sortable');
        wp_enqueue_script(
            'tsol-library-content-admin',
            TSOL_SITE_PLUGIN_URL . 'assets/features/library-content/library-content-admin.js',
            $script_dependencies,
            TSOL_SITE_PLUGIN_VERSION,
            true
        );
        wp_localize_script('tsol-library-content-admin', 'tsolLibraryContentAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::AJAX_ACTION),
            'postId' => $post_id,
            'postType' => (string) $screen->post_type,
            'requiresMedia' => TSOL_Library_Content_Model::ITEM_POST_TYPE === $screen->post_type,
            'strings' => array(
                'checking' => __('Checking URL…', 'tomschooloflife-plugin'),
                'empty' => __('Paste a Vimeo or YouTube URL, or choose WordPress media.', 'tomschooloflife-plugin'),
                'error' => __('That media URL could not be recognised.', 'tomschooloflife-plugin'),
                'remove' => __('Remove media', 'tomschooloflife-plugin'),
                'providerId' => __('ID', 'tomschooloflife-plugin'),
                'privateVimeo' => __('Private Vimeo reference detected', 'tomschooloflife-plugin'),
                'wordpressAttachment' => __('WordPress attachment', 'tomschooloflife-plugin'),
                'speakerAdded' => __('Speaker added.', 'tomschooloflife-plugin'),
                'speakerRemoved' => __('Speaker removed.', 'tomschooloflife-plugin'),
                'speakerMoved' => __('Speaker order updated.', 'tomschooloflife-plugin'),
                'speakerNoResults' => __('No speakers match that search.', 'tomschooloflife-plugin'),
                'speakerAdd' => __('Add speaker', 'tomschooloflife-plugin'),
                'speakerSelectedCount' => __('%d selected', 'tomschooloflife-plugin'),
                'speakerEdit' => __('Edit', 'tomschooloflife-plugin'),
                'speakerDrag' => __('Drag to reorder', 'tomschooloflife-plugin'),
                'speakerMoveUp' => __('Move up', 'tomschooloflife-plugin'),
                'speakerMoveDown' => __('Move down', 'tomschooloflife-plugin'),
                'speakerRemove' => __('Remove', 'tomschooloflife-plugin'),
            ),
        ));
    }

    public function render_details_meta_box($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);

        $content_type = (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_CONTENT_TYPE, true);
        if ('' === $content_type) {
            $content_type = $this->default_content_type($post->post_type);
        }
        $position = (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_POSITION, true);
        $featured = (bool) get_post_meta($post->ID, TSOL_Library_Content_Model::META_FEATURED, true);
        $current = (bool) get_post_meta($post->ID, TSOL_Library_Content_Model::META_CURRENT, true);
        ?>
        <div class="tsol-library-editor" data-library-editor>
            <div class="tsol-library-field-grid">
                <div class="tsol-library-field">
                    <label for="tsol-library-content-type"><?php esc_html_e('Content type', 'tomschooloflife-plugin'); ?></label>
                    <select id="tsol-library-content-type" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[content_type]">
                        <?php foreach ($this->content_type_options($post->post_type) as $value => $label) : ?>
                            <option value="<?php echo esc_attr($value); ?>" <?php selected($content_type, $value); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Used for catalogue labels and filtering; it does not grant access.', 'tomschooloflife-plugin'); ?></p>
                </div>

                <?php if (TSOL_Library_Content_Model::ITEM_POST_TYPE !== $post->post_type) : ?>
                    <div class="tsol-library-field">
                        <label for="tsol-library-position"><?php esc_html_e('Display position', 'tomschooloflife-plugin'); ?></label>
                        <input type="number" min="0" step="1" id="tsol-library-position" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[position]" value="<?php echo esc_attr($position); ?>" />
                        <p class="description"><?php esc_html_e('Optional catalogue order.', 'tomschooloflife-plugin'); ?></p>
                    </div>
                <?php endif; ?>
            </div>

            <div class="tsol-library-checks">
                <label><input type="checkbox" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[featured]" value="1" <?php checked($featured); ?> /> <?php esc_html_e('Featured', 'tomschooloflife-plugin'); ?></label>
                <label><input type="checkbox" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[current]" value="1" <?php checked($current); ?> /> <?php esc_html_e('Current/recommended version', 'tomschooloflife-plugin'); ?></label>
            </div>

            <?php if (TSOL_Library_Content_Model::SERIES_POST_TYPE === $post->post_type) : ?>
                <?php
                $item_label = (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_ITEM_LABEL, true) ?: 'episode';
                $item_label_plural = (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_ITEM_LABEL_PLURAL, true) ?: 'episodes';
                $series_sort = (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_SORT, true) ?: 'desc';
                $ongoing = (bool) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_ONGOING, true);
                ?>
                <hr />
                <h3><?php esc_html_e('Series settings', 'tomschooloflife-plugin'); ?></h3>
                <div class="tsol-library-field-grid">
                    <div class="tsol-library-field">
                        <label for="tsol-library-series-item-label"><?php esc_html_e('Item label', 'tomschooloflife-plugin'); ?></label>
                        <input type="text" id="tsol-library-series-item-label" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_item_label]" value="<?php echo esc_attr($item_label); ?>" />
                    </div>
                    <div class="tsol-library-field">
                        <label for="tsol-library-series-item-label-plural"><?php esc_html_e('Plural label', 'tomschooloflife-plugin'); ?></label>
                        <input type="text" id="tsol-library-series-item-label-plural" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_item_label_plural]" value="<?php echo esc_attr($item_label_plural); ?>" />
                    </div>
                    <div class="tsol-library-field">
                        <label for="tsol-library-series-sort"><?php esc_html_e('Library page order', 'tomschooloflife-plugin'); ?></label>
                        <select id="tsol-library-series-sort" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_sort]">
                            <option value="desc" <?php selected($series_sort, 'desc'); ?>><?php esc_html_e('Newest first', 'tomschooloflife-plugin'); ?></option>
                            <option value="asc" <?php selected($series_sort, 'asc'); ?>><?php esc_html_e('Oldest first', 'tomschooloflife-plugin'); ?></option>
                        </select>
                    </div>
                </div>
                <label><input type="checkbox" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_ongoing]" value="1" <?php checked($ongoing); ?> /> <?php esc_html_e('This is an ongoing series', 'tomschooloflife-plugin'); ?></label>
            <?php endif; ?>

            <?php if (TSOL_Library_Content_Model::ITEM_POST_TYPE === $post->post_type) : ?>
                <?php
                $course_id = (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_COURSE_ID, true);
                $series_id = (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_ID, true);
                if ($series_id <= 0 && isset($_GET['tsol_series_id'])) {
                    $requested_series_id = absint($_GET['tsol_series_id']);
                    if (TSOL_Library_Content_Model::SERIES_POST_TYPE === get_post_type($requested_series_id)) {
                        $series_id = $requested_series_id;
                    }
                }
                if ($course_id <= 0 && isset($_GET['tsol_course_id'])) {
                    $requested_course_id = absint($_GET['tsol_course_id']);
                    if (TSOL_Library_Content_Model::COURSE_POST_TYPE === get_post_type($requested_course_id)) {
                        $course_id = $requested_course_id;
                    }
                }
                $section_key = sanitize_key((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SECTION_KEY, true));
                $series_group_key = sanitize_key((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_GROUP_KEY, true));
                $requested_structure_key = isset($_GET['tsol_structure_key']) ? sanitize_key(wp_unslash($_GET['tsol_structure_key'])) : '';
                if ('' !== $requested_structure_key
                    && !metadata_exists('post', $post->ID, TSOL_Library_Content_Model::META_COURSE_ID)
                    && !metadata_exists('post', $post->ID, TSOL_Library_Content_Model::META_SERIES_ID)
                ) {
                    if ($course_id > 0) {
                        $section_key = $requested_structure_key;
                    } elseif ($series_id > 0) {
                        $series_group_key = $requested_structure_key;
                    }
                }
                $courses = get_posts(array(
                    'post_type' => TSOL_Library_Content_Model::COURSE_POST_TYPE,
                    'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
                    'numberposts' => -1,
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'suppress_filters' => true,
                ));
                $series = get_posts(array(
                    'post_type' => TSOL_Library_Content_Model::SERIES_POST_TYPE,
                    'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
                    'numberposts' => -1,
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'suppress_filters' => true,
                ));
                $course_groups = array();
                foreach ($courses as $course) {
                    $course_groups[(int) $course->ID] = TSOL_Library_Structure::group_options((int) $course->ID);
                }
                $series_groups = array();
                foreach ($series as $series_entry) {
                    $series_groups[(int) $series_entry->ID] = TSOL_Library_Structure::group_options((int) $series_entry->ID);
                }
                $placement_type = $course_id > 0 ? 'course' : ($series_id > 0 ? 'series' : 'standalone');
                ?>
                <hr />
                <h3><?php esc_html_e('Structure placement', 'tomschooloflife-plugin'); ?></h3>
                <p class="description"><?php esc_html_e('Choose where this content appears. Ordering and group names are managed in the parent structure builder.', 'tomschooloflife-plugin'); ?></p>
                <div class="tsol-library-placement" data-library-placement data-saved-placement="<?php echo esc_attr($placement_type); ?>" data-saved-parent-id="<?php echo esc_attr((string) ($course_id > 0 ? $course_id : $series_id)); ?>">
                    <div class="tsol-library-field">
                        <label for="tsol-library-placement-type"><?php esc_html_e('Placement', 'tomschooloflife-plugin'); ?></label>
                        <select id="tsol-library-placement-type" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[placement_type]" data-placement-type>
                            <option value="standalone" <?php selected($placement_type, 'standalone'); ?>><?php esc_html_e('Standalone content', 'tomschooloflife-plugin'); ?></option>
                            <option value="course" <?php selected($placement_type, 'course'); ?>><?php esc_html_e('Course lesson', 'tomschooloflife-plugin'); ?></option>
                            <option value="series" <?php selected($placement_type, 'series'); ?>><?php esc_html_e('Series episode', 'tomschooloflife-plugin'); ?></option>
                        </select>
                    </div>

                    <div class="tsol-library-placement__panel" data-placement-panel="course">
                        <div class="tsol-library-field">
                            <label for="tsol-library-course-id"><?php esc_html_e('Course', 'tomschooloflife-plugin'); ?></label>
                            <select id="tsol-library-course-id" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[course_id]" data-placement-parent="course">
                                <option value="0"><?php esc_html_e('Select a course', 'tomschooloflife-plugin'); ?></option>
                                <?php foreach ($courses as $course) : ?>
                                    <option value="<?php echo esc_attr((string) $course->ID); ?>" data-library-parent-slug="<?php echo esc_attr((string) ($course->post_name ?: sanitize_title($course->post_title))); ?>" <?php selected($course_id, (int) $course->ID); ?>><?php echo esc_html($course->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tsol-library-field">
                            <label for="tsol-library-section-key"><?php esc_html_e('Section', 'tomschooloflife-plugin'); ?></label>
                            <select id="tsol-library-section-key" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[section_key]" data-placement-group="course" data-selected-key="<?php echo esc_attr($section_key); ?>"></select>
                            <p class="description" data-placement-empty="course" hidden><?php esc_html_e('A “Course content” section will be created when this item is saved.', 'tomschooloflife-plugin'); ?></p>
                        </div>
                    </div>

                    <div class="tsol-library-placement__panel" data-placement-panel="series">
                        <div class="tsol-library-field">
                            <label for="tsol-library-series-id"><?php esc_html_e('Series', 'tomschooloflife-plugin'); ?></label>
                            <select id="tsol-library-series-id" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_id]" data-placement-parent="series">
                                <option value="0"><?php esc_html_e('Select a series', 'tomschooloflife-plugin'); ?></option>
                                <?php foreach ($series as $series_entry) : ?>
                                    <option value="<?php echo esc_attr((string) $series_entry->ID); ?>" data-library-parent-slug="<?php echo esc_attr((string) ($series_entry->post_name ?: sanitize_title($series_entry->post_title))); ?>" <?php selected($series_id, (int) $series_entry->ID); ?>><?php echo esc_html($series_entry->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tsol-library-field">
                            <label for="tsol-library-series-group-key"><?php esc_html_e('Group', 'tomschooloflife-plugin'); ?></label>
                            <select id="tsol-library-series-group-key" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_group_key]" data-placement-group="series" data-selected-key="<?php echo esc_attr($series_group_key); ?>"></select>
                            <p class="description" data-placement-empty="series" hidden><?php esc_html_e('A “Series episodes” group will be created when this item is saved.', 'tomschooloflife-plugin'); ?></p>
                        </div>
                    </div>

                    <div class="notice notice-warning inline tsol-library-placement__warning" data-placement-warning hidden>
                        <p><?php esc_html_e('Changing the parent also changes which Library post MemberPress evaluates. Review the effective access panel before publishing.', 'tomschooloflife-plugin'); ?></p>
                    </div>
                    <p data-placement-manage hidden><a class="button" href="#"><?php esc_html_e('Open parent structure builder', 'tomschooloflife-plugin'); ?></a></p>
                    <script type="application/json" data-placement-options><?php echo wp_json_encode(array('course' => $course_groups, 'series' => $series_groups)); ?></script>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_speakers_meta_box($post) {
        $selected_ids = TSOL_Library_Content_Model::direct_speaker_ids($post->ID);
        $speaker_context = TSOL_Library_Content_Model::effective_speaker_context($post->ID);
        $speaker_mode = (string) $speaker_context['mode'];
        $speaker_mode_explicit = metadata_exists('post', $post->ID, TSOL_Library_Content_Model::META_SPEAKER_MODE);
        $is_item = TSOL_Library_Content_Model::ITEM_POST_TYPE === $post->post_type;
        $parent_id = (int) $speaker_context['parent_id'];
        $parent_title = $parent_id > 0 ? get_the_title($parent_id) : '';
        $parent_edit_url = $parent_id > 0 ? get_edit_post_link($parent_id, 'raw') : '';
        $inherited_speakers = array();
        if ($is_item && $parent_id > 0) {
            foreach (TSOL_Library_Content_Model::direct_speaker_ids($parent_id) as $speaker_id) {
                $inherited_speaker = get_post($speaker_id);
                if ($inherited_speaker instanceof WP_Post
                    && TSOL_Library_Content_Model::SPEAKER_POST_TYPE === $inherited_speaker->post_type
                    && 'trash' !== $inherited_speaker->post_status
                ) {
                    $inherited_speakers[] = $inherited_speaker;
                }
            }
        }
        $speakers = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::SPEAKER_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'suppress_filters' => true,
        ));
        $speakers_by_id = array();
        foreach ($speakers as $speaker) {
            $speakers_by_id[(int) $speaker->ID] = $speaker;
        }
        $ordered_speakers = array();
        foreach ($selected_ids as $selected_id) {
            if (isset($speakers_by_id[$selected_id])) {
                $ordered_speakers[] = $speakers_by_id[$selected_id];
                unset($speakers_by_id[$selected_id]);
            }
        }
        foreach ($speakers as $speaker) {
            if (isset($speakers_by_id[(int) $speaker->ID])) {
                $ordered_speakers[] = $speaker;
            }
        }
        $search_id = 'tsol-library-speaker-search-' . (int) $post->ID;
        $results_id = 'tsol-library-speaker-results-' . (int) $post->ID;
        ?>
        <div
            class="tsol-library-speaker-picker"
            data-speaker-picker
            data-speaker-saved-parent-id="<?php echo esc_attr((string) $parent_id); ?>"
            data-speaker-mode-explicit="<?php echo $speaker_mode_explicit ? '1' : '0'; ?>"
        >
            <?php if ($is_item) : ?>
                <fieldset class="tsol-library-speaker-picker__mode" data-speaker-mode-controls>
                    <legend><?php esc_html_e('Speaker source', 'tomschooloflife-plugin'); ?></legend>
                    <label>
                        <input
                            type="radio"
                            name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[speaker_mode]"
                            value="<?php echo esc_attr(TSOL_Library_Content_Model::SPEAKER_MODE_INHERIT); ?>"
                            data-speaker-mode
                            data-speaker-inherit-mode
                            <?php checked(TSOL_Library_Content_Model::SPEAKER_MODE_INHERIT, $speaker_mode); ?>
                            <?php disabled(0 === $parent_id); ?>
                        />
                        <span>
                            <strong><?php esc_html_e('Inherit from parent', 'tomschooloflife-plugin'); ?></strong>
                            <small><?php esc_html_e('Uses the ordered Speakers assigned to the saved Course or Series.', 'tomschooloflife-plugin'); ?></small>
                        </span>
                    </label>
                    <label>
                        <input
                            type="radio"
                            name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[speaker_mode]"
                            value="<?php echo esc_attr(TSOL_Library_Content_Model::SPEAKER_MODE_DIRECT); ?>"
                            data-speaker-mode
                            <?php checked(TSOL_Library_Content_Model::SPEAKER_MODE_DIRECT, $speaker_mode); ?>
                        />
                        <span>
                            <strong><?php esc_html_e('Choose speakers for this content', 'tomschooloflife-plugin'); ?></strong>
                            <small><?php esc_html_e('Overrides the parent for this video. Select every presenter in display order.', 'tomschooloflife-plugin'); ?></small>
                        </span>
                    </label>
                    <label>
                        <input
                            type="radio"
                            name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[speaker_mode]"
                            value="<?php echo esc_attr(TSOL_Library_Content_Model::SPEAKER_MODE_NONE); ?>"
                            data-speaker-mode
                            <?php checked(TSOL_Library_Content_Model::SPEAKER_MODE_NONE, $speaker_mode); ?>
                        />
                        <span>
                            <strong><?php esc_html_e('No presenter', 'tomschooloflife-plugin'); ?></strong>
                            <small><?php esc_html_e('Explicitly suppresses inherited attribution for this video.', 'tomschooloflife-plugin'); ?></small>
                        </span>
                    </label>
                </fieldset>

                <div class="tsol-library-speaker-picker__inherited" data-speaker-inherited-panel<?php echo TSOL_Library_Content_Model::SPEAKER_MODE_INHERIT === $speaker_mode ? '' : ' hidden'; ?>>
                    <div class="tsol-library-speaker-picker__source">
                        <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                        <span>
                            <?php if ($parent_id > 0) : ?>
                                <strong><?php echo esc_html(sprintf(__('Inherited from %s', 'tomschooloflife-plugin'), (string) $speaker_context['parent_label'])); ?></strong>
                                <?php if ($parent_edit_url) : ?>
                                    <a href="<?php echo esc_url($parent_edit_url); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html($parent_title); ?>
                                        <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'tomschooloflife-plugin'); ?></span>
                                    </a>
                                <?php else : ?>
                                    <span><?php echo esc_html($parent_title); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <strong><?php esc_html_e('No saved parent', 'tomschooloflife-plugin'); ?></strong>
                            <?php endif; ?>
                        </span>
                    </div>
                    <p class="tsol-library-speaker-picker__refresh" hidden data-speaker-inherited-refresh>
                        <?php esc_html_e('Save this content to refresh Speakers from the selected parent.', 'tomschooloflife-plugin'); ?>
                    </p>
                    <div data-speaker-inherited-preview>
                        <?php if (!empty($inherited_speakers)) : ?>
                            <ul class="tsol-library-speaker-picker__selected tsol-library-speaker-picker__selected--inherited">
                                <?php foreach ($inherited_speakers as $inherited_speaker) : ?>
                                    <?php $this->render_inherited_speaker_card($inherited_speaker); ?>
                                <?php endforeach; ?>
                            </ul>
                        <?php else : ?>
                            <p class="tsol-library-speaker-picker__none">
                                <?php esc_html_e('The saved parent has no Speakers. Add them to the parent or choose an override here.', 'tomschooloflife-plugin'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tsol-library-speaker-picker__suppressed" data-speaker-none-panel<?php echo TSOL_Library_Content_Model::SPEAKER_MODE_NONE === $speaker_mode ? '' : ' hidden'; ?>>
                    <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                    <p><strong><?php esc_html_e('No presenter will be shown.', 'tomschooloflife-plugin'); ?></strong><br /><?php esc_html_e('This content will not inherit Speakers even when its parent has them.', 'tomschooloflife-plugin'); ?></p>
                </div>
            <?php endif; ?>

            <div data-speaker-direct-panel<?php echo !$is_item || TSOL_Library_Content_Model::SPEAKER_MODE_DIRECT === $speaker_mode ? '' : ' hidden'; ?>>
                <?php if (empty($speakers)) : ?>
                    <p class="tsol-library-speaker-picker__empty"><?php esc_html_e('No speaker profiles exist yet.', 'tomschooloflife-plugin'); ?></p>
                <?php endif; ?>

                <?php if (!empty($speakers)) : ?>
                    <div class="tsol-library-speaker-picker__native" data-speaker-native>
                        <label for="tsol-library-speaker-ids"><?php esc_html_e('Select speakers', 'tomschooloflife-plugin'); ?></label>
                        <select id="tsol-library-speaker-ids" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[speaker_ids][]" multiple size="6">
                        <?php foreach ($ordered_speakers as $speaker) : ?>
                            <?php
                            $speaker_id = (int) $speaker->ID;
                            $status = get_post_status_object($speaker->post_status);
                            $status_label = 'publish' === $speaker->post_status || !$status ? '' : (string) $status->label;
                            $thumbnail_id = (int) get_post_thumbnail_id($speaker_id);
                            $image_url = $thumbnail_id > 0
                                ? wp_get_attachment_image_url($thumbnail_id, TSOL_Library_Content_Model::speaker_image_display_size($thumbnail_id))
                                : '';
                            ?>
                            <option
                                value="<?php echo esc_attr((string) $speaker_id); ?>"
                                data-speaker-name="<?php echo esc_attr((string) $speaker->post_title); ?>"
                                data-speaker-job-title="<?php echo esc_attr((string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_JOB_TITLE, true)); ?>"
                                data-speaker-organization="<?php echo esc_attr((string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_ORGANIZATION, true)); ?>"
                                data-speaker-status="<?php echo esc_attr((string) $speaker->post_status); ?>"
                                data-speaker-status-label="<?php echo esc_attr($status_label); ?>"
                                data-speaker-image="<?php echo esc_url((string) $image_url); ?>"
                                data-speaker-edit-url="<?php echo esc_url((string) get_edit_post_link($speaker_id, 'raw')); ?>"
                                <?php selected(in_array($speaker_id, $selected_ids, true)); ?>
                            >
                                <?php echo esc_html((string) $speaker->post_title); ?><?php echo '' === $status_label ? '' : esc_html(' — ' . $status_label); ?>
                            </option>
                        <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Hold Command (Mac) or Control (Windows) to select more than one.', 'tomschooloflife-plugin'); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($speakers)) : ?>
                    <div class="tsol-library-speaker-picker__enhanced" data-speaker-enhanced>
                        <label for="<?php echo esc_attr($search_id); ?>"><?php esc_html_e('Search speakers', 'tomschooloflife-plugin'); ?></label>
                        <div class="tsol-library-speaker-picker__search-wrap">
                            <span class="dashicons dashicons-search" aria-hidden="true"></span>
                            <input
                                type="search"
                                id="<?php echo esc_attr($search_id); ?>"
                                class="tsol-library-speaker-picker__search"
                                placeholder="<?php esc_attr_e('Search by name, job title, or organisation…', 'tomschooloflife-plugin'); ?>"
                                autocomplete="off"
                                role="combobox"
                                aria-autocomplete="list"
                                aria-expanded="false"
                                aria-controls="<?php echo esc_attr($results_id); ?>"
                                data-speaker-search
                            />
                        </div>
                        <div
                            id="<?php echo esc_attr($results_id); ?>"
                            class="tsol-library-speaker-picker__results"
                            role="listbox"
                            hidden
                            data-speaker-results
                        ></div>

                        <div class="tsol-library-speaker-picker__selected-heading">
                            <strong><?php esc_html_e('Selected speakers', 'tomschooloflife-plugin'); ?></strong>
                            <span data-speaker-count></span>
                        </div>
                        <p class="tsol-library-speaker-picker__none" data-speaker-none><?php esc_html_e('No speakers selected.', 'tomschooloflife-plugin'); ?></p>
                        <ul class="tsol-library-speaker-picker__selected" data-speaker-selected></ul>
                        <span class="screen-reader-text" aria-live="polite" aria-atomic="true" data-speaker-announcer></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (current_user_can('edit_pages')) : ?>
                <p class="tsol-library-speaker-picker__add">
                    <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . TSOL_Library_Content_Model::SPEAKER_POST_TYPE)); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Add new speaker', 'tomschooloflife-plugin'); ?>
                        <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'tomschooloflife-plugin'); ?></span>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_inherited_speaker_card(WP_Post $speaker) {
        $speaker_id = (int) $speaker->ID;
        $name = '' !== trim((string) $speaker->post_title) ? (string) $speaker->post_title : __('(no name)', 'tomschooloflife-plugin');
        $status = get_post_status_object($speaker->post_status);
        $status_label = 'publish' === $speaker->post_status || !$status ? '' : (string) $status->label;
        $thumbnail_id = (int) get_post_thumbnail_id($speaker_id);
        $image_url = $thumbnail_id > 0
            ? wp_get_attachment_image_url($thumbnail_id, TSOL_Library_Content_Model::speaker_image_display_size($thumbnail_id))
            : '';
        $words = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        $first_character = static function ($value) {
            return function_exists('mb_substr') ? mb_substr((string) $value, 0, 1) : substr((string) $value, 0, 1);
        };
        $initials = empty($words)
            ? '?'
            : $first_character((string) $words[0]) . (count($words) > 1 ? $first_character((string) end($words)) : '');
        $initials = function_exists('mb_strtoupper') ? mb_strtoupper($initials) : strtoupper($initials);
        ?>
        <li class="tsol-library-speaker-picker__card tsol-library-speaker-picker__card--inherited">
            <span class="tsol-library-speaker-picker__avatar" aria-hidden="true">
                <?php if ($image_url) : ?>
                    <img src="<?php echo esc_url((string) $image_url); ?>" alt="" />
                <?php else : ?>
                    <?php echo esc_html($initials); ?>
                <?php endif; ?>
            </span>
            <span class="tsol-library-speaker-picker__identity">
                <strong><?php echo esc_html($name); ?></strong>
                <?php $job_title = (string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_JOB_TITLE, true); ?>
                <?php $organization = (string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_ORGANIZATION, true); ?>
                <?php if ('' !== $job_title) : ?><span class="tsol-library-speaker-picker__job-title"><?php echo esc_html($job_title); ?></span><?php endif; ?>
                <?php if ('' !== $organization) : ?><span class="tsol-library-speaker-picker__organization"><?php echo esc_html($organization); ?></span><?php endif; ?>
                <?php if ('' !== $status_label) : ?><span class="tsol-library-speaker-picker__status"><?php echo esc_html($status_label); ?></span><?php endif; ?>
            </span>
            <?php $edit_url = get_edit_post_link($speaker_id, 'raw'); ?>
            <?php if ($edit_url) : ?>
                <span class="tsol-library-speaker-picker__actions">
                    <a href="<?php echo esc_url($edit_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Edit', 'tomschooloflife-plugin'); ?>
                        <span class="screen-reader-text"><?php echo esc_html(sprintf(__(' %s (opens in a new tab)', 'tomschooloflife-plugin'), $name)); ?></span>
                    </a>
                </span>
            <?php endif; ?>
        </li>
        <?php
    }

    public function render_curriculum_meta_box($post) {
        $structure_admin = new TSOL_Library_Structure_Admin();
        $structure_admin->render_compact_summary($post);
    }

    public function render_series_episodes_meta_box($post) {
        $structure_admin = new TSOL_Library_Structure_Admin();
        $structure_admin->render_compact_summary($post);
    }

    public function render_media_meta_box($post) {
        $assets = get_post_meta($post->ID, TSOL_Library_Content_Model::META_MEDIA_ASSETS, true);
        $assets = is_array($assets) && !empty($assets) ? $assets : array(array());
        ?>
        <div class="tsol-library-media-editor" data-library-media-editor>
            <div class="tsol-library-section-intro">
                <div>
                    <p><?php esc_html_e('Paste one stable media URL. WordPress will infer the provider, video ID, private Vimeo reference, or attachment automatically.', 'tomschooloflife-plugin'); ?></p>
                    <p class="description"><?php esc_html_e('Multiple assets are supported and play in the order shown. Do not paste temporary signed playback URLs.', 'tomschooloflife-plugin'); ?></p>
                </div>
                <button type="button" class="button button-secondary" data-media-add><?php esc_html_e('Add media', 'tomschooloflife-plugin'); ?></button>
            </div>

            <div class="tsol-library-repeater" data-media-rows>
                <?php foreach (array_values($assets) as $index => $asset) : ?>
                    <?php $this->render_media_row($index, is_array($asset) ? $asset : array()); ?>
                <?php endforeach; ?>
            </div>

            <script type="text/html" data-media-template>
                <?php $this->render_media_row('__index__', array()); ?>
            </script>
        </div>
        <?php
    }

    public function render_resources_meta_box($post) {
        $resources = get_post_meta($post->ID, TSOL_Library_Content_Model::META_RESOURCES, true);
        $resources = is_array($resources) && !empty($resources) ? $resources : array(array());
        ?>
        <div class="tsol-library-resource-editor" data-library-resource-editor>
            <div class="tsol-library-section-intro">
                <div>
                    <p><?php esc_html_e('Add worksheets, downloads, reference links, or other supporting material.', 'tomschooloflife-plugin'); ?></p>
                    <p class="description"><?php esc_html_e('Resources are returned only through the protected Library content endpoint.', 'tomschooloflife-plugin'); ?></p>
                </div>
                <button type="button" class="button button-secondary" data-resource-add><?php esc_html_e('Add resource', 'tomschooloflife-plugin'); ?></button>
            </div>

            <div class="tsol-library-repeater" data-resource-rows>
                <?php foreach (array_values($resources) as $index => $resource) : ?>
                    <?php $this->render_resource_row($index, is_array($resource) ? $resource : array()); ?>
                <?php endforeach; ?>
            </div>

            <script type="text/html" data-resource-template>
                <?php $this->render_resource_row('__index__', array()); ?>
            </script>
        </div>
        <?php
    }

    public function render_protection_meta_box($post) {
        $authorization_post_id = (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
        if ($authorization_post_id <= 0) {
            $authorization_post_id = (int) $post->ID;
        }
        $authorization_post = get_post($authorization_post_id);
        $rules = array();
        if ($authorization_post && class_exists('MeprRule')) {
            $rules = MeprRule::get_rules($authorization_post);
        }
        $is_protected = !empty($rules);
        ?>
        <div class="tsol-library-protection <?php echo $is_protected ? 'is-protected' : 'is-open'; ?>">
            <span class="tsol-library-protection__status">
                <span class="dashicons <?php echo $is_protected ? 'dashicons-lock' : 'dashicons-unlock'; ?>" aria-hidden="true"></span>
                <?php echo $is_protected ? esc_html__('Protected by MemberPress', 'tomschooloflife-plugin') : esc_html__('No MemberPress rule applies', 'tomschooloflife-plugin'); ?>
            </span>

            <?php if ($is_protected) : ?>
                <p><?php esc_html_e('The Library asks WordPress to evaluate these effective rules at access time:', 'tomschooloflife-plugin'); ?></p>
                <ul>
                    <?php foreach ($rules as $rule) : ?>
                        <li>
                            <?php if (current_user_can('manage_options')) : ?>
                                <a href="<?php echo esc_url(get_edit_post_link($rule->ID)); ?>"><?php echo esc_html(get_the_title($rule->ID)); ?></a>
                            <?php else : ?>
                                <?php echo esc_html(get_the_title($rule->ID)); ?>
                            <?php endif; ?>
                            <code>#<?php echo esc_html((string) $rule->ID); ?></code>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p><?php esc_html_e('WordPress and MemberPress currently treat this content as unrestricted. Any signed-in Library user can open its full media.', 'tomschooloflife-plugin'); ?></p>
                <?php if (current_user_can('manage_options')) : ?>
                    <p><a class="button button-small" href="<?php echo esc_url(admin_url('edit.php?post_type=memberpressrule')); ?>"><?php esc_html_e('Manage MemberPress rules', 'tomschooloflife-plugin'); ?></a></p>
                <?php endif; ?>
            <?php endif; ?>

            <p class="description"><?php esc_html_e('Administrators retain access through their WordPress capability. Access is not configured in this box.', 'tomschooloflife-plugin'); ?></p>
        </div>
        <?php
    }

    public function render_provenance_meta_box($post) {
        $source_id = (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID, true);
        $source_type = (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_LEGACY_SOURCE_TYPE, true);
        $migration_version = (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_MIGRATION_VERSION, true);
        $source_edit_url = $source_id > 0 && current_user_can('edit_post', $source_id) ? get_edit_post_link($source_id) : '';
        ?>
        <dl class="tsol-library-provenance">
            <div><dt><?php esc_html_e('Source ID', 'tomschooloflife-plugin'); ?></dt><dd><code><?php echo esc_html((string) $source_id); ?></code></dd></div>
            <div><dt><?php esc_html_e('Source type', 'tomschooloflife-plugin'); ?></dt><dd><?php echo esc_html($source_type); ?></dd></div>
            <div><dt><?php esc_html_e('Migration version', 'tomschooloflife-plugin'); ?></dt><dd><?php echo esc_html($migration_version); ?></dd></div>
        </dl>
        <?php if ($source_edit_url) : ?>
            <p><a class="button button-small" href="<?php echo esc_url($source_edit_url); ?>"><?php esc_html_e('Edit legacy source', 'tomschooloflife-plugin'); ?></a></p>
        <?php endif; ?>
        <p class="description"><?php esc_html_e('Read-only migration provenance. Titles and slugs are never used as identity.', 'tomschooloflife-plugin'); ?></p>
        <?php
    }

    public function save_post($post_id, $post) {
        if (self::$updating_status || !$post instanceof WP_Post || !self::supports_post_type($post->post_type)) {
            return;
        }
        if (wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        $payload = isset($_POST[self::PAYLOAD_NAME]) && is_array($_POST[self::PAYLOAD_NAME])
            ? wp_unslash($_POST[self::PAYLOAD_NAME])
            : array();
        $content_type = isset($payload['content_type']) ? sanitize_key($payload['content_type']) : '';
        $allowed_types = array_keys($this->content_type_options($post->post_type));
        if (!in_array($content_type, $allowed_types, true)) {
            $content_type = '';
        }

        $media_result = $this->sanitize_media_rows(isset($payload['media_assets']) ? $payload['media_assets'] : array());
        $resource_result = $this->sanitize_resource_rows(isset($payload['resources']) ? $payload['resources'] : array());
        $errors = array_merge($media_result['errors'], $resource_result['errors']);
        $course_id = 0;
        $series_id = 0;

        if ('' === $content_type) {
            $errors[] = __('Choose a Library content type.', 'tomschooloflife-plugin');
        }
        if (
            'publish' === $post->post_status
            && TSOL_Library_Content_Model::ITEM_POST_TYPE === $post->post_type
            && empty($media_result['items'])
        ) {
            $errors[] = __('Published Library Items and lessons require at least one valid media URL.', 'tomschooloflife-plugin');
        }

        update_post_meta($post_id, TSOL_Library_Content_Model::META_CONTENT_TYPE, $content_type);
        if (isset($payload['position']) || TSOL_Library_Content_Model::ITEM_POST_TYPE !== $post->post_type) {
            update_post_meta($post_id, TSOL_Library_Content_Model::META_POSITION, isset($payload['position']) ? absint($payload['position']) : 0);
        }
        update_post_meta($post_id, TSOL_Library_Content_Model::META_FEATURED, !empty($payload['featured']));
        update_post_meta($post_id, TSOL_Library_Content_Model::META_CURRENT, !empty($payload['current']));
        if (!metadata_exists('post', $post_id, TSOL_Library_Content_Model::META_UUID)) {
            update_post_meta($post_id, TSOL_Library_Content_Model::META_UUID, wp_generate_uuid4());
        }
        $speaker_ids = isset($payload['speaker_ids']) && is_array($payload['speaker_ids'])
            ? array_values(array_unique(array_filter(array_map('absint', $payload['speaker_ids']))))
            : array();
        $speaker_ids = array_values(array_filter($speaker_ids, static function ($speaker_id) {
            $speaker = get_post((int) $speaker_id);
            return $speaker instanceof WP_Post
                && TSOL_Library_Content_Model::SPEAKER_POST_TYPE === $speaker->post_type
                && 'trash' !== $speaker->post_status;
        }));
        if (TSOL_Library_Content_Model::ITEM_POST_TYPE === $post->post_type) {
            $speaker_mode = isset($payload['speaker_mode']) ? sanitize_key((string) $payload['speaker_mode']) : '';
            $has_requested_parent = absint($payload['course_id'] ?? 0) > 0 || absint($payload['series_id'] ?? 0) > 0;
            if (!in_array($speaker_mode, array(
                TSOL_Library_Content_Model::SPEAKER_MODE_INHERIT,
                TSOL_Library_Content_Model::SPEAKER_MODE_DIRECT,
                TSOL_Library_Content_Model::SPEAKER_MODE_NONE,
            ), true)) {
                $speaker_mode = !empty($speaker_ids)
                    ? TSOL_Library_Content_Model::SPEAKER_MODE_DIRECT
                    : ($has_requested_parent
                        ? TSOL_Library_Content_Model::SPEAKER_MODE_INHERIT
                        : TSOL_Library_Content_Model::SPEAKER_MODE_NONE);
            }
            if (TSOL_Library_Content_Model::SPEAKER_MODE_INHERIT === $speaker_mode && !$has_requested_parent) {
                $speaker_mode = TSOL_Library_Content_Model::SPEAKER_MODE_NONE;
            }
            if (TSOL_Library_Content_Model::SPEAKER_MODE_DIRECT === $speaker_mode && empty($speaker_ids)) {
                $speaker_mode = TSOL_Library_Content_Model::SPEAKER_MODE_NONE;
            }
            if (TSOL_Library_Content_Model::SPEAKER_MODE_DIRECT !== $speaker_mode) {
                $speaker_ids = array();
            }
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SPEAKER_MODE, $speaker_mode);
        } else {
            delete_post_meta($post_id, TSOL_Library_Content_Model::META_SPEAKER_MODE);
        }
        delete_post_meta($post_id, TSOL_Library_Content_Model::META_SPEAKER_IDS);
        foreach ($speaker_ids as $speaker_id) {
            add_post_meta($post_id, TSOL_Library_Content_Model::META_SPEAKER_IDS, $speaker_id, false);
        }
        if (TSOL_Library_Content_Model::SERIES_POST_TYPE === $post->post_type) {
            $series_sort = isset($payload['series_sort']) && 'asc' === sanitize_key($payload['series_sort']) ? 'asc' : 'desc';
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SERIES_ITEM_LABEL, isset($payload['series_item_label']) ? sanitize_text_field($payload['series_item_label']) : 'episode');
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SERIES_ITEM_LABEL_PLURAL, isset($payload['series_item_label_plural']) ? sanitize_text_field($payload['series_item_label_plural']) : 'episodes');
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SERIES_SORT, $series_sort);
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SERIES_ONGOING, !empty($payload['series_ongoing']));
        }
        if (TSOL_Library_Content_Model::ITEM_POST_TYPE === $post->post_type) {
            update_post_meta($post_id, TSOL_Library_Content_Model::META_MEDIA_ASSETS, $media_result['items']);
            update_post_meta($post_id, TSOL_Library_Content_Model::META_RESOURCES, $resource_result['items']);

            $old_course_id = (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_COURSE_ID, true);
            $old_series_id = (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_SERIES_ID, true);
            $old_section_key = sanitize_key((string) get_post_meta($post_id, TSOL_Library_Content_Model::META_SECTION_KEY, true));
            $old_series_group_key = sanitize_key((string) get_post_meta($post_id, TSOL_Library_Content_Model::META_SERIES_GROUP_KEY, true));

            $placement_type = isset($payload['placement_type']) ? sanitize_key((string) $payload['placement_type']) : '';
            $course_id = isset($payload['course_id']) ? absint($payload['course_id']) : 0;
            $series_id = isset($payload['series_id']) ? absint($payload['series_id']) : 0;
            if ('standalone' === $placement_type) {
                $course_id = 0;
                $series_id = 0;
            } elseif ('course' === $placement_type) {
                $series_id = 0;
            } elseif ('series' === $placement_type) {
                $course_id = 0;
            }
            if ($course_id > 0 && TSOL_Library_Content_Model::COURSE_POST_TYPE !== get_post_type($course_id)) {
                $course_id = 0;
            }
            if ($series_id > 0 && TSOL_Library_Content_Model::SERIES_POST_TYPE !== get_post_type($series_id)) {
                $series_id = 0;
            }
            if ($course_id > 0 && $series_id > 0) {
                $errors[] = __('Content cannot belong to a course and a series at the same time. The series placement was removed.', 'tomschooloflife-plugin');
                $series_id = 0;
            }
            $section_key = isset($payload['section_key'])
                ? sanitize_key((string) $payload['section_key'])
                : $old_section_key;
            if ('' === $placement_type && $course_id > 0 && isset($payload['section_title'])) {
                $legacy_title = sanitize_text_field((string) $payload['section_title']);
                if ('' === $section_key) {
                    $section_key = sanitize_key('section-' . ('' !== $legacy_title ? $legacy_title : 'course-content'));
                }
                $this->ensure_legacy_structure_group(
                    $course_id,
                    $section_key,
                    '' !== $legacy_title ? $legacy_title : __('Course content', 'tomschooloflife-plugin'),
                    isset($payload['section_position']) ? absint($payload['section_position']) : 1
                );
            }
            $section = $course_id > 0 ? $this->resolve_structure_group($course_id, $section_key) : null;
            $section_key = is_array($section) ? (string) $section['key'] : '';
            $section_title = is_array($section) ? (string) $section['title'] : '';
            $section_position = is_array($section) ? (int) $section['position'] : 0;
            if ($course_id <= 0) {
                $section_key = '';
                $section_title = '';
                $section_position = 0;
            }
            update_post_meta($post_id, TSOL_Library_Content_Model::META_COURSE_ID, $course_id);
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SECTION_KEY, $section_key);
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SECTION_TITLE, $section_title);
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SECTION_POSITION, $section_position);

            $series_group_key = isset($payload['series_group_key'])
                ? sanitize_key((string) $payload['series_group_key'])
                : $old_series_group_key;
            if ('' === $placement_type && $series_id > 0 && isset($payload['series_group_title'])) {
                $legacy_title = sanitize_text_field((string) $payload['series_group_title']);
                if ('' === $series_group_key) {
                    $series_group_key = sanitize_key('group-' . ('' !== $legacy_title ? $legacy_title : 'episodes'));
                }
                $this->ensure_legacy_structure_group(
                    $series_id,
                    $series_group_key,
                    '' !== $legacy_title ? $legacy_title : __('Series episodes', 'tomschooloflife-plugin'),
                    isset($payload['series_group_position']) ? absint($payload['series_group_position']) : 1
                );
            }
            $series_group = $series_id > 0 ? $this->resolve_structure_group($series_id, $series_group_key) : null;
            $series_group_key = is_array($series_group) ? (string) $series_group['key'] : '';
            $series_group_title = is_array($series_group) ? (string) $series_group['title'] : '';
            $series_group_position = is_array($series_group) ? (int) $series_group['position'] : 0;
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SERIES_ID, $series_id);
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SERIES_GROUP_KEY, $series_group_key);
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SERIES_GROUP_TITLE, $series_group_title);
            update_post_meta($post_id, TSOL_Library_Content_Model::META_SERIES_GROUP_POSITION, $series_group_position);

            $placement_changed = $course_id !== $old_course_id
                || $series_id !== $old_series_id
                || $section_key !== $old_section_key
                || $series_group_key !== $old_series_group_key;
            if ($placement_changed || !metadata_exists('post', $post_id, TSOL_Library_Content_Model::META_POSITION)) {
                update_post_meta(
                    $post_id,
                    TSOL_Library_Content_Model::META_POSITION,
                    $this->next_structure_item_position($post_id, $course_id, $series_id, $section_key)
                );
            }
        }

        $migration_version = (string) get_post_meta($post_id, TSOL_Library_Content_Model::META_MIGRATION_VERSION, true);
        $access_migration_state = get_option('tsol_library_access_rules_migration_state', array());
        $native_import_access_active = is_array($access_migration_state)
            && 'activated' === (string) ($access_migration_state['phase'] ?? '');
        $should_follow_native_parent = '' === $migration_version || $native_import_access_active;

        if ($should_follow_native_parent || !metadata_exists('post', $post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID)) {
            $authorization_post_id = (int) $post_id;
            if (TSOL_Library_Content_Model::ITEM_POST_TYPE === $post->post_type) {
                $authorization_post_id = $course_id > 0 ? (int) $course_id : ($series_id > 0 ? (int) $series_id : (int) $post_id);
            }
            update_post_meta($post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, $authorization_post_id);
        }

        if ('publish' === $post->post_status && class_exists('MeprRule')) {
            $authorization_post_id = (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
            $authorization_post = get_post($authorization_post_id > 0 ? $authorization_post_id : $post_id);
            if (!$authorization_post instanceof WP_Post || empty(MeprRule::get_rules($authorization_post))) {
                $errors[] = __('Add a published MemberPress rule before publishing full Library content.', 'tomschooloflife-plugin');
            }
        }

        if (!empty($errors)) {
            if ('publish' === $post->post_status) {
                self::$updating_status = true;
                wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
                self::$updating_status = false;
                array_unshift($errors, __('This entry was kept as a draft because its Library metadata is incomplete.', 'tomschooloflife-plugin'));
            }
            $this->store_notice($post_id, $errors);
        }
    }

    public function render_admin_notice() {
        $screen = get_current_screen();
        if (!$screen || !self::supports_post_type($screen->post_type)) {
            return;
        }

        $post_id = isset($_GET['post']) ? absint($_GET['post']) : 0;
        if ($post_id <= 0) {
            return;
        }
        $key = $this->notice_key($post_id);
        $errors = get_transient($key);
        if (!is_array($errors) || empty($errors)) {
            return;
        }
        delete_transient($key);

        echo '<div class="notice notice-error is-dismissible"><p><strong>';
        echo esc_html__('TSOL Library metadata needs attention:', 'tomschooloflife-plugin');
        echo '</strong></p><ul class="ul-disc">';
        foreach ($errors as $error) {
            echo '<li>' . esc_html($error) . '</li>';
        }
        echo '</ul></div>';
    }

    public function ajax_normalize_media_url() {
        check_ajax_referer(self::AJAX_ACTION, 'nonce');

        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        if (($post_id > 0 && !current_user_can('edit_post', $post_id)) || ($post_id <= 0 && !current_user_can('edit_pages'))) {
            wp_send_json_error(array('message' => __('You cannot edit this Library content.', 'tomschooloflife-plugin')), 403);
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $asset = TSOL_Library_Media_Normalizer::from_url($url);
        if (is_wp_error($asset)) {
            wp_send_json_error(array('message' => $asset->get_error_message()), 422);
        }

        wp_send_json_success(array(
            'kind' => $asset['kind'],
            'provider' => $asset['provider'],
            'provider_label' => $this->provider_label($asset['provider']),
            'provider_id' => $asset['provider_id'],
            'has_privacy_hash' => '' !== $asset['privacy_hash'],
            'attachment_id' => (int) $asset['attachment_id'],
        ));
    }

    private function requested_content_scope() {
        if (isset($_GET[self::CONTENT_SCOPE_FILTER])) {
            $scope = sanitize_key(wp_unslash($_GET[self::CONTENT_SCOPE_FILTER]));
        } else {
            $scope = self::CONTENT_SCOPE_ALL;
        }
        return in_array($scope, array(self::CONTENT_SCOPE_STANDALONE, self::CONTENT_SCOPE_COURSE, self::CONTENT_SCOPE_SERIES, self::CONTENT_SCOPE_ALL), true)
            ? $scope
            : self::CONTENT_SCOPE_ALL;
    }

    private function requested_parent_content_id($scope) {
        if (!in_array($scope, array(self::CONTENT_SCOPE_COURSE, self::CONTENT_SCOPE_SERIES), true)
            || !isset($_GET[self::PARENT_FILTER])
        ) {
            return 0;
        }
        $parent_id = absint(wp_unslash($_GET[self::PARENT_FILTER]));
        $expected_post_type = self::CONTENT_SCOPE_COURSE === $scope
            ? TSOL_Library_Content_Model::COURSE_POST_TYPE
            : TSOL_Library_Content_Model::SERIES_POST_TYPE;
        return $parent_id > 0 && $expected_post_type === get_post_type($parent_id) ? $parent_id : 0;
    }

    private function append_meta_query(WP_Query $query, $clause) {
        $existing = $query->get('meta_query');
        if (empty($existing)) {
            $query->set('meta_query', array($clause));
            return;
        }

        $query->set('meta_query', array(
            'relation' => 'AND',
            $existing,
            $clause,
        ));
    }

    private function content_scope_counts() {
        if (is_array($this->content_scope_count_cache)) {
            return $this->content_scope_count_cache;
        }

        $post_ids = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'none',
            'suppress_filters' => true,
        ));
        $counts = array('standalone' => 0, 'course' => 0, 'series' => 0, 'all' => count($post_ids));
        foreach ($post_ids as $post_id) {
            if ((int) get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_COURSE_ID, true) > 0) {
                ++$counts['course'];
            } elseif ((int) get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_SERIES_ID, true) > 0) {
                ++$counts['series'];
            } else {
                ++$counts['standalone'];
            }
        }

        $this->content_scope_count_cache = $counts;
        return $this->content_scope_count_cache;
    }

    private function parent_content_counts($parent_post_type) {
        $parent_post_type = (string) $parent_post_type;
        if (isset($this->parent_content_count_cache[$parent_post_type])) {
            return $this->parent_content_count_cache[$parent_post_type];
        }

        $meta_key = TSOL_Library_Content_Model::COURSE_POST_TYPE === $parent_post_type
            ? TSOL_Library_Content_Model::META_COURSE_ID
            : TSOL_Library_Content_Model::META_SERIES_ID;
        $counts = array();
        $content_ids = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'none',
            'suppress_filters' => true,
        ));
        foreach (array_map('intval', $content_ids) as $content_id) {
            $parent_id = (int) get_post_meta($content_id, $meta_key, true);
            if ($parent_id > 0) {
                $counts[$parent_id] = isset($counts[$parent_id]) ? $counts[$parent_id] + 1 : 1;
            }
        }

        $this->parent_content_count_cache[$parent_post_type] = $counts;
        return $counts;
    }

    private function content_status_counts($scope, $parent_id = 0) {
        $parent_id = (int) $parent_id;
        $cache_key = (string) $scope . ':' . $parent_id . ':' . get_current_user_id();
        if (isset($this->content_status_count_cache[$cache_key])) {
            return $this->content_status_count_cache[$cache_key];
        }

        $posts = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future', 'trash'),
            'numberposts' => -1,
            'orderby' => 'none',
            'suppress_filters' => true,
        ));
        $counts = array(
            'all' => 0,
            'mine' => 0,
            'publish' => 0,
            'draft' => 0,
            'private' => 0,
            'pending' => 0,
            'future' => 0,
            'trash' => 0,
        );
        foreach ($posts as $post) {
            $course_id = (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_COURSE_ID, true);
            $series_id = (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_ID, true);
            $has_course = $course_id > 0;
            $has_series = $series_id > 0;
            if ((self::CONTENT_SCOPE_STANDALONE === $scope && $has_course)
                || (self::CONTENT_SCOPE_STANDALONE === $scope && $has_series)
                || (self::CONTENT_SCOPE_COURSE === $scope && !$has_course)
                || (self::CONTENT_SCOPE_SERIES === $scope && !$has_series)
                || ($parent_id > 0 && self::CONTENT_SCOPE_COURSE === $scope && $course_id !== $parent_id)
                || ($parent_id > 0 && self::CONTENT_SCOPE_SERIES === $scope && $series_id !== $parent_id)
            ) {
                continue;
            }

            $status = (string) $post->post_status;
            if (isset($counts[$status])) {
                ++$counts[$status];
            }
            if ('trash' !== $status) {
                ++$counts['all'];
                if ((int) $post->post_author === get_current_user_id()) {
                    ++$counts['mine'];
                }
            }
        }

        $this->content_status_count_cache[$cache_key] = $counts;
        return $this->content_status_count_cache[$cache_key];
    }

    public static function supports_post_type($post_type) {
        return in_array((string) $post_type, TSOL_Library_Content_Model::post_types(), true);
    }

    private function render_media_row($index, $asset) {
        $name = self::PAYLOAD_NAME . '[media_assets][' . $index . ']';
        $url = isset($asset['source_url']) ? (string) $asset['source_url'] : '';
        $label = isset($asset['label']) ? (string) $asset['label'] : '';
        $duration = isset($asset['duration_seconds']) ? absint($asset['duration_seconds']) : 0;
        $preview = !empty($asset['preview']);
        $provider = isset($asset['provider']) ? sanitize_key($asset['provider']) : '';
        $provider_id = isset($asset['provider_id']) ? (string) $asset['provider_id'] : '';
        $privacy_hash = isset($asset['privacy_hash']) ? (string) $asset['privacy_hash'] : '';
        $attachment_id = isset($asset['attachment_id']) ? absint($asset['attachment_id']) : 0;
        ?>
        <div class="tsol-library-row tsol-library-media-row <?php echo '' !== $provider ? 'is-normalized' : ''; ?>" data-media-row>
            <div class="tsol-library-row__toolbar">
                <span class="tsol-library-row__handle dashicons dashicons-menu" aria-hidden="true"></span>
                <strong data-media-summary><?php echo esc_html($label ?: __('Untitled media', 'tomschooloflife-plugin')); ?></strong>
                <div class="tsol-library-row__actions">
                    <button type="button" class="button-link" data-row-up aria-label="<?php esc_attr_e('Move media up', 'tomschooloflife-plugin'); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button-link" data-row-down aria-label="<?php esc_attr_e('Move media down', 'tomschooloflife-plugin'); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button-link-delete" data-media-remove><?php esc_html_e('Remove', 'tomschooloflife-plugin'); ?></button>
                </div>
            </div>

            <div class="tsol-library-row__body">
                <div class="tsol-library-field tsol-library-field--wide">
                    <label><?php esc_html_e('Media URL', 'tomschooloflife-plugin'); ?></label>
                    <div class="tsol-library-url-control">
                        <input type="url" name="<?php echo esc_attr($name); ?>[source_url]" value="<?php echo esc_attr($url); ?>" placeholder="https://vimeo.com/…" data-media-url />
                        <button type="button" class="button" data-media-library><?php esc_html_e('Choose media', 'tomschooloflife-plugin'); ?></button>
                    </div>
                </div>

                <div class="tsol-library-media-result" data-media-result aria-live="polite">
                    <?php if ('' !== $provider) : ?>
                        <span class="tsol-library-provider-badge"><?php echo esc_html($this->provider_label($provider)); ?></span>
                        <?php if ('' !== $provider_id) : ?><span><?php esc_html_e('ID', 'tomschooloflife-plugin'); ?> <code><?php echo esc_html($provider_id); ?></code></span><?php endif; ?>
                        <?php if ('' !== $privacy_hash) : ?><span><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e('Private Vimeo reference detected', 'tomschooloflife-plugin'); ?></span><?php endif; ?>
                        <?php if ($attachment_id > 0) : ?><span><?php esc_html_e('WordPress attachment', 'tomschooloflife-plugin'); ?> <code>#<?php echo esc_html((string) $attachment_id); ?></code></span><?php endif; ?>
                    <?php else : ?>
                        <span><?php esc_html_e('Paste a URL to infer its provider details.', 'tomschooloflife-plugin'); ?></span>
                    <?php endif; ?>
                </div>

                <div class="tsol-library-field-grid tsol-library-field-grid--media">
                    <div class="tsol-library-field">
                        <label><?php esc_html_e('Label', 'tomschooloflife-plugin'); ?></label>
                        <input type="text" name="<?php echo esc_attr($name); ?>[label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php esc_attr_e('Optional, e.g. Part 1', 'tomschooloflife-plugin'); ?>" data-media-label />
                    </div>
                    <div class="tsol-library-field">
                        <label><?php esc_html_e('Duration in seconds', 'tomschooloflife-plugin'); ?></label>
                        <input type="number" min="0" step="1" name="<?php echo esc_attr($name); ?>[duration_seconds]" value="<?php echo esc_attr($duration); ?>" />
                    </div>
                    <div class="tsol-library-field tsol-library-field--checkbox">
                        <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[preview]" value="1" <?php checked($preview); ?> /> <?php esc_html_e('Preview media', 'tomschooloflife-plugin'); ?></label>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_resource_row($index, $resource) {
        $name = self::PAYLOAD_NAME . '[resources][' . $index . ']';
        $label = isset($resource['label']) ? (string) $resource['label'] : '';
        $url = isset($resource['url']) ? (string) $resource['url'] : '';
        $type = isset($resource['type']) && 'download' === $resource['type'] ? 'download' : 'link';
        $attachment_id = isset($resource['attachment_id']) ? absint($resource['attachment_id']) : 0;
        ?>
        <div class="tsol-library-row tsol-library-resource-row" data-resource-row>
            <div class="tsol-library-row__toolbar">
                <span class="tsol-library-row__handle dashicons dashicons-menu" aria-hidden="true"></span>
                <strong data-resource-summary><?php echo esc_html($label ?: __('Untitled resource', 'tomschooloflife-plugin')); ?></strong>
                <div class="tsol-library-row__actions">
                    <button type="button" class="button-link" data-row-up aria-label="<?php esc_attr_e('Move resource up', 'tomschooloflife-plugin'); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button-link" data-row-down aria-label="<?php esc_attr_e('Move resource down', 'tomschooloflife-plugin'); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button-link-delete" data-resource-remove><?php esc_html_e('Remove', 'tomschooloflife-plugin'); ?></button>
                </div>
            </div>
            <div class="tsol-library-row__body">
                <div class="tsol-library-field-grid">
                    <div class="tsol-library-field">
                        <label><?php esc_html_e('Label', 'tomschooloflife-plugin'); ?></label>
                        <input type="text" name="<?php echo esc_attr($name); ?>[label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php esc_attr_e('Worksheet or reference name', 'tomschooloflife-plugin'); ?>" data-resource-label />
                    </div>
                    <div class="tsol-library-field">
                        <label><?php esc_html_e('Resource type', 'tomschooloflife-plugin'); ?></label>
                        <select name="<?php echo esc_attr($name); ?>[type]">
                            <option value="link" <?php selected($type, 'link'); ?>><?php esc_html_e('Reference link', 'tomschooloflife-plugin'); ?></option>
                            <option value="download" <?php selected($type, 'download'); ?>><?php esc_html_e('Download', 'tomschooloflife-plugin'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="tsol-library-field tsol-library-field--wide">
                    <label><?php esc_html_e('Resource URL', 'tomschooloflife-plugin'); ?></label>
                    <div class="tsol-library-url-control">
                        <input type="url" name="<?php echo esc_attr($name); ?>[url]" value="<?php echo esc_attr($url); ?>" placeholder="https://…" data-resource-url />
                        <input type="hidden" name="<?php echo esc_attr($name); ?>[attachment_id]" value="<?php echo esc_attr($attachment_id); ?>" data-resource-attachment />
                        <button type="button" class="button" data-resource-library><?php esc_html_e('Choose file', 'tomschooloflife-plugin'); ?></button>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    private function sanitize_media_rows($rows) {
        $items = array();
        $errors = array();
        if (!is_array($rows)) {
            return compact('items', 'errors');
        }

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = isset($row['source_url']) ? esc_url_raw($row['source_url']) : '';
            if ('' === $url) {
                continue;
            }
            $row['source_url'] = $url;
            $row['position'] = count($items) + 1;
            $normalized = TSOL_Library_Media_Normalizer::normalize_asset($row, count($items) + 1);
            if (is_wp_error($normalized)) {
                $errors[] = sprintf(__('Media row %1$d: %2$s', 'tomschooloflife-plugin'), $index + 1, $normalized->get_error_message());
                continue;
            }
            $items[] = $normalized;
        }

        return compact('items', 'errors');
    }

    private function sanitize_resource_rows($rows) {
        $items = array();
        $errors = array();
        if (!is_array($rows)) {
            return compact('items', 'errors');
        }

        foreach (array_values($rows) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = isset($row['url']) ? esc_url_raw($row['url']) : '';
            $attachment_id = isset($row['attachment_id']) ? absint($row['attachment_id']) : 0;
            $label = isset($row['label']) ? sanitize_text_field($row['label']) : '';
            if ('' === $url && 0 === $attachment_id && '' === $label) {
                continue;
            }
            if ('' === $url || !$this->is_absolute_http_url($url)) {
                $errors[] = sprintf(__('Resource row %d requires a valid absolute URL.', 'tomschooloflife-plugin'), $index + 1);
                continue;
            }

            $items[] = array(
                'key' => 'resource-' . (count($items) + 1),
                'type' => isset($row['type']) && 'download' === sanitize_key($row['type']) ? 'download' : 'link',
                'label' => $label,
                'url' => $url,
                'attachment_id' => $attachment_id,
                'position' => count($items) + 1,
            );
        }

        return compact('items', 'errors');
    }

    private function resolve_structure_group($parent_id, $requested_key) {
        $parent_id = (int) $parent_id;
        $registry = TSOL_Library_Structure::registry($parent_id);
        $requested_key = sanitize_key((string) $requested_key);
        foreach ($registry as $group) {
            if ('' !== $requested_key && $requested_key === (string) $group['key']) {
                return $group;
            }
        }
        if (!empty($registry)) {
            return reset($registry);
        }

        $post_type = get_post_type($parent_id);
        if (!in_array($post_type, array(
            TSOL_Library_Content_Model::COURSE_POST_TYPE,
            TSOL_Library_Content_Model::SERIES_POST_TYPE,
        ), true)) {
            return null;
        }

        $group = array(
            'key' => TSOL_Library_Structure::new_group_key(
                TSOL_Library_Content_Model::COURSE_POST_TYPE === $post_type ? 'section' : 'group',
                TSOL_Library_Content_Model::COURSE_POST_TYPE === $post_type ? 'course-content' : 'episodes'
            ),
            'title' => TSOL_Library_Content_Model::COURSE_POST_TYPE === $post_type
                ? __('Course content', 'tomschooloflife-plugin')
                : __('Series episodes', 'tomschooloflife-plugin'),
            'position' => 1,
        );
        update_post_meta($parent_id, TSOL_Library_Structure::registry_meta_key($post_type), array($group));
        return $group;
    }

    private function ensure_legacy_structure_group($parent_id, $key, $title, $position) {
        $parent_id = (int) $parent_id;
        $key = sanitize_key((string) $key);
        $title = sanitize_text_field((string) $title);
        if ('' === $key || '' === $title) {
            return;
        }
        $registry = TSOL_Library_Structure::registry($parent_id);
        foreach ($registry as $group) {
            if ($key === (string) $group['key']) {
                return;
            }
        }
        $registry[] = array(
            'key' => $key,
            'title' => $title,
            'position' => max(1, (int) $position),
        );
        update_post_meta(
            $parent_id,
            TSOL_Library_Structure::registry_meta_key(get_post_type($parent_id)),
            TSOL_Library_Content_Model::sanitize_structure_registry($registry)
        );
    }

    private function next_structure_item_position($post_id, $course_id, $series_id, $section_key) {
        $parent_meta_key = $course_id > 0
            ? TSOL_Library_Content_Model::META_COURSE_ID
            : TSOL_Library_Content_Model::META_SERIES_ID;
        $parent_id = $course_id > 0 ? (int) $course_id : (int) $series_id;
        if ($parent_id <= 0) {
            return 0;
        }

        $meta_query = array(array(
            'key' => $parent_meta_key,
            'value' => $parent_id,
            'compare' => '=',
            'type' => 'NUMERIC',
        ));
        if ($course_id > 0) {
            $meta_query[] = array(
                'key' => TSOL_Library_Content_Model::META_SECTION_KEY,
                'value' => sanitize_key((string) $section_key),
                'compare' => '=',
            );
        }
        $item_ids = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'post__not_in' => array((int) $post_id),
            'fields' => 'ids',
            'orderby' => 'none',
            'meta_query' => $meta_query,
            'suppress_filters' => true,
        ));

        $maximum = 0;
        foreach ($item_ids as $item_id) {
            $maximum = max($maximum, (int) get_post_meta((int) $item_id, TSOL_Library_Content_Model::META_POSITION, true));
        }
        return $maximum + 1;
    }

    private function content_type_options($post_type) {
        if (TSOL_Library_Content_Model::COURSE_POST_TYPE === $post_type) {
            return array('course' => __('Course', 'tomschooloflife-plugin'));
        }
        if (TSOL_Library_Content_Model::SERIES_POST_TYPE === $post_type) {
            return array('series' => __('Series', 'tomschooloflife-plugin'));
        }

        return array(
            'lesson' => __('Course lesson', 'tomschooloflife-plugin'),
            'session' => __('TSOL session', 'tomschooloflife-plugin'),
            'webinar' => __('Webinar', 'tomschooloflife-plugin'),
            'recording' => __('Standalone recording', 'tomschooloflife-plugin'),
            'live_event' => __('Live event', 'tomschooloflife-plugin'),
            'orientation' => __('Orientation', 'tomschooloflife-plugin'),
            'member_call' => __('Member call', 'tomschooloflife-plugin'),
            'book_club' => __('Book club', 'tomschooloflife-plugin'),
        );
    }

    private function default_content_type($post_type) {
        if (TSOL_Library_Content_Model::COURSE_POST_TYPE === $post_type) {
            return 'course';
        }
        if (TSOL_Library_Content_Model::SERIES_POST_TYPE === $post_type) {
            return 'series';
        }
        return 'recording';
    }

    private function provider_label($provider) {
        $labels = array(
            'vimeo' => __('Vimeo', 'tomschooloflife-plugin'),
            'youtube' => __('YouTube', 'tomschooloflife-plugin'),
            'wordpress' => __('WordPress media', 'tomschooloflife-plugin'),
            'external' => __('External media', 'tomschooloflife-plugin'),
        );
        return isset($labels[$provider]) ? $labels[$provider] : ucfirst((string) $provider);
    }

    private function is_absolute_http_url($url) {
        $parts = wp_parse_url($url);
        return is_array($parts)
            && !empty($parts['host'])
            && isset($parts['scheme'])
            && in_array(strtolower($parts['scheme']), array('http', 'https'), true);
    }

    private function has_provenance($post_id) {
        $source_id = (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID, true);
        $source_type = trim((string) get_post_meta($post_id, TSOL_Library_Content_Model::META_LEGACY_SOURCE_TYPE, true));
        $migration_version = trim((string) get_post_meta($post_id, TSOL_Library_Content_Model::META_MIGRATION_VERSION, true));

        return $source_id > 0 && '' !== $source_type && '' !== $migration_version;
    }

    private function store_notice($post_id, $errors) {
        set_transient($this->notice_key($post_id), array_values(array_unique($errors)), 5 * MINUTE_IN_SECONDS);
    }

    private function notice_key($post_id) {
        return self::NOTICE_PREFIX . get_current_user_id() . '_' . absint($post_id);
    }
}
