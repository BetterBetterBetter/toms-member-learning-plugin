<?php
/**
 * Dedicated wp-admin navigation and read-only operational views for Library.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Admin_Navigation {

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
        add_action('admin_menu', array($this, 'order_submenu'), 999);
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
            MemberLibrary_Brand::library_menu_label(),
            MemberLibrary_Brand::library_menu_label(),
            'edit_pages',
            self::MENU_SLUG,
            array($this, 'render_dashboard'),
            'dashicons-video-alt3',
            58.5
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Library Dashboard', 'member-library'),
            __('Dashboard', 'member-library'),
            'edit_pages',
            self::MENU_SLUG,
            array($this, 'render_dashboard')
        );
    }

    /**
     * Six classes register Library submenu items at different priorities. Put
     * them in one deliberate order: content, curation, access, then system.
     * Unknown items (other plugins hooking into the menu) keep their place at
     * the end.
     */
    public function order_submenu() {
        global $submenu;
        if (empty($submenu[self::MENU_SLUG]) || !is_array($submenu[self::MENU_SLUG])) {
            return;
        }
        $order = array_flip(array(
            self::MENU_SLUG,
            'edit.php?post_type=' . MemberLibrary_Content_Model::COURSE_POST_TYPE,
            'edit.php?post_type=' . MemberLibrary_Content_Model::SERIES_POST_TYPE,
            'edit.php?post_type=' . MemberLibrary_Content_Model::ITEM_POST_TYPE,
            'edit.php?post_type=' . MemberLibrary_Content_Model::SPEAKER_POST_TYPE,
            $this->taxonomy_menu_slug(MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY),
            $this->taxonomy_menu_slug(MemberLibrary_Content_Model::TOPIC_TAXONOMY),
            MemberLibrary_Homepage_Curation::PAGE_SLUG,
            'edit.php?post_type=' . MemberLibrary_Announcement_Model::POST_TYPE,
            MemberLibrary_Access_Groups_Admin::PAGE_SLUG,
            self::SETTINGS_SLUG,
            MemberLibrary_Environment_Migration_Admin::PAGE_SLUG,
        ));
        $items = array_values($submenu[self::MENU_SLUG]);
        $ranked = array();
        foreach ($items as $position => $entry) {
            $slug = (string) ($entry[2] ?? '');
            $ranked[] = array(isset($order[$slug]) ? $order[$slug] : count($order) + $position, $position, $entry);
        }
        usort($ranked, static function ($left, $right) {
            return $left[0] === $right[0] ? $left[1] - $right[1] : $left[0] - $right[0];
        });
        $submenu[self::MENU_SLUG] = array_map(static function ($row) {
            return $row[2];
        }, $ranked);
    }

    public function add_submenus() {
        add_submenu_page(
            self::MENU_SLUG,
            __('Collections', 'member-library'),
            __('Collections', 'member-library'),
            'manage_categories',
            'edit-tags.php?taxonomy=' . MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY . '&post_type=' . MemberLibrary_Content_Model::COURSE_POST_TYPE
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Library Topics', 'member-library'),
            __('Topics', 'member-library'),
            'manage_categories',
            'edit-tags.php?taxonomy=' . MemberLibrary_Content_Model::TOPIC_TAXONOMY . '&post_type=' . MemberLibrary_Content_Model::ITEM_POST_TYPE
        );
        add_submenu_page(
            self::MENU_SLUG,
            __('Library Settings', 'member-library'),
            __('Settings', 'member-library'),
            'edit_pages',
            self::SETTINGS_SLUG,
            array($this, 'render_settings')
        );

        $this->add_hidden_legacy_settings_page(
            self::AUTH_SLUG,
            __('Library Authentication', 'member-library'),
            'manage_options'
        );
        $this->add_hidden_legacy_settings_page(
            self::IMPORT_SLUG,
            __('Import & Legacy', 'member-library'),
            'manage_options'
        );
        $this->add_hidden_legacy_settings_page(
            self::ACCESS_SLUG,
            __('Library Access Overview', 'member-library'),
            'edit_pages'
        );
    }

    public function enqueue_assets($hook) {
        if (strpos((string) $hook, self::MENU_SLUG) === false) {
            return;
        }
        wp_enqueue_style(
            'tsol-library-content-admin',
            MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/library-content-admin.css',
            array(),
            MEMBER_LIBRARY_PLUGIN_VERSION
        );
    }

    public function redirect_legacy_settings_pages() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $legacy_tabs = array(
            self::AUTH_SLUG => array(self::SETTINGS_TAB_AUTHENTICATION, 'manage_options'),
            // The browser import screen was retired after the catalogue
            // migration. Keep the old URL harmless and useful while the
            // guarded WP-CLI recovery commands remain available.
            self::IMPORT_SLUG => array(self::SETTINGS_TAB_SYNC, 'manage_options'),
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
                $filtered[self::RECORD_COUNT_COLUMN] = _x('Count', 'Number/count of Library records', 'member-library');
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
        $description = __('Includes draft Library records.', 'member-library');
        $term = get_term((int) $term_id, $taxonomy);
        $post_types = isset($this->taxonomy_record_post_types[$taxonomy][(int) $term_id])
            ? $this->taxonomy_record_post_types[$taxonomy][(int) $term_id]
            : array();

        if (!is_wp_error($term) && $term instanceof WP_Term && count($post_types) <= 1) {
            $screen = function_exists('get_current_screen') ? get_current_screen() : null;
            $post_type = 1 === count($post_types)
                ? (string) reset($post_types)
                : ($screen && in_array($screen->post_type, MemberLibrary_Content_Model::post_types(), true)
                    ? (string) $screen->post_type
                    : MemberLibrary_Content_Model::ITEM_POST_TYPE);
            $url_args = array(
                'post_type' => $post_type,
                'taxonomy' => $taxonomy,
                'term' => $term->slug,
            );
            if (MemberLibrary_Content_Model::ITEM_POST_TYPE === $post_type) {
                $url_args[MemberLibrary_Content_Admin::CONTENT_SCOPE_FILTER] = MemberLibrary_Content_Admin::CONTENT_SCOPE_ALL;
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
        $can_manage = current_user_can('manage_options');
        $course_url = admin_url('edit.php?post_type=' . MemberLibrary_Content_Model::COURSE_POST_TYPE);
        $series_url = admin_url('edit.php?post_type=' . MemberLibrary_Content_Model::SERIES_POST_TYPE);
        $content_url = add_query_arg(array(
            'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
            MemberLibrary_Content_Admin::CONTENT_SCOPE_FILTER => MemberLibrary_Content_Admin::CONTENT_SCOPE_ALL,
        ), admin_url('edit.php'));
        $drafts_url = add_query_arg('post_status', 'draft', $content_url);
        $cards = $this->dashboard_cards($counts, $can_manage);
        $recent = get_posts(array(
            'post_type' => MemberLibrary_Content_Model::post_types(),
            'post_status' => array('publish', 'draft', 'pending', 'future', 'private'),
            'posts_per_page' => 8,
            'orderby' => 'modified',
            'order' => 'DESC',
            'suppress_filters' => true,
        ));
        $type_labels = array(
            MemberLibrary_Content_Model::COURSE_POST_TYPE => __('Course', 'member-library'),
            MemberLibrary_Content_Model::SERIES_POST_TYPE => __('Series', 'member-library'),
            MemberLibrary_Content_Model::ITEM_POST_TYPE => __('Content', 'member-library'),
        );
        ?>
        <div class="wrap tsol-library-admin-page tsol-library-dashboard">
            <h1><?php echo esc_html(MemberLibrary_Brand::library_menu_label()); ?></h1>
            <p class="tsol-library-admin-page__lead"><?php esc_html_e('Everything in the member Library, and whether it is live.', 'member-library'); ?></p>

            <div class="tsol-dashboard-cards">
                <?php foreach ($cards as $key => $card) : ?>
                    <a class="tsol-dashboard-card tsol-dashboard-card--<?php echo esc_attr($card['state']); ?>" href="<?php echo esc_url($card['url']); ?>" data-dashboard-card="<?php echo esc_attr($key); ?>" data-dashboard-state="<?php echo esc_attr($card['state']); ?>">
                        <span class="tsol-status-chip tsol-status-chip--<?php echo esc_attr($card['state']); ?>"><?php echo esc_html($card['badge']); ?></span>
                        <strong class="tsol-dashboard-card__title"><?php echo esc_html($card['title']); ?></strong>
                        <span class="tsol-dashboard-card__detail"><?php echo esc_html($card['detail']); ?></span>
                        <span class="tsol-dashboard-card__action"><?php echo esc_html($card['action']); ?> →</span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="tsol-library-admin-grid tsol-dashboard-lower">
                <section class="card tsol-dashboard-recent" data-dashboard-recent>
                    <h2><?php esc_html_e('Recently edited', 'member-library'); ?></h2>
                    <?php if (empty($recent)) : ?>
                        <p><?php esc_html_e('Nothing in the Library yet.', 'member-library'); ?></p>
                    <?php else : ?>
                        <ul>
                            <?php foreach ($recent as $post) : ?>
                                <?php $status = get_post_status_object((string) $post->post_status); ?>
                                <li>
                                    <a href="<?php echo esc_url(get_edit_post_link((int) $post->ID, 'raw')); ?>"><?php echo esc_html(get_the_title($post) ?: __('(untitled)', 'member-library')); ?></a>
                                    <span class="tsol-dashboard-recent__meta">
                                        <span class="tsol-status-chip tsol-status-chip--<?php echo 'publish' === $post->post_status ? 'live' : 'draft'; ?>"><?php echo esc_html('publish' === $post->post_status ? __('Live', 'member-library') : ($status ? $status->label : $post->post_status)); ?></span>
                                        <?php echo esc_html($type_labels[$post->post_type] ?? $post->post_type); ?> · <?php echo esc_html(sprintf(__('%s ago', 'member-library'), human_time_diff(strtotime($post->post_modified_gmt . ' UTC')))); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
                <section class="card">
                    <h2><?php esc_html_e('Add to the Library', 'member-library'); ?></h2>
                    <p><?php esc_html_e('A Course is an ordered curriculum. A Series is a run of related videos. Content is a single lesson or video, ideally inside one of those.', 'member-library'); ?></p>
                    <p class="tsol-dashboard-actions">
                        <a class="button button-primary" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . MemberLibrary_Content_Model::COURSE_POST_TYPE)); ?>"><?php esc_html_e('New course', 'member-library'); ?></a>
                        <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . MemberLibrary_Content_Model::SERIES_POST_TYPE)); ?>"><?php esc_html_e('New series', 'member-library'); ?></a>
                        <a class="button" href="<?php echo esc_url(admin_url('post-new.php?post_type=' . MemberLibrary_Content_Model::ITEM_POST_TYPE)); ?>"><?php esc_html_e('New content', 'member-library'); ?></a>
                    </p>
                    <p class="tsol-dashboard-counts">
                        <a href="<?php echo esc_url($course_url); ?>"><strong><?php echo esc_html(number_format_i18n($counts['courses'])); ?></strong> <?php esc_html_e('courses', 'member-library'); ?></a>
                        <a href="<?php echo esc_url($series_url); ?>"><strong><?php echo esc_html(number_format_i18n($counts['series'])); ?></strong> <?php esc_html_e('series', 'member-library'); ?></a>
                        <a href="<?php echo esc_url($content_url); ?>"><strong><?php echo esc_html(number_format_i18n($counts['content'])); ?></strong> <?php esc_html_e('content items', 'member-library'); ?></a>
                        <a href="<?php echo esc_url($drafts_url); ?>"><strong><?php echo esc_html(number_format_i18n($counts['drafts'])); ?></strong> <?php esc_html_e('drafts', 'member-library'); ?></a>
                    </p>
                </section>
            </div>
        </div>
        <?php
    }

    /**
     * One card per subsystem. Each answers the same question in the same
     * shape: what state is it in, what is the one number that matters, and
     * where do I go.
     */
    private function dashboard_cards($counts, $can_manage) {
        $cards = array();

        $drafts = (int) $counts['drafts'];
        $cards['catalogue'] = array(
            'state' => $drafts > 0 ? 'draft' : 'live',
            'badge' => $drafts > 0 ? __('Drafts', 'member-library') : __('Live', 'member-library'),
            'title' => __('Catalogue', 'member-library'),
            'detail' => $drafts > 0
                ? sprintf(_n('%1$s published · %2$s draft waiting to publish', '%1$s published · %2$s drafts waiting to publish', $drafts, 'member-library'), number_format_i18n((int) $counts['published']), number_format_i18n($drafts))
                : sprintf(__('%s items published, nothing in draft', 'member-library'), number_format_i18n((int) $counts['published'])),
            'action' => __('Open content', 'member-library'),
            'url' => add_query_arg(array(
                'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
                MemberLibrary_Content_Admin::CONTENT_SCOPE_FILTER => MemberLibrary_Content_Admin::CONTENT_SCOPE_ALL,
            ) + ($drafts > 0 ? array('post_status' => 'draft') : array()), admin_url('edit.php')),
        );

        $access = new MemberLibrary_Access_Groups();
        $access_url = $can_manage
            ? admin_url('admin.php?page=' . MemberLibrary_Access_Groups_Admin::PAGE_SLUG)
            : self::settings_url(self::SETTINGS_TAB_ACCESS);
        if (!$access->is_bootstrapped()) {
            $cards['access'] = array('state' => 'off', 'badge' => __('Not set up', 'member-library'), 'title' => __('Access Groups', 'member-library'), 'detail' => __('Members use the existing MemberPress rules. Import them to manage access here.', 'member-library'), 'action' => __('Set up', 'member-library'), 'url' => $access_url);
        } else {
            $preview = $access->preview();
            $phase = (string) ($preview['stage']['phase'] ?? '');
            $changes = $access->changes_since_publish();
            if ('staged' === $phase) {
                $card = array('state' => 'review', 'badge' => __('Review waiting', 'member-library'), 'detail' => __('A review is complete and waiting for Publish or Back to editing.', 'member-library'), 'action' => __('Decide', 'member-library'));
            } elseif (in_array($phase, array('staging', 'failed'), true)) {
                $card = array('state' => 'attention', 'badge' => __('Needs attention', 'member-library'), 'detail' => __('The last review did not finish. Nothing changed for members.', 'member-library'), 'action' => __('Clear it', 'member-library'));
            } elseif (!$changes['has_published']) {
                $card = array('state' => 'draft', 'badge' => __('Draft only', 'member-library'), 'detail' => sprintf(_n('%d group defined, nothing published yet', '%d groups defined, nothing published yet', (int) $preview['group_count'], 'member-library'), (int) $preview['group_count']), 'action' => __('Review and publish', 'member-library'));
            } elseif ($changes['has_changes']) {
                $card = array('state' => 'draft', 'badge' => __('Draft', 'member-library'), 'detail' => sprintf(_n('%d change not yet live', '%d changes not yet live', (int) $changes['counts']['total'], 'member-library'), (int) $changes['counts']['total']), 'action' => __('Review and publish', 'member-library'));
            } else {
                $card = array('state' => 'live', 'badge' => __('Live', 'member-library'), 'detail' => sprintf(_n('%1$d group live · %2$d membership assigned · same as draft', '%1$d groups live · %2$d memberships assigned · same as draft', (int) $preview['group_count'], 'member-library'), (int) $preview['group_count'], (int) $preview['assigned_memberships']), 'action' => __('Open', 'member-library'));
            }
            $cards['access'] = $card + array('title' => __('Access Groups', 'member-library'), 'url' => $access_url);
        }

        if ($can_manage) {
            $reason = MemberLibrary_Auth_Settings::readiness_error();
            $sync = MemberLibrary_Catalogue_Sync_Status::summary();
            if ('' !== $reason) {
                $card = array('state' => 'attention', 'badge' => __('Not connected', 'member-library'), 'detail' => $reason, 'action' => __('Finish setup', 'member-library'), 'url' => self::settings_url(self::SETTINGS_TAB_AUTHENTICATION));
            } elseif ('critical' === $sync['status']) {
                $card = array('state' => 'attention', 'badge' => __('Needs attention', 'member-library'), 'detail' => __('Sign-in works, but catalogue changes are not reaching the app.', 'member-library'), 'action' => __('See sync', 'member-library'), 'url' => self::settings_url(self::SETTINGS_TAB_SYNC));
            } elseif ('recommended' === $sync['status']) {
                $card = array('state' => 'review', 'badge' => __('Syncing', 'member-library'), 'detail' => sprintf(_n('%d change on its way to the app', '%d changes on their way to the app', (int) $sync['pending'], 'member-library'), (int) $sync['pending']), 'action' => __('See sync', 'member-library'), 'url' => self::settings_url(self::SETTINGS_TAB_SYNC));
            } else {
                $card = array('state' => 'ok', 'badge' => __('Connected', 'member-library'), 'detail' => __('Members can sign in and the app has every published change.', 'member-library'), 'action' => __('Settings', 'member-library'), 'url' => self::settings_url(self::SETTINGS_TAB_AUTHENTICATION));
            }
            $cards['connection'] = $card + array('title' => __('Library app', 'member-library'));
        }

        $layout = MemberLibrary_Homepage_Curation::layout();
        $curated = 0;
        foreach ((array) ($layout['rails'] ?? array()) as $ids) {
            $curated += count((array) $ids);
        }
        $cards['homepage'] = array(
            'state' => $curated > 0 ? 'live' : 'attention',
            'badge' => $curated > 0 ? __('Live', 'member-library') : __('Empty', 'member-library'),
            'title' => __('Homepage', 'member-library'),
            'detail' => $curated > 0
                ? sprintf(_n('%d item placed across the homepage rails', '%d items placed across the homepage rails', $curated, 'member-library'), $curated)
                : __('No items placed yet; the app shows its automatic layout.', 'member-library'),
            'action' => __('Arrange', 'member-library'),
            'url' => admin_url('admin.php?page=' . MemberLibrary_Homepage_Curation::PAGE_SLUG),
        );

        if ($can_manage) {
            $pending = get_option(MemberLibrary_Environment_Migration_Admin::PENDING_OPTION, array());
            $rollback = get_option(MemberLibrary_Environment_Migration::ROLLBACK_OPTION, array());
            $apply_phase = is_array($pending) ? (string) ($pending['apply_state']['phase'] ?? '') : '';
            if (is_array($pending) && !empty($pending['token'])) {
                $card = array('state' => 'failed' === $apply_phase ? 'attention' : 'review', 'badge' => 'failed' === $apply_phase ? __('Import stopped', 'member-library') : __('Import waiting', 'member-library'), 'detail' => 'failed' === $apply_phase ? __('An import stopped part-way. Resume it or roll it back.', 'member-library') : __('A package is uploaded and waiting to be imported.', 'member-library'), 'action' => __('Continue', 'member-library'));
            } elseif (is_array($rollback) && !empty($rollback['import_hash'])) {
                $card = array('state' => 'ok', 'badge' => __('Imported', 'member-library'), 'detail' => sprintf(__('Last import %s. Rollback is available.', 'member-library'), !empty($rollback['created_at']) ? human_time_diff(strtotime((string) $rollback['created_at'])) . ' ' . __('ago', 'member-library') : ''), 'action' => __('Open', 'member-library'));
            } else {
                $card = array('state' => 'off', 'badge' => __('Idle', 'member-library'), 'detail' => __('Move Library content between sites with a verified package.', 'member-library'), 'action' => __('Open', 'member-library'));
            }
            $cards['migration'] = $card + array('title' => __('Migration', 'member-library'), 'url' => admin_url('admin.php?page=' . MemberLibrary_Environment_Migration_Admin::PAGE_SLUG));
        }

        return $cards;
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
            <h1><?php esc_html_e('Library Settings', 'member-library'); ?></h1>
            <nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e('Library settings sections', 'member-library'); ?>">
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
                    $auth_settings = new MemberLibrary_Auth_Settings();
                    $auth_settings->render(true);
                } elseif (self::SETTINGS_TAB_SYNC === $active_tab) {
                    $sync_status = new MemberLibrary_Catalogue_Sync_Status();
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
                <h2><?php esc_html_e('Library Access', 'member-library'); ?></h2>
            <?php else : ?>
                <h1><?php esc_html_e('Library Access', 'member-library'); ?></h1>
            <?php endif; ?>
            <p class="tsol-library-admin-page__lead"><?php esc_html_e('MemberPress remains the live permission engine. Access Groups provide the standard way to manage which memberships unlock each part of the Library.', 'member-library'); ?></p>
            <?php if ('staged' === $access_phase) : ?>
                <div class="notice notice-info inline">
                    <p><strong><?php esc_html_e('Modern Library access rules are staged for review.', 'member-library'); ?></strong></p>
                    <p><?php echo esc_html(sprintf(
                        /* translators: %d is the number of inactive MemberPress rules. */
                        _n('%d new MemberPress rule is still a draft. Legacy delegation remains active.', '%d new MemberPress rules are still drafts. Legacy delegation remains active.', $staged_rule_count, 'member-library'),
                        $staged_rule_count
                    )); ?></p>
                </div>
            <?php elseif ('activated' === $access_phase) : ?>
                <div class="notice notice-success inline"><p><strong><?php esc_html_e('Native Course, Series, and Collection access is active.', 'member-library'); ?></strong></p></div>
            <?php endif; ?>
            <div class="tsol-library-admin-stats">
                <?php $this->render_stat(__('Protected', 'member-library'), $summary['protected']); ?>
                <?php $this->render_stat(__('All signed-in users', 'member-library'), $summary['open']); ?>
                <?php $this->render_stat(__('Legacy delegated', 'member-library'), $summary['legacy']); ?>
                <?php $this->render_stat(__('Native Library access', 'member-library'), $summary['native']); ?>
            </div>
            <section class="card tsol-library-admin-card--wide">
                <h2><?php esc_html_e('How to control Library access', 'member-library'); ?></h2>
                <ol>
                    <li><?php esc_html_e('Create a reusable Access Group and choose the Library content it unlocks.', 'member-library'); ?></li>
                    <li><?php esc_html_e('Assign that group from the relevant MemberPress membership editor.', 'member-library'); ?></li>
                    <li><?php esc_html_e('Check the full access comparison, then publish the change from Access Groups.', 'member-library'); ?></li>
                </ol>
                <p><?php esc_html_e('Publishing compiles the groups into native MemberPress Rules. Membership billing and all non-Library MemberPress rules remain unchanged.', 'member-library'); ?></p>
                <?php if (current_user_can('manage_options')) : ?>
                    <p><a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . MemberLibrary_Access_Groups_Admin::PAGE_SLUG)); ?>"><?php esc_html_e('Manage Access Groups', 'member-library'); ?></a></p>
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
                <h2><?php esc_html_e('Import & Legacy', 'member-library'); ?></h2>
            <?php else : ?>
                <h1><?php esc_html_e('Import & Legacy', 'member-library'); ?></h1>
            <?php endif; ?>
            <p class="tsol-library-admin-page__lead"><?php esc_html_e('The import is a guarded clone operation. It never converts, hides, unpublishes, or rewrites the legacy MemberPress Courses system.', 'member-library'); ?></p>
            <div class="tsol-library-admin-stats">
                <?php $this->render_stat(__('Legacy MP Courses', 'member-library'), $legacy_count, admin_url('edit.php?post_type=mpcs-course')); ?>
                <?php $this->render_stat(__('Cloned courses', 'member-library'), $counts['courses']); ?>
                <?php $this->render_stat(__('Cloned content', 'member-library'), $counts['content']); ?>
                <?php $this->render_stat(__('Import phase', 'member-library'), (string) ($state['phase'] ?? 'not started')); ?>
            </div>
            <section class="card tsol-library-admin-card--wide">
                <h2><?php esc_html_e('Safety boundary', 'member-library'); ?></h2>
                <ul class="ul-disc">
                    <li><?php esc_html_e('Legacy post content, statuses, URLs, metadata, rules, and progress remain untouched.', 'member-library'); ?></li>
                    <li><?php esc_html_e('Imported Library records begin as drafts and move through a separate administrator review and publication process.', 'member-library'); ?></li>
                    <li><?php esc_html_e('Imported records delegate live access checks to their original source.', 'member-library'); ?></li>
                    <li><?php esc_html_e('Apply and rollback stay in the guarded WP-CLI workflow; this page intentionally provides no destructive browser button.', 'member-library'); ?></li>
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
            $tabs[self::SETTINGS_TAB_AUTHENTICATION] = __('Authentication', 'member-library');
            $tabs[self::SETTINGS_TAB_SYNC] = __('Sync Status', 'member-library');
        }
        if (current_user_can('edit_pages')) {
            $tabs[self::SETTINGS_TAB_ACCESS] = __('Access', 'member-library');
        }
        return $tabs;
    }

    private function content_counts() {
        $course_counts = wp_count_posts(MemberLibrary_Content_Model::COURSE_POST_TYPE);
        $series_counts = wp_count_posts(MemberLibrary_Content_Model::SERIES_POST_TYPE);
        $content_counts = wp_count_posts(MemberLibrary_Content_Model::ITEM_POST_TYPE);
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
            'post_type' => MemberLibrary_Content_Model::post_types(),
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ));
        foreach ($ids as $post_id) {
            $post_id = (int) $post_id;
            $authorization_id = (int) get_post_meta($post_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, true);
            $authorization_id = $authorization_id > 0 ? $authorization_id : $post_id;
            if ($authorization_id !== $post_id && !in_array(get_post_type($authorization_id), MemberLibrary_Content_Model::post_types(), true)) {
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
            MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY,
            MemberLibrary_Content_Model::TOPIC_TAXONOMY,
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
        $post_type = MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY === $taxonomy
            ? MemberLibrary_Content_Model::COURSE_POST_TYPE
            : MemberLibrary_Content_Model::ITEM_POST_TYPE;
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

        $post_types = MemberLibrary_Content_Model::post_types();
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
