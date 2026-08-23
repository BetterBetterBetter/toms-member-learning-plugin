<?php
/**
 * Infers stable user-facing resources from legacy WordPress content.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Resource_Normalizer {

    const SUPPORTED_EXTENSIONS = array('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip');

    public static function extract_from_content($content) {
        $content = str_replace('\\/', '/', (string) $content);
        $resources = array();
        $seen = array();

        foreach (self::linked_urls($content) as $linked_url) {
            $resource = self::from_url(
                $linked_url['url'],
                count($resources) + 1,
                $linked_url['label']
            );
            if (is_wp_error($resource)) {
                continue;
            }

            $identity = self::url_identity($resource['url']);
            if (isset($seen[$identity])) {
                continue;
            }
            $seen[$identity] = true;
            $resources[] = $resource;
        }

        return $resources;
    }

    public static function from_url($url, $position = 1, $label = '') {
        $url = esc_url_raw(html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5));
        $parts = wp_parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? (string) $parts['host'] : '';
        $is_email = 'mailto' === $scheme && is_email((string) substr($url, strlen('mailto:')));
        $is_web = in_array($scheme, array('http', 'https'), true) && '' !== $host;
        if ('' === $url || (!$is_web && !$is_email)) {
            return new WP_Error('invalid_resource_url', __('Resource URL must be a web or email link.', 'tomschooloflife-plugin'));
        }

        if ($is_web && class_exists('TSOL_Library_Media_Normalizer')) {
            $media = TSOL_Library_Media_Normalizer::from_url($url);
            if (!is_wp_error($media)) {
                return new WP_Error('playable_media_url', __('Playable media is stored separately from lesson resources.', 'tomschooloflife-plugin'));
            }
        }

        $path = (string) wp_parse_url($url, PHP_URL_PATH);
        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $type = in_array($extension, self::SUPPORTED_EXTENSIONS, true) ? 'download' : 'link';

        $position = max(1, absint($position));
        $label = self::resource_label($label, $url, $path, $position);

        return array(
            'key' => 'resource-' . $position,
            'type' => $type,
            'label' => sanitize_text_field($label),
            'url' => $url,
            'attachment_id' => absint(attachment_url_to_postid($url)),
            'position' => $position,
        );
    }

    private static function linked_urls($content) {
        $linked_urls = array();
        $anchor_urls = array();
        $pattern = '~<a\b[^>]*\bhref\s*=\s*(["\x27])(.*?)\1[^>]*>(.*?)</a>~is';
        if (preg_match_all($pattern, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($matches as $match) {
                $url = html_entity_decode(trim((string) $match[2][0]), ENT_QUOTES | ENT_HTML5);
                $label = self::anchor_label(
                    (string) $match[3][0],
                    $url,
                    $content,
                    (int) $match[0][1],
                    strlen((string) $match[0][0])
                );
                $linked_urls[] = array('url' => $url, 'label' => $label);
                $anchor_urls[self::url_identity($url)] = true;
            }
        }

        // A few old editors left visible, unlinked URLs in paragraph text.
        // Strip markup first so iframe, image, script, and stylesheet sources
        // cannot accidentally appear as downloadable lesson resources.
        $without_anchors = (string) preg_replace($pattern, ' ', $content);
        $visible_text = wp_strip_all_tags((string) preg_replace('/<[^>]+>/', ' ', $without_anchors));
        foreach (wp_extract_urls(html_entity_decode($visible_text, ENT_QUOTES | ENT_HTML5)) as $url) {
            $identity = self::url_identity($url);
            if ('' === $identity || isset($anchor_urls[$identity])) {
                continue;
            }
            $linked_urls[] = array('url' => $url, 'label' => '');
            $anchor_urls[$identity] = true;
        }

        return $linked_urls;
    }

    private static function anchor_label($anchor_html, $url, $content, $offset, $length) {
        $label = self::clean_label(wp_strip_all_tags(html_entity_decode($anchor_html, ENT_QUOTES | ENT_HTML5)));
        if (!self::needs_context_label($label)) {
            return $label;
        }

        $block = self::enclosing_block_text($content, $offset, $length);
        if ('' !== $block) {
            $without_url = str_ireplace(array($label, $url), '', $block);
            $without_url = self::clean_label($without_url);
            if ('' !== $without_url) {
                return $without_url;
            }
        }

        return $label;
    }

    private static function enclosing_block_text($content, $offset, $length) {
        $before = substr($content, 0, $offset);
        if (!preg_match_all('/<(p|h[1-6]|li|div)\b[^>]*>/i', $before, $openings, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $opening = end($openings);
        $tag = strtolower((string) $opening[1][0]);
        $start = (int) $opening[0][1] + strlen((string) $opening[0][0]);
        $after_offset = $offset + $length;
        $after = substr($content, $after_offset);
        if (!preg_match(sprintf('/<\/%s\s*>/i', preg_quote($tag, '/')), $after, $closing, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $end = $after_offset + (int) $closing[0][1];
        $block_html = substr($content, $start, $end - $start);
        return self::clean_label(wp_strip_all_tags(html_entity_decode($block_html, ENT_QUOTES | ENT_HTML5)));
    }

    private static function needs_context_label($label) {
        if ('' === $label || filter_var($label, FILTER_VALIDATE_URL)) {
            return true;
        }

        return 1 === preg_match('/^(click(?:ing)? here|here|learn more|read more|visit|website|link)$/i', $label);
    }

    private static function resource_label($label, $url, $path, $position) {
        $label = self::clean_label($label);
        if ('' !== $label && !self::needs_context_label($label)) {
            return $label;
        }

        $basename = rawurldecode((string) pathinfo($path, PATHINFO_FILENAME));
        $basename = self::clean_label((string) preg_replace('/[-_]+/', ' ', $basename));
        if ('' !== $basename && !in_array(strtolower($basename), array('index', 'contact'), true)) {
            return ucwords($basename);
        }

        $host = preg_replace('/^www\./i', '', (string) wp_parse_url($url, PHP_URL_HOST));
        return '' !== $host ? $host : sprintf(__('Resource %d', 'tomschooloflife-plugin'), $position);
    }

    private static function clean_label($label) {
        $label = preg_replace('/\s+/u', ' ', (string) $label);
        $label = preg_replace('/,\s*\./u', '.', (string) $label);
        $label = preg_replace('/\s+([,.;!?])/u', '$1', (string) $label);
        $label = preg_replace('/\s+by\.$/iu', '', (string) $label);
        return trim((string) $label, " \t\n\r\0\x0B:;-|\xC2\xA0");
    }

    private static function url_identity($url) {
        $url = esc_url_raw(html_entity_decode(trim((string) $url), ENT_QUOTES | ENT_HTML5));
        return '' !== $url ? strtolower(untrailingslashit($url)) : '';
    }
}
