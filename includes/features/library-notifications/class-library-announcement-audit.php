<?php
/**
 * Bounded, identity-minimized editorial audit for announcement drafts.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Announcement_Audit {

    const MAX_ENTRIES = 100;

    public static function record($post_id, $event, $context = array()) {
        $post_id = absint($post_id);
        $allowed_events = array('draft_created', 'draft_updated', 'audience_changed', 'preview_completed', 'preview_failed', 'publication_blocked', 'self_test_blocked');
        if ($post_id <= 0 || !in_array($event, $allowed_events, true)) {
            return false;
        }
        $safe_context = array();
        foreach (array('definitionHash', 'eligible', 'errorCode') as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }
            $safe_context[$key] = 'eligible' === $key ? max(0, (int) $context[$key]) : sanitize_key((string) $context[$key]);
        }
        $entries = self::entries($post_id);
        $entries[] = array(
            'event' => $event,
            'actorId' => (int) get_current_user_id(),
            'occurredAt' => gmdate('c'),
            'context' => $safe_context,
        );
        $entries = array_slice($entries, -self::MAX_ENTRIES);
        return false !== update_post_meta($post_id, TSOL_Library_Announcement_Model::META_AUDIT, $entries);
    }

    public static function entries($post_id) {
        $entries = get_post_meta(absint($post_id), TSOL_Library_Announcement_Model::META_AUDIT, true);
        return is_array($entries) ? array_values($entries) : array();
    }
}
