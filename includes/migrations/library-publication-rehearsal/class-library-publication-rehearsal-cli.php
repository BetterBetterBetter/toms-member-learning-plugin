<?php
/** WP-CLI entry point for the guarded local Library publication rehearsal. */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Publication_Rehearsal_CLI {

    const COMMAND = 'tsol library-publication-rehearsal';

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
     * Publish the normalized local catalogue after hard gates pass.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be publish-local-tsol-library-rehearsal.
     */
    public function publish($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_Publication_Rehearsal::PUBLISH_CONFIRMATION);
        $this->run('publish');
    }

    /**
     * Restore the exact pre-rehearsal statuses after native access rollback.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be restore-local-tsol-library-statuses.
     */
    public function restore($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_Publication_Rehearsal::RESTORE_CONFIRMATION);
        $this->run('restore');
    }

    private function run($operation) {
        try {
            $report = call_user_func(array(new TSOL_Library_Publication_Rehearsal(), $operation));
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
            return;
        }
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        WP_CLI::success(sprintf('TSOL Library publication rehearsal %s passed.', $operation));
    }

    private function confirm($assoc_args, $expected) {
        $actual = isset($assoc_args['confirm']) ? sanitize_text_field($assoc_args['confirm']) : '';
        if ($expected !== $actual) {
            WP_CLI::error('The exact guarded local confirmation string is required.');
        }
    }
}
