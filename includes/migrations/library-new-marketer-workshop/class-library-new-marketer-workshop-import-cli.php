<?php
/** WP-CLI entry point for the guarded New Marketer Workshop import. */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_New_Marketer_Workshop_Import_CLI {

    const COMMAND = 'tsol library-new-marketer-workshop';

    public function preview() {
        $this->run('preview');
    }

    public function status() {
        $this->run('status');
    }

    public function verify() {
        $this->run('verify');
    }

    /**
     * Create and publish the guarded Course, lessons, and exact access rule.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be import-new-marketer-workshop-with-exact-legacy-access.
     */
    public function apply($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_New_Marketer_Workshop_Import::APPLY_CONFIRMATION);
        $this->run('apply');
    }

    /**
     * Replace the original flat curriculum with seven ordered sections.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be split-new-marketer-workshop-into-seven-sections.
     */
    public function restructure($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_New_Marketer_Workshop_Import::RESTRUCTURE_CONFIRMATION);
        $this->run('restructure');
    }

    /**
     * Apply canonical titles/slugs, flat artwork, and the verified speaker.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be apply-canonical-new-marketer-workshop-titles-slugs-and-thumbnail.
     */
    public function editorialize($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_New_Marketer_Workshop_Import::EDITORIAL_CONFIRMATION);
        $this->run('editorialize');
    }

    /**
     * Delete only unchanged importer-owned targets and the importer-owned rule.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be remove-unchanged-new-marketer-workshop-import.
     */
    public function rollback($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_New_Marketer_Workshop_Import::ROLLBACK_CONFIRMATION);
        $this->run('rollback');
    }

    private function run($operation) {
        try {
            $report = call_user_func(array(new TSOL_Library_New_Marketer_Workshop_Import(), $operation));
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
            return;
        }
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        WP_CLI::success(sprintf('New Marketer Workshop import %s passed.', $operation));
    }

    private function confirm($assoc_args, $expected) {
        $actual = isset($assoc_args['confirm']) ? sanitize_text_field($assoc_args['confirm']) : '';
        if ($expected !== $actual) {
            WP_CLI::error('The exact guarded local confirmation string is required.');
        }
    }
}
