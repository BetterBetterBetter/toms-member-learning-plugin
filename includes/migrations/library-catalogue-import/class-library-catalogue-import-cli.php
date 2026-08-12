<?php
/**
 * WP-CLI entry point for the guarded TSOL-owned catalogue import.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Catalogue_Import_CLI {

    const COMMAND = 'tsol library-catalogue-import';

    /** Read-only import plan. */
    public function preview() {
        $this->run('preview');
    }

    /** Report importer state without writing. */
    public function status() {
        $this->run('status');
    }

    /** Verify the applied clone-only import. */
    public function verify() {
        $this->run('verify');
    }

    /**
     * Create TSOL-owned draft clones.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be import-legacy-content-into-tsol-library-drafts.
     */
    public function apply($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_Catalogue_Import::APPLY_CONFIRMATION);
        $this->run('apply');
    }

    /**
     * Remove only untouched importer-owned drafts.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be remove-tsol-library-import-drafts.
     */
    public function rollback($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_Catalogue_Import::ROLLBACK_CONFIRMATION);
        $this->run('rollback');
    }

    private function run($operation) {
        $importer = new TSOL_Library_Catalogue_Import();
        try {
            $report = call_user_func(array($importer, $operation));
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
            return;
        }
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        WP_CLI::success(sprintf('TSOL Library catalogue import %s passed.', $operation));
    }

    private function confirm($assoc_args, $expected) {
        $actual = isset($assoc_args['confirm']) ? sanitize_text_field($assoc_args['confirm']) : '';
        if ($expected !== $actual) {
            WP_CLI::error('The exact guarded local confirmation string is required.');
        }
    }
}
