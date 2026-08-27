<?php
/**
 * Portable WordPress-only Library migration packages.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Environment_Migration {

    const SCHEMA_VERSION = 1;
    const ROLLBACK_OPTION = 'tsol_library_environment_migration_rollback';
    const LOCK_OPTION = 'tsol_library_environment_migration_lock';
    const LOCK_TTL = 300;
    const OWNER_META = '_tsol_library_environment_migration';
    const ROLLBACK_CONFIRMATION = 'rollback-library-migration';
    const BUNDLE_FORMAT = 'tsol-wordpress-library-zip-v1';
    const PACKAGE_FILENAME = 'tsol-library-package.json';
    const MAX_BUNDLE_BYTES = 2147483648;
    const MAX_BUNDLE_ENTRIES = 1000;

    public function build_package() {
        TSOL_Library_Homepage_Curation::reset_cache();
        $data = array(
            'posts' => $this->export_posts(),
            'terms' => $this->export_terms(),
            'homepage' => $this->export_homepage(),
            'access_groups' => $this->export_access_groups(),
        );
        $manifest = array(
            'schema_version' => self::SCHEMA_VERSION,
            'plugin_version' => TSOL_SITE_PLUGIN_VERSION,
            'source_url' => home_url('/'),
            'created_at' => gmdate('c'),
            'scope' => 'wordpress-library-only',
            'counts' => array(
                'posts' => count($data['posts']),
                'terms' => count($data['terms']),
                'groups' => count((array) ($data['access_groups']['groups'] ?? array())),
                'membership_assignments' => count((array) ($data['access_groups']['assignments'] ?? array())),
            ),
        );
        $manifest['data_sha256'] = $this->data_hash($data);
        return array('manifest' => $manifest, 'data' => $data);
    }

    public function encode($package) {
        return wp_json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function build_bundle($zip_path) {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP Zip extension is required to export a complete Library package.');
        }
        $package = $this->build_package();
        $attachments = $this->bundle_attachment_inventory($package);
        $package['data']['attachments'] = $attachments;
        $package['manifest']['bundle_format'] = self::BUNDLE_FORMAT;
        $package['manifest']['counts']['attachments'] = count($attachments);
        $package['manifest']['counts']['attachment_bytes'] = array_sum(array_map(static function ($attachment) {
            return (int) $attachment['bytes'];
        }, $attachments));
        $package['manifest']['data_sha256'] = $this->data_hash($package['data']);

        $zip = new ZipArchive();
        if (true !== $zip->open((string) $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE)) {
            throw new RuntimeException('WordPress could not create the Library ZIP package.');
        }
        try {
            if (!$zip->addFromString(self::PACKAGE_FILENAME, $this->encode($package))) {
                throw new RuntimeException('WordPress could not add the Library manifest to the ZIP package.');
            }
            $uploads = wp_upload_dir(null, false);
            foreach ($attachments as $attachment) {
                $source = trailingslashit((string) $uploads['basedir']) . $attachment['relative_file'];
                if (!$zip->addFile($source, $attachment['archive_path'])) {
                    throw new RuntimeException(sprintf('WordPress could not add upload “%s” to the ZIP package.', $attachment['relative_file']));
                }
            }
        } catch (Throwable $exception) {
            $zip->close();
            if (is_file($zip_path)) {
                unlink($zip_path);
            }
            throw $exception;
        }
        if (!$zip->close() || !is_file($zip_path)) {
            throw new RuntimeException('WordPress could not finalize the Library ZIP package.');
        }
        return $package;
    }

    public function decode_bundle($zip_path) {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP Zip extension is required to inspect a Library package.');
        }
        if (!is_file($zip_path) || filesize($zip_path) <= 0 || filesize($zip_path) > self::MAX_BUNDLE_BYTES) {
            throw new RuntimeException('The Library ZIP package is missing, empty, or larger than 2 GB.');
        }
        $zip = new ZipArchive();
        if (true !== $zip->open((string) $zip_path, ZipArchive::RDONLY)) {
            throw new RuntimeException('The uploaded file is not a readable ZIP package.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_BUNDLE_ENTRIES) {
                throw new RuntimeException('The Library ZIP package contains an unsafe number of files.');
            }
            $total_bytes = 0;
            $entry_names = array();
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = (string) ($stat['name'] ?? '');
                if (!$this->safe_archive_path($name) || isset($entry_names[$name])) {
                    throw new RuntimeException('The Library ZIP package contains an unsafe or duplicate path.');
                }
                $entry_names[$name] = true;
                $total_bytes += (int) ($stat['size'] ?? 0);
                if ($total_bytes > self::MAX_BUNDLE_BYTES) {
                    throw new RuntimeException('The expanded Library ZIP package is larger than 2 GB.');
                }
            }
            $package_json = $zip->getFromName(self::PACKAGE_FILENAME, 25 * MB_IN_BYTES);
            if (false === $package_json) {
                throw new RuntimeException('The Library ZIP package has no valid manifest.');
            }
            $package = $this->decode($package_json);
            if (self::BUNDLE_FORMAT !== (string) ($package['manifest']['bundle_format'] ?? '')) {
                throw new RuntimeException('This is not a complete TSOL Library ZIP package.');
            }
            $expected_entries = array(self::PACKAGE_FILENAME => true);
            $manifest_paths = array();
            foreach ((array) ($package['data']['attachments'] ?? array()) as $attachment) {
                $archive_path = (string) ($attachment['archive_path'] ?? '');
                $relative_file = (string) ($attachment['relative_file'] ?? '');
                if (!$this->safe_relative_upload_path($relative_file)
                    || !$this->safe_archive_path($archive_path)
                    || 'attachments/' . $relative_file !== $archive_path
                    || isset($expected_entries[$archive_path])
                ) {
                    throw new RuntimeException('The Library ZIP attachment manifest contains an unsafe path.');
                }
                $stat = $zip->statName($archive_path);
                if (false === $stat || (int) ($stat['size'] ?? -1) !== (int) ($attachment['bytes'] ?? -2)) {
                    throw new RuntimeException(sprintf('Bundled upload “%s” is missing or has the wrong size.', $relative_file));
                }
                if (!hash_equals((string) ($attachment['sha256'] ?? ''), $this->zip_entry_hash($zip, $archive_path))) {
                    throw new RuntimeException(sprintf('Bundled upload “%s” failed its checksum.', $relative_file));
                }
                $expected_entries[$archive_path] = true;
                $manifest_paths[$relative_file] = true;
            }
            if (!empty(array_diff_key($entry_names, $expected_entries))
                || !empty(array_diff_key($expected_entries, $entry_names))
                || $manifest_paths !== $this->package_reference_paths($package)
            ) {
                throw new RuntimeException('The Library ZIP package files do not exactly match its attachment manifest.');
            }
            return $package;
        } finally {
            $zip->close();
        }
    }

    /**
     * Loads the already-previewed package without re-hashing every bundled file.
     * Each attachment is checksum-verified when its resumable batch is applied.
     */
    public function decode_bundle_manifest($zip_path) {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('The PHP Zip extension is required to inspect a Library package.');
        }
        if (!is_file($zip_path) || filesize($zip_path) <= 0 || filesize($zip_path) > self::MAX_BUNDLE_BYTES) {
            throw new RuntimeException('The Library ZIP package is missing, empty, or larger than 2 GB.');
        }
        $zip = new ZipArchive();
        if (true !== $zip->open((string) $zip_path, ZipArchive::RDONLY)) {
            throw new RuntimeException('The uploaded file is not a readable ZIP package.');
        }
        try {
            if ($zip->numFiles < 1 || $zip->numFiles > self::MAX_BUNDLE_ENTRIES) {
                throw new RuntimeException('The Library ZIP package contains an unsafe number of files.');
            }
            $package_json = $zip->getFromName(self::PACKAGE_FILENAME, 25 * MB_IN_BYTES);
            if (false === $package_json) {
                throw new RuntimeException('The Library ZIP package has no valid manifest.');
            }
            $package = $this->decode($package_json);
            if (self::BUNDLE_FORMAT !== (string) ($package['manifest']['bundle_format'] ?? '')) {
                throw new RuntimeException('This is not a complete TSOL Library ZIP package.');
            }
            foreach ((array) ($package['data']['attachments'] ?? array()) as $attachment) {
                $relative = (string) ($attachment['relative_file'] ?? '');
                $archive_path = (string) ($attachment['archive_path'] ?? '');
                $stat = $zip->statName($archive_path);
                if (!$this->safe_relative_upload_path($relative)
                    || 'attachments/' . $relative !== $archive_path
                    || false === $stat
                    || (int) ($stat['size'] ?? -1) !== (int) ($attachment['bytes'] ?? -2)
                ) {
                    throw new RuntimeException('The Library ZIP attachment manifest contains a missing or unsafe file.');
                }
            }
            return $package;
        } finally {
            $zip->close();
        }
    }

    public function decode($json) {
        $package = json_decode((string) $json, true);
        if (!is_array($package)) {
            throw new RuntimeException('The migration file is not valid JSON.');
        }
        $this->validate($package);
        return $package;
    }

    public function validate($package) {
        if (!is_array($package)
            || self::SCHEMA_VERSION !== (int) ($package['manifest']['schema_version'] ?? 0)
            || 'wordpress-library-only' !== (string) ($package['manifest']['scope'] ?? '')
            || !is_array($package['data'] ?? null)
        ) {
            throw new RuntimeException('This is not a supported TSOL WordPress Library migration package.');
        }
        if (!hash_equals((string) ($package['manifest']['data_sha256'] ?? ''), $this->data_hash($package['data']))) {
            throw new RuntimeException('The migration package checksum is invalid.');
        }
        foreach ((array) ($package['data']['posts'] ?? array()) as $record) {
            if (!$this->valid_uuid((string) ($record['uuid'] ?? ''))
                || !in_array((string) ($record['post_type'] ?? ''), $this->post_types(), true)
            ) {
                throw new RuntimeException('The migration package contains an invalid Library record identity.');
            }
            if (!in_array((string) ($record['post_status'] ?? ''), array_values(get_post_stati()), true)
                || !empty(array_diff(array_keys((array) ($record['meta'] ?? array())), $this->portable_meta_keys((string) $record['post_type'])))
                || !empty(array_diff(array_keys((array) ($record['taxonomies'] ?? array())), $this->taxonomies()))
            ) {
                throw new RuntimeException('The migration package contains fields outside the WordPress Library allowlist.');
            }
        }
        foreach ((array) ($package['data']['terms'] ?? array()) as $term) {
            if (!in_array((string) ($term['taxonomy'] ?? ''), $this->taxonomies(), true)) {
                throw new RuntimeException('The migration package contains a taxonomy outside the WordPress Library allowlist.');
            }
        }
        return true;
    }

    public function preview($package, $has_verified_bundle = false) {
        $this->validate($package);
        $report = array(
            'creates' => 0,
            'updates' => 0,
            'adoptions' => 0,
            'unchanged' => 0,
            'terms' => count((array) ($package['data']['terms'] ?? array())),
            'groups' => count((array) ($package['data']['access_groups']['groups'] ?? array())),
            'membership_assignments' => count((array) ($package['data']['access_groups']['assignments'] ?? array())),
            'attachment_files' => count((array) ($package['data']['attachments'] ?? array())),
            'attachment_bytes' => array_sum(array_map(static function ($attachment) {
                return (int) ($attachment['bytes'] ?? 0);
            }, (array) ($package['data']['attachments'] ?? array()))),
            'bundled_attachments' => array(),
            'existing_attachments' => array(),
            'missing_attachments' => array(),
            'errors' => array(),
            'warnings' => array(),
        );
        $seen = array();
        $records_by_uuid = array();
        foreach ((array) $package['data']['posts'] as $record) {
            $records_by_uuid[(string) ($record['uuid'] ?? '')] = $record;
        }
        $bundle_index = $has_verified_bundle ? $this->bundle_index($package) : array();
        $current_fingerprints = array();
        foreach ($this->export_posts() as $current_record) {
            $current_fingerprints[(string) $current_record['uuid']] = (string) $current_record['fingerprint'];
        }
        foreach ((array) $package['data']['posts'] as $record) {
            $uuid = (string) $record['uuid'];
            if (isset($seen[$uuid])) {
                $report['errors'][] = sprintf('Duplicate Library UUID %s.', $uuid);
                continue;
            }
            $seen[$uuid] = true;
            $existing = $this->find_post_by_uuid($uuid, (string) $record['post_type']);
            $slug_owner = $this->find_slug_owner((string) $record['post_name'], (string) $record['post_type']);
            if ($slug_owner instanceof WP_Post && (!$existing || (int) $slug_owner->ID !== (int) $existing->ID)) {
                if (!$existing && $this->can_adopt_slug_owner($slug_owner, $record)) {
                    $existing = $slug_owner;
                    $report['adoptions']++;
                } else {
                    $report['errors'][] = sprintf('The slug “%s” is already owned by another %s record.', $record['post_name'], $record['post_type']);
                    continue;
                }
            }
            if ($existing) {
                $report[(string) ($current_fingerprints[$uuid] ?? '') === (string) ($record['fingerprint'] ?? '') ? 'unchanged' : 'updates']++;
            } else {
                $report['creates']++;
            }
            foreach ($this->record_attachment_refs($record) as $attachment) {
                $this->assess_attachment_ref($attachment, $bundle_index, $report);
            }
            $authorization_ref = $this->package_legacy_authorization_ref($record, $records_by_uuid);
            if (!empty($authorization_ref) && !$this->resolve_external_post($authorization_ref)) {
                $report['errors'][] = sprintf('Legacy authorization source for “%s” is missing in production.', $record['post_title']);
            }
        }
        foreach ((array) ($package['data']['terms'] ?? array()) as $term) {
            $attachment = (array) ($term['meta']['hero_attachment'] ?? array());
            if (!empty($attachment)) {
                $this->assess_attachment_ref($attachment, $bundle_index, $report);
            }
        }
        $memberships = $this->membership_index();
        foreach ((array) ($package['data']['access_groups']['assignments'] ?? array()) as $slug => $groups) {
            if (!isset($memberships[sanitize_title((string) $slug)])) {
                $report['errors'][] = sprintf('MemberPress membership “%s” is missing in production.', $slug);
            }
        }
        if (!empty($report['missing_attachments'])) {
            $report['warnings'][] = sprintf(
                '%d attachment reference could not be matched by its WordPress upload path. External URLs are preserved, but those files should be checked after import.',
                count($report['missing_attachments'])
            );
        }
        $report['bundled_attachments'] = array_values($report['bundled_attachments']);
        $report['existing_attachments'] = array_values($report['existing_attachments']);
        $report['missing_attachments'] = array_values($report['missing_attachments']);
        $report['package_hash'] = (string) $package['manifest']['data_sha256'];
        return $report;
    }

    public function apply($package, $expected_hash, $bundle_path = '', $prepared_created = array(), $attachments_prepared = false) {
        $has_bundle = '' !== (string) $bundle_path;
        if ($has_bundle) {
            $verified = $attachments_prepared
                ? $this->decode_bundle_manifest($bundle_path)
                : $this->decode_bundle($bundle_path);
            if (!hash_equals((string) ($verified['manifest']['data_sha256'] ?? ''), (string) ($package['manifest']['data_sha256'] ?? ''))) {
                throw new RuntimeException('The uploaded Library ZIP changed after preview.');
            }
        }
        $report = $this->preview($package, $has_bundle);
        if (!hash_equals((string) $report['package_hash'], (string) $expected_hash)) {
            throw new RuntimeException('The migration preview changed. Upload the package again before importing.');
        }
        if (!empty($report['errors'])) {
            throw new RuntimeException('The migration has blocking conflicts and was not applied.');
        }
        if (!empty(get_option(TSOL_Library_Access_Groups::STAGE_OPTION, array()))) {
            throw new RuntimeException('Roll back or finish the current Access Groups stage before importing.');
        }

        return $this->with_lock(function () use ($package, $report, $bundle_path, $prepared_created, $attachments_prepared) {
            $before = $this->build_package();
            $raw_before = array(
                'access_groups' => get_option(TSOL_Library_Access_Groups::OPTION_NAME, null),
                'homepage' => get_option(TSOL_Library_Homepage_Curation::OPTION_NAME, null),
                'authorization' => $this->authorization_snapshot(),
            );
            $created = array(
                'posts' => array_values((array) ($prepared_created['posts'] ?? array())),
                'terms' => array_values((array) ($prepared_created['terms'] ?? array())),
                'attachments' => array_values((array) ($prepared_created['attachments'] ?? array())),
            );
            try {
                if ('' !== (string) $bundle_path && !$attachments_prepared) {
                    $this->import_bundle_attachments($package, $bundle_path, $created);
                }
                $this->apply_data($package, (string) $report['package_hash'], $created);
            } catch (Throwable $exception) {
                $recovery_created = array('posts' => array(), 'terms' => array(), 'attachments' => array());
                $this->apply_data($before, 'automatic-recovery', $recovery_created);
                foreach (array_reverse($created['posts']) as $post_id) {
                    wp_delete_post((int) $post_id, true);
                }
                foreach (array_reverse($created['terms']) as $term) {
                    wp_delete_term((int) $term['term_id'], (string) $term['taxonomy']);
                }
                $this->remove_created_attachments($created['attachments']);
                $this->restore_raw_options($raw_before);
                throw $exception;
            }
            update_option(self::ROLLBACK_OPTION, array(
                'schema_version' => self::SCHEMA_VERSION,
                'package' => base64_encode(gzencode($this->encode($before), 6)),
                'raw_options' => $raw_before,
                'created' => $created,
                'import_hash' => (string) $report['package_hash'],
                'created_at' => gmdate('c'),
            ), false);
            return array_merge($report, array('created_ids' => $created, 'applied_at' => gmdate('c')));
        });
    }

    public function rollback($confirmation) {
        if (self::ROLLBACK_CONFIRMATION !== (string) $confirmation) {
            throw new RuntimeException('Enter the exact Library migration rollback confirmation.');
        }
        if (!empty(get_option(TSOL_Library_Access_Groups::STAGE_OPTION, array()))) {
            throw new RuntimeException('Roll back the Access Groups stage before rolling back the Library migration.');
        }
        return $this->with_lock(function () {
            $state = get_option(self::ROLLBACK_OPTION, array());
            $encoded = (string) ($state['package'] ?? '');
            $json = $encoded === '' ? false : gzdecode((string) base64_decode($encoded, true));
            if (false === $json) {
                throw new RuntimeException('No valid Library migration rollback snapshot is available.');
            }
            $package = $this->decode($json);
            $rollback_created = array('posts' => array(), 'terms' => array(), 'attachments' => array());
            $this->apply_data($package, 'rollback', $rollback_created);
            foreach (array_reverse((array) ($state['created']['posts'] ?? array())) as $post_id) {
                if ((string) get_post_meta((int) $post_id, self::OWNER_META, true) === (string) ($state['import_hash'] ?? '')) {
                    wp_delete_post((int) $post_id, true);
                }
            }
            foreach (array_reverse((array) ($state['created']['terms'] ?? array())) as $term) {
                wp_delete_term((int) ($term['term_id'] ?? 0), (string) ($term['taxonomy'] ?? ''));
            }
            $this->remove_created_attachments((array) ($state['created']['attachments'] ?? array()));
            $this->restore_raw_options((array) ($state['raw_options'] ?? array()));
            delete_option(self::ROLLBACK_OPTION);
            return array('phase' => 'rolled_back', 'rolled_back_at' => gmdate('c'));
        });
    }

    public function rollback_state() {
        $state = get_option(self::ROLLBACK_OPTION, array());
        return is_array($state) ? $state : array();
    }

    private function apply_data($package, $owner_hash, &$created) {
        $this->validate($package);
        $term_ids = $this->upsert_terms((array) $package['data']['terms'], $created);
        $post_ids = array();
        $records_by_uuid = array();
        foreach ((array) $package['data']['posts'] as $record) {
            $records_by_uuid[(string) $record['uuid']] = $record;
            $existing = $this->find_post_by_uuid((string) $record['uuid'], (string) $record['post_type']);
            if (!$existing) {
                $candidate = $this->find_slug_owner((string) $record['post_name'], (string) $record['post_type']);
                if ($candidate instanceof WP_Post && $this->can_adopt_slug_owner($candidate, $record)) {
                    $existing = $candidate;
                }
            }
            $payload = array(
                'post_type' => (string) $record['post_type'],
                'post_status' => (string) $record['post_status'],
                'post_name' => (string) $record['post_name'],
                'post_title' => (string) $record['post_title'],
                'post_content' => (string) $record['post_content'],
                'post_excerpt' => (string) $record['post_excerpt'],
                'menu_order' => (int) $record['menu_order'],
                'post_parent' => 0,
            );
            if ($existing) {
                $payload['ID'] = (int) $existing->ID;
                $post_id = wp_update_post(wp_slash($payload), true);
            } else {
                $post_id = wp_insert_post(wp_slash($payload), true);
                if (!is_wp_error($post_id)) {
                    $created['posts'][] = (int) $post_id;
                }
            }
            if (is_wp_error($post_id) || (int) $post_id <= 0) {
                throw new RuntimeException(sprintf('Could not import Library record “%s”.', $record['post_title']));
            }
            $post_ids[(string) $record['uuid']] = (int) $post_id;
            update_post_meta((int) $post_id, $this->uuid_key((string) $record['post_type']), (string) $record['uuid']);
            if (in_array((int) $post_id, $created['posts'], true)) {
                update_post_meta((int) $post_id, self::OWNER_META, $owner_hash);
            }
        }

        $transition = array();
        foreach ((array) $package['data']['posts'] as $record) {
            $post_id = (int) $post_ids[(string) $record['uuid']];
            if (!empty($record['parent_uuid']) && isset($post_ids[$record['parent_uuid']])) {
                wp_update_post(array('ID' => $post_id, 'post_parent' => (int) $post_ids[$record['parent_uuid']]));
            }
            $this->import_post_meta($post_id, $record, $post_ids);
            foreach ((array) ($record['taxonomies'] ?? array()) as $taxonomy => $slugs) {
                $ids = array();
                foreach ((array) $slugs as $slug) {
                    if (isset($term_ids[$taxonomy][$slug])) {
                        $ids[] = (int) $term_ids[$taxonomy][$slug];
                    }
                }
                wp_set_object_terms($post_id, $ids, (string) $taxonomy, false);
            }
            $attachment_id = $this->resolve_attachment((array) ($record['featured_attachment'] ?? array()));
            if ($attachment_id) {
                set_post_thumbnail($post_id, $attachment_id);
            } else {
                delete_post_thumbnail($post_id);
            }
            $authorization_ref = $this->package_legacy_authorization_ref($record, $records_by_uuid);
            if (!empty($authorization_ref)) {
                $authorization_id = $this->resolve_external_post($authorization_ref);
                if (!$authorization_id) {
                    throw new RuntimeException(sprintf('Legacy authorization source for “%s” disappeared during import.', $record['post_title']));
                }
                update_post_meta($post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, $authorization_id);
                $transition[$post_id] = $authorization_id;
            }
        }

        $this->import_term_meta((array) $package['data']['terms'], $term_ids, $post_ids);

        $this->import_homepage((array) ($package['data']['homepage'] ?? array()), $post_ids);
        $access = (array) ($package['data']['access_groups'] ?? array());
        if (!empty($access['groups'])) {
            (new TSOL_Library_Access_Groups())->import_portable_configuration(
                (array) $access['groups'],
                (array) $access['assignments'],
                (array) $access['exceptions'],
                $transition
            );
        } else {
            delete_option(TSOL_Library_Access_Groups::OPTION_NAME);
        }
        TSOL_Library_Homepage_Curation::reset_cache();
        return $created;
    }

    private function export_posts() {
        $records = array();
        $posts = get_posts(array(
            'post_type' => $this->post_types(),
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'orderby' => array('post_type' => 'ASC', 'ID' => 'ASC'),
            'suppress_filters' => true,
        ));
        foreach ($posts as $post) {
            $uuid = (string) get_post_meta($post->ID, $this->uuid_key($post->post_type), true);
            if (!$this->valid_uuid($uuid)) {
                throw new RuntimeException(sprintf('Library record #%d is missing its immutable UUID.', $post->ID));
            }
            $parent_uuid = $post->post_parent ? $this->post_uuid((int) $post->post_parent) : '';
            $record = array(
                'uuid' => $uuid,
                'post_type' => (string) $post->post_type,
                'post_status' => (string) $post->post_status,
                'post_name' => (string) $post->post_name,
                'post_title' => (string) $post->post_title,
                'post_content' => (string) $post->post_content,
                'post_excerpt' => (string) $post->post_excerpt,
                'menu_order' => (int) $post->menu_order,
                'parent_uuid' => $parent_uuid,
                'meta' => $this->export_post_meta((int) $post->ID, (string) $post->post_type),
                'taxonomies' => $this->export_post_terms((int) $post->ID),
                'speaker_uuids' => array_values(array_filter(array_map(array($this, 'post_uuid'), array_map('intval', get_post_meta($post->ID, TSOL_Library_Content_Model::META_SPEAKER_IDS, false))))),
                'featured_attachment' => $this->attachment_ref((int) get_post_thumbnail_id($post->ID)),
                'legacy_authorization' => $this->legacy_authorization_ref((int) $post->ID),
            );
            $record['fingerprint'] = $this->record_fingerprint($record);
            $records[] = $record;
        }
        return $records;
    }

    private function export_post_meta($post_id, $post_type) {
        $excluded = array(
            $this->uuid_key($post_type),
            TSOL_Library_Content_Model::META_COURSE_ID,
            TSOL_Library_Content_Model::META_SERIES_ID,
            TSOL_Library_Content_Model::META_SPEAKER_IDS,
            TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID,
            TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID,
        );
        $keys = $this->portable_meta_keys($post_type);
        $meta = array();
        foreach (array_diff($keys, $excluded) as $key) {
            if (metadata_exists('post', $post_id, $key)) {
                $value = get_post_meta($post_id, $key, true);
                $meta[$key] = $this->portable_attachment_values($value);
            }
        }
        $course_id = (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_COURSE_ID, true);
        $series_id = (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_SERIES_ID, true);
        if ($course_id) {
            $meta[TSOL_Library_Content_Model::META_COURSE_ID] = array('__post_uuid' => $this->post_uuid($course_id));
        }
        if ($series_id) {
            $meta[TSOL_Library_Content_Model::META_SERIES_ID] = array('__post_uuid' => $this->post_uuid($series_id));
        }
        ksort($meta, SORT_STRING);
        return $meta;
    }

    private function import_post_meta($post_id, $record, $post_ids) {
        foreach ($this->portable_meta_keys((string) $record['post_type']) as $key) {
            if (!array_key_exists($key, (array) ($record['meta'] ?? array()))) {
                delete_post_meta($post_id, $key);
            }
        }
        foreach ((array) ($record['meta'] ?? array()) as $key => $value) {
            if (is_array($value) && isset($value['__post_uuid'])) {
                $value = (int) ($post_ids[(string) $value['__post_uuid']] ?? 0);
            } else {
                $value = $this->restore_attachment_values($value);
            }
            update_post_meta($post_id, (string) $key, $value);
        }
        delete_post_meta($post_id, TSOL_Library_Content_Model::META_SPEAKER_IDS);
        foreach ((array) ($record['speaker_uuids'] ?? array()) as $speaker_uuid) {
            if (isset($post_ids[$speaker_uuid])) {
                add_post_meta($post_id, TSOL_Library_Content_Model::META_SPEAKER_IDS, (int) $post_ids[$speaker_uuid], false);
            }
        }
    }

    private function export_terms() {
        $records = array();
        foreach ($this->taxonomies() as $taxonomy) {
            $terms = get_terms(array('taxonomy' => $taxonomy, 'hide_empty' => false));
            if (is_wp_error($terms)) {
                throw new RuntimeException($terms->get_error_message());
            }
            foreach ($terms as $term) {
                $parent = $term->parent ? get_term((int) $term->parent, $taxonomy) : null;
                $record = array(
                    'taxonomy' => $taxonomy,
                    'slug' => (string) $term->slug,
                    'name' => (string) $term->name,
                    'description' => (string) $term->description,
                    'parent_slug' => $parent instanceof WP_Term ? (string) $parent->slug : '',
                    'meta' => array(),
                );
                if (TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY === $taxonomy) {
                    $record['meta'][TSOL_Library_Content_Model::COLLECTION_META_OVERVIEW] = (string) get_term_meta($term->term_id, TSOL_Library_Content_Model::COLLECTION_META_OVERVIEW, true);
                    $record['meta']['hero_attachment'] = $this->attachment_ref((int) get_term_meta($term->term_id, TSOL_Library_Content_Model::COLLECTION_META_HERO_IMAGE_ID, true));
                    $record['meta']['featured_course_uuid'] = $this->post_uuid((int) get_term_meta($term->term_id, TSOL_Library_Content_Model::COLLECTION_META_FEATURED_COURSE_ID, true));
                }
                $records[] = $record;
            }
        }
        return $records;
    }

    private function upsert_terms($records, &$created) {
        $ids = array();
        foreach ($records as $record) {
            $taxonomy = (string) $record['taxonomy'];
            $slug = sanitize_title((string) $record['slug']);
            $existing = get_term_by('slug', $slug, $taxonomy);
            if ($existing instanceof WP_Term) {
                $term_id = (int) $existing->term_id;
                wp_update_term($term_id, $taxonomy, array('name' => (string) $record['name'], 'description' => (string) $record['description']));
            } else {
                $result = wp_insert_term((string) $record['name'], $taxonomy, array('slug' => $slug, 'description' => (string) $record['description']));
                if (is_wp_error($result)) {
                    throw new RuntimeException($result->get_error_message());
                }
                $term_id = (int) $result['term_id'];
                $created['terms'][] = array('term_id' => $term_id, 'taxonomy' => $taxonomy);
            }
            $ids[$taxonomy][$slug] = $term_id;
        }
        foreach ($records as $record) {
            $taxonomy = (string) $record['taxonomy'];
            $slug = sanitize_title((string) $record['slug']);
            $term_id = (int) $ids[$taxonomy][$slug];
            $parent_id = !empty($record['parent_slug']) ? (int) ($ids[$taxonomy][$record['parent_slug']] ?? 0) : 0;
            wp_update_term($term_id, $taxonomy, array('parent' => $parent_id));
            if (TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY === $taxonomy) {
                update_term_meta($term_id, TSOL_Library_Content_Model::COLLECTION_META_OVERVIEW, (string) ($record['meta'][TSOL_Library_Content_Model::COLLECTION_META_OVERVIEW] ?? ''));
                $hero_id = $this->resolve_attachment((array) ($record['meta']['hero_attachment'] ?? array()));
                update_term_meta($term_id, TSOL_Library_Content_Model::COLLECTION_META_HERO_IMAGE_ID, $hero_id);
            }
        }
        return $ids;
    }

    private function import_term_meta($records, $term_ids, $post_ids) {
        foreach ($records as $record) {
            if (TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY !== (string) $record['taxonomy']) {
                continue;
            }
            $term_id = (int) ($term_ids[$record['taxonomy']][$record['slug']] ?? 0);
            if (!$term_id) {
                continue;
            }
            $featured_uuid = (string) ($record['meta']['featured_course_uuid'] ?? '');
            update_term_meta(
                $term_id,
                TSOL_Library_Content_Model::COLLECTION_META_FEATURED_COURSE_ID,
                (int) ($post_ids[$featured_uuid] ?? 0)
            );
        }
    }

    private function export_homepage() {
        $layout = TSOL_Library_Homepage_Curation::layout();
        $rails = array();
        foreach ((array) ($layout['rails'] ?? array()) as $rail => $post_ids) {
            $rails[$rail] = array_values(array_filter(array_map(array($this, 'post_uuid'), array_map('intval', (array) $post_ids))));
        }
        return array('version' => 1, 'rails' => $rails);
    }

    private function import_homepage($portable, $post_ids) {
        $rails = array();
        foreach (array_keys(TSOL_Library_Homepage_Curation::rails()) as $rail) {
            $rails[$rail] = array();
            foreach ((array) ($portable['rails'][$rail] ?? array()) as $uuid) {
                if (isset($post_ids[$uuid])) {
                    $rails[$rail][] = (int) $post_ids[$uuid];
                }
            }
        }
        update_option(TSOL_Library_Homepage_Curation::OPTION_NAME, array('version' => 1, 'rails' => $rails, 'updated_at' => gmdate('Y-m-d H:i:s')), false);
    }

    private function export_access_groups() {
        $configuration = get_option(TSOL_Library_Access_Groups::OPTION_NAME, array());
        if (!is_array($configuration) || empty($configuration['groups'])) {
            return array('groups' => array(), 'assignments' => array(), 'exceptions' => array());
        }
        $assignments = array();
        foreach ((array) $configuration['assignments'] as $membership_id => $group_ids) {
            $membership = get_post((int) $membership_id);
            if (!$membership instanceof WP_Post || 'memberpressproduct' !== $membership->post_type || '' === $membership->post_name) {
                throw new RuntimeException(sprintf('Access Groups references missing MemberPress membership #%d.', $membership_id));
            }
            $assignments[(string) $membership->post_name] = array_values(array_map('strval', (array) $group_ids));
        }
        ksort($assignments, SORT_STRING);
        $exceptions = array();
        foreach ((array) ($configuration['exceptions'] ?? array()) as $scope_key => $conditions) {
            foreach ((array) $conditions as $condition) {
                if ('membership' === (string) ($condition['access_type'] ?? '')) {
                    $membership = get_post(absint($condition['access_condition'] ?? 0));
                    if (!$membership instanceof WP_Post || 'memberpressproduct' !== $membership->post_type || '' === $membership->post_name) {
                        throw new RuntimeException('An Access Group exception references a missing MemberPress membership.');
                    }
                    $condition['access_type'] = 'membership_slug';
                    $condition['access_condition'] = (string) $membership->post_name;
                }
                $exceptions[(string) $scope_key][] = $condition;
            }
        }
        ksort($exceptions, SORT_STRING);
        return array(
            'groups' => array_values((array) $configuration['groups']),
            'assignments' => $assignments,
            'exceptions' => $exceptions,
        );
    }

    private function export_post_terms($post_id) {
        $result = array();
        foreach ($this->taxonomies() as $taxonomy) {
            $slugs = wp_get_object_terms($post_id, $taxonomy, array('fields' => 'slugs'));
            $result[$taxonomy] = is_wp_error($slugs) ? array() : array_values($slugs);
        }
        return $result;
    }

    private function legacy_authorization_ref($post_id, $visited = array()) {
        $post_id = absint($post_id);
        if (!$post_id || isset($visited[$post_id])) {
            return array();
        }
        $visited[$post_id] = true;
        $authorization_id = (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
        if ($authorization_id === $post_id || !$authorization_id) {
            $authorization_id = (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID, true);
        }
        if ($authorization_id > 0 && in_array((string) get_post_type($authorization_id), $this->post_types(), true)) {
            return $this->legacy_authorization_ref($authorization_id, $visited);
        }
        return $this->external_post_ref($authorization_id);
    }

    /**
     * Releases before 0.4.4 did not copy a child record's portable authority
     * through its Course or Series. Recover that reference from the stable
     * parent UUID so an already-exported ZIP can be reapplied safely.
     */
    private function package_legacy_authorization_ref($record, $records_by_uuid, $visited = array()) {
        $uuid = (string) ($record['uuid'] ?? '');
        if ('' !== $uuid) {
            if (isset($visited[$uuid])) {
                return array();
            }
            $visited[$uuid] = true;
        }
        $direct = (array) ($record['legacy_authorization'] ?? array());
        if (!empty($direct)) {
            return $direct;
        }
        $meta = (array) ($record['meta'] ?? array());
        foreach (array(TSOL_Library_Content_Model::META_COURSE_ID, TSOL_Library_Content_Model::META_SERIES_ID) as $key) {
            $relation = (array) ($meta[$key] ?? array());
            $parent_uuid = (string) ($relation['__post_uuid'] ?? '');
            if ('' !== $parent_uuid && isset($records_by_uuid[$parent_uuid])) {
                return $this->package_legacy_authorization_ref($records_by_uuid[$parent_uuid], $records_by_uuid, $visited);
            }
        }
        return array();
    }

    private function external_post_ref($post_id) {
        $post = get_post((int) $post_id);
        if (!$post instanceof WP_Post || in_array($post->post_type, $this->post_types(), true)) {
            return array();
        }
        return array(
            'post_type' => (string) $post->post_type,
            'slug' => (string) $post->post_name,
            'path' => (string) get_page_uri($post),
            'title' => (string) $post->post_title,
        );
    }

    private function resolve_external_post($ref) {
        if (!is_array($ref) || empty($ref['post_type']) || empty($ref['slug'])) {
            return 0;
        }
        if (!empty($ref['path'])) {
            $post = get_page_by_path((string) $ref['path'], OBJECT, (string) $ref['post_type']);
            if ($post instanceof WP_Post) {
                return (int) $post->ID;
            }
        }
        $posts = get_posts(array(
            'post_type' => (string) $ref['post_type'],
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => 2,
            'name' => sanitize_title((string) $ref['slug']),
            'suppress_filters' => true,
        ));
        return 1 === count($posts) ? (int) $posts[0]->ID : 0;
    }

    private function attachment_ref($attachment_id) {
        $attachment_id = absint($attachment_id);
        if (!$attachment_id || 'attachment' !== get_post_type($attachment_id)) {
            return array();
        }
        return array(
            'relative_file' => (string) get_post_meta($attachment_id, '_wp_attached_file', true),
            'source_url' => (string) wp_get_attachment_url($attachment_id),
            'mime_type' => (string) get_post_mime_type($attachment_id),
        );
    }

    private function resolve_attachment($ref) {
        global $wpdb;
        if (!is_array($ref) || empty($ref)) {
            return 0;
        }
        $relative = sanitize_text_field((string) ($ref['relative_file'] ?? ''));
        if ('' !== $relative) {
            $id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s ORDER BY post_id ASC LIMIT 1",
                $relative
            ));
            if ($id > 0 && 'attachment' === get_post_type($id)) {
                return $id;
            }
        }
        $url = esc_url_raw((string) ($ref['source_url'] ?? ''));
        return $url === '' ? 0 : absint(attachment_url_to_postid($url));
    }

    private function bundle_index($package) {
        $index = array();
        foreach ((array) ($package['data']['attachments'] ?? array()) as $attachment) {
            $relative = (string) ($attachment['relative_file'] ?? '');
            if ($this->safe_relative_upload_path($relative)) {
                $index[$relative] = $attachment;
            }
        }
        return $index;
    }

    private function assess_attachment_ref($ref, $bundle_index, &$report) {
        $relative = (string) ($ref['relative_file'] ?? '');
        $key = '' !== $relative ? $relative : (string) ($ref['source_url'] ?? 'unknown');
        $attachment_id = $this->resolve_attachment($ref);
        $uploads = wp_upload_dir(null, false);
        $local_file = $this->safe_relative_upload_path($relative)
            ? trailingslashit((string) $uploads['basedir']) . $relative
            : '';
        if ($attachment_id > 0 && is_file($local_file)) {
            if (isset($bundle_index[$relative])
                && !hash_equals((string) $bundle_index[$relative]['sha256'], (string) hash_file('sha256', $local_file))
            ) {
                $report['errors'][] = sprintf('Production upload “%s” differs from the bundled test-site file.', $relative);
                return;
            }
            $report['existing_attachments'][$key] = $key;
            return;
        }
        if (isset($bundle_index[$relative])) {
            if (is_file($local_file)
                && !hash_equals((string) $bundle_index[$relative]['sha256'], (string) hash_file('sha256', $local_file))
            ) {
                $report['errors'][] = sprintf('Production file “%s” exists but differs from the bundled test-site file.', $relative);
                return;
            }
            $report['bundled_attachments'][$key] = $key;
            return;
        }
        $report['missing_attachments'][$key] = $key;
    }

    private function import_bundle_attachments($package, $zip_path, &$created) {
        $this->import_bundle_attachment_range($package, $zip_path, 0, PHP_INT_MAX, $created, true);
    }

    public function prepare_attachment_batch($package, $zip_path, $start, $limit, &$created) {
        $this->validate($package);
        $attachments = array_values((array) ($package['data']['attachments'] ?? array()));
        $start = max(0, (int) $start);
        $limit = max(1, min(5, (int) $limit));
        if ($start > count($attachments)) {
            throw new RuntimeException('The resumable attachment cursor is invalid.');
        }
        return $this->with_lock(function () use ($package, $zip_path, $start, $limit, &$created, $attachments) {
            $this->import_bundle_attachment_range($package, $zip_path, $start, $limit, $created, false);
            return array(
                'next' => min(count($attachments), $start + $limit),
                'total' => count($attachments),
            );
        });
    }

    private function import_bundle_attachment_range($package, $zip_path, $start, $limit, &$created, $generate_metadata) {
        $zip = new ZipArchive();
        if (true !== $zip->open((string) $zip_path, ZipArchive::RDONLY)) {
            throw new RuntimeException('The verified Library ZIP could not be reopened for import.');
        }
        $uploads = wp_upload_dir(null, false);
        $base = trailingslashit((string) $uploads['basedir']);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        try {
            $attachments = array_slice(array_values((array) ($package['data']['attachments'] ?? array())), (int) $start, (int) $limit);
            foreach ($attachments as $attachment) {
                $relative = (string) $attachment['relative_file'];
                $destination = $base . $relative;
                $ref = array('relative_file' => $relative, 'source_url' => (string) ($attachment['source_url'] ?? ''));
                $attachment_id = $this->resolve_attachment($ref);
                $file_existed = is_file($destination);
                if (!$file_existed) {
                    if (!wp_mkdir_p(dirname($destination))) {
                        throw new RuntimeException(sprintf('WordPress could not create the upload directory for “%s”.', $relative));
                    }
                    $stream = $zip->getStream((string) $attachment['archive_path']);
                    $output = fopen($destination, 'xb');
                    if (!is_resource($stream) || !is_resource($output)) {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                        if (is_resource($output)) {
                            fclose($output);
                        }
                        throw new RuntimeException(sprintf('WordPress could not extract bundled upload “%s”.', $relative));
                    }
                    stream_copy_to_stream($stream, $output);
                    fclose($stream);
                    fclose($output);
                    $created['attachments'][] = array(
                        'action' => 'file_only',
                        'post_id' => 0,
                        'relative_file' => $relative,
                        'generated_files' => array(),
                    );
                }
                if (!hash_equals((string) $attachment['sha256'], (string) hash_file('sha256', $destination))) {
                    throw new RuntimeException(sprintf('Extracted upload “%s” failed its checksum.', $relative));
                }
                if ($attachment_id > 0) {
                    if (!$file_existed) {
                        $old_metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
                        $metadata = $generate_metadata ? wp_generate_attachment_metadata($attachment_id, $destination) : array();
                        if (!empty($metadata)) {
                            wp_update_attachment_metadata($attachment_id, $metadata);
                        }
                        array_pop($created['attachments']);
                        $created['attachments'][] = array(
                            'action' => 'restore_existing',
                            'post_id' => $attachment_id,
                            'relative_file' => $relative,
                            'old_metadata' => $old_metadata,
                            'generated_files' => $this->metadata_files($relative, $metadata),
                        );
                    }
                    continue;
                }
                $filetype = wp_check_filetype(basename($destination), null);
                $mime_type = (string) ($filetype['type'] ?: ($attachment['mime_type'] ?? 'application/octet-stream'));
                $attachment_id = wp_insert_attachment(array(
                    'post_mime_type' => $mime_type,
                    'post_title' => sanitize_text_field(pathinfo(basename($destination), PATHINFO_FILENAME)),
                    'post_status' => 'inherit',
                ), $destination, 0, true);
                if (is_wp_error($attachment_id) || (int) $attachment_id <= 0) {
                    throw new RuntimeException(sprintf('WordPress could not register bundled upload “%s”.', $relative));
                }
                $metadata = $generate_metadata ? wp_generate_attachment_metadata((int) $attachment_id, $destination) : array();
                if (!empty($metadata)) {
                    wp_update_attachment_metadata((int) $attachment_id, $metadata);
                }
                if (!$file_existed) {
                    array_pop($created['attachments']);
                }
                $created['attachments'][] = array(
                    'action' => 'created_attachment',
                    'post_id' => (int) $attachment_id,
                    'relative_file' => $relative,
                    'remove_original' => !$file_existed,
                    'generated_files' => $this->metadata_files($relative, $metadata),
                );
            }
        } finally {
            $zip->close();
        }
    }

    private function metadata_files($relative, $metadata) {
        $files = array();
        $directory = dirname($relative);
        foreach ((array) ($metadata['sizes'] ?? array()) as $size) {
            $file = sanitize_file_name((string) ($size['file'] ?? ''));
            if ('' !== $file) {
                $files[] = ('.' === $directory ? '' : trailingslashit($directory)) . $file;
            }
        }
        return array_values(array_unique($files));
    }

    private function remove_created_attachments($attachments) {
        $uploads = wp_upload_dir(null, false);
        $base = trailingslashit((string) $uploads['basedir']);
        foreach (array_reverse((array) $attachments) as $attachment) {
            foreach ((array) ($attachment['generated_files'] ?? array()) as $relative) {
                if ($this->safe_relative_upload_path($relative) && is_file($base . $relative)) {
                    unlink($base . $relative);
                }
            }
            $action = (string) ($attachment['action'] ?? '');
            $post_id = (int) ($attachment['post_id'] ?? 0);
            $relative = (string) ($attachment['relative_file'] ?? '');
            if ('created_attachment' === $action && $post_id > 0) {
                if (!empty($attachment['remove_original'])) {
                    wp_delete_attachment($post_id, true);
                } else {
                    wp_delete_post($post_id, true);
                }
            } elseif ('restore_existing' === $action && $post_id > 0) {
                if (empty($attachment['old_metadata'])) {
                    delete_post_meta($post_id, '_wp_attachment_metadata');
                } else {
                    wp_update_attachment_metadata($post_id, $attachment['old_metadata']);
                }
                if ($this->safe_relative_upload_path($relative) && is_file($base . $relative)) {
                    unlink($base . $relative);
                }
            } elseif ('file_only' === $action && $this->safe_relative_upload_path($relative) && is_file($base . $relative)) {
                unlink($base . $relative);
            }
        }
    }

    private function portable_attachment_values($value) {
        if (!is_array($value)) {
            return $value;
        }
        $portable = array();
        foreach ($value as $key => $child) {
            if ('attachment_id' === (string) $key && absint($child) > 0) {
                $portable['attachment_ref'] = $this->attachment_ref(absint($child));
                $portable[$key] = 0;
            } else {
                $portable[$key] = $this->portable_attachment_values($child);
            }
        }
        return $portable;
    }

    private function restore_attachment_values($value) {
        if (!is_array($value)) {
            return $value;
        }
        $restored = array();
        foreach ($value as $key => $child) {
            if ('attachment_ref' === (string) $key) {
                continue;
            }
            $restored[$key] = $this->restore_attachment_values($child);
        }
        if (isset($value['attachment_ref'])) {
            $restored['attachment_id'] = $this->resolve_attachment((array) $value['attachment_ref']);
            if ('wordpress' === (string) ($restored['provider'] ?? '')) {
                $restored['provider_id'] = (string) $restored['attachment_id'];
            }
        }
        return $restored;
    }

    private function record_attachment_refs($record) {
        $refs = array();
        if (!empty($record['featured_attachment'])) {
            $refs[] = $record['featured_attachment'];
        }
        $walk = function ($value) use (&$walk, &$refs) {
            if (!is_array($value)) {
                return;
            }
            if (isset($value['attachment_ref']) && is_array($value['attachment_ref'])) {
                $refs[] = $value['attachment_ref'];
            }
            foreach ($value as $child) {
                $walk($child);
            }
        };
        $walk((array) ($record['meta'] ?? array()));
        return $refs;
    }

    private function bundle_attachment_inventory($package) {
        $uploads = wp_upload_dir(null, false);
        if (!empty($uploads['error'])) {
            throw new RuntimeException((string) $uploads['error']);
        }
        $refs = array();
        foreach ((array) ($package['data']['posts'] ?? array()) as $record) {
            foreach ($this->record_attachment_refs($record) as $ref) {
                $relative = (string) ($ref['relative_file'] ?? '');
                if (!$this->safe_relative_upload_path($relative)) {
                    throw new RuntimeException('A referenced WordPress attachment has no safe upload path and cannot be bundled.');
                }
                $refs[$relative] = $ref;
            }
        }
        foreach ((array) ($package['data']['terms'] ?? array()) as $term) {
            $ref = (array) ($term['meta']['hero_attachment'] ?? array());
            if (empty($ref)) {
                continue;
            }
            $relative = (string) ($ref['relative_file'] ?? '');
            if (!$this->safe_relative_upload_path($relative)) {
                throw new RuntimeException('A referenced WordPress term image has no safe upload path and cannot be bundled.');
            }
            $refs[$relative] = $ref;
        }
        ksort($refs, SORT_STRING);
        $inventory = array();
        $base = trailingslashit((string) $uploads['basedir']);
        foreach ($refs as $relative => $ref) {
            $source = $base . $relative;
            if (!is_file($source) || !is_readable($source)) {
                throw new RuntimeException(sprintf('Referenced WordPress upload “%s” is missing from the test site.', $relative));
            }
            $inventory[] = array(
                'relative_file' => $relative,
                'archive_path' => 'attachments/' . $relative,
                'bytes' => (int) filesize($source),
                'sha256' => (string) hash_file('sha256', $source),
                'mime_type' => sanitize_mime_type((string) ($ref['mime_type'] ?? '')),
                'source_url' => esc_url_raw((string) ($ref['source_url'] ?? '')),
            );
        }
        return $inventory;
    }

    private function package_reference_paths($package) {
        $paths = array();
        foreach ((array) ($package['data']['posts'] ?? array()) as $record) {
            foreach ($this->record_attachment_refs($record) as $ref) {
                $relative = (string) ($ref['relative_file'] ?? '');
                if ($this->safe_relative_upload_path($relative)) {
                    $paths[$relative] = true;
                }
            }
        }
        foreach ((array) ($package['data']['terms'] ?? array()) as $term) {
            $relative = (string) ($term['meta']['hero_attachment']['relative_file'] ?? '');
            if ($this->safe_relative_upload_path($relative)) {
                $paths[$relative] = true;
            }
        }
        ksort($paths, SORT_STRING);
        return $paths;
    }

    private function safe_relative_upload_path($path) {
        $path = (string) $path;
        return '' !== $path
            && strlen($path) <= 500
            && '/' !== $path[0]
            && false === strpos($path, "\0")
            && false === strpos($path, '\\')
            && !preg_match('#(^|/)\.\.(/|$)#', $path)
            && !preg_match('#(^|/)(?:\.|)(/|$)#', $path);
    }

    private function safe_archive_path($path) {
        $path = (string) $path;
        return self::PACKAGE_FILENAME === $path
            || (0 === strpos($path, 'attachments/') && $this->safe_relative_upload_path(substr($path, strlen('attachments/'))));
    }

    private function zip_entry_hash($zip, $archive_path) {
        $stream = $zip->getStream($archive_path);
        if (!is_resource($stream)) {
            throw new RuntimeException(sprintf('Bundled file “%s” could not be read.', $archive_path));
        }
        $hash = hash_init('sha256');
        while (!feof($stream)) {
            $chunk = fread($stream, 1024 * 1024);
            if (false === $chunk) {
                fclose($stream);
                throw new RuntimeException(sprintf('Bundled file “%s” could not be verified.', $archive_path));
            }
            hash_update($hash, $chunk);
        }
        fclose($stream);
        return hash_final($hash);
    }

    private function post_uuid($post_id) {
        $post = get_post((int) $post_id);
        return $post instanceof WP_Post ? (string) get_post_meta($post->ID, $this->uuid_key($post->post_type), true) : '';
    }

    private function uuid_key($post_type) {
        return TSOL_Library_Content_Model::SPEAKER_POST_TYPE === $post_type
            ? TSOL_Library_Content_Model::SPEAKER_META_UUID
            : TSOL_Library_Content_Model::META_UUID;
    }

    private function find_post_by_uuid($uuid, $post_type) {
        $posts = get_posts(array(
            'post_type' => $post_type,
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => 2,
            'meta_key' => $this->uuid_key($post_type),
            'meta_value' => $uuid,
            'suppress_filters' => true,
        ));
        if (count($posts) > 1) {
            throw new RuntimeException(sprintf('Library UUID %s exists more than once in this environment.', $uuid));
        }
        return empty($posts) ? null : $posts[0];
    }

    private function record_fingerprint($record) {
        unset($record['fingerprint']);
        return hash('sha256', serialize($record));
    }

    private function membership_index() {
        $memberships = array();
        foreach (get_posts(array('post_type' => 'memberpressproduct', 'post_status' => array_values(get_post_stati(array('internal' => false))), 'posts_per_page' => -1)) as $post) {
            $memberships[(string) $post->post_name] = (int) $post->ID;
        }
        return $memberships;
    }

    private function find_slug_owner($slug, $post_type) {
        $posts = get_posts(array(
            'post_type' => $post_type,
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => 1,
            'name' => sanitize_title($slug),
            'suppress_filters' => true,
        ));
        return empty($posts) ? null : $posts[0];
    }

    /**
     * Older Library imports generated UUIDs independently in each environment.
     * Permit a one-time identity adoption only when the stable WordPress slug
     * and either the title or the legacy authorization source also agree.
     */
    private function can_adopt_slug_owner($candidate, $record) {
        if (!$candidate instanceof WP_Post
            || (string) $candidate->post_type !== (string) ($record['post_type'] ?? '')
            || (string) $candidate->post_name !== (string) ($record['post_name'] ?? '')
        ) {
            return false;
        }
        if (trim((string) $candidate->post_title) === trim((string) ($record['post_title'] ?? ''))) {
            return true;
        }
        $incoming = (array) ($record['legacy_authorization'] ?? array());
        $current = $this->legacy_authorization_ref((int) $candidate->ID);
        return !empty($incoming['post_type'])
            && (string) $incoming['post_type'] === (string) ($current['post_type'] ?? '')
            && (string) ($incoming['path'] ?? '') === (string) ($current['path'] ?? '');
    }

    private function restore_raw_options($options) {
        foreach (array('access_groups' => TSOL_Library_Access_Groups::OPTION_NAME, 'homepage' => TSOL_Library_Homepage_Curation::OPTION_NAME) as $key => $option) {
            if (!array_key_exists($key, $options) || null === $options[$key]) {
                delete_option($option);
            } else {
                update_option($option, $options[$key], false);
            }
        }
        foreach ((array) ($options['authorization'] ?? array()) as $post_id => $value) {
            if (!get_post((int) $post_id)) {
                continue;
            }
            if (null === $value) {
                delete_post_meta((int) $post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID);
            } else {
                update_post_meta((int) $post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, (int) $value);
            }
        }
        TSOL_Library_Homepage_Curation::reset_cache();
    }

    private function authorization_snapshot() {
        $snapshot = array();
        $post_ids = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::post_types(),
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        ));
        foreach ($post_ids as $post_id) {
            $snapshot[(int) $post_id] = metadata_exists('post', (int) $post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID)
                ? (int) get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true)
                : null;
        }
        return $snapshot;
    }

    private function data_hash($data) {
        return hash('sha256', wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function valid_uuid($uuid) {
        return (bool) preg_match('/^[a-f0-9-]{20,64}$/i', (string) $uuid);
    }

    private function post_types() {
        return array_merge(TSOL_Library_Content_Model::post_types(), array(TSOL_Library_Content_Model::SPEAKER_POST_TYPE));
    }

    private function taxonomies() {
        return array(TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, TSOL_Library_Content_Model::TOPIC_TAXONOMY);
    }

    private function portable_meta_keys($post_type) {
        if (TSOL_Library_Content_Model::SPEAKER_POST_TYPE === $post_type) {
            return array(
                TSOL_Library_Content_Model::SPEAKER_META_JOB_TITLE,
                TSOL_Library_Content_Model::SPEAKER_META_ORGANIZATION,
                TSOL_Library_Content_Model::SPEAKER_META_WEBSITE_URL,
                TSOL_Library_Content_Model::SPEAKER_META_SOCIAL_LINKS,
            );
        }
        return array_values(array_diff(
            TSOL_Library_Content_Model::metadata_keys_for_post_type($post_type),
            array(
                $this->uuid_key($post_type),
                TSOL_Library_Content_Model::META_SPEAKER_IDS,
                TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID,
                TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID,
            )
        ));
    }

    private function with_lock($callback) {
        $now = time();
        $acquired = add_option(self::LOCK_OPTION, $now, '', 'no');
        if (!$acquired) {
            $locked_at = (int) get_option(self::LOCK_OPTION, 0);
            if ($locked_at > 0 && $locked_at <= $now - self::LOCK_TTL) {
                delete_option(self::LOCK_OPTION);
                $acquired = add_option(self::LOCK_OPTION, $now, '', 'no');
            }
        }
        if (!$acquired) {
            throw new RuntimeException('Another Library migration operation is running.');
        }
        try {
            return call_user_func($callback);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }
}
