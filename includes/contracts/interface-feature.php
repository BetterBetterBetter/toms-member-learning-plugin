<?php
/**
 * Feature contract for isolated site-specific features.
 */

if (!defined('ABSPATH')) {
    exit;
}

interface TSOL_Site_Feature {
    public function init();
}
