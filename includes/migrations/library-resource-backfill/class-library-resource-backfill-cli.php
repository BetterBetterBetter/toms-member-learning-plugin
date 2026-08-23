<?php
/** WP-CLI entry point for the legacy body-link resource backfill. */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Resource_Backfill_CLI {

    const COMMAND = 'tsol library-resource-backfill';

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
     * ## OPTIONS
     *
     * --confirm=<confirmation>
     * : Must be backfill-all-legacy-body-links-as-resources.
     */
    public function apply($args, $assoc_args) {
        unset($args);
        $actual = sanitize_text_field((string) ($assoc_args['confirm'] ?? ''));
        if (TSOL_Library_Resource_Backfill::APPLY_CONFIRMATION !== $actual) {
            WP_CLI::error('The exact guarded confirmation string is required.');
        }
        $this->run('apply');
    }

    private function run($operation) {
        try {
            $report = call_user_func(array(new TSOL_Library_Resource_Backfill(), $operation));
        } catch (Throwable $exception) {
            WP_CLI::error($exception->getMessage());
            return;
        }
        WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        WP_CLI::success(sprintf('Legacy resource-link backfill %s passed.', $operation));
    }
}
