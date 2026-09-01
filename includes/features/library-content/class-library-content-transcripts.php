<?php
/**
 * Private WebVTT source editing and signed delivery to the School app.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Content_Transcripts {

    const NONCE_ACTION = 'tsol_library_transcript_upload';
    const NONCE_NAME = 'tsol_library_transcript_nonce';
    const FILE_NAME = 'tsol_library_transcript_file';
    const RETRY_HOOK = 'tsol_library_retry_transcript_delivery';
    const ENDPOINT_PATH = '/api/internal/transcripts/import';
    const MAX_FILE_BYTES = 5 * 1024 * 1024;
    const STATUS_META = '_tsol_library_transcript_delivery_status';
    const ATTEMPT_META = '_tsol_library_transcript_delivery_attempt';

    public static function register_hooks() {
        add_action('post_edit_form_tag', array(__CLASS__, 'enable_file_upload'));
        add_action('save_post_' . TSOL_Library_Content_Model::ITEM_POST_TYPE, array(__CLASS__, 'save_upload'), 40, 3);
        add_action(self::RETRY_HOOK, array(__CLASS__, 'deliver'));
        add_action('added_post_meta', array(__CLASS__, 'schedule_after_import'), 10, 4);
        add_action('updated_post_meta', array(__CLASS__, 'schedule_after_import'), 10, 4);
    }

    public static function enable_file_upload() {
        global $post;
        if ($post instanceof WP_Post && TSOL_Library_Content_Model::ITEM_POST_TYPE === $post->post_type) {
            echo ' enctype="multipart/form-data"';
        }
    }

    public static function render_media_fields($post) {
        wp_nonce_field(self::NONCE_ACTION, self::NONCE_NAME);
        $hash = sanitize_text_field((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_TRANSCRIPT_HASH, true));
        $filename = sanitize_file_name((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_TRANSCRIPT_FILENAME, true));
        $modified_at = sanitize_text_field((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_TRANSCRIPT_MODIFIED_AT, true));
        $delivery_status = sanitize_key((string) get_post_meta($post->ID, self::STATUS_META, true));
        ?>
        <section class="tsol-library-transcript-editor" data-library-transcript-editor aria-labelledby="tsol-library-transcript-heading">
            <div class="tsol-library-section-intro">
                <div>
                    <h3 id="tsol-library-transcript-heading"><?php esc_html_e('Primary video transcript', 'libertyclassroom-library'); ?></h3>
                    <p><?php esc_html_e('Upload the transcript for the primary playback source in the first media row.', 'libertyclassroom-library'); ?></p>
                    <p class="description"><?php esc_html_e('Choose a UTF-8 WebVTT file up to 5 MB. Saving without choosing a file keeps the current transcript.', 'libertyclassroom-library'); ?></p>
                </div>
            </div>
            <div class="tsol-library-field tsol-library-field--wide">
                <label for="tsol-library-transcript-file"><?php esc_html_e('WebVTT transcript (.vtt)', 'libertyclassroom-library'); ?></label>
                <input id="tsol-library-transcript-file" name="<?php echo esc_attr(self::FILE_NAME); ?>" type="file" accept=".vtt,text/vtt" />
            </div>
            <?php if ('' !== $hash) : ?>
                <div class="tsol-library-transcript-status" role="status">
                    <p>
                        <strong><?php esc_html_e('Current transcript:', 'libertyclassroom-library'); ?></strong>
                        <?php echo esc_html('' !== $filename ? $filename : __('WebVTT file', 'libertyclassroom-library')); ?>
                        <?php if ('' !== $modified_at) : ?>
                            <span class="description"><?php echo esc_html(sprintf(__('updated %s UTC', 'libertyclassroom-library'), $modified_at)); ?></span>
                        <?php endif; ?>
                    </p>
                    <p><code><?php echo esc_html(substr($hash, 0, 12)); ?>&hellip;</code>
                        <?php if ('delivered' === $delivery_status) : ?>
                            <span class="tsol-library-transcript-status__delivered"><?php esc_html_e('Synchronized with School', 'libertyclassroom-library'); ?></span>
                        <?php else : ?>
                            <span class="tsol-library-transcript-status__pending"><?php esc_html_e('Waiting to synchronize with School', 'libertyclassroom-library'); ?></span>
                        <?php endif; ?>
                    </p>
                </div>
            <?php endif; ?>
        </section>
        <?php
    }

    public static function save_upload($post_id, $post, $update) {
        unset($update);
        if (!$post instanceof WP_Post || wp_is_post_autosave($post_id) || wp_is_post_revision($post_id)) {
            return;
        }
        if (!isset($_POST[self::NONCE_NAME]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[self::NONCE_NAME])), self::NONCE_ACTION)) {
            return;
        }
        if (!current_user_can('edit_post', $post_id) || empty($_FILES[self::FILE_NAME]['name'])) {
            return;
        }

        $upload = $_FILES[self::FILE_NAME];
        $error = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
        $size = isset($upload['size']) ? (int) $upload['size'] : 0;
        $filename = sanitize_file_name((string) ($upload['name'] ?? ''));
        $temporary_name = (string) ($upload['tmp_name'] ?? '');
        if (UPLOAD_ERR_OK !== $error || $size <= 0 || $size > self::MAX_FILE_BYTES || 'vtt' !== strtolower((string) pathinfo($filename, PATHINFO_EXTENSION))) {
            self::store_error($post_id, __('Choose a valid .vtt transcript no larger than 5 MB.', 'libertyclassroom-library'));
            return;
        }
        if (!is_uploaded_file($temporary_name) || !is_readable($temporary_name)) {
            self::store_error($post_id, __('The transcript upload could not be read. Please choose the file again.', 'libertyclassroom-library'));
            return;
        }
        $vtt = file_get_contents($temporary_name);
        $vtt = TSOL_Library_Content_Model::sanitize_transcript_vtt($vtt);
        if ('' === $vtt) {
            self::store_error($post_id, __('The selected file is not valid WebVTT.', 'libertyclassroom-library'));
            return;
        }

        $version = max(0, (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_VERSION, true)) + 1;
        $modified_at = gmdate('Y-m-d\TH:i:s.000\Z');
        update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_HASH, hash('sha256', $vtt));
        update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_LANGUAGE, 'en');
        update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_FILENAME, $filename);
        update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_MODIFIED_AT, $modified_at);
        update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_VERSION, $version);
        update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_CONTENT, wp_slash($vtt));
        update_post_meta($post_id, self::STATUS_META, 'pending');
        delete_post_meta($post_id, self::ATTEMPT_META);
        self::deliver($post_id);
    }

    public static function schedule_after_import($meta_id, $post_id, $meta_key, $meta_value) {
        unset($meta_id, $meta_value);
        if (TSOL_Library_Content_Model::META_TRANSCRIPT_CONTENT !== (string) $meta_key) {
            return;
        }
        update_post_meta((int) $post_id, self::STATUS_META, 'pending');
        self::schedule_retry((int) $post_id, 10);
    }

    public static function deliver($post_id) {
        $payload = self::delivery_payload((int) $post_id);
        $secret = self::secret();
        $app_url = TSOL_Library_Auth_Settings::app_url();
        if (empty($payload) || strlen($secret) < 32 || '' === $app_url) {
            self::mark_failed((int) $post_id);
            return false;
        }
        $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            self::mark_failed((int) $post_id);
            return false;
        }
        $timestamp = (string) time();
        $endpoint_url = (string) apply_filters('tsol_library_transcript_endpoint_url', $app_url . self::ENDPOINT_PATH);
        $response = wp_remote_post($endpoint_url, array(
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-TSOL-Transcript-Timestamp' => $timestamp,
                'X-TSOL-Transcript-Signature' => 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret),
            ),
            'sslverify' => true,
            'timeout' => 20,
            'redirection' => 0,
            'blocking' => true,
            'user-agent' => 'TSOL-Library-Transcript/' . TSOL_SITE_PLUGIN_VERSION,
        ));
        $status_code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        if ($status_code >= 200 && $status_code < 300) {
            update_post_meta((int) $post_id, self::STATUS_META, 'delivered');
            delete_post_meta((int) $post_id, self::ATTEMPT_META);
            wp_clear_scheduled_hook(self::RETRY_HOOK, array((int) $post_id));
            return true;
        }
        self::mark_failed((int) $post_id);
        return false;
    }

    public static function delivery_payload($post_id, $issued_at = null) {
        $post = get_post((int) $post_id);
        if (!$post instanceof WP_Post || TSOL_Library_Content_Model::ITEM_POST_TYPE !== $post->post_type) {
            return array();
        }
        $vtt = (string) get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_CONTENT, true);
        $hash = strtolower((string) get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_HASH, true));
        $content_uuid = strtolower((string) get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_UUID, true));
        $media = get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_MEDIA_ASSETS, true);
        $media = is_array($media) ? array_values(array_filter($media, 'is_array')) : array();
        usort($media, static function ($left, $right) {
            return absint($left['position'] ?? 0) <=> absint($right['position'] ?? 0);
        });
        $primary = $media[0] ?? array();
        $provider = sanitize_key((string) ($primary['provider'] ?? ''));
        $provider_video_id = sanitize_text_field((string) ($primary['provider_id'] ?? ''));
        if ('' === $vtt || !preg_match('/^[0-9a-f]{64}$/', $hash) || !wp_is_uuid($content_uuid) || '' === $provider || '' === $provider_video_id) {
            return array();
        }
        return array(
            'version' => 1,
            'event' => 'transcript.source.replaced',
            'audience' => 'tsol-library-transcripts',
            'issued_at' => null === $issued_at ? time() : (int) $issued_at,
            'content_uuid' => $content_uuid,
            'provider' => $provider,
            'provider_video_id' => $provider_video_id,
            'provider_track_id' => 'wordpress:en',
            'provenance' => 'wordpress-upload',
            'language' => 'en',
            'display_language' => 'English',
            'name' => 'English',
            'kind' => 'subtitles',
            'source_modified_at' => (string) get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_MODIFIED_AT, true),
            'source_version' => max(1, (int) get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_VERSION, true)),
            'content_sha256' => $hash,
            'vtt' => $vtt,
        );
    }

    private static function secret() {
        $secret = trim((string) apply_filters('tsol_library_catalogue_webhook_secret', TSOL_Library_Auth_Settings::catalogue_webhook_secret()));
        $client_secret = TSOL_Library_Auth_Settings::client_secret();
        return '' !== $client_secret && hash_equals($client_secret, $secret) ? '' : $secret;
    }

    private static function mark_failed($post_id) {
        update_post_meta($post_id, self::STATUS_META, 'pending');
        $attempt = max(0, (int) get_post_meta($post_id, self::ATTEMPT_META, true)) + 1;
        update_post_meta($post_id, self::ATTEMPT_META, $attempt);
        $delays = array(60, 120, 300, 600, 1800, 3600);
        self::schedule_retry($post_id, $delays[min(count($delays) - 1, $attempt - 1)]);
    }

    private static function schedule_retry($post_id, $delay) {
        $args = array((int) $post_id);
        if (!wp_next_scheduled(self::RETRY_HOOK, $args)) {
            wp_schedule_single_event(time() + max(1, (int) $delay), self::RETRY_HOOK, $args);
        }
    }

    private static function store_error($post_id, $message) {
        $key = TSOL_Library_Content_Admin::NOTICE_PREFIX . get_current_user_id() . '_' . (int) $post_id;
        $messages = get_transient($key);
        $messages = is_array($messages) ? $messages : array();
        $messages[] = (string) $message;
        set_transient(
            $key,
            array_values(array_unique($messages)),
            MINUTE_IN_SECONDS
        );
    }
}
