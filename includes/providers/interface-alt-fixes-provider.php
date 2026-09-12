<?php
/**
 * Provider contract for Alt Fixes AI.
 */

if (!defined('ABSPATH')) {
    exit;
}

// Load the diagnostic guard before any of the other plugin classes. This lets
// the plugin capture parse/compile/runtime fatals that happen during loading.
require_once dirname(__DIR__) . '/class-alt-fixes-diagnostics.php';

interface Alt_Fixes_Provider {
    /**
     * Generate a structured alt-text suggestion from an image and context.
     *
     * @param string $image_data_url Data URL containing the image.
     * @param array  $context        WordPress context for the image.
     * @return array|\WP_Error
     */
    public function suggest($image_data_url, array $context = []);
}
