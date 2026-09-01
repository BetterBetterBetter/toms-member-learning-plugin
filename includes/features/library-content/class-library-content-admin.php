<?php
/**
 * WordPress-native Library metadata editor.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Content_Admin {

    const NONCE_ACTION = 'tsol_library_content_editor';
    const NONCE_NAME = 'tsol_library_content_nonce';
    const PAYLOAD_NAME = 'tsol_library';
    const AJAX_ACTION = 'tsol_library_normalize_media_url';
    const NOTICE_PREFIX = 'tsol_library_editor_notice_';
    const COURSE_COLUMN = 'tsol-course';
    const SERIES_COLUMN = 'tsol-series';
    const AVAILABILITY_COLUMN = 'tsol-availability';
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
        add_action('load-post.php', array($this, 'isolate_private_editor_integrations'), 99);
        add_action('load-post-new.php', array($this, 'isolate_private_editor_integrations'), 99);
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'ajax_normalize_media_url'));
        add_filter('manage_edit-' . MemberLibrary_Content_Model::ITEM_POST_TYPE . '_columns', array($this, 'add_course_column'));
        add_filter('manage_edit-' . MemberLibrary_Content_Model::ITEM_POST_TYPE . '_columns', array($this, 'add_series_column'), 11);
        add_filter('manage_edit-' . MemberLibrary_Content_Model::ITEM_POST_TYPE . '_columns', array($this, 'add_availability_column'), 12);
        add_filter('views_edit-' . MemberLibrary_Content_Model::ITEM_POST_TYPE, array($this, 'filter_content_status_views'));
        add_action('manage_' . MemberLibrary_Content_Model::ITEM_POST_TYPE . '_posts_custom_column', array($this, 'render_course_column'), 10, 2);
        add_action('manage_' . MemberLibrary_Content_Model::ITEM_POST_TYPE . '_posts_custom_column', array($this, 'render_series_column'), 10, 2);
        add_action('manage_' . MemberLibrary_Content_Model::ITEM_POST_TYPE . '_posts_custom_column', array($this, 'render_availability_column'), 10, 2);
        foreach (array(MemberLibrary_Content_Model::COURSE_POST_TYPE, MemberLibrary_Content_Model::SERIES_POST_TYPE) as $parent_post_type) {
            add_filter('manage_edit-' . $parent_post_type . '_columns', array($this, 'add_content_count_column'), 12);
            add_action('manage_' . $parent_post_type . '_posts_custom_column', array($this, 'render_content_count_column'), 10, 2);
        }
        add_action('pre_get_posts', array($this, 'filter_content_list_query'));
        add_action('restrict_manage_posts', array($this, 'render_content_scope_filter'), 10, 2);
        foreach (MemberLibrary_Content_Model::post_types() as $post_type) {
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
                $with_course[self::COURSE_COLUMN] = __('Course', 'member-library');
            }
        }

        if (!isset($with_course[self::COURSE_COLUMN])) {
            $with_course[self::COURSE_COLUMN] = __('Course', 'member-library');
        }

        return $with_course;
    }

    public function add_availability_column($columns) {
        if (!is_array($columns) || isset($columns[self::AVAILABILITY_COLUMN])) {
            return $columns;
        }

        $result = array();
        foreach ($columns as $column => $label) {
            $result[$column] = $label;
            if ('title' === $column) {
                $result[self::AVAILABILITY_COLUMN] = __('Availability', 'member-library');
            }
        }
        return $result;
    }

    public function render_availability_column($column, $post_id) {
        if (self::AVAILABILITY_COLUMN !== $column) {
            return;
        }

        $availability = MemberLibrary_Content_Model::availability($post_id);
        if (MemberLibrary_Content_Model::AVAILABILITY_COMING_SOON !== $availability) {
            esc_html_e('Available', 'member-library');
            return;
        }

        esc_html_e('Coming soon', 'member-library');
        $release_at_gmt = MemberLibrary_Content_Model::release_at_gmt($post_id);
        if ('' !== $release_at_gmt) {
            printf(
                '<br><span class="description">%s</span>',
                esc_html(get_date_from_gmt(
                    $release_at_gmt,
                    get_option('date_format')
                ))
            );
        }
    }

    public function add_content_count_column($columns) {
        if (!is_array($columns) || isset($columns[self::CONTENT_COUNT_COLUMN])) {
            return $columns;
        }
        $columns[self::CONTENT_COUNT_COLUMN] = __('Content', 'member-library');
        return $columns;
    }

    public function render_content_count_column($column, $post_id) {
        if (self::CONTENT_COUNT_COLUMN !== $column) {
            return;
        }
        $post_type = get_post_type((int) $post_id);
        if (!in_array($post_type, array(MemberLibrary_Content_Model::COURSE_POST_TYPE, MemberLibrary_Content_Model::SERIES_POST_TYPE), true)) {
            return;
        }
        $counts = $this->parent_content_counts($post_type);
        $count = isset($counts[(int) $post_id]) ? (int) $counts[(int) $post_id] : 0;
        $scope = MemberLibrary_Content_Model::COURSE_POST_TYPE === $post_type
            ? self::CONTENT_SCOPE_COURSE
            : self::CONTENT_SCOPE_SERIES;
        $url = add_query_arg(array(
            'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
            self::CONTENT_SCOPE_FILTER => $scope,
            self::PARENT_FILTER => (int) $post_id,
        ), admin_url('edit.php'));
        $parent_title = get_the_title((int) $post_id);
        printf(
            '<a href="%s" aria-label="%s">%s</a>',
            esc_url($url),
            esc_attr(sprintf(
                _n('View %1$s content item in %2$s', 'View %1$s content items in %2$s', $count, 'member-library'),
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

        $course_id = (int) get_post_meta((int) $post_id, MemberLibrary_Content_Model::META_COURSE_ID, true);
        if ($course_id <= 0) {
            echo '<span class="tsol-library-course-column__empty" aria-hidden="true">&#8212;</span>';
            echo '<span class="screen-reader-text">';
            esc_html_e('No course', 'member-library');
            echo '</span>';
            return;
        }

        $course = get_post($course_id);
        if (!$course instanceof WP_Post || MemberLibrary_Content_Model::COURSE_POST_TYPE !== $course->post_type) {
            echo '<span class="tsol-library-course-column__unavailable">';
            esc_html_e('Unavailable', 'member-library');
            echo '</span>';
            return;
        }

        $title = '' !== trim((string) $course->post_title)
            ? (string) $course->post_title
            : __('(no title)', 'member-library');
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
                $with_series[self::SERIES_COLUMN] = __('Series', 'member-library');
            }
        }
        if (!isset($with_series[self::SERIES_COLUMN])) {
            $with_series[self::SERIES_COLUMN] = __('Series', 'member-library');
        }
        return $with_series;
    }

    public function render_series_column($column, $post_id) {
        if (self::SERIES_COLUMN !== $column) {
            return;
        }
        $series_id = (int) get_post_meta((int) $post_id, MemberLibrary_Content_Model::META_SERIES_ID, true);
        $series = $series_id > 0 ? get_post($series_id) : null;
        if (!$series instanceof WP_Post || MemberLibrary_Content_Model::SERIES_POST_TYPE !== $series->post_type) {
            echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">';
            esc_html_e('No series', 'member-library');
            echo '</span>';
            return;
        }
        $title = '' !== trim((string) $series->post_title) ? (string) $series->post_title : __('(no title)', 'member-library');
        $edit_url = get_edit_post_link($series_id, 'raw');
        echo $edit_url ? '<a href="' . esc_url($edit_url) . '">' . esc_html($title) . '</a>' : esc_html($title);
    }

    public function shorten_taxonomy_column_labels($columns) {
        if (!is_array($columns)) {
            return $columns;
        }

        $labels = array(
            'taxonomy-' . MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY => __('Collections', 'member-library'),
            'taxonomy-' . MemberLibrary_Content_Model::TOPIC_TAXONOMY => __('Topics', 'member-library'),
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
                $with_speakers[self::SPEAKERS_COLUMN] = __('Speakers', 'member-library');
            }
        }
        if (!isset($with_speakers[self::SPEAKERS_COLUMN])) {
            $with_speakers[self::SPEAKERS_COLUMN] = __('Speakers', 'member-library');
        }
        return $with_speakers;
    }

    public function render_speakers_column($column, $post_id) {
        if (self::SPEAKERS_COLUMN !== $column) {
            return;
        }

        $speaker_context = MemberLibrary_Content_Model::effective_speaker_context((int) $post_id);
        $speaker_ids = $speaker_context['speaker_ids'];
        $links = array();
        foreach ($speaker_ids as $speaker_id) {
            $speaker = get_post($speaker_id);
            if (!$speaker instanceof WP_Post || MemberLibrary_Content_Model::SPEAKER_POST_TYPE !== $speaker->post_type || 'trash' === $speaker->post_status) {
                continue;
            }
            $name = '' !== trim((string) $speaker->post_title) ? (string) $speaker->post_title : __('(no name)', 'member-library');
            $edit_url = get_edit_post_link($speaker_id, 'raw');
            $links[] = $edit_url
                ? '<a href="' . esc_url($edit_url) . '">' . esc_html($name) . '</a>'
                : esc_html($name);
        }

        if (empty($links)) {
            echo '<span aria-hidden="true">&#8212;</span><span class="screen-reader-text">' . esc_html__('No speakers', 'member-library') . '</span>';
            return;
        }
        echo wp_kses_post(implode(', ', $links));
        if (in_array((string) $speaker_context['source'], array('course', 'series'), true)) {
            $parent_title = get_the_title((int) $speaker_context['parent_id']);
            printf(
                '<span class="tsol-library-speaker-source" title="%1$s">%2$s</span>',
                esc_attr(sprintf(
                    __('Inherited from %1$s: %2$s', 'member-library'),
                    (string) $speaker_context['parent_label'],
                    $parent_title
                )),
                esc_html__('Inherited', 'member-library')
            );
        }
    }

    public function filter_content_list_query($query) {
        if (!is_admin()
            || !$query instanceof WP_Query
            || !$query->is_main_query()
            || MemberLibrary_Content_Model::ITEM_POST_TYPE !== (string) $query->get('post_type')
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
                        'key' => MemberLibrary_Content_Model::META_COURSE_ID,
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key' => MemberLibrary_Content_Model::META_COURSE_ID,
                        'value' => 0,
                        'compare' => '=',
                        'type' => 'NUMERIC',
                    ),
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key' => MemberLibrary_Content_Model::META_SERIES_ID,
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key' => MemberLibrary_Content_Model::META_SERIES_ID,
                        'value' => 0,
                        'compare' => '=',
                        'type' => 'NUMERIC',
                    ),
                ),
            ));
        } elseif (self::CONTENT_SCOPE_COURSE === $scope) {
            $this->append_meta_query($query, array(
                'key' => MemberLibrary_Content_Model::META_COURSE_ID,
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ));
        } elseif (self::CONTENT_SCOPE_SERIES === $scope) {
            $this->append_meta_query($query, array(
                'key' => MemberLibrary_Content_Model::META_SERIES_ID,
                'value' => 0,
                'compare' => '>',
                'type' => 'NUMERIC',
            ));
        }

        $parent_id = $this->requested_parent_content_id($scope);
        if ($parent_id > 0) {
            $this->append_meta_query($query, array(
                'key' => self::CONTENT_SCOPE_COURSE === $scope
                    ? MemberLibrary_Content_Model::META_COURSE_ID
                    : MemberLibrary_Content_Model::META_SERIES_ID,
                'value' => $parent_id,
                'compare' => '=',
                'type' => 'NUMERIC',
            ));
        }

    }

    public function render_content_scope_filter($post_type, $which = 'top') {
        if (MemberLibrary_Content_Model::ITEM_POST_TYPE !== (string) $post_type || 'top' !== (string) $which) {
            return;
        }

        $counts = $this->content_scope_counts();
        $scope = $this->requested_content_scope();
        $parent_id = $this->requested_parent_content_id($scope);
        ?>
        <label class="screen-reader-text" for="tsol-content-scope"><?php esc_html_e('Filter by content scope', 'member-library'); ?></label>
        <select name="<?php echo esc_attr(self::CONTENT_SCOPE_FILTER); ?>" id="tsol-content-scope">
            <option value="<?php echo esc_attr(self::CONTENT_SCOPE_STANDALONE); ?>" <?php selected($scope, self::CONTENT_SCOPE_STANDALONE); ?>><?php echo esc_html(sprintf(__('Standalone content (%s)', 'member-library'), number_format_i18n($counts['standalone']))); ?></option>
            <option value="<?php echo esc_attr(self::CONTENT_SCOPE_COURSE); ?>" <?php selected($scope, self::CONTENT_SCOPE_COURSE); ?>><?php echo esc_html(sprintf(__('Course lessons (%s)', 'member-library'), number_format_i18n($counts['course']))); ?></option>
            <option value="<?php echo esc_attr(self::CONTENT_SCOPE_SERIES); ?>" <?php selected($scope, self::CONTENT_SCOPE_SERIES); ?>><?php echo esc_html(sprintf(__('Series episodes (%s)', 'member-library'), number_format_i18n($counts['series']))); ?></option>
            <option value="<?php echo esc_attr(self::CONTENT_SCOPE_ALL); ?>" <?php selected($scope, self::CONTENT_SCOPE_ALL); ?>><?php echo esc_html(sprintf(__('All content (%s)', 'member-library'), number_format_i18n($counts['all']))); ?></option>
        </select>
        <?php if ($parent_id > 0) : ?>
            <input type="hidden" name="<?php echo esc_attr(self::PARENT_FILTER); ?>" value="<?php echo esc_attr($parent_id); ?>" />
            <span class="description"><?php echo esc_html(sprintf(__('Filtered by: %s', 'member-library'), get_the_title($parent_id))); ?></span>
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
            || !in_array((string) $screen->post_type, MemberLibrary_Content_Model::post_types(), true)
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

        if (MemberLibrary_Content_Model::ITEM_POST_TYPE === $post_type) {
            add_meta_box(
                'tsol-library-placement',
                __('Library placement', 'member-library'),
                array($this, 'render_details_meta_box'),
                $post_type,
                'normal',
                'high'
            );
        }

        if (MemberLibrary_Content_Model::SERIES_POST_TYPE === $post_type) {
            add_meta_box(
                'tsol-library-series-settings',
                __('Series settings', 'member-library'),
                array($this, 'render_details_meta_box'),
                $post_type,
                'normal',
                'high'
            );
        }

        if (MemberLibrary_Content_Model::ITEM_POST_TYPE === $post_type) {
            add_meta_box(
                'tsol-library-media',
                __('Media', 'member-library'),
                array($this, 'render_media_meta_box'),
                $post_type,
                'normal',
                'high'
            );

            add_meta_box(
                'tsol-library-resources',
                __('Library resources', 'member-library'),
                array($this, 'render_resources_meta_box'),
                $post_type,
                'normal',
                'default'
            );
        }

        if (MemberLibrary_Content_Model::COURSE_POST_TYPE === $post_type) {
            add_meta_box(
                'tsol-library-course-page-content',
                __('What you’ll learn', 'member-library'),
                array($this, 'render_course_page_content_meta_box'),
                $post_type,
                'normal',
                'high'
            );

            add_meta_box(
                'tsol-library-curriculum',
                __('Course curriculum', 'member-library'),
                array($this, 'render_curriculum_meta_box'),
                $post_type,
                'normal',
                'high'
            );
        }

        if (MemberLibrary_Content_Model::SERIES_POST_TYPE === $post_type) {
            add_meta_box(
                'tsol-library-series-episodes',
                __('Series episodes', 'member-library'),
                array($this, 'render_series_episodes_meta_box'),
                $post_type,
                'normal',
                'high'
            );
        }

        add_meta_box(
            'tsol-library-protection',
            __('Library access', 'member-library'),
            array($this, 'render_protection_meta_box'),
            $post_type,
            'side',
            'high'
        );

        if (in_array($post_type, array(
            MemberLibrary_Content_Model::COURSE_POST_TYPE,
            MemberLibrary_Content_Model::SERIES_POST_TYPE,
        ), true)) {
            add_meta_box(
                'tsol-library-ai-assistant',
                __('AI assistant', 'member-library'),
                array($this, 'render_ai_assistant_meta_box'),
                $post_type,
                'side',
                'high'
            );
        }

        add_meta_box(
            'tsol-library-speakers',
            __('Speakers', 'member-library'),
            array($this, 'render_speakers_meta_box'),
            $post_type,
            'side',
            'default'
        );

        if ($this->has_provenance($post->ID)) {
            add_meta_box(
                'tsol-library-provenance',
                __('Legacy import source', 'member-library'),
                array($this, 'render_provenance_meta_box'),
                $post_type,
                'side',
                'low'
            );
        }
    }

    /**
     * Library records have no WordPress frontend, so page-popup integrations
     * are inapplicable. Keep LeadPages' generic TinyMCE callbacks off these
     * screens to avoid both irrelevant controls and remote-response warnings.
     */
    public function isolate_private_editor_integrations() {
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || !self::supports_post_type((string) $screen->post_type)) {
            return;
        }

        foreach (array('admin_head-post.php', 'admin_head-post-new.php', 'mce_external_plugins', 'mce_buttons') as $hook_name) {
            $this->remove_object_callbacks($hook_name, 'LeadpagesWP\\Admin\\TinyMCE\\LeadboxTinyMCE');
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

        if (MemberLibrary_Content_Model::ITEM_POST_TYPE === $screen->post_type) {
            wp_enqueue_media();
        }
        wp_enqueue_style(
            'tsol-library-content-admin',
            MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-content-admin.css',
            array(),
            MEMBER_LIBRARY_PLUGIN_VERSION
        );
        wp_enqueue_style(
            'tsol-library-structure-summary',
            MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-structure-builder.css',
            array('tsol-library-content-admin'),
            MEMBER_LIBRARY_PLUGIN_VERSION
        );

        if ('edit.php' === $hook) {
            return;
        }

        if (MemberLibrary_Content_Model::ITEM_POST_TYPE === $screen->post_type) {
            wp_enqueue_script(
                'tsol-library-structure-placement',
                MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-structure-placement.js',
                array(),
                MEMBER_LIBRARY_PLUGIN_VERSION,
                true
            );
        }

        $script_dependencies = array('jquery', 'jquery-ui-sortable');
        wp_enqueue_script(
            'tsol-library-content-admin',
            MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-content-admin.js',
            $script_dependencies,
            MEMBER_LIBRARY_PLUGIN_VERSION,
            true
        );
        wp_localize_script('tsol-library-content-admin', 'tsolLibraryContentAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::AJAX_ACTION),
            'postId' => $post_id,
            'postType' => (string) $screen->post_type,
            'requiresMedia' => MemberLibrary_Content_Model::ITEM_POST_TYPE === $screen->post_type,
            'mediaProviders' => $this->media_provider_ui(),
            'strings' => array(
                'checking' => __('Checking URL…', 'member-library'),
                'empty' => __('Choose a source and enter its media URL.', 'member-library'),
                'error' => __('That media URL could not be recognised.', 'member-library'),
                'remove' => __('Remove media', 'member-library'),
                'providerId' => __('ID', 'member-library'),
                'privateVimeo' => __('Private Vimeo reference detected', 'member-library'),
                'wordpressAttachment' => __('WordPress attachment', 'member-library'),
                'primaryMedia' => __('Primary playback source', 'member-library'),
                'secondaryMedia' => __('Additional playback source', 'member-library'),
                'testPlayback' => __('Test playback', 'member-library'),
                'hidePlayback' => __('Hide test player', 'member-library'),
                'previewTitle' => __('Media playback test', 'member-library'),
                'providerMismatch' => __('This URL resolves to %1$s. Choose that source type or enter a %2$s URL.', 'member-library'),
                'speakerAdded' => __('Speaker added.', 'member-library'),
                'speakerRemoved' => __('Speaker removed.', 'member-library'),
                'speakerMoved' => __('Speaker order updated.', 'member-library'),
                'speakerNoResults' => __('No speakers match that search.', 'member-library'),
                'speakerAdd' => __('Add speaker', 'member-library'),
                'speakerSelectedCount' => __('%d selected', 'member-library'),
                'speakerEdit' => __('Edit', 'member-library'),
                'speakerDrag' => __('Drag to reorder', 'member-library'),
                'speakerMoveUp' => __('Move up', 'member-library'),
                'speakerMoveDown' => __('Move down', 'member-library'),
                'speakerRemove' => __('Remove', 'member-library'),
                'courseBodyTitle' => __('About this course', 'member-library'),
                'contentBodyTitle' => __('Description', 'member-library'),
                'seriesBodyTitle' => __('Description', 'member-library'),
                'excerptDescription' => __('Used as the short introduction on Library cards and pages, and as the preferred search description.', 'member-library'),
                'excerptCountTemplate' => __('%1$d / %2$d recommended', 'member-library'),
                'excerptLongWarning' => __('Search engines may truncate longer descriptions.', 'member-library'),
            ),
        ));
    }

    public function render_details_meta_box($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        ?>
        <div class="tsol-library-editor" data-library-editor>
            <?php if (MemberLibrary_Content_Model::SERIES_POST_TYPE === $post->post_type) : ?>
                <?php
                $item_label = (string) get_post_meta($post->ID, MemberLibrary_Content_Model::META_SERIES_ITEM_LABEL, true) ?: 'episode';
                $item_label_plural = (string) get_post_meta($post->ID, MemberLibrary_Content_Model::META_SERIES_ITEM_LABEL_PLURAL, true) ?: 'episodes';
                $series_sort = (string) get_post_meta($post->ID, MemberLibrary_Content_Model::META_SERIES_SORT, true) ?: 'desc';
                $ongoing = (bool) get_post_meta($post->ID, MemberLibrary_Content_Model::META_SERIES_ONGOING, true);
                ?>
                <div class="tsol-library-field-grid">
                    <div class="tsol-library-field">
                        <label for="tsol-library-series-item-label"><?php esc_html_e('Item label', 'member-library'); ?></label>
                        <input type="text" id="tsol-library-series-item-label" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_item_label]" value="<?php echo esc_attr($item_label); ?>" />
                    </div>
                    <div class="tsol-library-field">
                        <label for="tsol-library-series-item-label-plural"><?php esc_html_e('Plural label', 'member-library'); ?></label>
                        <input type="text" id="tsol-library-series-item-label-plural" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_item_label_plural]" value="<?php echo esc_attr($item_label_plural); ?>" />
                    </div>
                    <div class="tsol-library-field">
                        <label for="tsol-library-series-sort"><?php esc_html_e('Library page order', 'member-library'); ?></label>
                        <select id="tsol-library-series-sort" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_sort]">
                            <option value="desc" <?php selected($series_sort, 'desc'); ?>><?php esc_html_e('Newest first', 'member-library'); ?></option>
                            <option value="asc" <?php selected($series_sort, 'asc'); ?>><?php esc_html_e('Oldest first', 'member-library'); ?></option>
                        </select>
                    </div>
                </div>
                <label><input type="checkbox" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_ongoing]" value="1" <?php checked($ongoing); ?> /> <?php esc_html_e('This is an ongoing series', 'member-library'); ?></label>
            <?php endif; ?>

            <?php if (MemberLibrary_Content_Model::ITEM_POST_TYPE === $post->post_type) : ?>
                <?php
                $course_id = (int) get_post_meta($post->ID, MemberLibrary_Content_Model::META_COURSE_ID, true);
                $series_id = (int) get_post_meta($post->ID, MemberLibrary_Content_Model::META_SERIES_ID, true);
                if ($series_id <= 0 && isset($_GET['tsol_series_id'])) {
                    $requested_series_id = absint($_GET['tsol_series_id']);
                    if (MemberLibrary_Content_Model::SERIES_POST_TYPE === get_post_type($requested_series_id)) {
                        $series_id = $requested_series_id;
                    }
                }
                if ($course_id <= 0 && isset($_GET['tsol_course_id'])) {
                    $requested_course_id = absint($_GET['tsol_course_id']);
                    if (MemberLibrary_Content_Model::COURSE_POST_TYPE === get_post_type($requested_course_id)) {
                        $course_id = $requested_course_id;
                    }
                }
                $section_key = sanitize_key((string) get_post_meta($post->ID, MemberLibrary_Content_Model::META_SECTION_KEY, true));
                $series_group_key = sanitize_key((string) get_post_meta($post->ID, MemberLibrary_Content_Model::META_SERIES_GROUP_KEY, true));
                $requested_structure_key = isset($_GET['tsol_structure_key']) ? sanitize_key(wp_unslash($_GET['tsol_structure_key'])) : '';
                if ('' !== $requested_structure_key
                    && !metadata_exists('post', $post->ID, MemberLibrary_Content_Model::META_COURSE_ID)
                    && !metadata_exists('post', $post->ID, MemberLibrary_Content_Model::META_SERIES_ID)
                ) {
                    if ($course_id > 0) {
                        $section_key = $requested_structure_key;
                    } elseif ($series_id > 0) {
                        $series_group_key = $requested_structure_key;
                    }
                }
                $courses = get_posts(array(
                    'post_type' => MemberLibrary_Content_Model::COURSE_POST_TYPE,
                    'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
                    'numberposts' => -1,
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'suppress_filters' => true,
                ));
                $series = get_posts(array(
                    'post_type' => MemberLibrary_Content_Model::SERIES_POST_TYPE,
                    'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
                    'numberposts' => -1,
                    'orderby' => 'title',
                    'order' => 'ASC',
                    'suppress_filters' => true,
                ));
                $course_groups = array();
                foreach ($courses as $course) {
                    $course_groups[(int) $course->ID] = MemberLibrary_Structure::group_options((int) $course->ID);
                }
                $series_groups = array();
                foreach ($series as $series_entry) {
                    $series_groups[(int) $series_entry->ID] = MemberLibrary_Structure::group_options((int) $series_entry->ID);
                }
                $placement_type = $course_id > 0 ? 'course' : ($series_id > 0 ? 'series' : 'standalone');
                ?>
                <p class="description"><?php esc_html_e('Choose where this content appears. Ordering and group names are managed in the parent structure builder.', 'member-library'); ?></p>
                <div class="tsol-library-placement" data-library-placement data-saved-placement="<?php echo esc_attr($placement_type); ?>" data-saved-parent-id="<?php echo esc_attr((string) ($course_id > 0 ? $course_id : $series_id)); ?>">
                    <div class="tsol-library-field">
                        <label for="tsol-library-placement-type"><?php esc_html_e('Placement', 'member-library'); ?></label>
                        <select id="tsol-library-placement-type" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[placement_type]" data-placement-type>
                            <option value="standalone" <?php selected($placement_type, 'standalone'); ?>><?php esc_html_e('Standalone content', 'member-library'); ?></option>
                            <option value="course" <?php selected($placement_type, 'course'); ?>><?php esc_html_e('Course lesson', 'member-library'); ?></option>
                            <option value="series" <?php selected($placement_type, 'series'); ?>><?php esc_html_e('Series episode', 'member-library'); ?></option>
                        </select>
                    </div>

                    <div class="tsol-library-placement__panel" data-placement-panel="course">
                        <div class="tsol-library-field">
                            <label for="tsol-library-course-id"><?php esc_html_e('Course', 'member-library'); ?></label>
                            <select id="tsol-library-course-id" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[course_id]" data-placement-parent="course">
                                <option value="0"><?php esc_html_e('Select a course', 'member-library'); ?></option>
                                <?php foreach ($courses as $course) : ?>
                                    <option value="<?php echo esc_attr((string) $course->ID); ?>" data-library-parent-slug="<?php echo esc_attr((string) ($course->post_name ?: sanitize_title($course->post_title))); ?>" <?php selected($course_id, (int) $course->ID); ?>><?php echo esc_html($course->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tsol-library-field">
                            <label for="tsol-library-section-key"><?php esc_html_e('Section', 'member-library'); ?></label>
                            <select id="tsol-library-section-key" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[section_key]" data-placement-group="course" data-selected-key="<?php echo esc_attr($section_key); ?>"></select>
                            <p class="description" data-placement-empty="course" hidden><?php esc_html_e('A “Course content” section will be created when this item is saved.', 'member-library'); ?></p>
                        </div>
                    </div>

                    <div class="tsol-library-placement__panel" data-placement-panel="series">
                        <div class="tsol-library-field">
                            <label for="tsol-library-series-id"><?php esc_html_e('Series', 'member-library'); ?></label>
                            <select id="tsol-library-series-id" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_id]" data-placement-parent="series">
                                <option value="0"><?php esc_html_e('Select a series', 'member-library'); ?></option>
                                <?php foreach ($series as $series_entry) : ?>
                                    <option value="<?php echo esc_attr((string) $series_entry->ID); ?>" data-library-parent-slug="<?php echo esc_attr((string) ($series_entry->post_name ?: sanitize_title($series_entry->post_title))); ?>" <?php selected($series_id, (int) $series_entry->ID); ?>><?php echo esc_html($series_entry->post_title); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="tsol-library-field">
                            <label for="tsol-library-series-group-key"><?php esc_html_e('Group', 'member-library'); ?></label>
                            <select id="tsol-library-series-group-key" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[series_group_key]" data-placement-group="series" data-selected-key="<?php echo esc_attr($series_group_key); ?>"></select>
                            <p class="description" data-placement-empty="series" hidden><?php esc_html_e('A “Series episodes” group will be created when this item is saved.', 'member-library'); ?></p>
                        </div>
                    </div>

                    <div class="notice notice-warning inline tsol-library-placement__warning" data-placement-warning hidden>
                        <p><?php esc_html_e('Changing the parent also changes which Library post MemberPress evaluates. Review the effective access panel before publishing.', 'member-library'); ?></p>
                    </div>
                    <p data-placement-manage hidden><a class="button" href="#"><?php esc_html_e('Open parent structure builder', 'member-library'); ?></a></p>
                    <script type="application/json" data-placement-options><?php echo wp_json_encode(array('course' => $course_groups, 'series' => $series_groups)); ?></script>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_speakers_meta_box($post) {
        $selected_ids = MemberLibrary_Content_Model::direct_speaker_ids($post->ID);
        $speaker_context = MemberLibrary_Content_Model::effective_speaker_context($post->ID);
        $speaker_mode = (string) $speaker_context['mode'];
        $speaker_mode_explicit = metadata_exists('post', $post->ID, MemberLibrary_Content_Model::META_SPEAKER_MODE);
        $is_item = MemberLibrary_Content_Model::ITEM_POST_TYPE === $post->post_type;
        $parent_id = (int) $speaker_context['parent_id'];
        $parent_title = $parent_id > 0 ? get_the_title($parent_id) : '';
        $parent_edit_url = $parent_id > 0 ? get_edit_post_link($parent_id, 'raw') : '';
        $inherited_speakers = array();
        if ($is_item && $parent_id > 0) {
            foreach (MemberLibrary_Content_Model::direct_speaker_ids($parent_id) as $speaker_id) {
                $inherited_speaker = get_post($speaker_id);
                if ($inherited_speaker instanceof WP_Post
                    && MemberLibrary_Content_Model::SPEAKER_POST_TYPE === $inherited_speaker->post_type
                    && 'trash' !== $inherited_speaker->post_status
                ) {
                    $inherited_speakers[] = $inherited_speaker;
                }
            }
        }
        $speakers = get_posts(array(
            'post_type' => MemberLibrary_Content_Model::SPEAKER_POST_TYPE,
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
                    <legend><?php esc_html_e('Speaker source', 'member-library'); ?></legend>
                    <label>
                        <input
                            type="radio"
                            name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[speaker_mode]"
                            value="<?php echo esc_attr(MemberLibrary_Content_Model::SPEAKER_MODE_INHERIT); ?>"
                            data-speaker-mode
                            data-speaker-inherit-mode
                            <?php checked(MemberLibrary_Content_Model::SPEAKER_MODE_INHERIT, $speaker_mode); ?>
                            <?php disabled(0 === $parent_id); ?>
                        />
                        <span>
                            <strong><?php esc_html_e('Inherit from parent', 'member-library'); ?></strong>
                            <small><?php esc_html_e('Uses the ordered Speakers assigned to the saved Course or Series.', 'member-library'); ?></small>
                        </span>
                    </label>
                    <label>
                        <input
                            type="radio"
                            name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[speaker_mode]"
                            value="<?php echo esc_attr(MemberLibrary_Content_Model::SPEAKER_MODE_DIRECT); ?>"
                            data-speaker-mode
                            <?php checked(MemberLibrary_Content_Model::SPEAKER_MODE_DIRECT, $speaker_mode); ?>
                        />
                        <span>
                            <strong><?php esc_html_e('Choose speakers for this content', 'member-library'); ?></strong>
                            <small><?php esc_html_e('Overrides the parent for this video. Select every presenter in display order.', 'member-library'); ?></small>
                        </span>
                    </label>
                    <label>
                        <input
                            type="radio"
                            name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[speaker_mode]"
                            value="<?php echo esc_attr(MemberLibrary_Content_Model::SPEAKER_MODE_NONE); ?>"
                            data-speaker-mode
                            <?php checked(MemberLibrary_Content_Model::SPEAKER_MODE_NONE, $speaker_mode); ?>
                        />
                        <span>
                            <strong><?php esc_html_e('No presenter', 'member-library'); ?></strong>
                            <small><?php esc_html_e('Explicitly suppresses inherited attribution for this video.', 'member-library'); ?></small>
                        </span>
                    </label>
                </fieldset>

                <div class="tsol-library-speaker-picker__inherited" data-speaker-inherited-panel<?php echo MemberLibrary_Content_Model::SPEAKER_MODE_INHERIT === $speaker_mode ? '' : ' hidden'; ?>>
                    <div class="tsol-library-speaker-picker__source">
                        <span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
                        <span>
                            <?php if ($parent_id > 0) : ?>
                                <strong><?php echo esc_html(sprintf(__('Inherited from %s', 'member-library'), (string) $speaker_context['parent_label'])); ?></strong>
                                <?php if ($parent_edit_url) : ?>
                                    <a href="<?php echo esc_url($parent_edit_url); ?>" target="_blank" rel="noopener noreferrer">
                                        <?php echo esc_html($parent_title); ?>
                                        <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'member-library'); ?></span>
                                    </a>
                                <?php else : ?>
                                    <span><?php echo esc_html($parent_title); ?></span>
                                <?php endif; ?>
                            <?php else : ?>
                                <strong><?php esc_html_e('No saved parent', 'member-library'); ?></strong>
                            <?php endif; ?>
                        </span>
                    </div>
                    <p class="tsol-library-speaker-picker__refresh" hidden data-speaker-inherited-refresh>
                        <?php esc_html_e('Save this content to refresh Speakers from the selected parent.', 'member-library'); ?>
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
                                <?php esc_html_e('The saved parent has no Speakers. Add them to the parent or choose an override here.', 'member-library'); ?>
                            </p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tsol-library-speaker-picker__suppressed" data-speaker-none-panel<?php echo MemberLibrary_Content_Model::SPEAKER_MODE_NONE === $speaker_mode ? '' : ' hidden'; ?>>
                    <span class="dashicons dashicons-hidden" aria-hidden="true"></span>
                    <p><strong><?php esc_html_e('No presenter will be shown.', 'member-library'); ?></strong><br /><?php esc_html_e('This content will not inherit Speakers even when its parent has them.', 'member-library'); ?></p>
                </div>
            <?php endif; ?>

            <div data-speaker-direct-panel<?php echo !$is_item || MemberLibrary_Content_Model::SPEAKER_MODE_DIRECT === $speaker_mode ? '' : ' hidden'; ?>>
                <?php if (empty($speakers)) : ?>
                    <p class="tsol-library-speaker-picker__empty"><?php esc_html_e('No speaker profiles exist yet.', 'member-library'); ?></p>
                <?php endif; ?>

                <?php if (!empty($speakers)) : ?>
                    <div class="tsol-library-speaker-picker__native" data-speaker-native>
                        <label for="tsol-library-speaker-ids"><?php esc_html_e('Select speakers', 'member-library'); ?></label>
                        <select id="tsol-library-speaker-ids" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[speaker_ids][]" multiple size="6">
                        <?php foreach ($ordered_speakers as $speaker) : ?>
                            <?php
                            $speaker_id = (int) $speaker->ID;
                            $status = get_post_status_object($speaker->post_status);
                            $status_label = 'publish' === $speaker->post_status || !$status ? '' : (string) $status->label;
                            $thumbnail_id = (int) get_post_thumbnail_id($speaker_id);
                            $image_url = $thumbnail_id > 0
                                ? wp_get_attachment_image_url($thumbnail_id, MemberLibrary_Content_Model::speaker_image_display_size($thumbnail_id))
                                : '';
                            ?>
                            <option
                                value="<?php echo esc_attr((string) $speaker_id); ?>"
                                data-speaker-name="<?php echo esc_attr((string) $speaker->post_title); ?>"
                                data-speaker-job-title="<?php echo esc_attr((string) get_post_meta($speaker_id, MemberLibrary_Content_Model::SPEAKER_META_JOB_TITLE, true)); ?>"
                                data-speaker-organization="<?php echo esc_attr((string) get_post_meta($speaker_id, MemberLibrary_Content_Model::SPEAKER_META_ORGANIZATION, true)); ?>"
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
                        <p class="description"><?php esc_html_e('Hold Command (Mac) or Control (Windows) to select more than one.', 'member-library'); ?></p>
                    </div>
                <?php endif; ?>

                <?php if (!empty($speakers)) : ?>
                    <div class="tsol-library-speaker-picker__enhanced" data-speaker-enhanced>
                        <label for="<?php echo esc_attr($search_id); ?>"><?php esc_html_e('Search speakers', 'member-library'); ?></label>
                        <div class="tsol-library-speaker-picker__search-wrap">
                            <span class="dashicons dashicons-search" aria-hidden="true"></span>
                            <input
                                type="search"
                                id="<?php echo esc_attr($search_id); ?>"
                                class="tsol-library-speaker-picker__search"
                                placeholder="<?php esc_attr_e('Search by name, job title, or organisation…', 'member-library'); ?>"
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
                            <strong><?php esc_html_e('Selected speakers', 'member-library'); ?></strong>
                            <span data-speaker-count></span>
                        </div>
                        <p class="tsol-library-speaker-picker__none" data-speaker-none><?php esc_html_e('No speakers selected.', 'member-library'); ?></p>
                        <ul class="tsol-library-speaker-picker__selected" data-speaker-selected></ul>
                        <span class="screen-reader-text" aria-live="polite" aria-atomic="true" data-speaker-announcer></span>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (current_user_can('edit_pages')) : ?>
                <p class="tsol-library-speaker-picker__add">
                    <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . MemberLibrary_Content_Model::SPEAKER_POST_TYPE)); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Add new speaker', 'member-library'); ?>
                        <span class="screen-reader-text"><?php esc_html_e('(opens in a new tab)', 'member-library'); ?></span>
                    </a>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_inherited_speaker_card(WP_Post $speaker) {
        $speaker_id = (int) $speaker->ID;
        $name = '' !== trim((string) $speaker->post_title) ? (string) $speaker->post_title : __('(no name)', 'member-library');
        $status = get_post_status_object($speaker->post_status);
        $status_label = 'publish' === $speaker->post_status || !$status ? '' : (string) $status->label;
        $thumbnail_id = (int) get_post_thumbnail_id($speaker_id);
        $image_url = $thumbnail_id > 0
            ? wp_get_attachment_image_url($thumbnail_id, MemberLibrary_Content_Model::speaker_image_display_size($thumbnail_id))
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
                <?php $job_title = (string) get_post_meta($speaker_id, MemberLibrary_Content_Model::SPEAKER_META_JOB_TITLE, true); ?>
                <?php $organization = (string) get_post_meta($speaker_id, MemberLibrary_Content_Model::SPEAKER_META_ORGANIZATION, true); ?>
                <?php if ('' !== $job_title) : ?><span class="tsol-library-speaker-picker__job-title"><?php echo esc_html($job_title); ?></span><?php endif; ?>
                <?php if ('' !== $organization) : ?><span class="tsol-library-speaker-picker__organization"><?php echo esc_html($organization); ?></span><?php endif; ?>
                <?php if ('' !== $status_label) : ?><span class="tsol-library-speaker-picker__status"><?php echo esc_html($status_label); ?></span><?php endif; ?>
            </span>
            <?php $edit_url = get_edit_post_link($speaker_id, 'raw'); ?>
            <?php if ($edit_url) : ?>
                <span class="tsol-library-speaker-picker__actions">
                    <a href="<?php echo esc_url($edit_url); ?>" target="_blank" rel="noopener noreferrer">
                        <?php esc_html_e('Edit', 'member-library'); ?>
                        <span class="screen-reader-text"><?php echo esc_html(sprintf(__(' %s (opens in a new tab)', 'member-library'), $name)); ?></span>
                    </a>
                </span>
            <?php endif; ?>
        </li>
        <?php
    }

    public function render_curriculum_meta_box($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $structure_admin = new MemberLibrary_Structure_Admin();
        $structure_admin->render_compact_summary($post);
    }

    public function render_course_page_content_meta_box($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $outcomes = get_post_meta(
            $post->ID,
            MemberLibrary_Content_Model::META_COURSE_LEARNING_OUTCOMES,
            true
        );
        $outcomes = is_array($outcomes) && !empty($outcomes) ? $outcomes : array('');
        ?>
        <div class="tsol-library-course-page-editor" data-library-course-page-editor>
            <input type="hidden" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[course_learning_outcomes_present]" value="1" />
            <section class="tsol-library-course-page-editor__section" aria-label="<?php esc_attr_e('Learning outcomes', 'member-library'); ?>">
                <div class="tsol-library-section-intro">
                    <p class="description"><?php esc_html_e('Add four to six concise outcomes. Titles appear prominently, with optional supporting text beneath each one.', 'member-library'); ?></p>
                </div>

                <div class="tsol-library-outcome-list" data-outcome-rows>
                    <?php foreach (array_values($outcomes) as $index => $outcome) : ?>
                        <?php $this->render_learning_outcome_row($index, is_array($outcome) ? ($outcome['text'] ?? '') : $outcome); ?>
                    <?php endforeach; ?>
                </div>

                <script type="text/html" data-outcome-template>
                    <?php $this->render_learning_outcome_row('__index__', ''); ?>
                </script>

                <div class="tsol-library-outcome-add">
                    <button type="button" class="button button-secondary" data-outcome-add><?php esc_html_e('Add outcome', 'member-library'); ?></button>
                    <span class="description"><?php esc_html_e('Up to 12 outcomes.', 'member-library'); ?></span>
                </div>
            </section>
        </div>
        <?php
    }

    public function render_series_episodes_meta_box($post) {
        $structure_admin = new MemberLibrary_Structure_Admin();
        $structure_admin->render_compact_summary($post);
    }

    public function render_media_meta_box($post) {
        $assets = get_post_meta($post->ID, MemberLibrary_Content_Model::META_MEDIA_ASSETS, true);
        $assets = is_array($assets) && !empty($assets) ? $assets : array(array());
        $availability = MemberLibrary_Content_Model::availability($post->ID);
        $release_at_gmt = MemberLibrary_Content_Model::release_at_gmt($post->ID);
        $release_date_local = '';
        if ('' !== $release_at_gmt) {
            $release_date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $release_at_gmt, new DateTimeZone('UTC'));
            if ($release_date) {
                $release_date_local = $release_date->setTimezone(wp_timezone())->format('Y-m-d');
            }
        }
        $release_is_past = '' !== $release_date_local && $release_date_local < current_datetime()->format('Y-m-d');
        ?>
        <div class="tsol-library-media-editor" data-library-media-editor>
            <div class="tsol-library-availability" data-library-availability>
                <div class="tsol-library-field-grid">
                    <div class="tsol-library-field">
                        <label for="tsol-library-availability"><?php esc_html_e('Availability', 'member-library'); ?></label>
                        <select id="tsol-library-availability" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[availability]" data-library-availability-select>
                            <option value="available" <?php selected($availability, MemberLibrary_Content_Model::AVAILABILITY_AVAILABLE); ?>><?php esc_html_e('Available now', 'member-library'); ?></option>
                            <option value="coming_soon" <?php selected($availability, MemberLibrary_Content_Model::AVAILABILITY_COMING_SOON); ?>><?php esc_html_e('Coming soon', 'member-library'); ?></option>
                        </select>
                        <p class="description"><?php esc_html_e('Coming-soon content appears in its course or series but cannot be played by members.', 'member-library'); ?></p>
                    </div>
                    <div class="tsol-library-field" data-library-release-field>
                        <label for="tsol-library-release-date"><?php esc_html_e('Release date', 'member-library'); ?> <span class="tsol-library-field__optional"><?php esc_html_e('(optional)', 'member-library'); ?></span></label>
                        <input type="date" id="tsol-library-release-date" name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[release_date_local]" value="<?php echo esc_attr($release_date_local); ?>" />
                        <p class="description"><?php esc_html_e('Displayed as a date only. Reaching this date does not automatically publish or unlock the video.', 'member-library'); ?></p>
                        <?php if ($release_is_past && MemberLibrary_Content_Model::AVAILABILITY_COMING_SOON === $availability) : ?>
                            <p class="notice notice-warning inline"><strong><?php esc_html_e('This release date has passed.', 'member-library'); ?></strong> <?php esc_html_e('The video remains Coming soon until an administrator attaches its media and changes Availability to Available now.', 'member-library'); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="tsol-library-section-intro">
                <div>
                    <p><?php esc_html_e('Choose where the media lives, then add its stable URL. The first row is the primary playback source used by the Library.', 'member-library'); ?></p>
                    <p class="description"><?php esc_html_e('Additional rows play in the order shown. Do not paste temporary signed playback URLs.', 'member-library'); ?></p>
                </div>
                <button type="button" class="button button-secondary" data-media-add><?php esc_html_e('Add media', 'member-library'); ?></button>
            </div>

            <div class="tsol-library-repeater" data-media-rows>
                <?php foreach (array_values($assets) as $index => $asset) : ?>
                    <?php $this->render_media_row($index, is_array($asset) ? $asset : array()); ?>
                <?php endforeach; ?>
            </div>

            <script type="text/html" data-media-template>
                <?php $this->render_media_row('__index__', array()); ?>
            </script>

            <?php MemberLibrary_Content_Transcripts::render_media_fields($post); ?>
        </div>
        <?php
    }

    public function render_resources_meta_box($post) {
        $resources = get_post_meta($post->ID, MemberLibrary_Content_Model::META_RESOURCES, true);
        $resources = is_array($resources) && !empty($resources) ? $resources : array(array());
        ?>
        <div class="tsol-library-resource-editor" data-library-resource-editor>
            <div class="tsol-library-section-intro">
                <div>
                    <p><?php esc_html_e('Add worksheets, downloads, reference links, or other supporting material.', 'member-library'); ?></p>
                    <p class="description"><?php esc_html_e('Resources are returned only through the protected Library content endpoint.', 'member-library'); ?></p>
                </div>
                <button type="button" class="button button-secondary" data-resource-add><?php esc_html_e('Add resource', 'member-library'); ?></button>
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
        $authorization_post_id = (int) get_post_meta($post->ID, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, true);
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
                <?php echo $is_protected ? esc_html__('Protected by MemberPress', 'member-library') : esc_html__('No MemberPress rule applies', 'member-library'); ?>
            </span>

            <?php if ($is_protected) : ?>
                <p><?php esc_html_e('The Library asks WordPress to evaluate these effective rules at access time:', 'member-library'); ?></p>
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
                <p><?php esc_html_e('WordPress and MemberPress currently treat this content as unrestricted. Any signed-in Library user can open its full media.', 'member-library'); ?></p>
                <?php if (current_user_can('manage_options')) : ?>
                    <p><a class="button button-small" href="<?php echo esc_url(admin_url('edit.php?post_type=memberpressrule')); ?>"><?php esc_html_e('Manage MemberPress rules', 'member-library'); ?></a></p>
                <?php endif; ?>
            <?php endif; ?>

            <p class="description"><?php esc_html_e('Administrators retain access through their WordPress capability. Access is not configured in this box.', 'member-library'); ?></p>
        </div>
        <?php
    }

    public function render_ai_assistant_meta_box($post) {
        $enabled = (bool) get_post_meta(
            $post->ID,
            MemberLibrary_Content_Model::META_AI_ASSISTANT_ENABLED,
            true
        );
        $questions = MemberLibrary_Content_Model::sanitize_ai_assistant_questions(get_post_meta(
            $post->ID,
            MemberLibrary_Content_Model::META_AI_ASSISTANT_QUESTIONS,
            true
        ));
        ?>
        <label>
            <input
                type="checkbox"
                name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[ai_assistant_enabled]"
                value="1"
                <?php checked($enabled); ?>
            />
            <strong><?php esc_html_e('Enable AI assistant', 'member-library'); ?></strong>
        </label>
        <p class="description"><?php esc_html_e('Allow enrolled users to ask transcript-grounded questions across this collection.', 'member-library'); ?></p>
        <p><strong><?php esc_html_e('Starter questions', 'member-library'); ?></strong></p>
        <?php for ($index = 0; $index < 3; $index++) : ?>
            <p>
                <label class="screen-reader-text" for="tsol-ai-question-<?php echo esc_attr((string) $index); ?>">
                    <?php echo esc_html(sprintf(__('Starter question %d', 'member-library'), $index + 1)); ?>
                </label>
                <input
                    id="tsol-ai-question-<?php echo esc_attr((string) $index); ?>"
                    class="widefat"
                    type="text"
                    maxlength="120"
                    name="<?php echo esc_attr(self::PAYLOAD_NAME); ?>[ai_assistant_questions][]"
                    value="<?php echo esc_attr($questions[$index] ?? ''); ?>"
                    placeholder="<?php echo esc_attr(sprintf(__('Question %d', 'member-library'), $index + 1)); ?>"
                />
            </p>
        <?php endfor; ?>
        <p class="description"><?php esc_html_e('Shown when the conversation is empty. Add up to three questions, each no longer than 120 characters.', 'member-library'); ?></p>
        <?php if ($enabled) : ?>
            <div class="notice notice-warning inline">
                <p><?php esc_html_e('Confirm that every playable lesson or episode has a synchronized transcript and completed search index. Missing coverage limits the answers and citations available to members.', 'member-library'); ?></p>
            </div>
        <?php endif; ?>
        <?php
    }

    public function render_provenance_meta_box($post) {
        $source_id = (int) get_post_meta($post->ID, MemberLibrary_Content_Model::META_LEGACY_SOURCE_ID, true);
        $source_type = (string) get_post_meta($post->ID, MemberLibrary_Content_Model::META_LEGACY_SOURCE_TYPE, true);
        $migration_version = (string) get_post_meta($post->ID, MemberLibrary_Content_Model::META_MIGRATION_VERSION, true);
        $source_edit_url = $source_id > 0 && current_user_can('edit_post', $source_id) ? get_edit_post_link($source_id) : '';
        ?>
        <dl class="tsol-library-provenance">
            <div><dt><?php esc_html_e('Source ID', 'member-library'); ?></dt><dd><code><?php echo esc_html((string) $source_id); ?></code></dd></div>
            <div><dt><?php esc_html_e('Source type', 'member-library'); ?></dt><dd><?php echo esc_html($source_type); ?></dd></div>
            <div><dt><?php esc_html_e('Migration version', 'member-library'); ?></dt><dd><?php echo esc_html($migration_version); ?></dd></div>
        </dl>
        <?php if ($source_edit_url) : ?>
            <p><a class="button button-small" href="<?php echo esc_url($source_edit_url); ?>"><?php esc_html_e('Edit legacy source', 'member-library'); ?></a></p>
        <?php endif; ?>
        <p class="description"><?php esc_html_e('Read-only migration provenance. Titles and slugs are never used as identity.', 'member-library'); ?></p>
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

        $media_result = $this->sanitize_media_rows(isset($payload['media_assets']) ? $payload['media_assets'] : array());
        $resource_result = $this->sanitize_resource_rows(isset($payload['resources']) ? $payload['resources'] : array());
        $errors = array_merge($media_result['errors'], $resource_result['errors']);
        $course_id = 0;
        $series_id = 0;
        $availability = MemberLibrary_Content_Model::sanitize_availability($payload['availability'] ?? '');
        $release_at_gmt = '';
        $release_date_local = trim((string) ($payload['release_date_local'] ?? ($payload['release_at_local'] ?? '')));
        if (MemberLibrary_Content_Model::AVAILABILITY_COMING_SOON === $availability && '' !== $release_date_local) {
            $release_date = DateTimeImmutable::createFromFormat('!Y-m-d', $release_date_local, wp_timezone());
            $date_errors = DateTimeImmutable::getLastErrors();
            if (!$release_date || (is_array($date_errors) && ((int) $date_errors['warning_count'] > 0 || (int) $date_errors['error_count'] > 0))) {
                $errors[] = __('Enter a valid release date.', 'member-library');
            } else {
                $release_at_gmt = $release_date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }

        if (
            'publish' === $post->post_status
            && MemberLibrary_Content_Model::ITEM_POST_TYPE === $post->post_type
            && MemberLibrary_Content_Model::AVAILABILITY_AVAILABLE === $availability
            && empty($media_result['items'])
        ) {
            $errors[] = __('Published Library Items and lessons require at least one valid media URL.', 'member-library');
        }

        if (!metadata_exists('post', $post_id, MemberLibrary_Content_Model::META_UUID)) {
            update_post_meta($post_id, MemberLibrary_Content_Model::META_UUID, wp_generate_uuid4());
        }
        $content_uuid = sanitize_text_field((string) get_post_meta($post_id, MemberLibrary_Content_Model::META_UUID, true));
        if (!metadata_exists('post', $post_id, MemberLibrary_Content_Model::META_MIGRATION_KEY)) {
            update_post_meta(
                $post_id,
                MemberLibrary_Content_Model::META_MIGRATION_KEY,
                sanitize_key('manual-' . $post->post_type . '-' . $content_uuid)
            );
        }
        if (!metadata_exists('post', $post_id, MemberLibrary_Content_Model::META_MIGRATION_VERSION)) {
            update_post_meta($post_id, MemberLibrary_Content_Model::META_MIGRATION_VERSION, 'manual-1');
        }
        if (!metadata_exists('post', $post_id, MemberLibrary_Content_Model::META_CONTENT_FINGERPRINT)) {
            update_post_meta($post_id, MemberLibrary_Content_Model::META_CONTENT_FINGERPRINT, hash('sha256', 'manual:' . $content_uuid));
        }
        if (
            MemberLibrary_Content_Model::COURSE_POST_TYPE === $post->post_type
            && !empty($payload['course_learning_outcomes_present'])
        ) {
            update_post_meta(
                $post_id,
                MemberLibrary_Content_Model::META_COURSE_LEARNING_OUTCOMES,
                MemberLibrary_Content_Model::sanitize_course_learning_outcomes($payload['learning_outcomes'] ?? array())
            );
        }
        $speaker_ids = isset($payload['speaker_ids']) && is_array($payload['speaker_ids'])
            ? array_values(array_unique(array_filter(array_map('absint', $payload['speaker_ids']))))
            : array();
        $speaker_ids = array_values(array_filter($speaker_ids, static function ($speaker_id) {
            $speaker = get_post((int) $speaker_id);
            return $speaker instanceof WP_Post
                && MemberLibrary_Content_Model::SPEAKER_POST_TYPE === $speaker->post_type
                && 'trash' !== $speaker->post_status;
        }));
        if (MemberLibrary_Content_Model::ITEM_POST_TYPE === $post->post_type) {
            $speaker_mode = isset($payload['speaker_mode']) ? sanitize_key((string) $payload['speaker_mode']) : '';
            $has_requested_parent = absint($payload['course_id'] ?? 0) > 0 || absint($payload['series_id'] ?? 0) > 0;
            if (!in_array($speaker_mode, array(
                MemberLibrary_Content_Model::SPEAKER_MODE_INHERIT,
                MemberLibrary_Content_Model::SPEAKER_MODE_DIRECT,
                MemberLibrary_Content_Model::SPEAKER_MODE_NONE,
            ), true)) {
                $speaker_mode = !empty($speaker_ids)
                    ? MemberLibrary_Content_Model::SPEAKER_MODE_DIRECT
                    : ($has_requested_parent
                        ? MemberLibrary_Content_Model::SPEAKER_MODE_INHERIT
                        : MemberLibrary_Content_Model::SPEAKER_MODE_NONE);
            }
            if (MemberLibrary_Content_Model::SPEAKER_MODE_INHERIT === $speaker_mode && !$has_requested_parent) {
                $speaker_mode = MemberLibrary_Content_Model::SPEAKER_MODE_NONE;
            }
            if (MemberLibrary_Content_Model::SPEAKER_MODE_DIRECT === $speaker_mode && empty($speaker_ids)) {
                $speaker_mode = MemberLibrary_Content_Model::SPEAKER_MODE_NONE;
            }
            if (MemberLibrary_Content_Model::SPEAKER_MODE_DIRECT !== $speaker_mode) {
                $speaker_ids = array();
            }
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SPEAKER_MODE, $speaker_mode);
        } else {
            delete_post_meta($post_id, MemberLibrary_Content_Model::META_SPEAKER_MODE);
        }
        delete_post_meta($post_id, MemberLibrary_Content_Model::META_SPEAKER_IDS);
        foreach ($speaker_ids as $speaker_id) {
            add_post_meta($post_id, MemberLibrary_Content_Model::META_SPEAKER_IDS, $speaker_id, false);
        }
        if (MemberLibrary_Content_Model::SERIES_POST_TYPE === $post->post_type) {
            $series_sort = isset($payload['series_sort']) && 'asc' === sanitize_key($payload['series_sort']) ? 'asc' : 'desc';
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SERIES_ITEM_LABEL, isset($payload['series_item_label']) ? sanitize_text_field($payload['series_item_label']) : 'episode');
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SERIES_ITEM_LABEL_PLURAL, isset($payload['series_item_label_plural']) ? sanitize_text_field($payload['series_item_label_plural']) : 'episodes');
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SERIES_SORT, $series_sort);
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SERIES_ONGOING, !empty($payload['series_ongoing']));
        }
        if (in_array($post->post_type, array(
            MemberLibrary_Content_Model::COURSE_POST_TYPE,
            MemberLibrary_Content_Model::SERIES_POST_TYPE,
        ), true)) {
            update_post_meta(
                $post_id,
                MemberLibrary_Content_Model::META_AI_ASSISTANT_ENABLED,
                !empty($payload['ai_assistant_enabled'])
            );
            update_post_meta(
                $post_id,
                MemberLibrary_Content_Model::META_AI_ASSISTANT_QUESTIONS,
                MemberLibrary_Content_Model::sanitize_ai_assistant_questions(
                    $payload['ai_assistant_questions'] ?? array()
                )
            );
        }
        if (MemberLibrary_Content_Model::ITEM_POST_TYPE === $post->post_type) {
            update_post_meta($post_id, MemberLibrary_Content_Model::META_AVAILABILITY, $availability);
            if (MemberLibrary_Content_Model::AVAILABILITY_COMING_SOON === $availability && '' !== $release_at_gmt) {
                update_post_meta($post_id, MemberLibrary_Content_Model::META_RELEASE_AT_GMT, $release_at_gmt);
            } else {
                delete_post_meta($post_id, MemberLibrary_Content_Model::META_RELEASE_AT_GMT);
            }
            update_post_meta($post_id, MemberLibrary_Content_Model::META_MEDIA_ASSETS, $media_result['items']);
            update_post_meta($post_id, MemberLibrary_Content_Model::META_RESOURCES, $resource_result['items']);

            $old_course_id = (int) get_post_meta($post_id, MemberLibrary_Content_Model::META_COURSE_ID, true);
            $old_series_id = (int) get_post_meta($post_id, MemberLibrary_Content_Model::META_SERIES_ID, true);
            $old_section_key = sanitize_key((string) get_post_meta($post_id, MemberLibrary_Content_Model::META_SECTION_KEY, true));
            $old_series_group_key = sanitize_key((string) get_post_meta($post_id, MemberLibrary_Content_Model::META_SERIES_GROUP_KEY, true));

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
            if ($course_id > 0 && MemberLibrary_Content_Model::COURSE_POST_TYPE !== get_post_type($course_id)) {
                $course_id = 0;
            }
            if ($series_id > 0 && MemberLibrary_Content_Model::SERIES_POST_TYPE !== get_post_type($series_id)) {
                $series_id = 0;
            }
            if ($course_id > 0 && $series_id > 0) {
                $errors[] = __('Content cannot belong to a course and a series at the same time. The series placement was removed.', 'member-library');
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
                    '' !== $legacy_title ? $legacy_title : __('Course content', 'member-library'),
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
            update_post_meta($post_id, MemberLibrary_Content_Model::META_COURSE_ID, $course_id);
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SECTION_KEY, $section_key);
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SECTION_TITLE, $section_title);
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SECTION_POSITION, $section_position);

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
                    '' !== $legacy_title ? $legacy_title : __('Series episodes', 'member-library'),
                    isset($payload['series_group_position']) ? absint($payload['series_group_position']) : 1
                );
            }
            $series_group = $series_id > 0 ? $this->resolve_structure_group($series_id, $series_group_key) : null;
            $series_group_key = is_array($series_group) ? (string) $series_group['key'] : '';
            $series_group_title = is_array($series_group) ? (string) $series_group['title'] : '';
            $series_group_position = is_array($series_group) ? (int) $series_group['position'] : 0;
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SERIES_ID, $series_id);
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SERIES_GROUP_KEY, $series_group_key);
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SERIES_GROUP_TITLE, $series_group_title);
            update_post_meta($post_id, MemberLibrary_Content_Model::META_SERIES_GROUP_POSITION, $series_group_position);

            $placement_changed = $course_id !== $old_course_id
                || $series_id !== $old_series_id
                || $section_key !== $old_section_key
                || $series_group_key !== $old_series_group_key;
            if ($placement_changed || !metadata_exists('post', $post_id, MemberLibrary_Content_Model::META_POSITION)) {
                update_post_meta(
                    $post_id,
                    MemberLibrary_Content_Model::META_POSITION,
                    $this->next_structure_item_position($post_id, $course_id, $series_id, $section_key)
                );
            }

            foreach (array_unique(array_filter(array($old_course_id, $old_series_id))) as $old_parent_id) {
                if (!in_array((int) $old_parent_id, array($course_id, $series_id), true)) {
                    MemberLibrary_Content_Changes::record_current_state((int) $old_parent_id);
                }
            }
        }

        update_post_meta(
            $post_id,
            MemberLibrary_Content_Model::META_CONTENT_TYPE,
            $this->derived_content_type($post, $course_id)
        );

        $migration_version = (string) get_post_meta($post_id, MemberLibrary_Content_Model::META_MIGRATION_VERSION, true);
        $access_migration_state = get_option('tsol_library_access_rules_migration_state', array());
        $native_import_access_active = is_array($access_migration_state)
            && 'activated' === (string) ($access_migration_state['phase'] ?? '');
        $should_follow_native_parent = '' === $migration_version
            || 0 === strpos($migration_version, 'manual-')
            || $native_import_access_active;

        if ($should_follow_native_parent || !metadata_exists('post', $post_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID)) {
            $authorization_post_id = (int) $post_id;
            if (MemberLibrary_Content_Model::ITEM_POST_TYPE === $post->post_type) {
                $authorization_post_id = $course_id > 0 ? (int) $course_id : ($series_id > 0 ? (int) $series_id : (int) $post_id);
            }
            update_post_meta($post_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, $authorization_post_id);
        }

        if ('publish' === $post->post_status && class_exists('MeprRule')) {
            $authorization_post_id = (int) get_post_meta($post_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, true);
            $authorization_post = get_post($authorization_post_id > 0 ? $authorization_post_id : $post_id);
            if (!$authorization_post instanceof WP_Post || empty(MeprRule::get_rules($authorization_post))) {
                $errors[] = __('Add a published MemberPress rule before publishing full Library content.', 'member-library');
            }
        }

        if (!empty($errors)) {
            if ('publish' === $post->post_status) {
                self::$updating_status = true;
                wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
                self::$updating_status = false;
                array_unshift($errors, __('This entry was kept as a draft because its Library metadata is incomplete.', 'member-library'));
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
        echo esc_html__('TSOL Library metadata needs attention:', 'member-library');
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
            wp_send_json_error(array('message' => __('You cannot edit this Library content.', 'member-library')), 403);
        }

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $asset = MemberLibrary_Media_Normalizer::from_url($url);
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
            'preview_type' => in_array($asset['provider'], array('vimeo', 'youtube'), true) ? 'iframe' : $asset['kind'],
            'preview_url' => $this->media_preview_url($asset),
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
            ? MemberLibrary_Content_Model::COURSE_POST_TYPE
            : MemberLibrary_Content_Model::SERIES_POST_TYPE;
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
            'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'none',
            'suppress_filters' => true,
        ));
        $counts = array('standalone' => 0, 'course' => 0, 'series' => 0, 'all' => count($post_ids));
        foreach ($post_ids as $post_id) {
            if ((int) get_post_meta((int) $post_id, MemberLibrary_Content_Model::META_COURSE_ID, true) > 0) {
                ++$counts['course'];
            } elseif ((int) get_post_meta((int) $post_id, MemberLibrary_Content_Model::META_SERIES_ID, true) > 0) {
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

        $meta_key = MemberLibrary_Content_Model::COURSE_POST_TYPE === $parent_post_type
            ? MemberLibrary_Content_Model::META_COURSE_ID
            : MemberLibrary_Content_Model::META_SERIES_ID;
        $counts = array();
        $content_ids = get_posts(array(
            'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
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
            'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
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
            $course_id = (int) get_post_meta($post->ID, MemberLibrary_Content_Model::META_COURSE_ID, true);
            $series_id = (int) get_post_meta($post->ID, MemberLibrary_Content_Model::META_SERIES_ID, true);
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
        return in_array((string) $post_type, MemberLibrary_Content_Model::post_types(), true);
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
        $provider_ui = $this->media_provider_ui();
        $selected_provider = isset($provider_ui[$provider]) ? $provider : 'vimeo';
        $field_id = 'tsol-library-media-' . sanitize_html_class((string) $index);
        ?>
        <div class="tsol-library-row tsol-library-media-row <?php echo '' !== $provider ? 'is-normalized' : ''; ?>" data-media-row data-original-url="<?php echo esc_attr($url); ?>">
            <div class="tsol-library-row__toolbar">
                <span class="tsol-library-row__handle dashicons dashicons-menu" aria-hidden="true"></span>
                <strong data-media-summary><?php echo esc_html($label ?: __('Untitled media', 'member-library')); ?></strong>
                <span class="tsol-library-media-role" data-media-role></span>
                <div class="tsol-library-row__actions">
                    <button type="button" class="button-link" data-row-up aria-label="<?php esc_attr_e('Move media up', 'member-library'); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button-link" data-row-down aria-label="<?php esc_attr_e('Move media down', 'member-library'); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button-link-delete" data-media-remove><?php esc_html_e('Remove', 'member-library'); ?></button>
                </div>
            </div>

            <div class="tsol-library-row__body">
                <div class="tsol-library-field tsol-library-media-source">
                    <label for="<?php echo esc_attr($field_id); ?>-provider"><?php esc_html_e('Video source', 'member-library'); ?></label>
                    <select id="<?php echo esc_attr($field_id); ?>-provider" data-media-provider>
                        <?php foreach ($provider_ui as $provider_key => $provider_config) : ?>
                            <option value="<?php echo esc_attr($provider_key); ?>" <?php selected($selected_provider, $provider_key); ?>><?php echo esc_html($provider_config['label']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description" data-media-provider-help><?php echo esc_html($provider_ui[$selected_provider]['help']); ?></p>
                </div>

                <div class="tsol-library-field tsol-library-field--wide">
                    <label for="<?php echo esc_attr($field_id); ?>-url" data-media-url-label><?php echo esc_html($provider_ui[$selected_provider]['urlLabel']); ?></label>
                    <div class="tsol-library-url-control">
                        <input type="url" id="<?php echo esc_attr($field_id); ?>-url" name="<?php echo esc_attr($name); ?>[source_url]" value="<?php echo esc_attr($url); ?>" placeholder="<?php echo esc_attr($provider_ui[$selected_provider]['placeholder']); ?>" inputmode="url" data-media-url />
                        <button type="button" class="button" data-media-library <?php echo 'wordpress' === $selected_provider ? '' : 'hidden'; ?>><?php esc_html_e('Choose from Media Library', 'member-library'); ?></button>
                    </div>
                    <p class="description" data-media-url-help><?php echo esc_html($provider_ui[$selected_provider]['urlHelp']); ?></p>
                </div>
                <input type="hidden" name="<?php echo esc_attr($name); ?>[label]" value="<?php echo esc_attr($label); ?>" />
                <input type="hidden" name="<?php echo esc_attr($name); ?>[duration_seconds]" value="<?php echo esc_attr($duration); ?>" />
                <input type="hidden" name="<?php echo esc_attr($name); ?>[preview]" value="<?php echo $preview ? '1' : '0'; ?>" />

                <div class="notice notice-warning inline tsol-library-media-replacement" data-media-replacement-warning hidden>
                    <p><strong><?php esc_html_e('This replaces the current media identity.', 'member-library'); ?></strong> <?php esc_html_e('Existing progress and notes remain attached to the previous source and will not carry over.', 'member-library'); ?></p>
                </div>

                <div class="tsol-library-media-result" data-media-result role="status" aria-live="polite">
                    <?php if ('' !== $provider) : ?>
                        <span class="tsol-library-provider-badge"><?php echo esc_html($this->provider_label($provider)); ?></span>
                        <?php if ('' !== $provider_id) : ?><span><?php esc_html_e('ID', 'member-library'); ?> <code><?php echo esc_html($provider_id); ?></code></span><?php endif; ?>
                        <?php if ('' !== $privacy_hash) : ?><span><span class="dashicons dashicons-lock" aria-hidden="true"></span> <?php esc_html_e('Private Vimeo reference detected', 'member-library'); ?></span><?php endif; ?>
                        <?php if ($attachment_id > 0) : ?><span><?php esc_html_e('WordPress attachment', 'member-library'); ?> <code>#<?php echo esc_html((string) $attachment_id); ?></code></span><?php endif; ?>
                    <?php else : ?>
                        <span><?php esc_html_e('Paste a URL to infer its provider details.', 'member-library'); ?></span>
                    <?php endif; ?>
                </div>

                <div class="tsol-library-media-test-actions">
                    <button type="button" class="button button-secondary" data-media-test aria-controls="<?php echo esc_attr($field_id); ?>-test-player" aria-expanded="false" disabled><?php esc_html_e('Test playback', 'member-library'); ?></button>
                    <span class="description"><?php esc_html_e('Loads the selected source only when you request it.', 'member-library'); ?></span>
                </div>
                <div class="tsol-library-media-test" id="<?php echo esc_attr($field_id); ?>-test-player" data-media-test-player hidden></div>

            </div>
        </div>
        <?php
    }

    private function render_learning_outcome_row($index, $outcome) {
        $outcome = trim((string) $outcome);
        $separator = ' — ';
        $separator_position = strpos($outcome, $separator);
        $title = false === $separator_position ? $outcome : trim(substr($outcome, 0, $separator_position));
        $description = false === $separator_position ? '' : trim(substr($outcome, $separator_position + strlen($separator)));
        $name = self::PAYLOAD_NAME . '[learning_outcomes][' . $index . ']';
        $title_id = 'tsol-library-outcome-title-' . $index;
        $description_id = 'tsol-library-outcome-description-' . $index;
        ?>
        <div class="tsol-library-outcome-row" data-outcome-row>
            <div class="tsol-library-outcome-row__order">
                <button type="button" class="button-link tsol-library-outcome-row__handle" data-outcome-handle>
                    <span class="dashicons dashicons-menu" aria-hidden="true"></span>
                    <span class="screen-reader-text"><?php esc_html_e('Drag to reorder outcome', 'member-library'); ?></span>
                </button>
                <strong class="tsol-library-outcome-row__number" data-outcome-position><?php echo esc_html((string) ((int) $index + 1)); ?></strong>
            </div>
            <div class="tsol-library-outcome-row__content">
                <div class="tsol-library-field tsol-library-outcome-row__title">
                    <label for="<?php echo esc_attr($title_id); ?>"><?php esc_html_e('Outcome title', 'member-library'); ?></label>
                    <input id="<?php echo esc_attr($title_id); ?>" type="text" name="<?php echo esc_attr($name); ?>[title]" value="<?php echo esc_attr($title); ?>" placeholder="<?php esc_attr_e('e.g. Choose the right working weight', 'member-library'); ?>" data-outcome-title />
                </div>
                <div class="tsol-library-field tsol-library-outcome-row__description">
                    <label for="<?php echo esc_attr($description_id); ?>"><?php esc_html_e('Supporting text', 'member-library'); ?> <span class="tsol-library-field__optional"><?php esc_html_e('(optional)', 'member-library'); ?></span></label>
                    <textarea id="<?php echo esc_attr($description_id); ?>" name="<?php echo esc_attr($name); ?>[description]" rows="2" placeholder="<?php esc_attr_e('e.g. Calculate the correct starting weight instead of guessing.', 'member-library'); ?>" data-outcome-description><?php echo esc_textarea($description); ?></textarea>
                </div>
            </div>
            <div class="tsol-library-outcome-row__actions">
                <div class="tsol-library-outcome-row__move-actions">
                    <button type="button" class="button-link" data-row-up>
                        <span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
                        <span class="screen-reader-text"><?php esc_html_e('Move outcome up', 'member-library'); ?></span>
                    </button>
                    <button type="button" class="button-link" data-row-down>
                        <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                        <span class="screen-reader-text"><?php esc_html_e('Move outcome down', 'member-library'); ?></span>
                    </button>
                </div>
                <button type="button" class="button-link button-link-delete" data-outcome-remove><?php esc_html_e('Remove', 'member-library'); ?></button>
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
                <strong data-resource-summary><?php echo esc_html($label ?: __('Untitled resource', 'member-library')); ?></strong>
                <div class="tsol-library-row__actions">
                    <button type="button" class="button-link" data-row-up aria-label="<?php esc_attr_e('Move resource up', 'member-library'); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button-link" data-row-down aria-label="<?php esc_attr_e('Move resource down', 'member-library'); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button-link-delete" data-resource-remove><?php esc_html_e('Remove', 'member-library'); ?></button>
                </div>
            </div>
            <div class="tsol-library-row__body">
                <div class="tsol-library-field-grid">
                    <div class="tsol-library-field">
                        <label><?php esc_html_e('Label', 'member-library'); ?></label>
                        <input type="text" name="<?php echo esc_attr($name); ?>[label]" value="<?php echo esc_attr($label); ?>" placeholder="<?php esc_attr_e('Worksheet or reference name', 'member-library'); ?>" data-resource-label />
                    </div>
                    <div class="tsol-library-field">
                        <label><?php esc_html_e('Resource type', 'member-library'); ?></label>
                        <select name="<?php echo esc_attr($name); ?>[type]">
                            <option value="link" <?php selected($type, 'link'); ?>><?php esc_html_e('Reference link', 'member-library'); ?></option>
                            <option value="download" <?php selected($type, 'download'); ?>><?php esc_html_e('Download', 'member-library'); ?></option>
                        </select>
                    </div>
                </div>
                <div class="tsol-library-field tsol-library-field--wide">
                    <label><?php esc_html_e('Resource URL', 'member-library'); ?></label>
                    <div class="tsol-library-url-control">
                        <input type="url" name="<?php echo esc_attr($name); ?>[url]" value="<?php echo esc_attr($url); ?>" placeholder="https://…" data-resource-url />
                        <input type="hidden" name="<?php echo esc_attr($name); ?>[attachment_id]" value="<?php echo esc_attr($attachment_id); ?>" data-resource-attachment />
                        <button type="button" class="button" data-resource-library><?php esc_html_e('Choose file', 'member-library'); ?></button>
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
            $normalized = MemberLibrary_Media_Normalizer::normalize_asset($row, count($items) + 1);
            if (is_wp_error($normalized)) {
                $errors[] = sprintf(__('Media row %1$d: %2$s', 'member-library'), $index + 1, $normalized->get_error_message());
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
                $errors[] = sprintf(__('Resource row %d requires a valid absolute URL.', 'member-library'), $index + 1);
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
        $registry = MemberLibrary_Structure::registry($parent_id);
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
            MemberLibrary_Content_Model::COURSE_POST_TYPE,
            MemberLibrary_Content_Model::SERIES_POST_TYPE,
        ), true)) {
            return null;
        }

        $group = array(
            'key' => MemberLibrary_Structure::new_group_key(
                MemberLibrary_Content_Model::COURSE_POST_TYPE === $post_type ? 'section' : 'group',
                MemberLibrary_Content_Model::COURSE_POST_TYPE === $post_type ? 'course-content' : 'episodes'
            ),
            'title' => MemberLibrary_Content_Model::COURSE_POST_TYPE === $post_type
                ? __('Course content', 'member-library')
                : __('Series episodes', 'member-library'),
            'position' => 1,
        );
        update_post_meta($parent_id, MemberLibrary_Structure::registry_meta_key($post_type), array($group));
        return $group;
    }

    private function ensure_legacy_structure_group($parent_id, $key, $title, $position) {
        $parent_id = (int) $parent_id;
        $key = sanitize_key((string) $key);
        $title = sanitize_text_field((string) $title);
        if ('' === $key || '' === $title) {
            return;
        }
        $registry = MemberLibrary_Structure::registry($parent_id);
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
            MemberLibrary_Structure::registry_meta_key(get_post_type($parent_id)),
            MemberLibrary_Content_Model::sanitize_structure_registry($registry)
        );
    }

    private function next_structure_item_position($post_id, $course_id, $series_id, $section_key) {
        $parent_meta_key = $course_id > 0
            ? MemberLibrary_Content_Model::META_COURSE_ID
            : MemberLibrary_Content_Model::META_SERIES_ID;
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
                'key' => MemberLibrary_Content_Model::META_SECTION_KEY,
                'value' => sanitize_key((string) $section_key),
                'compare' => '=',
            );
        }
        $item_ids = get_posts(array(
            'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
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
            $maximum = max($maximum, (int) get_post_meta((int) $item_id, MemberLibrary_Content_Model::META_POSITION, true));
        }
        return $maximum + 1;
    }

    private function derived_content_type(WP_Post $post, $course_id = 0) {
        if (MemberLibrary_Content_Model::COURSE_POST_TYPE === $post->post_type) {
            return 'course';
        }
        if (MemberLibrary_Content_Model::SERIES_POST_TYPE === $post->post_type) {
            return 'series';
        }
        return (int) $course_id > 0 ? 'lesson' : 'recording';
    }

    private function provider_label($provider) {
        $labels = array(
            'vimeo' => __('Vimeo', 'member-library'),
            'youtube' => __('YouTube', 'member-library'),
            'wordpress' => __('WordPress media', 'member-library'),
            'external' => __('External media', 'member-library'),
        );
        return isset($labels[$provider]) ? $labels[$provider] : ucfirst((string) $provider);
    }

    private function media_provider_ui() {
        return array(
            'vimeo' => array(
                'label' => __('Vimeo', 'member-library'),
                'urlLabel' => __('Vimeo video URL', 'member-library'),
                'placeholder' => 'https://vimeo.com/123456789/abcdef12',
                'help' => __('Use the public or private Vimeo page URL, including its privacy reference when present.', 'member-library'),
                'urlHelp' => __('Vimeo page and player URLs are supported.', 'member-library'),
            ),
            'youtube' => array(
                'label' => __('YouTube', 'member-library'),
                'urlLabel' => __('YouTube video URL', 'member-library'),
                'placeholder' => 'https://www.youtube.com/watch?v=…',
                'help' => __('Use a standard YouTube, youtu.be, Shorts, or embed URL.', 'member-library'),
                'urlHelp' => __('Playback uses YouTube privacy-enhanced mode in the Library.', 'member-library'),
            ),
            'wordpress' => array(
                'label' => __('WordPress Media Library', 'member-library'),
                'urlLabel' => __('WordPress video or audio URL', 'member-library'),
                'placeholder' => __('Choose an uploaded video or audio file', 'member-library'),
                'help' => __('Choose a permanent video or audio attachment from this site’s Media Library.', 'member-library'),
                'urlHelp' => __('The attachment must remain available at this URL.', 'member-library'),
            ),
            'external' => array(
                'label' => __('Direct video or audio URL', 'member-library'),
                'urlLabel' => __('Direct media URL', 'member-library'),
                'placeholder' => 'https://media.example.com/video.mp4',
                'help' => __('Use a permanent HTTPS URL ending in a supported video or audio file extension.', 'member-library'),
                'urlHelp' => __('Do not use expiring, signed, download-page, or document URLs.', 'member-library'),
            ),
        );
    }

    private function media_preview_url($asset) {
        $provider = isset($asset['provider']) ? sanitize_key($asset['provider']) : '';
        $provider_id = isset($asset['provider_id']) ? sanitize_text_field($asset['provider_id']) : '';
        if ('vimeo' === $provider && preg_match('/^\d+$/', $provider_id)) {
            $url = 'https://player.vimeo.com/video/' . rawurlencode($provider_id);
            $privacy_hash = isset($asset['privacy_hash']) ? sanitize_text_field($asset['privacy_hash']) : '';
            return '' !== $privacy_hash ? add_query_arg('h', $privacy_hash, $url) : $url;
        }
        if ('youtube' === $provider && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $provider_id)) {
            return 'https://www.youtube-nocookie.com/embed/' . rawurlencode($provider_id);
        }
        return isset($asset['source_url']) ? esc_url_raw($asset['source_url']) : '';
    }

    private function is_absolute_http_url($url) {
        $parts = wp_parse_url($url);
        return is_array($parts)
            && !empty($parts['host'])
            && isset($parts['scheme'])
            && in_array(strtolower($parts['scheme']), array('http', 'https'), true);
    }

    private function has_provenance($post_id) {
        $source_id = (int) get_post_meta($post_id, MemberLibrary_Content_Model::META_LEGACY_SOURCE_ID, true);
        $source_type = trim((string) get_post_meta($post_id, MemberLibrary_Content_Model::META_LEGACY_SOURCE_TYPE, true));
        $migration_version = trim((string) get_post_meta($post_id, MemberLibrary_Content_Model::META_MIGRATION_VERSION, true));

        return $source_id > 0 && '' !== $source_type && '' !== $migration_version;
    }

    private function store_notice($post_id, $errors) {
        set_transient($this->notice_key($post_id), array_values(array_unique($errors)), 5 * MINUTE_IN_SECONDS);
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
