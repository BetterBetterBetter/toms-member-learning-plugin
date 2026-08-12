<?php
/** WP-CLI entry point for the guarded TSOL-native MemberPress rule migration. */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Access_Rules_Migration_CLI {

    const COMMAND = 'tsol library-access-rules';

    /** Show the proposed native policies without writing. */
    public function preview() {
        $this->run('preview');
    }

    /** Show the current migration phase. */
    public function status() {
        $this->run('status');
    }

    /** Verify staged or activated policies and run the privacy-safe matrix. */
    public function verify() {
        $this->run('verify');
    }

    /**
     * Create inactive draft MemberPress rules. Legacy access remains active.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be stage-tsol-library-memberpress-rules.
     */
    public function stage($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_Access_Rules_Migration::STAGE_CONFIRMATION);
        $this->run('stage');
    }

    /**
     * Publish native rules and switch authorization after content publication.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be activate-tsol-library-memberpress-rules.
     *
     * --approve-differences=<approval>
     * : Must be approve-course-root-inheritance-corrections.
     */
    public function activate($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_Access_Rules_Migration::ACTIVATE_CONFIRMATION);
        $approval = isset($assoc_args['approve-differences'])
            ? sanitize_text_field($assoc_args['approve-differences'])
            : '';
        $this->run('activate', array($approval));
    }

    /**
     * Restore legacy delegation and remove only migration-owned rules.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be rollback-tsol-library-memberpress-rules.
     */
    public function rollback($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_Access_Rules_Migration::ROLLBACK_CONFIRMATION);
        $this->run('rollback');
    }

    private function run($operation, $arguments = array()) {
        try {
            $report = call_user_func_array(array(new TSOL_Library_Access_Rules_Migration(), $operation), $arguments);
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
            return;
        }
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        WP_CLI::success(sprintf('TSOL Library access rules %s passed.', $operation));
    }

    private function confirm($assoc_args, $expected) {
        $actual = isset($assoc_args['confirm']) ? sanitize_text_field($assoc_args['confirm']) : '';
        if ($expected !== $actual) {
            WP_CLI::error('The exact guarded local confirmation string is required.');
        }
    }
}
