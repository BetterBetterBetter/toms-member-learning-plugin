<?php
/**
 * Strict Library editor-HTML sanitizer contract.
 *
 * Run: php -d memory_limit=512M /usr/local/bin/wp eval-file
 * tests/library-content-html-sanitizer-contract.php --skip-themes
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract check through WP-CLI.');
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(class_exists('MemberLibrary_Content_HTML_Sanitizer'), 'Library editor-HTML sanitizer is not loaded.');

$source = <<<'HTML'
<!-- wp:tadv/classic-paragraph -->
<h1 id="pasted-title" class="display" style="color:red" onclick="alert('no')" data-layout="wide">Public <span style="font-size:72px">heading</span></h1>
<style>.library { display: none; }</style>
<script>alert('script')</script>
<iframe src="https://player.example.test/private">private player</iframe>
<p id="copy" class="lead" style="margin-left:999px" onmouseover="alert('no')">Useful <strong style="color:red">strong copy</strong> and <em>emphasis</em>.</p>
<h6>Deep heading</h6>
<div class="layout"><ul style="display:grid"><li data-index="1">First outcome</li><li>Second outcome</li></ul></div>
<blockquote style="width:200vw">A useful quotation.</blockquote>
<a href="javascript:alert('no')" target="_blank" rel="opener" style="position:fixed">Unsafe destination</a>
<a href="https://example.test/resource" target="_blank" rel="opener" class="button" title="Public resource">Safe destination</a>
<a href="mailto:help@example.test">Email support</a>
<a href="/courses/safe-course">Internal Course</a>
<a href="#course-details">Course details</a>
<a href="example.test/resource">Normalized destination</a>
<a href="//hostile.example.test/resource">Protocol-relative destination</a>
<a href="/courses/../private">Traversal destination</a>
<img src="https://example.test/tracker.gif" style="position:fixed" onerror="alert('no')" />
<form action="https://example.test/collect"><input name="secret" value="private" /><button>Submit</button></form>
[private_embed]member-only shortcode content[/private_embed]
<p>&nbsp;<br /></p>
<!-- /wp:tadv/classic-paragraph -->
HTML;

$sanitized = MemberLibrary_Content_HTML_Sanitizer::sanitize($source);

$copied_affiliate_html = <<<'HTML'
<p>In blogging groups, I heard about the <a href="https://www.amazon.com/gp/product/1942589050/ref=as_li_tl?ie=UTF8&amp;camp=1789&amp;creative=9325&amp;creativeASIN=1942589050&amp;linkCode=as2&amp;tag=colesmithwrit-20&amp;linkId=a717f7f43097a429e4af1a3c444e177a">Miracle Morning</a> and read it. Holy mole, <a href="https://www.colesmithwrites.com/habit-changed-writing-life/">I was a morning person</a>!!! I discovered Chandler Bolt's book <a href="https://www.amazon.com/gp/product/1539412334/ref=as_li_tl?ie=UTF8&amp;camp=1789&amp;creative=9325&amp;creativeASIN=1539412334&amp;linkCode=as2&amp;tag=colesmithwrit-20&amp;linkId=bd613542f649498be0d5c76a19f3d6e7"><em>Published</em></a>. In just a few months, I finished and self-pubbed <a href="https://www.amazon.com/gp/product/B07BJJS3T6/ref=as_li_tl?ie=UTF8&amp;camp=1789&amp;creative=9325&amp;creativeASIN=B07BJJS3T6&amp;linkCode=as2&amp;tag=colesmithwrit-20&amp;linkId=4714824e78d48bd521b6ea9bd53f3a48"><em>Waiting for Jacob</em></a>.</p>
HTML;
$sanitized_affiliate_html = MemberLibrary_Content_HTML_Sanitizer::sanitize($copied_affiliate_html);

foreach (array('Miracle Morning', 'I was a morning person', 'Published', 'Waiting for Jacob') as $linked_text) {
    $assert(
        (bool) preg_match('~<a href="[^"]+"[^>]*>(?:<em>)?' . preg_quote($linked_text, '~') . '(?:</em>)?</a>~', $sanitized_affiliate_html),
        'Sanitizer removed the destination from copied link text: ' . $linked_text
    );
}

foreach (array(
    '<h2>Public heading</h2>',
    '<p>Useful <strong>strong copy</strong> and <em>emphasis</em>.</p>',
    '<h4>Deep heading</h4>',
    '<li>First outcome</li>',
    '<li>Second outcome</li>',
    '<blockquote><p>A useful quotation.</p></blockquote>',
    '<a href="https://example.test/resource" title="Public resource">Safe destination</a>',
    '<a href="mailto:help@example.test">Email support</a>',
    '<a href="/courses/safe-course">Internal Course</a>',
    '<a href="#course-details">Course details</a>',
    '<a href="https://example.test/resource">Normalized destination</a>',
) as $allowed_fragment) {
    $assert(false !== strpos($sanitized, $allowed_fragment), 'Sanitizer removed or changed allowed semantic HTML: ' . $allowed_fragment);
}

foreach (array(
    '<h1', '<h6', '<style', '<script', '<iframe', '<img', '<form', '<input', '<button', '<div', '<span',
    'class=', 'style=', 'id=', 'onclick=', 'onmouseover=', 'onerror=', 'data-layout=', 'data-index=', 'target=', 'rel=',
    'javascript:', 'display: none', 'alert(', 'private player', 'tracker.gif', 'member-only shortcode content', '<!--', '&nbsp;',
    'href="//hostile.example.test/resource"', 'href="/courses/../private"',
) as $forbidden_fragment) {
    $assert(false === stripos($sanitized, $forbidden_fragment), 'Sanitizer retained forbidden editor HTML: ' . $forbidden_fragment);
}
$assert(false !== strpos($sanitized, 'Unsafe destination'), 'Sanitizer removed useful link text with an unsafe destination.');
$assert(false !== strpos($sanitized, 'Protocol-relative destination'), 'Sanitizer removed useful protocol-relative link text.');
$assert(false !== strpos($sanitized, 'Traversal destination'), 'Sanitizer removed useful traversal-link text.');
$assert(!preg_match('~<p>\s*(?:<br\s*/?>)?\s*</p>~i', $sanitized), 'Sanitizer retained an empty paragraph.');

