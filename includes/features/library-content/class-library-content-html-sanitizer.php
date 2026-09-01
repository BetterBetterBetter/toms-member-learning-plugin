<?php
/**
 * Strict semantic HTML boundary for WordPress editor content synchronized to
 * the standalone Library.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Content_HTML_Sanitizer {

    const MAX_EMPTY_ELEMENT_PASSES = 10;

    public static function sanitize($content) {
        $source = strip_shortcodes((string) $content);

        // Remove block-editor comments, registered or legacy shortcode
        // wrappers, and executable/embedded elements together with their
        // contents. KSES alone unwraps some forbidden tags and can leave their
        // text behind.
        $source = preg_replace('/<!--.*?-->/s', '', $source);
        $source = preg_replace(
            '#<(script|style|iframe|video|audio|object|svg|math|canvas|template|noscript|form)\b[^>]*>.*?</\1\s*>#is',
            '',
            (string) $source
        );
        $source = preg_replace(
            '#<(?:script|style|iframe|video|audio|object|embed|svg|math|canvas|template|noscript|form|input|button|textarea|select|option)\b[^>]*/?>#is',
            '',
            (string) $source
        );
        $source = preg_replace('/\[[A-Za-z0-9_-]+(?:\s[^\]]*)?\].*?\[\/[A-Za-z0-9_-]+\]/s', '', (string) $source);
        $source = preg_replace('/\[(?:\/?)[A-Za-z0-9_-]+[^\]]*\]/', '', (string) $source);

        // A Library page owns its single h1. Preserve pasted heading meaning
        // without allowing editor content to alter that document hierarchy.
        $source = preg_replace('~<(\/?)h1\b[^>]*>~i', '<$1h2>', (string) $source);
        $source = preg_replace('~<(\/?)h[5-6]\b[^>]*>~i', '<$1h4>', (string) $source);
        $source = preg_replace_callback(
            '~\shref\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))~i',
            static function ($matches) {
                $href = '' !== (string) ($matches[1] ?? '')
                    ? (string) $matches[1]
                    : ('' !== (string) ($matches[2] ?? '') ? (string) $matches[2] : (string) ($matches[3] ?? ''));
                $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
                if (preg_match('~^(?:www\.)?(?:[a-z0-9-]+\.)+[a-z]{2,}(?::\d+)?(?:[/?#][^\s]*)?$~iu', $href)) {
                    $href = 'https://' . $href;
                }
                $is_absolute = (bool) preg_match('~^(?:https?://|mailto:)[^\s]+$~iu', $href);
                $is_root_relative = (bool) preg_match('~^/(?!/)(?!\.\.(?:/|$))(?!.*(?:/)\.\.(?:/|$))(?!.*%2e)[^\s<>]*$~iu', $href)
                    && false === strpos($href, '\\');
                $is_fragment = (bool) preg_match('~^#[A-Za-z][A-Za-z0-9_-]*$~', $href);
                if (!$is_absolute && !$is_root_relative && !$is_fragment) {
                    return '';
                }
                if ($is_absolute) {
                    $href = esc_url($href, array('http', 'https', 'mailto'));
                    if ('' === $href) {
                        return '';
                    }
                }
                return ' href="' . esc_attr($href) . '"';
            },
            (string) $source
        );

        $allowed_html = array(
            'p' => array(),
            'br' => array(),
            'h2' => array(),
            'h3' => array(),
            'h4' => array(),
            'ul' => array(),
            'ol' => array(),
            'li' => array(),
            'strong' => array(),
            'b' => array(),
            'em' => array(),
            'i' => array(),
            'blockquote' => array(),
            'a' => array(
                'href' => true,
                'title' => true,
            ),
        );
        $html = wp_kses(wpautop((string) $source), $allowed_html, array('http', 'https', 'mailto'));
        $html = str_ireplace(array('&nbsp;', '&#160;', '&#x00a0;', '&#xa0;'), ' ', (string) $html);

        // Removed paste wrappers and embeds can leave blank semantic blocks.
        // Prune them recursively so application spacing remains deterministic.
        $empty_element_pattern = '~<(p|h2|h3|h4|blockquote|ul|ol|li|a)(?:\s[^>]*)?>'
            . '(?:(?:\s|\xC2\xA0)|<br\s*/?>|<!--.*?-->)*'
            . '</\1\s*>~is';
        for ($pass = 0; $pass < self::MAX_EMPTY_ELEMENT_PASSES; $pass++) {
            $cleaned_html = preg_replace($empty_element_pattern, '', (string) $html);
            if (!is_string($cleaned_html) || $cleaned_html === $html) {
                break;
            }
            $html = $cleaned_html;
        }

        return trim((string) $html);
    }

    public static function sanitize_plain_text_summary($content) {
        $text = strip_shortcodes((string) $content);
        $text = wp_strip_all_tags($text, true);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset'));
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function sanitize_post_data($data, $postarr) {
        $post_type = isset($data['post_type']) ? (string) $data['post_type'] : '';
        $supported_post_types = array_merge(
            MemberLibrary_Content_Model::post_types(),
            array(MemberLibrary_Content_Model::SPEAKER_POST_TYPE)
        );
        if (!in_array($post_type, $supported_post_types, true)) {
            return $data;
        }

        // wp_insert_post_data receives slash-escaped values and WordPress
        // unslashes the returned array immediately after this filter. Inspect
        // real HTML rather than escaped quotes, then restore the filter's
        // documented slashed-data contract for downstream filters and Core.
        if (array_key_exists('post_content', $data)) {
            $data['post_content'] = wp_slash(self::sanitize(wp_unslash($data['post_content'])));
        }
        if (MemberLibrary_Content_Model::SPEAKER_POST_TYPE === $post_type && array_key_exists('post_excerpt', $data)) {
            $data['post_excerpt'] = wp_slash(self::sanitize_plain_text_summary(wp_unslash($data['post_excerpt'])));
        }
        return $data;
    }
}
