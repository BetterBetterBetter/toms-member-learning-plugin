<?php
/**
 * Dedicated full-width wp-admin structure builder for Courses and Series.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Structure_Admin {

    const PAGE_SLUG = 'tsol-library-structure';
    const AJAX_ACTION = 'tsol_library_save_structure';
    const NONCE_ACTION = 'tsol_library_structure';
    const RETURN_ARG = 'tsol_return_to_structure';

    private $page_hook = '';

    public function init() {
        add_action('admin_menu', array($this, 'register_page'), 30);
        add_action('admin_head', array($this, 'hide_page_from_submenu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_ajax_' . self::AJAX_ACTION, array($this, 'ajax_save'));
        add_filter('parent_file', array($this, 'filter_parent_file'), 99);
        add_filter('submenu_file', array($this, 'filter_submenu_file'), 99, 2);
        add_filter('post_row_actions', array($this, 'add_row_action'), 10, 2);
        add_action('edit_form_top', array($this, 'render_contextual_return'));
        add_filter('redirect_post_location', array($this, 'preserve_contextual_return'), 10, 2);
    }

    public function register_page() {
        $this->page_hook = (string) add_submenu_page(
            MemberLibrary_Admin_Navigation::MENU_SLUG,
            __('Library Structure Builder', 'member-library'),
            __('Structure Builder', 'member-library'),
            'edit_pages',
            self::PAGE_SLUG,
            array($this, 'render_page')
        );
    }

    public function hide_page_from_submenu() {
        // This runs after admin.php's capability/page lookup but before the
        // menu is rendered. The builder remains an in-context destination
        // while WordPress retains the correct Library parent hierarchy.
        remove_submenu_page(MemberLibrary_Admin_Navigation::MENU_SLUG, self::PAGE_SLUG);
    }

    public static function url($parent_id) {
        return add_query_arg(array(
            'page' => self::PAGE_SLUG,
            'parent_id' => (int) $parent_id,
        ), admin_url('admin.php'));
    }

    public function enqueue_assets($hook) {
        if (self::PAGE_SLUG !== $this->requested_page() || ('' !== $this->page_hook && $hook !== $this->page_hook)) {
            return;
        }

        $parent_id = isset($_GET['parent_id']) ? absint(wp_unslash($_GET['parent_id'])) : 0;
        if ($parent_id <= 0 || !current_user_can('edit_post', $parent_id)) {
            return;
        }

        wp_enqueue_style(
            'tsol-library-structure-builder',
            MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-structure-builder.css',
            array(),
            MEMBER_LIBRARY_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'tsol-library-structure-builder',
            MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-structure-builder.js',
            array('jquery', 'jquery-ui-sortable'),
            MEMBER_LIBRARY_PLUGIN_VERSION,
            true
        );
        wp_localize_script('tsol-library-structure-builder', 'tsolLibraryStructureBuilder', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action' => self::AJAX_ACTION,
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'messages' => array(
                'unsaved' => __('You have unsaved structure changes.', 'member-library'),
                'saving' => __('Saving structure…', 'member-library'),
                'saved' => __('Structure saved.', 'member-library'),
                'saveError' => __('The structure could not be saved.', 'member-library'),
                'filteredSorting' => __('Clear the search before reordering.', 'member-library'),
                'groupTitle' => __('Group title', 'member-library'),
                'newGroup' => __('New group', 'member-library'),
                'emptyGroup' => __('No content in this group yet.', 'member-library'),
                'removeGroup' => __('Remove empty group', 'member-library'),
            ),
        ));
    }

    public function render_page() {
        $parent_id = isset($_GET['parent_id']) ? absint(wp_unslash($_GET['parent_id'])) : 0;
        if ($parent_id <= 0 || !current_user_can('edit_post', $parent_id)) {
            wp_die(esc_html__('You are not allowed to edit this Library structure.', 'member-library'));
        }

        $snapshot = MemberLibrary_Structure::snapshot($parent_id);
        if (is_wp_error($snapshot)) {
            wp_die(esc_html($snapshot->get_error_message()));
        }

        $item_plural = 1 === (int) $snapshot['itemCount']
            ? (string) $snapshot['itemLabel']
            : $this->pluralize((string) $snapshot['itemLabel']);
        $group_plural = 1 === (int) $snapshot['groupCount']
            ? (string) $snapshot['groupLabel']
            : $this->pluralize((string) $snapshot['groupLabel']);
        ?>
        <div class="wrap tsol-library-structure-page" data-structure-builder data-parent-id="<?php echo esc_attr((string) $parent_id); ?>" data-revision="<?php echo esc_attr((string) $snapshot['revision']); ?>" data-item-label="<?php echo esc_attr((string) $snapshot['itemLabel']); ?>" data-item-plural="<?php echo esc_attr($this->pluralize((string) $snapshot['itemLabel'])); ?>" data-start-collapsed="<?php echo (int) $snapshot['itemCount'] > 12 ? 'true' : 'false'; ?>">
            <a class="tsol-library-structure-page__back" href="<?php echo esc_url((string) $snapshot['parentEditUrl']); ?>">
                <span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span>
                <?php echo esc_html(sprintf(__('Back to %s', 'member-library'), (string) $snapshot['parentTitle'])); ?>
            </a>

            <div class="tsol-library-structure-page__heading">
                <div>
                    <h1><?php echo esc_html(sprintf(__('%s structure', 'member-library'), (string) $snapshot['parentTitle'])); ?></h1>
                    <p class="description">
                        <?php
                        echo esc_html(sprintf(
                            __('Arrange %1$s %2$s in %3$s %4$s. This changes catalogue presentation only; MemberPress access is not changed here.', 'member-library'),
                            number_format_i18n((int) $snapshot['itemCount']),
                            $item_plural,
                            number_format_i18n((int) $snapshot['groupCount']),
                            $group_plural
                        ));
                        ?>
                    </p>
                    <?php if (!empty($snapshot['descending'])) : ?>
                        <p class="tsol-library-structure-page__order-note">
                            <span class="dashicons dashicons-sort" aria-hidden="true"></span>
                            <?php
                            echo esc_html(sprintf(
                                /* translators: %s: configured member-facing application name. */
                                __('Shown in the same newest-first order visitors see in %s.', 'member-library'),
                                MemberLibrary_Brand::app_name()
                            ));
                            ?>
                        </p>
                    <?php endif; ?>
                </div>
                <button type="button" class="button button-primary button-hero" data-structure-save disabled><?php esc_html_e('Save structure', 'member-library'); ?></button>
            </div>

            <div class="notice notice-error inline tsol-library-structure-page__notice" data-structure-error hidden><p></p></div>

            <div class="tsol-library-structure-toolbar">
                <?php if ((int) $snapshot['itemCount'] > 12) : ?>
                    <label class="tsol-library-structure-toolbar__search">
                        <span class="screen-reader-text"><?php esc_html_e('Search content in this structure', 'member-library'); ?></span>
                        <span class="dashicons dashicons-search" aria-hidden="true"></span>
                        <input type="search" data-structure-search placeholder="<?php esc_attr_e('Search content…', 'member-library'); ?>" />
                    </label>
                <?php endif; ?>
                <div class="tsol-library-structure-toolbar__actions">
                    <button type="button" class="button" data-structure-expand><?php esc_html_e('Expand all', 'member-library'); ?></button>
                    <button type="button" class="button" data-structure-collapse><?php esc_html_e('Collapse all', 'member-library'); ?></button>
                </div>
            </div>
            <p class="tsol-library-structure-toolbar__filter-note" data-structure-filter-note hidden></p>

            <div class="tsol-library-structure-groups" data-structure-groups>
                <?php foreach ($snapshot['groups'] as $group) : ?>
                    <?php $this->render_group($snapshot, $group); ?>
                <?php endforeach; ?>
            </div>

            <div class="tsol-library-structure-add-group">
                <button type="button" class="button button-secondary" data-structure-add-group>
                    <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                    <?php echo esc_html(sprintf(__('Add %s', 'member-library'), (string) $snapshot['groupLabel'])); ?>
                </button>
            </div>

            <template data-structure-group-template>
                <?php $this->render_group($snapshot, array('key' => '', 'title' => '', 'position' => 0, 'items' => array()), true); ?>
            </template>

            <div class="tsol-library-structure-page__footer">
                <p data-structure-status aria-live="polite"></p>
                <button type="button" class="button button-primary" data-structure-save disabled><?php esc_html_e('Save structure', 'member-library'); ?></button>
            </div>
        </div>
        <?php
    }

    public function render_compact_summary($post) {
        $snapshot = MemberLibrary_Structure::snapshot((int) $post->ID);
        if (is_wp_error($snapshot)) {
            echo '<p class="description">' . esc_html($snapshot->get_error_message()) . '</p>';
            return;
        }

        $group_word = 1 === (int) $snapshot['groupCount'] ? (string) $snapshot['groupLabel'] : $this->pluralize((string) $snapshot['groupLabel']);
        $item_word = 1 === (int) $snapshot['itemCount'] ? (string) $snapshot['itemLabel'] : $this->pluralize((string) $snapshot['itemLabel']);
        ?>
        <div class="tsol-library-structure-summary">
            <div class="tsol-library-structure-summary__counts" aria-label="<?php esc_attr_e('Structure summary', 'member-library'); ?>">
                <span><strong><?php echo esc_html(number_format_i18n((int) $snapshot['groupCount'])); ?></strong> <?php echo esc_html($group_word); ?></span>
                <span><strong><?php echo esc_html(number_format_i18n((int) $snapshot['itemCount'])); ?></strong> <?php echo esc_html($item_word); ?></span>
            </div>
            <?php if (empty($snapshot['groups'])) : ?>
                <p class="description"><?php esc_html_e('No structure has been created yet.', 'member-library'); ?></p>
            <?php else : ?>
                <ul class="tsol-library-structure-summary__groups">
                    <?php foreach (array_slice($snapshot['groups'], 0, 5) as $group) : ?>
                        <li><span><?php echo esc_html((string) $group['title']); ?></span><span><?php echo esc_html(number_format_i18n(count($group['items']))); ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($snapshot['groups']) > 5) : ?>
                    <p class="description"><?php echo esc_html(sprintf(__('%s more groups are available in the builder.', 'member-library'), number_format_i18n(count($snapshot['groups']) - 5))); ?></p>
                <?php endif; ?>
            <?php endif; ?>
            <p class="tsol-library-structure-summary__actions">
                <a class="button button-primary" href="<?php echo esc_url(self::url((int) $post->ID)); ?>"><?php esc_html_e('Open structure builder', 'member-library'); ?></a>
                <?php
                $new_item_url = add_query_arg(array(
                    'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
                    MemberLibrary_Content_Model::COURSE_POST_TYPE === $post->post_type ? 'tsol_course_id' : 'tsol_series_id' => (int) $post->ID,
                ), admin_url('post-new.php'));
                ?>
                <a class="button" href="<?php echo esc_url($new_item_url); ?>"><?php echo esc_html(sprintf(__('Add %s', 'member-library'), (string) $snapshot['itemLabel'])); ?></a>
            </p>
        </div>
        <?php
    }

    public function ajax_save() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $parent_id = isset($_POST['parent_id']) ? absint(wp_unslash($_POST['parent_id'])) : 0;
        if ($parent_id <= 0 || !current_user_can('edit_post', $parent_id)) {
            wp_send_json_error(array('message' => __('You are not allowed to edit this Library structure.', 'member-library')), 403);
        }

        $revision = isset($_POST['revision']) ? sanitize_text_field(wp_unslash($_POST['revision'])) : '';
        $raw_structure = isset($_POST['structure']) ? wp_unslash($_POST['structure']) : '';
        $structure = json_decode((string) $raw_structure, true);
        if (!is_array($structure)) {
            wp_send_json_error(array('message' => __('The submitted structure could not be read.', 'member-library')), 400);
        }

        $result = MemberLibrary_Structure::save_display_structure($parent_id, $structure, $revision);
        if (is_wp_error($result)) {
            $status = 'structure_conflict' === $result->get_error_code() ? 409 : 400;
            wp_send_json_error(array(
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), $status);
        }

        wp_send_json_success(array(
            'revision' => (string) $result['revision'],
            'groupCount' => (int) $result['groupCount'],
            'itemCount' => (int) $result['itemCount'],
            'message' => __('Structure saved.', 'member-library'),
        ));
    }

    public function add_row_action($actions, $post) {
        if (!$post instanceof WP_Post || !in_array($post->post_type, array(
            MemberLibrary_Content_Model::COURSE_POST_TYPE,
            MemberLibrary_Content_Model::SERIES_POST_TYPE,
        ), true) || !current_user_can('edit_post', $post->ID)) {
            return $actions;
        }

        $actions['tsol_structure'] = sprintf(
            '<a href="%s">%s</a>',
            esc_url(self::url((int) $post->ID)),
            esc_html__('Manage structure', 'member-library')
        );
        return $actions;
    }

    public function filter_parent_file($parent_file) {
        return self::PAGE_SLUG === $this->requested_page()
            ? MemberLibrary_Admin_Navigation::MENU_SLUG
            : $parent_file;
    }

    public function filter_submenu_file($submenu_file, $parent_file) {
        if (self::PAGE_SLUG !== $this->requested_page()) {
            return $submenu_file;
        }
        $parent_id = isset($_GET['parent_id']) ? absint(wp_unslash($_GET['parent_id'])) : 0;
        $post_type = get_post_type($parent_id);
        return in_array($post_type, array(
            MemberLibrary_Content_Model::COURSE_POST_TYPE,
            MemberLibrary_Content_Model::SERIES_POST_TYPE,
        ), true) ? 'edit.php?post_type=' . $post_type : $submenu_file;
    }

    private function render_group($snapshot, $group, $template = false) {
        $key = isset($group['key']) ? (string) $group['key'] : '';
        $title = isset($group['title']) ? (string) $group['title'] : '';
        $items = isset($group['items']) && is_array($group['items']) ? $group['items'] : array();
        $display_title = '' !== $title ? $title : __('New group', 'member-library');
        ?>
        <section class="tsol-library-structure-group" data-structure-group data-group-key="<?php echo esc_attr($key); ?>"<?php echo $template ? ' data-template-group' : ''; ?>>
            <header class="tsol-library-structure-group__header">
                <button type="button" class="button-link tsol-library-structure-handle tsol-library-structure-handle--group" data-group-handle aria-label="<?php echo esc_attr(sprintf(__('Drag %s to reorder', 'member-library'), $display_title)); ?>" title="<?php esc_attr_e('Drag to reorder', 'member-library'); ?>">
                    <span class="dashicons dashicons-move" aria-hidden="true"></span>
                </button>
                <button type="button" class="button-link tsol-library-structure-disclosure" data-group-toggle aria-expanded="true" aria-label="<?php echo esc_attr(sprintf(__('Collapse %s', 'member-library'), $display_title)); ?>">
                    <span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
                </button>
                <div class="tsol-library-structure-group__name">
                    <label>
                        <span class="screen-reader-text"><?php echo esc_html(sprintf(__('%s title', 'member-library'), ucfirst((string) $snapshot['groupLabel']))); ?></span>
                        <input type="text" value="<?php echo esc_attr($title); ?>" data-group-title required maxlength="200" placeholder="<?php echo esc_attr(sprintf(__('%s title', 'member-library'), ucfirst((string) $snapshot['groupLabel']))); ?>" />
                    </label>
                    <span data-group-count>
                        <?php
                        echo esc_html(sprintf(
                            '%1$s %2$s',
                            number_format_i18n(count($items)),
                            1 === count($items) ? (string) $snapshot['itemLabel'] : $this->pluralize((string) $snapshot['itemLabel'])
                        ));
                        ?>
                    </span>
                </div>
                <div class="tsol-library-structure-group__controls">
                    <button type="button" class="button button-small" data-group-up aria-label="<?php echo esc_attr(sprintf(__('Move %s up', 'member-library'), $display_title)); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button button-small" data-group-down aria-label="<?php echo esc_attr(sprintf(__('Move %s down', 'member-library'), $display_title)); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
                    <button type="button" class="button button-small tsol-library-structure-group__remove" data-group-remove<?php echo !empty($items) ? ' disabled' : ''; ?>><?php esc_html_e('Remove', 'member-library'); ?></button>
                </div>
            </header>
            <div class="tsol-library-structure-group__body" data-group-body>
                <ul class="tsol-library-structure-items" data-structure-items>
                    <?php foreach ($items as $item) : ?>
                        <?php $this->render_item($item, $snapshot['groups'], (int) $snapshot['parentId']); ?>
                    <?php endforeach; ?>
                </ul>
                <p class="tsol-library-structure-group__empty" data-group-empty<?php echo !empty($items) ? ' hidden' : ''; ?>><?php esc_html_e('No content in this group yet.', 'member-library'); ?></p>
                <div class="tsol-library-structure-group__footer">
                    <?php
                    $add_args = array(
                        'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
                        'tsol_structure_key' => $key,
                        self::RETURN_ARG => (int) $snapshot['parentId'],
                    );
                    if (MemberLibrary_Content_Model::COURSE_POST_TYPE === $snapshot['parentType']) {
                        $add_args['tsol_course_id'] = (int) $snapshot['parentId'];
                    } else {
                        $add_args['tsol_series_id'] = (int) $snapshot['parentId'];
                    }
                    ?>
                    <a class="button button-small" data-group-add-item href="<?php echo esc_url(add_query_arg($add_args, admin_url('post-new.php'))); ?>">
                        <span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span>
                        <?php echo esc_html(sprintf(__('Add %s here', 'member-library'), (string) $snapshot['itemLabel'])); ?>
                    </a>
                </div>
            </div>
        </section>
        <?php
    }

    private function render_item($item, $groups, $parent_id) {
        $edit_url = add_query_arg(
            self::RETURN_ARG,
            (int) $parent_id,
            (string) $item['editUrl']
        );
        $is_coming_soon = MemberLibrary_Content_Model::AVAILABILITY_COMING_SOON === (string) ($item['availability'] ?? '');
        $availability_label = __('Coming soon', 'member-library');
        if ($is_coming_soon && !empty($item['releaseAt'])) {
            $release_date_local = get_date_from_gmt((string) $item['releaseAt'], 'Y-m-d');
            $availability_label = $release_date_local < current_datetime()->format('Y-m-d')
                ? __('Coming soon · release date passed', 'member-library')
                : sprintf(
                    __('Coming soon · %s', 'member-library'),
                    get_date_from_gmt(
                        (string) $item['releaseAt'],
                        get_option('date_format')
                    )
                );
        }
        ?>
        <li class="tsol-library-structure-item" data-structure-item data-item-id="<?php echo esc_attr((string) $item['id']); ?>" data-search-text="<?php echo esc_attr(strtolower((string) $item['title'] . ' ' . (string) $item['statusLabel'] . ($is_coming_soon ? ' ' . $availability_label : ''))); ?>">
            <button type="button" class="button-link tsol-library-structure-handle" data-item-handle aria-label="<?php echo esc_attr(sprintf(__('Drag %s to reorder', 'member-library'), (string) $item['title'])); ?>" title="<?php esc_attr_e('Drag to reorder', 'member-library'); ?>">
                <span class="dashicons dashicons-move" aria-hidden="true"></span>
            </button>
            <div class="tsol-library-structure-item__identity">
                <a href="<?php echo esc_url($edit_url); ?>"><?php echo esc_html((string) $item['title']); ?></a>
                <span class="tsol-library-structure-status tsol-library-structure-status--<?php echo esc_attr(sanitize_html_class((string) $item['status'])); ?>"><?php echo esc_html((string) $item['statusLabel']); ?></span>
                <?php if ($is_coming_soon) : ?>
                    <span class="tsol-library-structure-status tsol-library-structure-status--coming-soon"><?php echo esc_html($availability_label); ?></span>
                <?php endif; ?>
            </div>
            <label class="tsol-library-structure-item__move">
                <span><?php esc_html_e('Move to', 'member-library'); ?></span>
                <select data-item-group-select>
                    <?php foreach ($groups as $group) : ?>
                        <option value="<?php echo esc_attr((string) $group['key']); ?>"><?php echo esc_html((string) $group['title']); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <div class="tsol-library-structure-item__controls">
                <button type="button" class="button button-small" data-item-up aria-label="<?php echo esc_attr(sprintf(__('Move %s up', 'member-library'), (string) $item['title'])); ?>"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span></button>
                <button type="button" class="button button-small" data-item-down aria-label="<?php echo esc_attr(sprintf(__('Move %s down', 'member-library'), (string) $item['title'])); ?>"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span></button>
            </div>
        </li>
        <?php
    }

    public function render_contextual_return($post) {
        if (!$post instanceof WP_Post || MemberLibrary_Content_Model::ITEM_POST_TYPE !== $post->post_type) {
            return;
        }

        $requested_parent_id = isset($_GET[self::RETURN_ARG])
            ? absint(wp_unslash($_GET[self::RETURN_ARG]))
            : 0;
        $parent_id = $this->valid_return_parent_id((int) $post->ID, $requested_parent_id);
        if ($parent_id <= 0) {
            return;
        }

        $parent = get_post($parent_id);
        $parent_label = MemberLibrary_Content_Model::COURSE_POST_TYPE === $parent->post_type
            ? __('Course', 'member-library')
            : __('Series', 'member-library');
        $parent_title = '' !== trim((string) $parent->post_title)
            ? (string) $parent->post_title
            : __('(no title)', 'member-library');
        ?>
        <div class="tsol-library-structure-return" data-structure-return>
            <a
                class="button"
                href="<?php echo esc_url(self::url($parent_id)); ?>"
                aria-label="<?php echo esc_attr(sprintf(__('Back to %1$s structure for %2$s', 'member-library'), $parent_label, $parent_title)); ?>"
            >
                <svg class="tsol-library-structure-return__icon" aria-hidden="true" viewBox="0 0 20 20" focusable="false">
                    <path d="M17 10H3M9 4l-6 6 6 6" />
                </svg>
                <?php echo esc_html(sprintf(__('Back to %s structure', 'member-library'), $parent_label)); ?>
            </a>
            <span class="tsol-library-structure-return__parent"><?php echo esc_html($parent_title); ?></span>
            <input type="hidden" name="<?php echo esc_attr(self::RETURN_ARG); ?>" value="<?php echo esc_attr((string) $parent_id); ?>" />
        </div>
        <?php
    }

    public function preserve_contextual_return($location, $post_id) {
        $requested_parent_id = isset($_POST[self::RETURN_ARG])
            ? absint(wp_unslash($_POST[self::RETURN_ARG]))
            : 0;
        $parent_id = $this->valid_return_parent_id((int) $post_id, $requested_parent_id);
        return $parent_id > 0
            ? add_query_arg(self::RETURN_ARG, $parent_id, $location)
            : $location;
    }

    private function valid_return_parent_id($item_id, $parent_id) {
        $item_id = (int) $item_id;
        $parent_id = (int) $parent_id;
        $item = get_post($item_id);
        $parent = get_post($parent_id);
        if (!$item instanceof WP_Post
            || MemberLibrary_Content_Model::ITEM_POST_TYPE !== $item->post_type
            || !$parent instanceof WP_Post
            || !in_array($parent->post_type, array(
                MemberLibrary_Content_Model::COURSE_POST_TYPE,
                MemberLibrary_Content_Model::SERIES_POST_TYPE,
            ), true)
            || !current_user_can('edit_post', $parent_id)
        ) {
            return 0;
        }

        $parent_meta_key = MemberLibrary_Structure::child_parent_meta_key($parent->post_type);
        if ($parent_id === (int) get_post_meta($item_id, $parent_meta_key, true)) {
            return $parent_id;
        }

        // New content opened from an Add action has not saved its placement yet.
        $requested_placement_arg = MemberLibrary_Content_Model::COURSE_POST_TYPE === $parent->post_type
            ? 'tsol_course_id'
            : 'tsol_series_id';
        if ('auto-draft' === $item->post_status
            && isset($_GET[$requested_placement_arg])
            && $parent_id === absint(wp_unslash($_GET[$requested_placement_arg]))
        ) {
            return $parent_id;
        }

        return 0;
    }

    private function requested_page() {
        return isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    }

    private function pluralize($word) {
        return preg_match('/s$/i', (string) $word) ? (string) $word : (string) $word . 's';
    }
}