foreach (array_merge(MemberLibrary_Content_Model::post_types(), array(MemberLibrary_Content_Model::SPEAKER_POST_TYPE)) as $post_type) {
    $supported_data = wp_slash(array(
        'post_type' => $post_type,
        'post_content' => $source,
    ));
    $saved_data = MemberLibrary_Content_HTML_Sanitizer::sanitize_post_data($supported_data, array());
    $assert(wp_slash($sanitized) === $saved_data['post_content'], sprintf('%s did not sanitize editor content before storage.', $post_type));
}

$speaker_summary_data = wp_slash(array(
    'post_type' => MemberLibrary_Content_Model::SPEAKER_POST_TYPE,
    'post_excerpt' => '<p class="pasted" style="color:red">A short <strong>course-page</strong> biography.</p><style>.leak{color:red}</style>',
));
$saved_speaker_summary_data = MemberLibrary_Content_HTML_Sanitizer::sanitize_post_data($speaker_summary_data, array());
$assert(
    wp_slash('A short course-page biography.') === $saved_speaker_summary_data['post_excerpt'],
    'Speaker Short bio was not reduced to plain text before storage.'
);

$saved_post_id = wp_insert_post(wp_slash(array(
    'post_type' => MemberLibrary_Content_Model::COURSE_POST_TYPE,
    'post_status' => 'draft',
    'post_title' => 'Library editor sanitizer save fixture',
    'post_content' => '<p><a href="/courses/safe-course" class="pasted">Internal Course</a> and <a href="https://example.test/resource" target="_blank">external resource</a>.</p>' . $copied_affiliate_html,
)), true);
$assert(!is_wp_error($saved_post_id), 'Could not create the editor sanitizer save fixture.');
if (!is_wp_error($saved_post_id)) {
    $saved_content = (string) get_post_field('post_content', (int) $saved_post_id);
    $assert(false !== strpos($saved_content, '<a href="/courses/safe-course">Internal Course</a>'), 'A safe internal link did not survive an actual WordPress save.');
    $assert(false !== strpos($saved_content, '<a href="https://example.test/resource">external resource</a>'), 'A safe absolute link did not survive an actual WordPress save.');
    foreach (array('Miracle Morning', 'I was a morning person', 'Published', 'Waiting for Jacob') as $linked_text) {
        $assert(
            (bool) preg_match('~<a href="[^"]+"[^>]*>(?:<em>)?' . preg_quote($linked_text, '~') . '(?:</em>)?</a>~', $saved_content),
            'A copied affiliate or article link did not survive an actual WordPress save: ' . $linked_text
        );
    }
    $assert(false === strpos($saved_content, 'class=') && false === strpos($saved_content, 'target='), 'Presentation or behavior attributes survived an actual WordPress save.');
    wp_delete_post((int) $saved_post_id, true);
}

$unsupported_data = array(
    'post_type' => 'post',
    'post_content' => $source,
);
$assert(
    $unsupported_data === MemberLibrary_Content_HTML_Sanitizer::sanitize_post_data($unsupported_data, array()),
    'Library sanitizer changed an unrelated WordPress post type.'
);

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error('Library editor-HTML sanitizer contract failed.');
}

WP_CLI::success('Library editor-HTML sanitizer contract passed.');
