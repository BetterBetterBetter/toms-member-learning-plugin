<?php
/**
 * wp-admin UI for WordPress-only Library environment migration packages.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Environment_Migration_Admin {

    const PAGE_SLUG = 'tsol-library-migration';
    const PENDING_OPTION = 'tsol_library_environment_migration_pending';
    const UPLOAD_OPTION_PREFIX = 'tsol_library_environment_migration_upload_';
    const IMPORT_CONFIRMATION = 'import-wordpress-library';
    const NONCE_ACTION = 'tsol_library_environment_migration';
    const CHUNK_BYTES = 524288;
    const PENDING_TTL = 86400;
    const ATTACHMENT_BATCH_SIZE = 2;
    const LOG_LIMIT = 300;

    public function init() {
        add_action('admin_menu', array($this, 'add_page'), 21);
        add_action('admin_post_tsol_library_migration_export', array($this, 'export'));
        add_action('admin_post_tsol_library_migration_preview', array($this, 'preview'));
        add_action('admin_post_tsol_library_migration_apply', array($this, 'apply'));
        add_action('admin_post_tsol_library_migration_rollback', array($this, 'rollback'));
        add_action('wp_ajax_tsol_library_migration_upload_chunk', array($this, 'upload_chunk'));
        add_action('wp_ajax_tsol_library_migration_prepare_attachments', array($this, 'prepare_attachments'));
        add_action('wp_ajax_tsol_library_migration_apply_step', array($this, 'apply_step'));
    }

    public function add_page() {
        add_submenu_page(
            MemberLibrary_Admin_Navigation::MENU_SLUG,
            __('Library Migration', 'member-library'),
            __('Migration', 'member-library'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'render')
        );
    }

    public function export() {
        $this->authorize();
        $migration = new MemberLibrary_Environment_Migration();
        $zip_path = wp_tempnam('tsol-wordpress-library.zip');
        if (!$zip_path) {
            $this->fail(__('WordPress could not allocate temporary space for the Library ZIP.', 'member-library'));
        }
        try {
            @set_time_limit(0);
            $migration->build_bundle($zip_path);
        } catch (Throwable $exception) {
            if (is_file($zip_path)) {
                unlink($zip_path);
            }
            $this->fail($exception->getMessage());
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ignore_user_abort(true);
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Length: ' . filesize($zip_path));
        header('Content-Disposition: attachment; filename="tsol-wordpress-library-' . gmdate('Ymd-His') . '.zip"');
        header('X-Content-Type-Options: nosniff');
        $stream = fopen($zip_path, 'rb');
        if (is_resource($stream)) {
            fpassthru($stream); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary ZIP download.
            fclose($stream);
        }
        unlink($zip_path);
        exit;
    }

    public function preview() {
        $this->authorize();
        $upload_token = sanitize_text_field(wp_unslash((string) ($_POST['upload_token'] ?? '')));
        $upload = get_option($this->upload_option_name(), array());
        if (!is_array($upload)
            || empty($upload['complete'])
            || !hash_equals((string) ($upload['token'] ?? ''), $upload_token)
            || time() - (int) ($upload['created_at'] ?? 0) > HOUR_IN_SECONDS
            || !is_file((string) ($upload['path'] ?? ''))
        ) {
            $this->fail(__('The ZIP upload expired or is incomplete. Upload it again.', 'member-library'));
        }
        $zip_path = (string) $upload['path'];
        $migration = new MemberLibrary_Environment_Migration();
        try {
            @set_time_limit(0);
            $package = $migration->decode_bundle($zip_path);
            $report = $migration->preview($package, true);
        } catch (Throwable $exception) {
            $this->fail($exception->getMessage());
        }
        $token = wp_generate_uuid4();
        $previous_pending = get_option(self::PENDING_OPTION, array());
        if (is_array($previous_pending) && !empty($previous_pending['bundle_path']) && $previous_pending['bundle_path'] !== $zip_path) {
            $this->delete_private_bundle((string) $previous_pending['bundle_path']);
        }
        update_option(self::PENDING_OPTION, array(
            'token' => $token,
            'user_id' => get_current_user_id(),
            'created_at' => time(),
            'bundle_path' => $zip_path,
            'report' => $report,
            'attachment_index' => 0,
            'prepared_created' => array('posts' => array(), 'terms' => array(), 'attachments' => array()),
        ), false);
        delete_option($this->upload_option_name());
        wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'preview' => 'ready'), admin_url('admin.php')));
        exit;
    }

    public function upload_chunk() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to migrate the Library.', 'member-library')), 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $file = isset($_FILES['chunk']) ? $_FILES['chunk'] : array();
        $index = absint($_POST['index'] ?? -1);
        $total_chunks = absint($_POST['total_chunks'] ?? 0);
        $total_bytes = (int) ($_POST['total_bytes'] ?? 0);
        $name = sanitize_file_name((string) ($_POST['filename'] ?? ''));
        $token = sanitize_text_field((string) ($_POST['upload_token'] ?? ''));
        if ('zip' !== strtolower((string) pathinfo($name, PATHINFO_EXTENSION))
            || $total_chunks < 1 || $total_chunks > 4096
            || $total_bytes < 1 || $total_bytes > MemberLibrary_Environment_Migration::MAX_BUNDLE_BYTES
            || !is_array($file) || UPLOAD_ERR_OK !== (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE)
            || (int) ($file['size'] ?? 0) < 1 || (int) ($file['size'] ?? 0) > self::CHUNK_BYTES
        ) {
            wp_send_json_error(array('message' => __('The ZIP upload chunk is invalid.', 'member-library')), 400);
        }
        $option_name = $this->upload_option_name();
        $state = get_option($option_name, array());
        if (0 === $index) {
            $this->discard_upload_state($state);
            $token = wp_generate_uuid4();
            $path = trailingslashit(get_temp_dir()) . 'tsol-library-' . $token . '.zip';
            $state = array(
                'token' => $token,
                'path' => $path,
                'filename' => $name,
                'total_chunks' => $total_chunks,
                'total_bytes' => $total_bytes,
                'next_index' => 0,
                'received_bytes' => 0,
                'created_at' => time(),
                'complete' => false,
            );
        }
        if (!is_array($state)
            || !hash_equals((string) ($state['token'] ?? ''), $token)
            || $index !== (int) ($state['next_index'] ?? -1)
            || $total_chunks !== (int) ($state['total_chunks'] ?? 0)
            || $total_bytes !== (int) ($state['total_bytes'] ?? 0)
            || time() - (int) ($state['created_at'] ?? 0) > HOUR_IN_SECONDS
        ) {
            wp_send_json_error(array('message' => __('The ZIP upload sequence expired or changed.', 'member-library')), 409);
        }
        $output = fopen((string) $state['path'], 0 === $index ? 'xb' : 'ab');
        $input = fopen((string) $file['tmp_name'], 'rb');
        if (!is_resource($output) || !is_resource($input) || !flock($output, LOCK_EX)) {
            if (is_resource($output)) {
                fclose($output);
            }
            if (is_resource($input)) {
                fclose($input);
            }
            wp_send_json_error(array('message' => __('WordPress could not store the ZIP upload.', 'member-library')), 500);
        }
        $written = stream_copy_to_stream($input, $output);
        fflush($output);
        flock($output, LOCK_UN);
        fclose($input);
        fclose($output);
        if (false === $written || (int) $written !== (int) $file['size']) {
            $this->discard_upload_state($state);
            wp_send_json_error(array('message' => __('WordPress could not store the complete ZIP chunk.', 'member-library')), 500);
        }
        if (0 === $index) {
            chmod((string) $state['path'], 0600);
        }
        $state['next_index']++;
        $state['received_bytes'] += (int) $written;
        $state['complete'] = $state['next_index'] === $state['total_chunks'];
        if ($state['complete'] && ((int) $state['received_bytes'] !== $state['total_bytes'] || filesize($state['path']) !== $state['total_bytes'])) {
            $this->discard_upload_state($state);
            wp_send_json_error(array('message' => __('The completed ZIP size does not match the selected file.', 'member-library')), 400);
        }
        update_option($option_name, $state, false);
        wp_send_json_success(array(
            'upload_token' => $token,
            'next_index' => (int) $state['next_index'],
            'complete' => (bool) $state['complete'],
        ));
    }

    public function apply() {
        $this->authorize();
        $pending = $this->pending();
        $token = sanitize_text_field(wp_unslash((string) ($_POST['migration_token'] ?? '')));
        $confirmation = sanitize_text_field(wp_unslash((string) ($_POST['confirmation'] ?? '')));
        if (empty($pending) || !hash_equals((string) ($pending['token'] ?? ''), $token)) {
            $this->fail(__('The migration preview expired or changed. Upload it again.', 'member-library'));
        }
        if (self::IMPORT_CONFIRMATION !== $confirmation) {
            $this->fail(__('Enter the exact migration confirmation before importing.', 'member-library'));
        }
        try {
            $migration = new MemberLibrary_Environment_Migration();
            $bundle_path = (string) ($pending['bundle_path'] ?? '');
            $attachments_prepared = !empty($_POST['attachments_prepared']);
            $attachment_total = (int) ($pending['report']['attachment_files'] ?? 0);
            if ($attachments_prepared && (int) ($pending['attachment_index'] ?? 0) < $attachment_total) {
                throw new RuntimeException('The attachment preparation is incomplete. Resume the staged import.');
            }
            @set_time_limit(0);
            $package = $attachments_prepared
                ? $migration->decode_bundle_manifest($bundle_path)
                : $migration->decode_bundle($bundle_path);
            $migration->apply(
                $package,
                (string) ($pending['report']['package_hash'] ?? ''),
                $bundle_path,
                (array) ($pending['prepared_created'] ?? array()),
                $attachments_prepared
            );
            $this->delete_private_bundle($bundle_path);
            delete_option(self::PENDING_OPTION);
        } catch (Throwable $exception) {
            $this->fail($exception->getMessage());
        }
        wp_safe_redirect(add_query_arg(array('page' => self::PAGE_SLUG, 'result' => 'applied'), admin_url('admin.php')));
        exit;
    }

    public function prepare_attachments() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to migrate the Library.', 'member-library')), 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $pending = $this->pending();
        $token = sanitize_text_field(wp_unslash((string) ($_POST['migration_token'] ?? '')));
        if (empty($pending) || !hash_equals((string) ($pending['token'] ?? ''), $token)) {
            wp_send_json_error(array('message' => __('The migration preview expired or changed.', 'member-library')), 409);
        }
        try {
            @set_time_limit(0);
            $migration = new MemberLibrary_Environment_Migration();
            $package = $migration->decode_bundle_manifest((string) $pending['bundle_path']);
            if (!hash_equals((string) ($pending['report']['package_hash'] ?? ''), (string) ($package['manifest']['data_sha256'] ?? ''))) {
                throw new RuntimeException('The staged migration package changed after preview.');
            }
            $created = (array) ($pending['prepared_created'] ?? array('posts' => array(), 'terms' => array(), 'attachments' => array()));
            $progress = $migration->prepare_attachment_batch(
                $package,
                (string) $pending['bundle_path'],
                (int) ($pending['attachment_index'] ?? 0),
                self::ATTACHMENT_BATCH_SIZE,
                $created
            );
            $pending['attachment_index'] = (int) $progress['next'];
            $pending['prepared_created'] = $created;
            $pending['last_progress_at'] = time();
            update_option(self::PENDING_OPTION, $pending, false);
            wp_send_json_success(array(
                'processed' => (int) $progress['next'],
                'total' => (int) $progress['total'],
                'complete' => (int) $progress['next'] >= (int) $progress['total'],
            ));
        } catch (Throwable $exception) {
            wp_send_json_error(array('message' => $exception->getMessage()), 409);
        }
    }

    public function apply_step() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to migrate the Library.', 'member-library')), 403);
        }
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        $pending = $this->pending();
        $token = sanitize_text_field(wp_unslash((string) ($_POST['migration_token'] ?? '')));
        if (empty($pending) || !hash_equals((string) ($pending['token'] ?? ''), $token)) {
            wp_send_json_error(array('message' => __('The migration preview expired or changed.', 'member-library')), 409);
        }
        $state = (array) ($pending['apply_state'] ?? array());
        if (empty($state['phase'])) {
            $confirmation = sanitize_text_field(wp_unslash((string) ($_POST['confirmation'] ?? '')));
            if (self::IMPORT_CONFIRMATION !== $confirmation) {
                wp_send_json_error(array('message' => __('Enter the exact migration confirmation before importing.', 'member-library')), 400);
            }
            $attachment_total = (int) ($pending['report']['attachment_files'] ?? 0);
            if ((int) ($pending['attachment_index'] ?? 0) < $attachment_total) {
                wp_send_json_error(array('message' => __('The bundled files are not fully prepared yet.', 'member-library')), 409);
            }
            $state = array('phase' => 'prepare', 'created' => (array) ($pending['prepared_created'] ?? array()));
            $this->log($pending, __('Import started.', 'member-library'));
        } elseif ('failed' === $state['phase']) {
            $state['phase'] = (string) ($state['resume_phase'] ?? 'prepare');
            $this->log($pending, __('Resuming import.', 'member-library'));
        }
        try {
            @set_time_limit(0);
            $migration = new MemberLibrary_Environment_Migration();
            $package = $migration->decode_bundle_manifest((string) $pending['bundle_path']);
            $result = $migration->apply_step($package, (string) ($pending['report']['package_hash'] ?? ''), $state, true);
            $state = $result['state'];
            foreach ((array) $result['messages'] as $message) {
                $this->log($pending, (string) $message);
            }
            $complete = 'complete' === (string) $state['phase'];
            if ($complete) {
                $this->log($pending, __('Import complete.', 'member-library'));
                $this->delete_private_bundle((string) $pending['bundle_path']);
                $log = (array) ($pending['log'] ?? array());
                delete_option(self::PENDING_OPTION);
                wp_send_json_success(array('complete' => true, 'phase' => 'complete', 'progress' => 100, 'log' => $log));
            }
            $pending['apply_state'] = $state;
            $pending['last_progress_at'] = time();
            update_option(self::PENDING_OPTION, $pending, false);
            wp_send_json_success(array(
                'complete' => false,
                'phase' => (string) $state['phase'],
                'progress' => $this->apply_progress($state),
                'log' => (array) ($pending['log'] ?? array()),
            ));
        } catch (Throwable $exception) {
            $failed_phase = (string) (($state['phase'] ?? '') ?: 'prepare');
            $state['resume_phase'] = $failed_phase;
            $state['phase'] = 'failed';
            $state['error'] = $exception->getMessage();
            $pending['apply_state'] = $state;
            $this->log($pending, sprintf(__('Error during %1$s: %2$s', 'member-library'), $failed_phase, $exception->getMessage()));
            update_option(self::PENDING_OPTION, $pending, false);
            wp_send_json_error(array(
                'message' => $exception->getMessage(),
                'phase' => 'failed',
                'resumable' => true,
                'log' => (array) ($pending['log'] ?? array()),
            ), 409);
        }
    }

    private function log(&$pending, $message) {
        $log = (array) ($pending['log'] ?? array());
        $log[] = gmdate('H:i:s') . ' ' . (string) $message;
        $pending['log'] = array_slice($log, -self::LOG_LIMIT);
    }

    private function apply_progress($state) {
        $total = max(1, (int) ($state['total'] ?? 0));
        $cursor = (int) ($state['cursor'] ?? 0);
        switch ((string) ($state['phase'] ?? '')) {
            case 'records':
                return (int) round(5 + 60 * $cursor / $total);
            case 'relations':
                return (int) round(65 + 30 * $cursor / $total);
            case 'finalize':
                return 96;
            case 'complete':
                return 100;
            default:
                return 2;
        }
    }

    public function rollback() {
        $this->authorize();
        $confirmation = sanitize_text_field(wp_unslash((string) ($_POST['confirmation'] ?? '')));
        try {
            (new MemberLibrary_Environment_Migration())->rollback($confirmation);
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
        $rollback = (new MemberLibrary_Environment_Migration())->rollback_state();
        $result = isset($_GET['result']) ? sanitize_key(wp_unslash($_GET['result'])) : '';
        ?>
        <div class="wrap tsol-library-admin-page">
            <h1><?php esc_html_e('Migration', 'member-library'); ?></h1>
            <p class="tsol-library-admin-page__lead"><?php esc_html_e('Move only WordPress-owned Library content and configuration between environments using stable UUIDs and membership slugs.', 'member-library'); ?></p>

            <?php if ('applied' === $result) : ?>
                <div class="notice notice-success inline"><p><?php esc_html_e('The WordPress Library package was imported. Access Groups remain a draft: check the full matrix before publishing their MemberPress rules.', 'member-library'); ?></p></div>
            <?php elseif ('rolled-back' === $result) : ?>
                <div class="notice notice-success inline"><p><?php esc_html_e('The last WordPress Library migration was rolled back.', 'member-library'); ?></p></div>
            <?php endif; ?>

            <div class="notice notice-info inline">
                <p><strong><?php esc_html_e('WordPress Library only.', 'member-library'); ?></strong> <?php esc_html_e('Packages never contain the standalone app database, app accounts, sessions, progress, notes, bookmarks, WordPress users, MemberPress transactions, secrets, logs, or temporary state.', 'member-library'); ?></p>
            </div>

            <div class="tsol-library-admin-grid">
                <section class="card">
                    <h2><?php esc_html_e('1. Export from test', 'member-library'); ?></h2>
                    <p><?php esc_html_e('Download one verified ZIP containing courses, series, content, speakers, terms, homepage curation, portable Access Groups, and the uploads only this site holds. Uploads WP Offload Media already keeps in the shared storage bucket are linked on production, and existing videos are matched and verified there, instead of being uploaded again.', 'member-library'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tsol_library_migration_export">
                        <?php wp_nonce_field(self::NONCE_ACTION); ?>
                        <?php submit_button(__('Download complete Library ZIP', 'member-library'), 'secondary', 'tsol_library_migration_export', false); ?>
                    </form>
                </section>
                <section class="card">
                    <h2><?php esc_html_e('2. Preview on production', 'member-library'); ?></h2>
                    <p><?php esc_html_e('Large ZIPs upload securely in small chunks, so normal PHP upload limits do not truncate the catalogue or its files. Preview verifies every checksum before making changes.', 'member-library'); ?></p>
                    <form id="tsol-library-migration-upload" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tsol_library_migration_preview">
                        <input type="hidden" name="upload_token" value="">
                        <?php wp_nonce_field(self::NONCE_ACTION); ?>
                        <input id="tsol-library-migration-file" type="file" accept="application/zip,.zip" required>
                        <button id="tsol-library-migration-upload-button" type="button" class="button button-secondary"><?php esc_html_e('Upload and verify ZIP', 'member-library'); ?></button>
                        <progress id="tsol-library-migration-progress" max="100" value="0" hidden></progress>
                        <p id="tsol-library-migration-upload-status" class="description" aria-live="polite"></p>
                        <noscript><p class="notice notice-error inline"><?php esc_html_e('JavaScript is required for reliable large-file uploads.', 'member-library'); ?></p></noscript>
                    </form>
                </section>
            </div>

            <?php if (!empty($pending)) : ?>
                <section class="card tsol-library-admin-card--wide">
                    <h2><?php esc_html_e('Import preview', 'member-library'); ?></h2>
                    <div class="tsol-library-admin-stats">
                        <?php $this->stat(__('Create', 'member-library'), (int) ($report['creates'] ?? 0)); ?>
                        <?php $this->stat(__('Update', 'member-library'), (int) ($report['updates'] ?? 0)); ?>
                        <?php $this->stat(__('Adopt existing', 'member-library'), (int) ($report['adoptions'] ?? 0)); ?>
                        <?php $this->stat(__('Unchanged', 'member-library'), (int) ($report['unchanged'] ?? 0)); ?>
                        <?php $this->stat(__('Terms', 'member-library'), (int) ($report['terms'] ?? 0)); ?>
                        <?php $this->stat(__('Access Groups', 'member-library'), (int) ($report['groups'] ?? 0)); ?>
                        <?php $this->stat(__('Memberships', 'member-library'), (int) ($report['membership_assignments'] ?? 0)); ?>
                        <?php $this->stat(__('Bundled files', 'member-library'), (int) ($report['bundled_attachment_files'] ?? 0)); ?>
                        <?php $this->stat(__('Linked from storage', 'member-library'), (int) ($report['linked_attachment_files'] ?? 0)); ?>
                        <?php $this->stat(__('Matched on production', 'member-library'), count((array) ($report['existing_attachments'] ?? array()))); ?>
                        <?php $this->stat(__('Upload size', 'member-library'), size_format((int) ($report['attachment_bytes'] ?? 0), 1)); ?>
                    </div>
                    <?php foreach ((array) ($report['errors'] ?? array()) as $error) : ?>
                        <div class="notice notice-error inline"><p><?php echo esc_html($error); ?></p></div>
                    <?php endforeach; ?>
                    <?php foreach ((array) ($report['warnings'] ?? array()) as $warning) : ?>
                        <div class="notice notice-warning inline"><p><?php echo esc_html($warning); ?></p></div>
                    <?php endforeach; ?>
                    <?php if (!empty($report['missing_attachments'])) : ?>
                        <details><summary><?php echo esc_html(sprintf(__('%d files are genuinely missing', 'member-library'), count($report['missing_attachments']))); ?></summary><ul>
                            <?php foreach ($report['missing_attachments'] as $missing) : ?><li><code><?php echo esc_html($missing); ?></code></li><?php endforeach; ?>
                        </ul></details>
                    <?php endif; ?>
                    <?php $apply_state = (array) ($pending['apply_state'] ?? array()); $in_flight = !empty($apply_state['phase']); ?>
                    <?php if (empty($report['errors'])) : ?>
                        <?php if ($in_flight) : ?>
                            <?php if ('failed' === (string) $apply_state['phase']) : ?>
                                <div class="notice notice-error inline"><p><?php echo esc_html(sprintf(__('The import stopped during “%1$s”: %2$s', 'member-library'), (string) ($apply_state['resume_phase'] ?? ''), (string) ($apply_state['error'] ?? ''))); ?></p></div>
                                <p><?php esc_html_e('Resume to retry that step, or roll back the partial import below.', 'member-library'); ?></p>
                            <?php else : ?>
                                <p><?php esc_html_e('An import is in progress. If the browser tab was closed, resume it here; nothing is applied twice.', 'member-library'); ?></p>
                            <?php endif; ?>
                        <?php else : ?>
                            <p><?php esc_html_e('Importing creates a rollback snapshot and leaves Access Groups unpublished. Existing legacy MemberPress authorization remains active until the separate access comparison is checked and explicitly published.', 'member-library'); ?></p>
                        <?php endif; ?>
                        <form id="tsol-library-migration-apply" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-in-flight="<?php echo $in_flight ? '1' : '0'; ?>">
                            <input type="hidden" name="action" value="tsol_library_migration_apply">
                            <input type="hidden" name="migration_token" value="<?php echo esc_attr((string) $pending['token']); ?>">
                            <input type="hidden" name="attachments_prepared" value="0">
                            <?php wp_nonce_field(self::NONCE_ACTION); ?>
                            <?php if (!$in_flight) : ?>
                                <label for="tsol-library-migration-confirmation"><strong><?php esc_html_e('Type import-wordpress-library to confirm', 'member-library'); ?></strong></label><br>
                                <input id="tsol-library-migration-confirmation" class="regular-text code" name="confirmation" autocomplete="off" required>
                                <?php submit_button(__('Import WordPress Library', 'member-library'), 'primary', 'tsol_library_migration_import', false); ?>
                            <?php else : ?>
                                <?php submit_button(__('Resume import', 'member-library'), 'primary', 'tsol_library_migration_resume', false); ?>
                            <?php endif; ?>
                            <progress id="tsol-library-migration-apply-progress" max="100" value="<?php echo esc_attr($in_flight ? $this->apply_progress($apply_state) : (int) round(100 * (int) ($pending['attachment_index'] ?? 0) / max(1, (int) ($report['attachment_files'] ?? 0)))); ?>" <?php echo $in_flight ? '' : 'hidden'; ?>></progress>
                            <p id="tsol-library-migration-apply-status" class="description" aria-live="polite"></p>
                            <pre id="tsol-library-migration-log" class="tsol-library-migration-log" <?php echo empty($pending['log']) ? 'hidden' : ''; ?> style="max-height:16em;overflow:auto;background:#f6f7f7;padding:8px 12px;font-size:12px;"><?php echo esc_html(implode("\n", (array) ($pending['log'] ?? array()))); ?></pre>
                        </form>
                    <?php endif; ?>
                </section>
            <?php endif; ?>

            <?php if (!empty($rollback)) : ?>
                <section class="card tsol-library-admin-card--wide">
                    <h2><?php esc_html_e('Rollback', 'member-library'); ?></h2>
                    <?php if (!empty($rollback['partial'])) : ?>
                        <div class="notice notice-warning inline"><p><?php esc_html_e('The last import did not finish. This snapshot was taken before it wrote anything, so rolling back restores production exactly.', 'member-library'); ?></p></div>
                    <?php endif; ?>
                    <p><?php esc_html_e('A snapshot from before the last import is available. Roll back any Access Groups stage first, then restore the previous WordPress Library records and configuration.', 'member-library'); ?></p>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <input type="hidden" name="action" value="tsol_library_migration_rollback">
                        <?php wp_nonce_field(self::NONCE_ACTION); ?>
                        <label for="tsol-library-rollback-confirmation"><strong><?php esc_html_e('Type rollback-library-migration to confirm', 'member-library'); ?></strong></label><br>
                        <input id="tsol-library-rollback-confirmation" class="regular-text code" name="confirmation" autocomplete="off" required>
                        <?php submit_button(__('Roll back last migration', 'member-library'), 'delete', 'tsol_library_migration_rollback', false); ?>
                    </form>
                </section>
            <?php endif; ?>
        </div>
        <script>
        (() => {
            const form = document.getElementById('tsol-library-migration-upload');
            const fileInput = document.getElementById('tsol-library-migration-file');
            const button = document.getElementById('tsol-library-migration-upload-button');
            const progress = document.getElementById('tsol-library-migration-progress');
            const status = document.getElementById('tsol-library-migration-upload-status');
            if (!form || !fileInput || !button || !progress || !status) return;
            button.addEventListener('click', async () => {
                const file = fileInput.files && fileInput.files[0];
                if (!file || !file.name.toLowerCase().endsWith('.zip')) {
                    status.textContent = <?php echo wp_json_encode(__('Choose the complete Library ZIP first.', 'member-library')); ?>;
                    return;
                }
                const chunkBytes = <?php echo (int) self::CHUNK_BYTES; ?>;
                const totalChunks = Math.ceil(file.size / chunkBytes);
                let uploadToken = '';
                button.disabled = true;
                fileInput.disabled = true;
                progress.hidden = false;
                try {
                    for (let index = 0; index < totalChunks; index++) {
                        const body = new FormData();
                        body.append('action', 'tsol_library_migration_upload_chunk');
                        body.append('nonce', <?php echo wp_json_encode(wp_create_nonce(self::NONCE_ACTION)); ?>);
                        body.append('index', String(index));
                        body.append('total_chunks', String(totalChunks));
                        body.append('total_bytes', String(file.size));
                        body.append('filename', file.name);
                        body.append('upload_token', uploadToken);
                        body.append('chunk', file.slice(index * chunkBytes, Math.min(file.size, (index + 1) * chunkBytes)), file.name + '.part');
                        status.textContent = <?php echo wp_json_encode(__('Uploading Library ZIP…', 'member-library')); ?> + ' ' + Math.round(index / totalChunks * 100) + '%';
                        const response = await fetch(<?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>, { method: 'POST', credentials: 'same-origin', body });
                        const result = await response.json();
                        if (!response.ok || !result.success) throw new Error(result?.data?.message || <?php echo wp_json_encode(__('The ZIP upload failed.', 'member-library')); ?>);
                        uploadToken = result.data.upload_token;
                        progress.value = Math.round((index + 1) / totalChunks * 100);
                    }
                    status.textContent = <?php echo wp_json_encode(__('Upload complete. Verifying checksums…', 'member-library')); ?>;
                    form.querySelector('[name="upload_token"]').value = uploadToken;
                    HTMLFormElement.prototype.submit.call(form);
                } catch (error) {
                    status.textContent = error instanceof Error ? error.message : <?php echo wp_json_encode(__('The ZIP upload failed.', 'member-library')); ?>;
                    button.disabled = false;
                    fileInput.disabled = false;
                }
            });

            const applyForm = document.getElementById('tsol-library-migration-apply');
            const applyProgress = document.getElementById('tsol-library-migration-apply-progress');
            const applyStatus = document.getElementById('tsol-library-migration-apply-status');
            const applyLog = document.getElementById('tsol-library-migration-log');
            if (applyForm && applyProgress && applyStatus) {
                const ajaxUrl = <?php echo wp_json_encode(admin_url('admin-ajax.php')); ?>;
                const nonce = <?php echo wp_json_encode(wp_create_nonce(self::NONCE_ACTION)); ?>;
                const token = <?php echo wp_json_encode((string) ($pending['token'] ?? '')); ?>;
                const renderLog = (lines) => {
                    if (!applyLog || !Array.isArray(lines)) return;
                    applyLog.hidden = lines.length === 0;
                    applyLog.textContent = lines.join('\n');
                    applyLog.scrollTop = applyLog.scrollHeight;
                };
                const post = async (action, extra) => {
                    const body = new FormData();
                    body.append('action', action);
                    body.append('nonce', nonce);
                    body.append('migration_token', token);
                    Object.keys(extra || {}).forEach((key) => body.append(key, extra[key]));
                    const response = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
                    let result = null;
                    try { result = await response.json(); } catch (e) { result = null; }
                    if (!result) throw new Error(<?php echo wp_json_encode(__('The server returned an unreadable response (status %s). Reload this page to resume.', 'member-library')); ?>.replace('%s', String(response.status)));
                    if (result.data && result.data.log) renderLog(result.data.log);
                    if (!response.ok || !result.success) throw new Error(result?.data?.message || <?php echo wp_json_encode(__('The staged import could not continue.', 'member-library')); ?>);
                    return result.data;
                };
                const phaseLabels = {
                    prepare: <?php echo wp_json_encode(__('Saving rollback snapshot and syncing terms…', 'member-library')); ?>,
                    records: <?php echo wp_json_encode(__('Writing catalogue records…', 'member-library')); ?>,
                    relations: <?php echo wp_json_encode(__('Linking relationships, media, and access…', 'member-library')); ?>,
                    finalize: <?php echo wp_json_encode(__('Finalizing homepage and Access Groups…', 'member-library')); ?>,
                };
                applyForm.addEventListener('submit', async (event) => {
                    event.preventDefault();
                    if (!applyForm.reportValidity()) return;
                    const submit = applyForm.querySelector('[type="submit"]');
                    if (submit) submit.disabled = true;
                    applyProgress.hidden = false;
                    const inFlight = applyForm.dataset.inFlight === '1';
                    const confirmationField = applyForm.querySelector('[name="confirmation"]');
                    const confirmation = confirmationField ? confirmationField.value : '';
                    try {
                        if (!inFlight) {
                            let complete = false;
                            while (!complete) {
                                const data = await post('tsol_library_migration_prepare_attachments', {});
                                const processed = Number(data.processed || 0);
                                const total = Number(data.total || 0);
                                complete = Boolean(data.complete);
                                applyProgress.value = total > 0 ? Math.round(processed / total * 100) : 100;
                                applyStatus.textContent = <?php echo wp_json_encode(__('Preparing bundled files…', 'member-library')); ?> + ' ' + processed + ' / ' + total;
                            }
                        }
                        applyStatus.textContent = phaseLabels.prepare;
                        let done = false;
                        while (!done) {
                            const data = await post('tsol_library_migration_apply_step', { confirmation });
                            done = Boolean(data.complete);
                            applyProgress.value = Number(data.progress || 0);
                            applyStatus.textContent = done
                                ? <?php echo wp_json_encode(__('Import complete. Reloading…', 'member-library')); ?>
                                : (phaseLabels[data.phase] || data.phase) + ' ' + applyProgress.value + '%';
                        }
                        window.location.href = <?php echo wp_json_encode(add_query_arg(array('page' => self::PAGE_SLUG, 'result' => 'applied'), admin_url('admin.php'))); ?>;
                    } catch (error) {
                        applyStatus.textContent = error instanceof Error ? error.message : <?php echo wp_json_encode(__('The staged import could not continue.', 'member-library')); ?>;
                        applyForm.dataset.inFlight = '1';
                        if (confirmationField) confirmationField.required = false;
                        if (submit) {
                            submit.disabled = false;
                            submit.value = <?php echo wp_json_encode(__('Resume import', 'member-library')); ?>;
                        }
                    }
                });
            }
        })();
        </script>
        <?php
    }

    private function pending() {
        $pending = get_option(self::PENDING_OPTION, array());
        if (!is_array($pending)
            || (int) ($pending['user_id'] ?? 0) !== get_current_user_id()
            || time() - (int) ($pending['created_at'] ?? 0) > self::PENDING_TTL
        ) {
            if (!empty($pending)) {
                $this->delete_private_bundle((string) ($pending['bundle_path'] ?? ''));
                delete_option(self::PENDING_OPTION);
            }
            return array();
        }
        return $pending;
    }

    private function upload_option_name() {
        return self::UPLOAD_OPTION_PREFIX . get_current_user_id();
    }

    private function discard_upload_state($state) {
        if (is_array($state)) {
            $this->delete_private_bundle((string) ($state['path'] ?? ''));
        }
        delete_option($this->upload_option_name());
    }

    private function delete_private_bundle($path) {
        $path = (string) $path;
        $temp = trailingslashit(wp_normalize_path(get_temp_dir()));
        $normalized = wp_normalize_path($path);
        if (0 === strpos($normalized, $temp)
            && preg_match('/\/tsol-library-[a-f0-9-]+\.zip$/i', $normalized)
            && is_file($path)
        ) {
            unlink($path);
        }
    }

    private function authorize() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to migrate the Library.', 'member-library'));
        }
        check_admin_referer(self::NONCE_ACTION);
    }

    private function fail($message) {
        wp_die(esc_html((string) $message), esc_html__('Library migration stopped', 'member-library'), array('response' => 400, 'back_link' => true));
    }

    private function stat($label, $value) {
        ?>
        <div class="tsol-library-admin-stat"><strong><?php echo esc_html((string) $value); ?></strong><span><?php echo esc_html($label); ?></span></div>
        <?php
    }
}
