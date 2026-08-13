<?php
/** WP-CLI entry point for publishing native Course bodies safely. */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Course_Body_Publication_CLI {

    const COMMAND = 'tsol library-course-body-publication';

    public function status() {
        $this->run('status');
    }

    public function verify() {
        $this->run('verify');
    }

    /**
     * Archive resource-only Course bodies, move their links into lesson
     * Resources, and retire the duplicate public-description metadata.
     *
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be publish-native-course-bodies-and-move-protected-resources.
     */
    public function apply($args, $assoc_args) {
        unset($args);
        $actual = isset($assoc_args['confirm']) ? sanitize_text_field($assoc_args['confirm']) : '';
        if (TSOL_Library_Course_Body_Publication::APPLY_CONFIRMATION !== $actual) {
            WP_CLI::error('The exact guarded confirmation string is required.');
        }
        $this->run('apply');
    }

    private function run($operation) {
        try {
            $report = call_user_func(array(new TSOL_Library_Course_Body_Publication(), $operation));
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
            return;
        }
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        WP_CLI::success(sprintf('Course body publication %s passed.', $operation));
    }
}
