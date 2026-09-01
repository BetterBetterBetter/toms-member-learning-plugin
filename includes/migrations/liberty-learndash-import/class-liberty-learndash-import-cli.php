<?php
/** WP-CLI entry point for the guarded Liberty LearnDash migration. */

if (!defined('ABSPATH')) {
    exit;
}

class Liberty_Classroom_LearnDash_Import_CLI {
    const COMMAND = 'liberty library-import';

    /** Display the read-only, evidence-locked migration plan. */
    public function preview() {
        $this->run('preview');
    }

    /** Display importer state without writing. */
    public function status() {
        $this->run('status');
    }

    /** Verify an applied local migration. */
    public function verify() {
        $this->run('verify');
    }

    /**
     * Create draft Library records without changing LearnDash or live rules.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be create-liberty-library-drafts-from-learndash.
     */
    public function apply($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, Liberty_Classroom_LearnDash_Import::APPLY_CONFIRMATION);
        $this->run('apply');
    }

    /**
     * Publish only the fully verified importer-owned local records.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be publish-verified-liberty-library-import.
     */
    public function publish($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, Liberty_Classroom_LearnDash_Import::PUBLISH_CONFIRMATION);
        $this->run('publish');
    }

    /**
     * Remove only untouched migration-owned drafts.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be remove-untouched-liberty-library-import-drafts.
     */
    public function rollback($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, Liberty_Classroom_LearnDash_Import::ROLLBACK_CONFIRMATION);
        $this->run('rollback');
    }

    private function run($operation) {
        try {
            $report = call_user_func(array(new Liberty_Classroom_LearnDash_Import(), $operation));
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
            return;
        }
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        WP_CLI::success(sprintf('Liberty Library LearnDash migration %s passed.', $operation));
    }

    private function confirm($assoc_args, $expected) {
        $actual = isset($assoc_args['confirm']) ? sanitize_text_field($assoc_args['confirm']) : '';
        if ($expected !== $actual) {
            WP_CLI::error('The exact guarded local confirmation string is required.');
        }
    }
}
