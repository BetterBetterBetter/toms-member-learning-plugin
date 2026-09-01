<?php
/**
 * Read-only catalogue synchronization health for wp-admin and Site Health.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Catalogue_Sync_Status {

    const SITE_HEALTH_TEST = 'tsol_library_catalogue_sync';

    public function init() {
        add_filter('site_status_tests', array($this, 'register_site_health_test'));
        add_action('admin_post_tsol_library_catalogue_retry', array($this, 'handle_retry'));
    }

    public function handle_retry() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You are not allowed to retry catalogue synchronization.', 'libertyclassroom-library'), '', array('response' => 403));
        }
        check_admin_referer('tsol_library_catalogue_retry');

        $delivered = TSOL_Library_Catalogue_Webhook::retry_pending_now();
        $url = add_query_arg(
            'catalogue_retry',
            $delivered ? 'delivered' : 'pending',
            TSOL_Library_Admin_Navigation::settings_url(TSOL_Library_Admin_Navigation::SETTINGS_TAB_SYNC)
        );
        wp_safe_redirect($url);
        exit;
    }

    public function register_site_health_test($tests) {
        if (!is_array($tests)) {
            $tests = array();
        }
        if (!isset($tests['direct']) || !is_array($tests['direct'])) {
            $tests['direct'] = array();
        }
        $tests['direct'][self::SITE_HEALTH_TEST] = array(
            'label' => __('Liberty Classroom catalogue synchronization', 'libertyclassroom-library'),
            'test' => array($this, 'site_health_test'),
        );
        return $tests;
    }

    public function site_health_test() {
        $status = TSOL_Library_Catalogue_Webhook::delivery_status();
        $assessment = self::assess_local_status($status);
        $labels = array(
            'good' => __('Liberty Classroom catalogue delivery is healthy', 'libertyclassroom-library'),
            'recommended' => __('Liberty Classroom catalogue delivery needs attention', 'libertyclassroom-library'),
            'critical' => __('Liberty Classroom catalogue delivery is unhealthy', 'libertyclassroom-library'),
        );

        return array(
            'label' => $labels[$assessment['status']],
            'status' => $assessment['status'],
            'badge' => array(
                'label' => __('Liberty Classroom', 'libertyclassroom-library'),
                'color' => 'blue',
            ),
            'description' => sprintf('<p>%s</p>', esc_html($assessment['message'])),
            'actions' => sprintf(
                '<p><a href="%s">%s</a></p>',
                esc_url(TSOL_Library_Admin_Navigation::settings_url(TSOL_Library_Admin_Navigation::SETTINGS_TAB_SYNC)),
                esc_html__('Open synchronization status', 'libertyclassroom-library')
            ),
            'test' => self::SITE_HEALTH_TEST,
        );
    }

    public function render() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $local = TSOL_Library_Catalogue_Webhook::delivery_status();
        $remote = TSOL_Library_Catalogue_Webhook::school_status($local['source_cursor']);
        $assessment = self::assess_combined_status($local, $remote);
        $notice_class = 'good' === $assessment['status']
            ? 'notice-success'
            : ('critical' === $assessment['status'] ? 'notice-error' : 'notice-warning');
        $retry_notice = isset($_GET['catalogue_retry']) ? sanitize_key(wp_unslash($_GET['catalogue_retry'])) : '';
        ?>
        <h2><?php esc_html_e('Catalogue Synchronization', 'libertyclassroom-library'); ?></h2>
        <p class="tsol-library-admin-page__lead"><?php esc_html_e('WordPress remains the editorial source of truth. This page confirms that its durable change journal and the School catalogue projection are moving together.', 'libertyclassroom-library'); ?></p>
        <div class="notice <?php echo esc_attr($notice_class); ?> inline">
            <p><strong><?php echo esc_html($assessment['message']); ?></strong></p>
        </div>
        <?php if ('delivered' === $retry_notice) : ?>
            <div class="notice notice-success inline"><p><?php esc_html_e('WordPress delivered the pending catalogue change. The School worker is now processing it; refresh this page shortly to confirm matching cursors.', 'libertyclassroom-library'); ?></p></div>
        <?php elseif ('pending' === $retry_notice) : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('The catalogue delivery is still pending. Confirm that the Library URL and catalogue synchronization secret exactly match the School app, then retry.', 'libertyclassroom-library'); ?></p></div>
        <?php endif; ?>

        <div class="tsol-library-admin-stats">
            <?php self::render_stat(__('WordPress cursor', 'libertyclassroom-library'), $local['source_cursor']); ?>
            <?php self::render_stat(__('School cursor', 'libertyclassroom-library'), !empty($remote['ok']) ? $remote['cursor'] : __('Unavailable', 'libertyclassroom-library')); ?>
            <?php self::render_stat(__('Pending deliveries', 'libertyclassroom-library'), $local['pending']['count']); ?>
            <?php self::render_stat(__('Pending School wake-ups', 'libertyclassroom-library'), !empty($remote['ok']) ? $remote['pending_wakeups'] : __('Unavailable', 'libertyclassroom-library')); ?>
        </div>

        <section class="card tsol-library-admin-card--wide">
            <h3><?php esc_html_e('Delivery details', 'libertyclassroom-library'); ?></h3>
            <table class="widefat striped" role="presentation">
                <tbody>
                    <?php self::render_row(__('Latest WordPress change', 'libertyclassroom-library'), self::display_time($local['latest_change_at'])); ?>
                    <?php self::render_row(__('Oldest pending delivery', 'libertyclassroom-library'), self::display_time($local['pending']['oldest_at'])); ?>
                    <?php self::render_row(__('Next retry', 'libertyclassroom-library'), self::display_time($local['pending']['next_attempt_at'])); ?>
                    <?php self::render_row(__('Highest retry count', 'libertyclassroom-library'), (string) $local['pending']['max_attempts']); ?>
                    <?php self::render_row(__('Next immediate delivery attempt', 'libertyclassroom-library'), self::display_time($local['cron_scheduled_at'])); ?>
                    <?php self::render_row(__('One-minute delivery watchdog', 'libertyclassroom-library'), self::display_time($local['watchdog_scheduled_at'])); ?>
                    <?php self::render_row(__('Last confirmed webhook delivery', 'libertyclassroom-library'), self::last_delivery_label($local['last_delivery'])); ?>
                    <?php self::render_row(__('School last successful sync', 'libertyclassroom-library'), !empty($remote['ok']) ? self::display_time($remote['last_successful_sync_at']) : __('Unavailable', 'libertyclassroom-library')); ?>
                    <?php self::render_row(__('School schema', 'libertyclassroom-library'), !empty($remote['ok']) && '' !== $remote['schema_version'] ? $remote['schema_version'] : __('Unavailable', 'libertyclassroom-library')); ?>
                </tbody>
            </table>
        </section>

        <?php if ((int) $local['pending']['count'] > 0) : ?>
            <section class="card tsol-library-admin-card--wide">
                <h3><?php esc_html_e('Retry pending synchronization', 'libertyclassroom-library'); ?></h3>
                <p><?php esc_html_e('Retry the retained catalogue cursor immediately after correcting the Library URL or catalogue synchronization secret. This does not recreate, discard, or duplicate catalogue data.', 'libertyclassroom-library'); ?></p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="tsol_library_catalogue_retry">
                    <?php wp_nonce_field('tsol_library_catalogue_retry'); ?>
                    <?php submit_button(__('Retry synchronization now', 'libertyclassroom-library'), 'secondary', 'submit', false); ?>
                </form>
            </section>
        <?php endif; ?>

        <section class="card tsol-library-admin-card--wide">
            <h3><?php esc_html_e('Production requirement', 'libertyclassroom-library'); ?></h3>
            <p><?php esc_html_e('Run WordPress cron from the hosting platform at least once per minute. Immediate delivery accelerates normal browser saves, while the durable cron retry and the School worker poll recover from process, network, or receiver failures.', 'libertyclassroom-library'); ?></p>
            <p><?php esc_html_e('Investigate when the oldest pending delivery exceeds two minutes, retry counts keep increasing, the School cursor remains behind, or the latest School run fails.', 'libertyclassroom-library'); ?></p>
        </section>
        <?php
    }

    private static function assess_local_status($status) {
        if (empty($status['outbox_installed']) || empty($status['configured']) || empty($status['watchdog_scheduled_at'])) {
            return array(
                'status' => 'critical',
                'message' => __('Catalogue delivery is not fully configured or its recovery watchdog is missing.', 'libertyclassroom-library'),
            );
        }

        $pending_count = (int) $status['pending']['count'];
        $oldest_age = self::age_seconds($status['pending']['oldest_at']);
        $attempts = (int) $status['pending']['max_attempts'];
        if (($pending_count > 0 && $oldest_age > 120) || $attempts >= 3) {
            return array(
                'status' => 'critical',
                'message' => __('A catalogue delivery has remained pending beyond the recovery window.', 'libertyclassroom-library'),
            );
        }
        if ($pending_count > 0 || $attempts > 0) {
            return array(
                'status' => 'recommended',
                'message' => __('Catalogue delivery is retrying or waiting to be confirmed.', 'libertyclassroom-library'),
            );
        }

        return array(
            'status' => 'good',
            'message' => __('The durable WordPress catalogue outbox is healthy.', 'libertyclassroom-library'),
        );
    }

    private static function assess_combined_status($local, $remote) {
        $local_assessment = self::assess_local_status($local);
        if ('critical' === $local_assessment['status']) {
            return $local_assessment;
        }
        if (empty($remote['ok'])) {
            return array(
                'status' => 'recommended',
                'message' => __('WordPress delivery is available, but the School synchronization status could not be confirmed.', 'libertyclassroom-library'),
            );
        }

        $source_cursor = (int) $local['source_cursor'];
        $school_cursor = (int) $remote['cursor'];
        if ($school_cursor < $source_cursor || (int) $remote['pending_wakeups'] > 0 || (int) $local['pending']['count'] > 0) {
            return array(
                'status' => 'recommended',
                'message' => __('The School catalogue is safely catching up with WordPress.', 'libertyclassroom-library'),
            );
        }
        if (is_array($remote['latest_run']) && 'FAILED' === (string) $remote['latest_run']['status']) {
            return array(
                'status' => 'critical',
                'message' => __('The latest School catalogue synchronization failed.', 'libertyclassroom-library'),
            );
        }

        return array(
            'status' => 'good',
            'message' => __('The School catalogue is synchronized with WordPress.', 'libertyclassroom-library'),
        );
    }

    private static function age_seconds($iso8601) {
        if (!is_string($iso8601) || false === strtotime($iso8601)) {
            return 0;
        }
        return max(0, time() - (int) strtotime($iso8601));
    }

    private static function display_time($iso8601) {
        if (!is_string($iso8601) || false === strtotime($iso8601)) {
            return __('None', 'libertyclassroom-library');
        }
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) strtotime($iso8601));
    }

    private static function last_delivery_label($last_delivery) {
        if (!is_array($last_delivery) || null === $last_delivery['success']) {
            return __('None recorded yet', 'libertyclassroom-library');
        }
        $result = $last_delivery['success']
            ? __('Succeeded', 'libertyclassroom-library')
            : __('Failed; retry retained', 'libertyclassroom-library');
        return sprintf(
            /* translators: 1: delivery result, 2: cursor, 3: date/time. */
            __('%1$s at cursor %2$s — %3$s', 'libertyclassroom-library'),
            $result,
            (string) $last_delivery['cursor'],
            self::display_time($last_delivery['recorded_at'])
        );
    }

    private static function render_stat($label, $value) {
        ?>
        <div class="tsol-library-admin-stat">
            <span><?php echo esc_html($label); ?></span>
            <strong><?php echo esc_html((string) $value); ?></strong>
        </div>
        <?php
    }

    private static function render_row($label, $value) {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td><?php echo esc_html((string) $value); ?></td>
        </tr>
        <?php
    }
}
