<?php
/**
 * wp-admin UI for WordPress-only Library environment migration packages.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Environment_Migration_Admin {

    const PAGE_SLUG = 'tsol-library-migration';
    const PENDING_OPTION = 'tsol_library_environment_migration_pending';
    const IMPORT_CONFIRMATION = 'import-wordpress-library';
    const NONCE_ACTION = 'tsol_library_environment_migration';

    public function init() {
        add_action('admin_menu', array($this, 'add_page'), 21);
        add_action('admin_post_tsol_library_migration_export', array($this, 'export'));
        add_action('admin_post_tsol_library_migration_preview', array($this, 'preview'));
        add_action('admin_post_tsol_library_migration_apply', array($this, 'apply'));
        add_action('admin_post_tsol_library_migration_rollback', array($this, 'rollback'));
    }

    public function add_page() {
        add_submenu_page(
            TSOL_Library_Admin_Navigation::MENU_SLUG,
            __('Library Migration', 'tomschooloflife-plugin'),
            __('Migration', 'tomschooloflife-plugin'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render')
        );
    }

    public function export() {
        $this->authorize();
        $migration = new TSOL_Library_Environment_Migration();
        try {
            $json = $migration->encode($migration->build_package());
        } catch (Throwable $exception) {
            $this->fail($exception->getMessage());
        }
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="tsol-wordpress-library-' . gmdate('Ymd-His') . '.json"');
        header('X-Content-Type-Options: nosniff');
        echo $json; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON download.
        exit;
    }

    public function preview() {
        $this->authorize();
        $file = isset($_FILES['tsol_library_migration_file']) ? $_FILES['tsol_library_migration_file'] : array();
        if (!is_array($file) || UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)) {
            $this->fail(__('Choose a valid TSOL Library JSON package.', 'tomschooloflife-plugin'));
        }
        $name = sanitize_file_name((string) ($file['name'] ?? ''));
        $size = (int) ($file['size'] ?? 0);
        if ('json' !== strtolower((string) pathinfo($name, PATHINFO_EXTENSION)) || $size <= 0 || $size > min(wp_max_upload_size(), 25 * MB_IN_BYTES)) {
            $this->fail(__('The migration file must be JSON and no larger than 25 MB.', 'tomschooloflife-plugin'));
        }
        $json = file_get_contents((string) $file['tmp_name']);
        if (false === $json) {
            $this->fail(__('WordPress could not read the migration upload.', 'tomschooloflife-plugin'));
        }
        $migration = new TSOL_Library_Environment_Migration();
        try {
            $package = $migration->decode($json);
            $report = $migration->preview($package);
        } catch (Throwable $exception) {
            $this->fail($exception->getMessage());
        }
        $token = wp_generate_uuid4();
        update_option(self::PENDING_OPTION, array(
            'token' => $token,
            'user_id' => get_current_user_id(),
            'created_at' => time(),
            'package' => base64_encode(gzencode($json, 6)),
            'report' => $report,
        ), false);
        wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'preview' => 'ready'), admin_url('admin.php')));
        exit;
    }

    public function apply() {
        $this->authorize();
        $pending = $this->pending();
        $token = sanitize_text_field(wp_unslash((string) ($_POST['migration_token'] ?? '')));
        $confirmation = sanitize_text_field(wp_unslash((string) ($_POST['confirmation'] ?? '')));
        if (empty($pending) || !hash_equals((string) ($pending['token'] ?? ''), $token)) {
            $this->fail(__('The migration preview expired or changed. Upload it again.', 'tomschooloflife-plugin'));
        }
        if (self::IMPORT_CONFIRMATION !== $confirmation) {
            $this->fail(__('Enter the exact migration confirmation before importing.', 'tomschooloflife-plugin'));
        }
        try {
            $json = gzdecode((string) base64_decode((string) $pending['package'], true));
            $migration = new TSOL_Library_Environment_Migration();
            $package = $migration->decode($json);
            $migration->apply($package, (string) ($pending['report']['package_hash'] ?? ''));
            delete_option(self::PENDING_OPTION);
        } catch (Throwable $exception) {
            $this->fail($exception->getMessage());
        }
        wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'result' => 'applied'), admin_url('admin.php')));
        exit;
    }

    public function rollback() {
        $this->authorize();
        $confirmation = sanitize_text_field(wp_unslash((string) ($_POST['confirmation'] ?? '')));
        try {
            (new TSOL_Library_Environment_Migration())->rollback($confirmation);
        } catch (Throwable $exception) {
            $this->fail($exception->getMessage());
        }
        wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'result' => 'rolled-back'), admin_url('admin.php')));
        exit;
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }
        $pending = $this->pending();
        $report = is_array($pending['report'] ?? null) ? $pending['report'] : array();
        $rollback = (new TSOL_Library_Environment_Migration())->rollback_state();
        $result = isset($_GET['result']) ? sanitize_key(wp_unslash($_GET['result'])) : '';
        ?>
        <div class="wrap tsol-library-admin-page">
            <h1><?php esc_html_e('Test → Production Migration', 'tomschooloflife-plugin'); ?></h1>
            <p class="tsol-library-admin-page__lead"><?php esc_html_e('Move only WordPress-owned TSOL Library content and configuration between environments using stable UUIDs and membership slugs.', 'tomschooloflife-plugin'); ?></p>

            <?php if ('applied' === $result) : ?>
                <div class="notice notice-success inline"><p><?php esc_html_e('The WordPress Library package was imported. Access Groups remain a draft: check the full matrix before publishing their MemberPress rules.', 'tomschooloflife-plugin'); ?></p></div>
            <?php elseif ('rolled-back' === $result) : ?>
                <div class="notice notice-success inline"><p><?php esc_html_e('The last WordPress Library migration was rolled back.', 'tomschooloflife-plugin'); ?></p></div>
            <?php endif; ?>

            <div class="notice notice-info inline">
                <p><strong><?php esc_html_e('WordPress Library only.', 'tomschooloflife-plugin'); ?></strong> <?php esc_html_e('Packages never contain the standalone app database, app accounts, sessions, progress, notes, bookmarks, WordPress users, MemberPress transactions, secrets, logs, or temporary state.', 'tomschooloflife-plugin'); ?></p>
            </div>

            <div class="tsol-library-admin-grid">
                <section class="card">
                    <h2><?php esc_html_e('1. Export from test', 'tomschooloflife-plugin'); ?></h2>
                    <p><?php esc_html_e('Download courses, series, content, speakers, terms, homepage curation, attachment references, and portable Access Groups.', 'tomschooloflife-plugin'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tsol_library_migration_export">
                        <?php wp_nonce_field(self::NONCE_ACTION); ?>
                        <?php submit_button(__('Download WordPress Library package', 'tomschooloflife-plugin'), 'secondary', 'submit', false); ?>
                    </form>
                </section>
                <section class="card">
                    <h2><?php esc_html_e('2. Preview on production', 'tomschooloflife-plugin'); ?></h2>
                    <p><?php esc_html_e('Upload the package. Preview is read-only and blocks missing memberships, duplicate UUIDs, slug conflicts, or missing legacy authorization sources.', 'tomschooloflife-plugin'); ?></p>
                    <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tsol_library_migration_preview">
                        <?php wp_nonce_field(self::NONCE_ACTION); ?>
                        <input type="file" name="tsol_library_migration_file" accept="application/json,.json" required>
                        <?php submit_button(__('Preview package', 'tomschooloflife-plugin'), 'secondary', 'submit', false); ?>
                    </form>
                </section>
            </div>

            <?php if (!empty($pending)) : ?>
                <section class="card tsol-library-admin-card--wide">
                    <h2><?php esc_html_e('Import preview', 'tomschooloflife-plugin'); ?></h2>
                    <div class="tsol-library-admin-stats">
                        <?php $this->stat(__('Create', 'tomschooloflife-plugin'), (int) ($report['creates'] ?? 0)); ?>
                        <?php $this->stat(__('Update', 'tomschooloflife-plugin'), (int) ($report['updates'] ?? 0)); ?>
                        <?php $this->stat(__('Adopt existing', 'tomschooloflife-plugin'), (int) ($report['adoptions'] ?? 0)); ?>
                        <?php $this->stat(__('Unchanged', 'tomschooloflife-plugin'), (int) ($report['unchanged'] ?? 0)); ?>
                        <?php $this->stat(__('Terms', 'tomschooloflife-plugin'), (int) ($report['terms'] ?? 0)); ?>
                        <?php $this->stat(__('Access Groups', 'tomschooloflife-plugin'), (int) ($report['groups'] ?? 0)); ?>
                        <?php $this->stat(__('Memberships', 'tomschooloflife-plugin'), (int) ($report['membership_assignments'] ?? 0)); ?>
                    </div>
                    <?php foreach ((array) ($report['errors'] ?? array()) as $error) : ?>
                        <div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div>
                    <?php endforeach; ?>
                    <?php foreach ((array) ($report['warnings'] ?? array()) as $warning) : ?>
                        <div class="notice notice-warning inline"><p><?php echo esc_html($warning); ?></p></div>
                    <?php endforeach; ?>
                    <?php if (empty($report['errors'])) : ?>
                        <p><?php esc_html_e('Importing creates a rollback snapshot and leaves Access Groups unpublished. Existing legacy MemberPress authorization remains active until the separate access comparison is checked and explicitly published.', 'tomschooloflife-plugin'); ?></p>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="tsol_library_migration_apply">
                            <input type="hidden" name="migration_token" value="<?php echo esc_attr((string) $pending['token']); ?>">
                            <?php wp_nonce_field(self::NONCE_ACTION); ?>
                            <label for="tsol-library-migration-confirmation"><strong><?php esc_html_e('Type import-wordpress-library to confirm', 'tomschooloflife-plugin'); ?></strong></label><br>
                            <input id="tsol-library-migration-confirmation" class="regular-text code" name="confirmation" autocomplete="off" required>
                            <?php submit_button(__('Import WordPress Library', 'tomschooloflife-plugin'), 'primary', 'submit', false); ?>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (!empty($rollback)) : ?>
                <section class="card tsol-library-admin-card--wide">
                    <h2><?php esc_html_e('Rollback', 'tomschooloflife-plugin'); ?></h2>
                    <p><?php esc_html_e('A snapshot from before the last import is available. Roll back any Access Groups stage first, then restore the previous WordPress Library records and configuration.', 'tomschooloflife-plugin'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tsol_library_migration_rollback">
                        <?php wp_nonce_field(self::NONCE_ACTION); ?>
                        <label for="tsol-library-rollback-confirmation"><strong><?php esc_html_e('Type rollback-library-migration to confirm', 'tomschooloflife-plugin'); ?></strong></label><br>
                        <input id="tsol-library-rollback-confirmation" class="regular-text code" name="confirmation" autocomplete="off" required>
                        <?php submit_button(__('Roll back last migration', 'tomschooloflife-plugin'), 'delete', 'submit', false); ?>
                    </form>
                </section>
            <?php endif; ?>
        </div>
        <?php
    }

    private function pending() {
        $pending = get_option(self::PENDING_OPTION, array());
        if (!is_array($pending)
            || (int) ($pending['user_id'] ?? 0) !== get_current_user_id()
            || time() - (int) ($pending['created_at'] ?? 0) > HOUR_IN_SECONDS
        ) {
            if (!empty($pending)) {
                delete_option(self::PENDING_OPTION);
            }
            return array();
        }
        return $pending;
    }

    private function authorize() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to migrate the Library.', 'tomschooloflife-plugin'));
        }
        check_admin_referer(self::NONCE_ACTION);
    }

    private function fail($message) {
        wp_die(esc_html((string) $message), esc_html__('Library migration stopped', 'tomschooloflife-plugin'), array('response' => 400, 'back_link' => true));
    }

    private function stat($label, $value) {
        ?>
        <div class="tsol-library-admin-stat"><strong><?php echo esc_html((string) $value); ?></strong><span><?php echo esc_html($label); ?></span></div>
        <?php
    }
}
