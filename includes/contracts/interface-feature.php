<?php
/**
 * Feature contract for isolated site-specific features.
 */

if (!defined('ABSPATH')) {
    exit;
}

interface MemberLibrary_Feature {
    public function init();
}
