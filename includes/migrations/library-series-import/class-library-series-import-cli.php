<?php
/** WP-CLI entry point for the guarded Series and Collections migration. */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Series_Import_CLI {
    const COMMAND = 'tsol library-series-import';

    public function preview() { $this->run('preview'); }
    public function status() { $this->run('status'); }
    public function verify() { $this->run('verify'); }

    /** --confirm must be group-normalized-library-items-into-series. */
    public function apply($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_Series_Import::APPLY_CONFIRMATION);
        $this->run('apply');
    }

    /** --confirm must be remove-normalized-series-structure. */
    public function rollback($args, $assoc_args) {
        unset($args);
        $this->confirm($assoc_args, TSOL_Library_Series_Import::ROLLBACK_CONFIRMATION);
        $this->run('rollback');
    }

    private function run($operation) {
        try {
            $report = call_user_func(array(new TSOL_Library_Series_Import(), $operation));
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
            return;
        }
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        WP_CLI::success(sprintf('TSOL Library Series migration %s passed.', $operation));
    }

    private function confirm($assoc_args, $expected) {
        $actual = isset($assoc_args['confirm']) ? sanitize_text_field($assoc_args['confirm']) : '';
        if ($expected !== $actual) {
            WP_CLI::error('The exact guarded local confirmation string is required.');
        }
    }
}
