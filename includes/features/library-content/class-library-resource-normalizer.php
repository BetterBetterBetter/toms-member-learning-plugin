<?php
/**
 * Infers stable downloadable resources from legacy WordPress content.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Resource_Normalizer {

    const SUPPORTED_EXTENSIONS = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip');

    public static function extract_from_content($content) {
        $content = str_replace('\\/', '/', (string) $content);
        $urls = wp_extract_urls(html_entity_decode($content, ENT_QUOTES | ENT_HTML5));
        $resources = array();
        $seen = array();

        foreach ($urls as $url) {
            $resource = self::from_url($url, count($resources) + 1);
            if (is_wp_error($resource)) {
                continue;
            }

            $identity = strtolower((string) $resource['url']);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $resources[] = $resource;
        }

        return $resources;
    }

    public static function from_url($url, $position = 1) {
        $url = esc_url_raw(html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5));
        $parts = wp_parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '';
        if ('' === $url || !in_array($scheme, array('http', 'https'), true) || '' === $host) {
            return new WP_Error('invalid_resource_url', __('Resource URL must use HTTP or HTTPS.', 'tomschooloflife-plugin'));
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, self::SUPPORTED_EXTENSIONS, true)) {
            return new WP_Error('unsupported_resource_url', __('The URL is not a supported downloadable resource.', 'tomschooloflife-plugin'));
        }

        $position = max(1, absint($position));
        $basename = rawurldecode((string) pathinfo($path, PATHINFO_FILENAME));
        $label = trim((string) preg_replace('/[-_]+/', ' ', $basename));
        $label = '' !== $label ? ucwords($label) : sprintf(__('Resource %d', 'tomschooloflife-plugin'), $position);

        return array(
            'key' => 'resource-' . $position,
            'type' => 'download',
            'label' => sanitize_text_field($label),
            'url' => $url,
            'attachment_id' => absint(attachment_url_to_postid($url)),
            'position' => $position,
        );
    }
}
