<?php
/**
 * Normalizes admin-entered and legacy media URLs into stable provider fields.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Media_Normalizer {

    public static function from_url($url) {
        $url = self::clean_url($url);
        if ('' === $url) {
            return new WP_Error('empty_media_url', __('Enter a media URL.', 'member-library'));
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return new WP_Error('invalid_media_url', __('Enter a valid absolute media URL.', 'member-library'));
        }

        $host = strtolower(preg_replace('/^www\./', '', (string) $parts['host']));
        $path = isset($parts['path']) ? trim((string) $parts['path'], '/') : '';
        $segments = '' === $path ? array() : array_values(array_filter(explode('/', $path), 'strlen'));

        if (self::host_matches($host, 'vimeo.com')) {
            return self::vimeo_asset($url, $parts, $segments);
        }

        if (self::host_matches($host, 'youtube.com') || self::host_matches($host, 'youtu.be')) {
            return self::youtube_asset($url, $parts, $segments, $host);
        }

        $attachment_id = function_exists('attachment_url_to_postid') ? (int) attachment_url_to_postid($url) : 0;
        if ($attachment_id > 0) {
            $mime_type = (string) get_post_mime_type($attachment_id);
            if (0 !== strpos($mime_type, 'video/') && 0 !== strpos($mime_type, 'audio/')) {
                return new WP_Error('unsupported_media_attachment', __('Choose a WordPress audio or video attachment. Documents belong in Library resources.', 'member-library'));
            }
            return array(
                'kind' => 0 === strpos($mime_type, 'audio/') ? 'audio' : 'video',
                'provider' => 'wordpress',
                'provider_id' => (string) $attachment_id,
                'privacy_hash' => '',
                'attachment_id' => $attachment_id,
                'source_url' => $url,
            );
        }

        if (!self::is_direct_media_url($url)) {
            return new WP_Error('unsupported_media_url', __('Use a Vimeo, YouTube, or direct audio/video URL.', 'member-library'));
        }

        return array(
            'kind' => self::infer_kind($url),
            'provider' => 'external',
            // Direct files have no provider-owned identifier. A stable URL
            // fingerprint keeps learning state bound to the selected asset.
            'provider_id' => hash('sha256', $url),
            'privacy_hash' => '',
            'attachment_id' => 0,
            'source_url' => $url,
        );
    }

    public static function normalize_asset($asset, $position = 1) {
        if (!is_array($asset)) {
            return new WP_Error('invalid_media_asset', __('Media assets must be arrays.', 'member-library'));
        }

        $normalized = array();
        if (!empty($asset['source_url'])) {
            $normalized = self::from_url($asset['source_url']);
            if (is_wp_error($normalized)) {
                return $normalized;
            }
        } else {
            $provider = isset($asset['provider']) ? sanitize_key($asset['provider']) : '';
            if (!in_array($provider, array('vimeo', 'youtube', 'wordpress', 'external'), true)) {
                return new WP_Error('invalid_media_provider', __('Choose a supported media provider.', 'member-library'));
            }

            $normalized = array(
                'kind' => isset($asset['kind']) && 'audio' === sanitize_key($asset['kind']) ? 'audio' : 'video',
                'provider' => $provider,
                'provider_id' => isset($asset['provider_id']) ? sanitize_text_field($asset['provider_id']) : '',
                'privacy_hash' => isset($asset['privacy_hash']) ? sanitize_text_field($asset['privacy_hash']) : '',
                'attachment_id' => isset($asset['attachment_id']) ? absint($asset['attachment_id']) : 0,
                'source_url' => '',
            );
        }

        $normalized['key'] = !empty($asset['key']) ? sanitize_key($asset['key']) : 'asset-' . absint($position);
        $normalized['label'] = isset($asset['label']) ? sanitize_text_field($asset['label']) : '';
        $normalized['position'] = isset($asset['position']) ? absint($asset['position']) : absint($position);
        $normalized['preview'] = !empty($asset['preview']);
        $normalized['duration_seconds'] = isset($asset['duration_seconds']) ? absint($asset['duration_seconds']) : 0;

        return $normalized;
    }

    public static function extract_from_content($content) {
        $content = html_entity_decode((string) $content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $content = str_replace(array('\/', '\u0026'), array('/', '&'), $content);
        // Four PHP-level slashes become the two regex-level slashes needed to
        // exclude a literal backslash without escaping the character-class end.
        preg_match_all('~https?://[^\s\"\'<>\\\\]+~iu', $content, $matches);

        $assets = array();
        $seen = array();
        foreach ($matches[0] as $candidate) {
            $candidate = rtrim($candidate, '.,;:)]}');
            $asset = self::from_url($candidate);
            if (is_wp_error($asset)) {
                continue;
            }

            if (in_array($asset['provider'], array('vimeo', 'youtube'), true)) {
                $identity = implode(':', array($asset['provider'], $asset['provider_id'], $asset['privacy_hash']));
            } elseif ('wordpress' === $asset['provider']) {
                $identity = 'wordpress:' . $asset['attachment_id'];
            } else {
                $identity = 'external:' . $asset['source_url'];
            }
            if (isset($seen[$identity])) {
                continue;
            }

            $seen[$identity] = true;
            $asset['key'] = 'asset-' . (count($assets) + 1);
            $asset['label'] = '';
            $asset['position'] = count($assets) + 1;
            $asset['preview'] = false;
            $asset['duration_seconds'] = 0;
            $assets[] = $asset;
        }

        return $assets;
    }

    private static function vimeo_asset($url, $parts, $segments) {
        $video_id = '';
        $privacy_hash = '';
        $video_index = null;

        foreach ($segments as $index => $segment) {
            if (preg_match('/^\d+$/', $segment)) {
                $video_id = $segment;
                $video_index = $index;
                break;
            }
        }

        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            if (!empty($query['h'])) {
                $privacy_hash = sanitize_text_field($query['h']);
            }
        }

        if ('' === $privacy_hash && null !== $video_index && isset($segments[$video_index + 1])) {
            $candidate_hash = (string) $segments[$video_index + 1];
            if (preg_match('/^[A-Za-z0-9_-]{6,}$/', $candidate_hash)) {
                $privacy_hash = sanitize_text_field($candidate_hash);
            }
        }

        if ('' === $video_id) {
            return new WP_Error('invalid_vimeo_url', __('The Vimeo URL does not contain a video ID.', 'member-library'));
        }

        return array(
            'kind' => 'video',
            'provider' => 'vimeo',
            'provider_id' => $video_id,
            'privacy_hash' => $privacy_hash,
            'attachment_id' => 0,
            'source_url' => $url,
        );
    }

    private static function youtube_asset($url, $parts, $segments, $host) {
        $video_id = '';

        if (self::host_matches($host, 'youtu.be') && !empty($segments[0])) {
            $video_id = $segments[0];
        }

        if ('' === $video_id && isset($parts['query'])) {
            parse_str((string) $parts['query'], $query);
            if (!empty($query['v'])) {
                $video_id = (string) $query['v'];
            }
        }

        if ('' === $video_id && count($segments) >= 2 && in_array($segments[0], array('embed', 'shorts', 'live'), true)) {
            $video_id = $segments[1];
        }

        $video_id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $video_id);
        if ('' === $video_id) {
            return new WP_Error('invalid_youtube_url', __('The YouTube URL does not contain a video ID.', 'member-library'));
        }

        return array(
            'kind' => 'video',
            'provider' => 'youtube',
            'provider_id' => $video_id,
            'privacy_hash' => '',
            'attachment_id' => 0,
            'source_url' => $url,
        );
    }

    private static function clean_url($url) {
        $url = html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $url = str_replace('\/', '/', $url);
        return esc_url_raw($url, array('http', 'https'));
    }

    private static function host_matches($host, $domain) {
        return $host === $domain || str_ends_with($host, '.' . $domain);
    }

    private static function is_direct_media_url($url) {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        return (bool) preg_match('/\.(mp4|m4v|mov|webm|ogv|mp3|m4a|wav|ogg|oga)(?:$)/i', $path);
    }

    private static function infer_kind($url) {
        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        return preg_match('/\.(mp3|m4a|wav|ogg|oga)$/i', $path) ? 'audio' : 'video';
    }
}
