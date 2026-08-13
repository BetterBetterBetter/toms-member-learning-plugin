<?php
/**
 * Dedicated wp-admin navigation and read-only operational views for Library.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Admin_Navigation {

    const MENU_SLUG = 'tsol-library';
    const SETTINGS_SLUG = 'tsol-library-settings';
    const AUTH_SLUG = 'tsol-library-auth';
    const ACCESS_SLUG = 'tsol-library-access';
    const IMPORT_SLUG = 'tsol-library-import';
    const SETTINGS_TAB_AUTHENTICATION = 'authentication';
    const SETTINGS_TAB_IMPORT = 'import-legacy';
    const SETTINGS_TAB_ACCESS = 'access-overview';
    const SETTINGS_TAB_SYNC = 'sync-status';
    const RECORD_COUNT_COLUMN = 'tsol_library_records';

    private $taxonomy_record_counts = array();
    private $taxonomy_record_post_types = array();

    public function init() {
        add_action('admin_menu', array($this, 'add_root_menu'), 8);
        add_action('admin_menu', array($this, 'add_submenus'), 20);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'redirect_legacy_settings_pages'), 1);
        add_filter('parent_file', array($this, 'filter_parent_file'));
        add_filter('submenu_file', array($this, 'filter_submenu_file'), 10, 2);

        foreach ($this->library_taxonomies() as $taxonomy) {
            add_filter('manage_edit-' . $taxonomy . '_columns', array($this, 'filter_taxonomy_columns'));
            add_filter('manage_' . $taxonomy . '_custom_column', array($this, 'render_taxonomy_custom_column'), 10, 3);
        }
    }

    public function add_root_menu() {
        add_menu_page(
            __('TSOL Library', 'tomschooloflife-plugin'),
            __('TSOL Library', 'tomschooloflife-plugin'),
            'edit_pages',
            self::MENU_SLUG,
            array($this, 'render_dashboard'),
            'dashicons-video-alt3',
            58.5
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Library Dashboard', 'tomschooloflife-plugin'),
            __('Dashboard', 'tomschooloflife-plugin'),
            'edit_pages',
            self::MENU_SLUG,
            array($this, 'render_dashboard')
        );
    }

    public function add_submenus() {
        add_submenu_page(
            self::MENU_SLUG,
            __('Collections', 'tomschooloflife-plugin'),
            __('Collections', 'tomschooloflife-plugin'),
            'manage_categories',
            'edit-tags.php?taxonomy=' . TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY . '&post_type=' . TSOL_Library_Content_Model::COURSE_POST_TYPE
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Library Topics', 'tomschooloflife-plugin'),
            __('Topics', 'tomschooloflife-plugin'),
            'manage_categories',
            'edit-tags.php?taxonomy=' . TSOL_Library_Content_Model::TOPIC_TAXONOMY . '&post_type=' . TSOL_Library_Content_Model::ITEM_POST_TYPE
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('TSOL Library Settings', 'tomschooloflife-plugin'),
            __('Settings', 'tomschooloflife-plugin'),
            'edit_pages',
            self::SETTINGS_SLUG,
            array($this, 'render_settings')
        );

        $this->add_hidden_legacy_settings_page(
            self::AUTH_SLUG,
            __('Library Authentication', 'tomschooloflife-plugin'),
            'manage_options'
        );
        $this->add_hidden_legacy_settings_page(
            self::IMPORT_SLUG,
            __('Import & Legacy', 'tomschooloflife-plugin'),
            'manage_options'
        );
        $this->add_hidden_legacy_settings_page(
            self::ACCESS_SLUG,
            __('Library Access Overview', 'tomschooloflife-plugin'),
            'edit_pages'
        );
    }

    public function enqueue_assets($hook) {
        if (strpos((string) $hook, self::MENU_SLUG) === false) {
            return;
        }
        wp_enqueue_style(
            'tsol-library-content-admin',
            TSOL_SITE_PLUGIN_URL . 'assets/features/library-content/library-content-admin.css',
            array(),
            TSOL_SITE_PLUGIN_VERSION
        );
    }

    public function redirect_legacy_settings_pages() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $legacy_tabs = array(
            self::AUTH_SLUG => array(self::SETTINGS_TAB_AUTHENTICATION, 'manage_options'),
            self::IMPORT_SLUG => array(self::SETTINGS_TAB_IMPORT, 'manage_options'),
            self::ACCESS_SLUG => array(self::SETTINGS_TAB_ACCESS, 'edit_pages'),
        );
        if (!isset($legacy_tabs[$page]) || !current_user_can($legacy_tabs[$page][1])) {
            return;
        }

        wp_safe_redirect(self::settings_url($legacy_tabs[$page][0]));
        exit;
    }

    private function add_hidden_legacy_settings_page($slug, $title, $capability) {
        $hook = add_submenu_page(
            null,
            $title,
            $title,
            $capability,
            $slug,
            array($this, 'redirect_legacy_settings_pages')
        );
        if ($hook) {
            add_action('load-' . $hook, array($this, 'redirect_legacy_settings_pages'));
        }
    }

    public function filter_parent_file($parent_file) {
        if ('' !== $this->current_library_taxonomy()) {
            return self::MENU_SLUG;
        }

        return $parent_file;
    }

    public function filter_submenu_file($submenu_file, $parent_file) {
        $taxonomy = $this->current_library_taxonomy();
        if (self::MENU_SLUG === $parent_file && '' !== $taxonomy) {
            return $this->taxonomy_menu_slug($taxonomy);
        }

        return $submenu_file;
    }

    public function filter_taxonomy_columns($columns) {
        if (!is_array($columns) || !isset($columns['posts'])) {
            return $columns;
        }

        $filtered = array();
        foreach ($columns as $column => $label) {
            if ('posts' === $column) {
                $filtered[self::RECORD_COUNT_COLUMN] = _x('Count', 'Number/count of Library records', 'tomschooloflife-plugin');
                continue;
            }
            $filtered[$column] = $label;
        }

        return $filtered;
    }

    public function render_taxonomy_custom_column($output, $column_name, $term_id) {
        if (self::RECORD_COUNT_COLUMN !== $column_name) {
            return $output;
        }

        $taxonomy = $this->current_library_taxonomy();
        if ('' === $taxonomy) {
            return $output;
        }

        $counts = $this->taxonomy_record_counts($taxonomy);
        $count = isset($counts[(int) $term_id]) ? (int) $counts[(int) $term_id] : 0;
        $formatted_count = number_format_i18n($count);
        $description = __('Includes draft Library records.', 'tomschooloflife-plugin');
        $term = get_term((int) $term_id, $taxonomy);
        $post_types = isset($this->taxonomy_record_post_types[$taxonomy][(int) $term_id])
            ? $this->taxonomy_record_post_types[$taxonomy][(int) $term_id]
            : array();

        if (!is_wp_error($term) && $term instanceof WP_Term && count($post_types) <= 1) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $post_type = 1 === count($post_types)
                ? (string) reset($post_types)
                : ($screen && in_array($screen->post_type, TSOL_Library_Content_Model::post_types(), true)
                    ? (string) $screen->post_type
                    : TSOL_Library_Content_Model::ITEM_POST_TYPE);
            $url_args = array(
                'post_type' => $post_type,
                'taxonomy' => $taxonomy,
                'term' => $term->slug,
            );
            if (TSOL_Library_Content_Model::ITEM_POST_TYPE === $post_type) {
                $url_args[TSOL_Library_Content_Admin::CONTENT_SCOPE_FILTER] = TSOL_Library_Content_Admin::CONTENT_SCOPE_ALL;
            }
            $url = add_query_arg($url_args, admin_url('edit.php'));

            return sprintf(
                '<a href="%1$s" title="%2$s">%3$s</a>',
                esc_url($url),
                esc_attr($description),
                esc_html($formatted_count)
            );
        }

        return sprintf(
            '<span title="%s">%s</span>',
            esc_attr($description),
            esc_html($formatted_count)
        );
    }

    public function render_dashboard() {
        if (!current_user_can('edit_pages')) {
            return;
        }
        $counts = $this->content_counts();
        $course_url = admin_url('edit.php?post_type=' . TSOL_Library_Content_Model::COURSE_POST_TYPE);
        $series_url = admin_url('edit.php?post_type=' . TSOL_Library_Content_Model::SERIES_POST_TYPE);
        $content_url = add_query_arg(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            TSOL_Library_Content_Admin::CONTENT_SCOPE_FILTER => TSOL_Library_Content_Admin::CONTENT_SCOPE_ALL,
        ), admin_url('edit.php'));
        ?>
        <div class="wrap tsol-library-admin-page">
            <h1><?php esc_html_e('TSOL Library', 'tomschooloflife-plugin'); ?></h1>
            <p class="tsol-library-admin-page__lead"><?php esc_html_e('Build the new Library catalogue here. The existing MemberPress Courses area and all legacy pages remain separate and unchanged.', 'tomschooloflife-plugin'); ?></p>

            <div class="tsol-library-admin-stats">
                <?php $this->render_stat(__('Courses', 'tomschooloflife-plugin'), $counts['courses'], $course_url); ?>
                <?php $this->render_stat(__('Series', 'tomschooloflife-plugin'), $counts['series'], $series_url); ?>
                <?php $this->render_stat(__('Content', 'tomschooloflife-plugin'), $counts['content'], $content_url); ?>
                <?php $this->render_stat(__('Published', 'tomschooloflife-plugin'), $counts['published'], $content_url); ?>
                <?php $this->render_stat(__('Drafts', 'tomschooloflife-plugin'), $counts['drafts'], $content_url); ?>
            </div>

            <div class="tsol-library-admin-grid">
                <section class="card">
                    <h2><?php esc_html_e('Create and organize', 'tomschooloflife-plugin'); ?></h2>
                    <p><?php esc_html_e('Courses own intentional curricula. Series own related ordered videos, whether recurring or finite. Content may remain standalone only when it has no meaningful parent.', 'tomschooloflife-plugin'); ?></p>
                    <p>
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . TSOL_Library_Content_Model::COURSE_POST_TYPE)); ?>"><?php esc_html_e('Add course', 'tomschooloflife-plugin'); ?></a>
                        <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . TSOL_Library_Content_Model::SERIES_POST_TYPE)); ?>"><?php esc_html_e('Add series', 'tomschooloflife-plugin'); ?></a>
                        <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . TSOL_Library_Content_Model::ITEM_POST_TYPE)); ?>"><?php esc_html_e('Add content', 'tomschooloflife-plugin'); ?></a>
                    </p>
                </section>
                <section class="card">
                    <h2><?php esc_html_e('Access stays in MemberPress', 'tomschooloflife-plugin'); ?></h2>
                    <p><?php esc_html_e('Library records are real MemberPress Rule targets. Imported drafts continue to delegate to their untouched legacy source until an approved transition.', 'tomschooloflife-plugin'); ?></p>
                    <p><a class="button" href="<?php echo esc_url(self::settings_url(self::SETTINGS_TAB_ACCESS)); ?>"><?php esc_html_e('Review effective access', 'tomschooloflife-plugin'); ?></a></p>
                </section>
            </div>
        </div>
        <?php
    }

    public function render_settings() {
        if (!current_user_can('edit_pages')) {
            return;
        }

        $tabs = $this->available_settings_tabs();
        if (empty($tabs)) {
            return;
        }
        $active_tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : (string) array_key_first($tabs);
        if (!isset($tabs[$active_tab])) {
            $active_tab = (string) array_key_first($tabs);
        }
        ?>
        <div class="wrap tsol-library-admin-page tsol-library-settings-page">
            <h1><?php esc_html_e('TSOL Library Settings', 'tomschooloflife-plugin'); ?></h1>
            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Library settings sections', 'tomschooloflife-plugin'); ?>">
                <?php foreach ($tabs as $tab => $label) : ?>
                    <a
                        class="nav-tab <?php echo $active_tab === $tab ? 'nav-tab-active' : ''; ?>"
                        href="<?php echo esc_url(self::settings_url($tab)); ?>"
                        <?php if ($active_tab === $tab) : ?>aria-current="page"<?php endif; ?>
                    ><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <div class="tsol-library-settings-page__panel">
                <?php
                if (self::SETTINGS_TAB_AUTHENTICATION === $active_tab) {
                    $auth_settings = new TSOL_Library_Auth_Settings();
                    $auth_settings->render(true);
                } elseif (self::SETTINGS_TAB_IMPORT === $active_tab) {
                    $this->render_import(true);
                } elseif (self::SETTINGS_TAB_SYNC === $active_tab) {
                    $sync_status = new TSOL_Library_Catalogue_Sync_Status();
                    $sync_status->render();
                } else {
                    $this->render_access_overview(true);
                }
                ?>
            </div>
        </div>
        <?php
    }

    public function render_access_overview($embedded = false) {
        if (!current_user_can('edit_pages')) {
            return;
        }
        $summary = $this->access_counts();
        $access_migration = get_option('tsol_library_access_rules_migration_state', array());
        $access_migration = is_array($access_migration) ? $access_migration : array();
        $access_phase = (string) ($access_migration['phase'] ?? 'not_started');
        $staged_rule_count = count((array) ($access_migration['created_rule_ids'] ?? array()));
        ?>
        <?php if (!$embedded) : ?><div class="wrap tsol-library-admin-page"><?php endif; ?>
            <?php if ($embedded) : ?>
                <h2><?php esc_html_e('Library Access Overview', 'tomschooloflife-plugin'); ?></h2>
            <?php else : ?>
                <h1><?php esc_html_e('Library Access Overview', 'tomschooloflife-plugin'); ?></h1>
            <?php endif; ?>
            <p class="tsol-library-admin-page__lead"><?php esc_html_e('This is a read-only view of live MemberPress authority. It does not copy memberships or create a second permission system.', 'tomschooloflife-plugin'); ?></p>
            <?php if ('staged' === $access_phase) : ?>
                <div class="notice notice-info inline">
                    <p><strong><?php esc_html_e('Modern Library access rules are staged for review.', 'tomschooloflife-plugin'); ?></strong></p>
                    <p><?php echo esc_html(sprintf(
                        /* translators: %d is the number of inactive MemberPress rules. */
                        _n('%d new MemberPress rule is still a draft. Legacy delegation remains active.', '%d new MemberPress rules are still drafts. Legacy delegation remains active.', $staged_rule_count, 'tomschooloflife-plugin'),
                        $staged_rule_count
                    )); ?></p>
                </div>
            <?php elseif ('activated' === $access_phase) : ?>
                <div class="notice notice-success inline"><p><strong><?php esc_html_e('Native Course, Series, and Collection access is active.', 'tomschooloflife-plugin'); ?></strong></p></div>
            <?php endif; ?>
            <div class="tsol-library-admin-stats">
                <?php $this->render_stat(__('Protected', 'tomschooloflife-plugin'), $summary['protected']); ?>
                <?php $this->render_stat(__('All signed-in users', 'tomschooloflife-plugin'), $summary['open']); ?>
                <?php $this->render_stat(__('Legacy delegated', 'tomschooloflife-plugin'), $summary['legacy']); ?>
                <?php $this->render_stat(__('Native Library access', 'tomschooloflife-plugin'), $summary['native']); ?>
            </div>
            <section class="card tsol-library-admin-card--wide">
                <h2><?php esc_html_e('How to control access', 'tomschooloflife-plugin'); ?></h2>
                <ol>
                    <li><?php esc_html_e('Create or open a normal MemberPress Rule.', 'tomschooloflife-plugin'); ?></li>
                    <li><?php esc_html_e('Choose a Library Course, Series, Content item, or Course Collection target.', 'tomschooloflife-plugin'); ?></li>
                    <li><?php esc_html_e('Select the memberships or other conditions that grant access, then save in MemberPress.', 'tomschooloflife-plugin'); ?></li>
                </ol>
                <p><?php esc_html_e('MemberPress timing, drip, expiry, and OR-rule behavior remain native. Each Library editor and list shows the resulting effective rules.', 'tomschooloflife-plugin'); ?></p>
                <?php if (current_user_can('manage_options')) : ?>
                    <p><a class="button button-primary" href="<?php echo esc_url(admin_url('edit.php?post_type=memberpressrule')); ?>"><?php esc_html_e('Open MemberPress Rules', 'tomschooloflife-plugin'); ?></a></p>
                <?php endif; ?>
            </section>
        <?php if (!$embedded) : ?></div><?php endif; ?>
        <?php
    }

    public function render_import($embedded = false) {
        if (!current_user_can('manage_options')) {
            return;
        }
        $state = get_option('tsol_library_catalogue_import_state', array());
        $state = is_array($state) ? $state : array();
        $counts = $this->content_counts();
        $legacy_count = (int) wp_count_posts('mpcs-course')->publish;
        ?>
        <?php if (!$embedded) : ?><div class="wrap tsol-library-admin-page"><?php endif; ?>
            <?php if ($embedded) : ?>
                <h2><?php esc_html_e('Import & Legacy', 'tomschooloflife-plugin'); ?></h2>
            <?php else : ?>
                <h1><?php esc_html_e('Import & Legacy', 'tomschooloflife-plugin'); ?></h1>
            <?php endif; ?>
            <p class="tsol-library-admin-page__lead"><?php esc_html_e('The import is a guarded clone operation. It never converts, hides, unpublishes, or rewrites the legacy MemberPress Courses system.', 'tomschooloflife-plugin'); ?></p>
            <div class="tsol-library-admin-stats">
                <?php $this->render_stat(__('Legacy MP Courses', 'tomschooloflife-plugin'), $legacy_count, admin_url('edit.php?post_type=mpcs-course')); ?>
                <?php $this->render_stat(__('Cloned courses', 'tomschooloflife-plugin'), $counts['courses']); ?>
                <?php $this->render_stat(__('Cloned content', 'tomschooloflife-plugin'), $counts['content']); ?>
                <?php $this->render_stat(__('Import phase', 'tomschooloflife-plugin'), (string) ($state['phase'] ?? 'not started')); ?>
            </div>
            <section class="card tsol-library-admin-card--wide">
                <h2><?php esc_html_e('Safety boundary', 'tomschooloflife-plugin'); ?></h2>
                <ul class="ul-disc">
                    <li><?php esc_html_e('Legacy post content, statuses, URLs, metadata, rules, and progress remain untouched.', 'tomschooloflife-plugin'); ?></li>
                    <li><?php esc_html_e('Imported Library records begin as drafts and move through a separate administrator review and publication process.', 'tomschooloflife-plugin'); ?></li>
                    <li><?php esc_html_e('Imported records delegate live access checks to their original source.', 'tomschooloflife-plugin'); ?></li>
                    <li><?php esc_html_e('Apply and rollback stay in the guarded WP-CLI workflow; this page intentionally provides no destructive browser button.', 'tomschooloflife-plugin'); ?></li>
                </ul>
            </section>
        <?php if (!$embedded) : ?></div><?php endif; ?>
        <?php
    }

    public static function settings_url($tab = self::SETTINGS_TAB_AUTHENTICATION) {
        return add_query_arg(array(
            'page' => self::SETTINGS_SLUG,
            'tab' => sanitize_key((string) $tab),
        ), admin_url('admin.php'));
    }

    private function available_settings_tabs() {
        $tabs = array();
        if (current_user_can('manage_options')) {
            $tabs[self::SETTINGS_TAB_AUTHENTICATION] = __('Authentication', 'tomschooloflife-plugin');
            $tabs[self::SETTINGS_TAB_IMPORT] = __('Import & Legacy', 'tomschooloflife-plugin');
            $tabs[self::SETTINGS_TAB_SYNC] = __('Sync Status', 'tomschooloflife-plugin');
        }
        if (current_user_can('edit_pages')) {
            $tabs[self::SETTINGS_TAB_ACCESS] = __('Access Overview', 'tomschooloflife-plugin');
        }
        return $tabs;
    }

    private function content_counts() {
        $course_counts = wp_count_posts(TSOL_Library_Content_Model::COURSE_POST_TYPE);
        $series_counts = wp_count_posts(TSOL_Library_Content_Model::SERIES_POST_TYPE);
        $content_counts = wp_count_posts(TSOL_Library_Content_Model::ITEM_POST_TYPE);
        $course_drafts = isset($course_counts->draft) ? (int) $course_counts->draft : 0;
        $series_drafts = isset($series_counts->draft) ? (int) $series_counts->draft : 0;
        $content_drafts = isset($content_counts->draft) ? (int) $content_counts->draft : 0;
        return array(
            'courses' => $this->total_count($course_counts),
            'series' => $this->total_count($series_counts),
            'content' => $this->total_count($content_counts),
            'published' => (isset($course_counts->publish) ? (int) $course_counts->publish : 0) + (isset($series_counts->publish) ? (int) $series_counts->publish : 0) + (isset($content_counts->publish) ? (int) $content_counts->publish : 0),
            'drafts' => $course_drafts + $series_drafts + $content_drafts,
        );
    }

    private function access_counts() {
        $summary = array('protected' => 0, 'open' => 0, 'legacy' => 0, 'native' => 0);
        $ids = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::post_types(),
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ));
        foreach ($ids as $post_id) {
            $post_id = (int) $post_id;
            $authorization_id = (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
            $authorization_id = $authorization_id > 0 ? $authorization_id : $post_id;
            if ($authorization_id !== $post_id && !in_array(get_post_type($authorization_id), TSOL_Library_Content_Model::post_types(), true)) {
                $summary['legacy']++;
            } else {
                $summary['native']++;
            }
            $authorization_post = get_post($authorization_id);
            $rules = $authorization_post instanceof WP_Post && class_exists('MeprRule') ? MeprRule::get_rules($authorization_post) : array();
            if (empty($rules)) {
                $summary['open']++;
            } else {
                $summary['protected']++;
            }
        }
        return $summary;
    }

    private function total_count($counts) {
        if (!is_object($counts)) {
            return 0;
        }
        $total = 0;
        foreach (get_object_vars($counts) as $status => $count) {
            if ('trash' !== $status) {
                $total += (int) $count;
            }
        }
        return $total;
    }

    private function library_taxonomies() {
        return array(
            TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY,
            TSOL_Library_Content_Model::TOPIC_TAXONOMY,
        );
    }

    private function current_library_taxonomy() {
        if (!function_exists('get_current_screen')) {
            return '';
        }

        $screen = get_current_screen();
        if (!$screen || !in_array((string) $screen->base, array('edit-tags', 'term'), true)) {
            return '';
        }
        $taxonomy = $screen && isset($screen->taxonomy) ? (string) $screen->taxonomy : '';

        return in_array($taxonomy, $this->library_taxonomies(), true) ? $taxonomy : '';
    }

    private function taxonomy_menu_slug($taxonomy) {
        $post_type = TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY === $taxonomy
            ? TSOL_Library_Content_Model::COURSE_POST_TYPE
            : TSOL_Library_Content_Model::ITEM_POST_TYPE;
        return 'edit-tags.php?taxonomy=' . $taxonomy . '&post_type=' . $post_type;
    }

    private function taxonomy_record_counts($taxonomy) {
        if (isset($this->taxonomy_record_counts[$taxonomy])) {
            return $this->taxonomy_record_counts[$taxonomy];
        }

        global $wpdb;

        $counts = array();
        $terms = get_terms(array(
            'taxonomy' => $taxonomy,
            'hide_empty' => false,
        ));
        if (is_wp_error($terms)) {
            $this->taxonomy_record_counts[$taxonomy] = $counts;
            return $counts;
        }

        $post_types = TSOL_Library_Content_Model::post_types();
        $post_statuses = array('publish', 'draft', 'private', 'pending', 'future');
        $post_type_placeholders = implode(', ', array_fill(0, count($post_types), '%s'));
        $post_status_placeholders = implode(', ', array_fill(0, count($post_statuses), '%s'));
        $query = "SELECT tt.term_id, tr.object_id, p.post_type
            FROM {$wpdb->term_relationships} tr
            INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
            INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id
            WHERE tt.taxonomy = %s
                AND p.post_type IN ({$post_type_placeholders})
                AND p.post_status IN ({$post_status_placeholders})";
        $prepared = $wpdb->prepare(
            $query,
            array_merge(array($taxonomy), $post_types, $post_statuses)
        );
        $rows = $wpdb->get_results($prepared, ARRAY_A);
        $direct_assignments = array();

        foreach ($rows as $row) {
            $term_id = (int) $row['term_id'];
            $direct_assignments[$term_id][(int) $row['object_id']] = (string) $row['post_type'];
        }

        $hierarchical = is_taxonomy_hierarchical($taxonomy);
        $this->taxonomy_record_post_types[$taxonomy] = array();
        foreach ($terms as $term) {
            $term_ids = array((int) $term->term_id);
            if ($hierarchical) {
                $children = get_term_children((int) $term->term_id, $taxonomy);
                if (!is_wp_error($children)) {
                    $term_ids = array_merge($term_ids, array_map('intval', $children));
                }
            }

            $record_ids = array();
            foreach (array_unique($term_ids) as $term_id) {
                if (isset($direct_assignments[$term_id])) {
                    $record_ids += $direct_assignments[$term_id];
                }
            }
            $counts[(int) $term->term_id] = count($record_ids);
            $this->taxonomy_record_post_types[$taxonomy][(int) $term->term_id] = array_values(array_unique(array_values($record_ids)));
        }

        $this->taxonomy_record_counts[$taxonomy] = $counts;
        return $counts;
    }

    private function render_stat($label, $value, $url = '') {
        ?>
        <div class="tsol-library-admin-stat">
            <span><?php echo esc_html($label); ?></span>
            <?php if ('' !== $url) : ?>
                <a href="<?php echo esc_url($url); ?>"><?php echo esc_html((string) $value); ?></a>
            <?php else : ?>
                <strong><?php echo esc_html((string) $value); ?></strong>
            <?php endif; ?>
        </div>
        <?php
    }
}
