<?php
/**
 * Provider contract for Alt Fixes AI.
 */

if (!defined('ABSPATH')) {
    exit;
}

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
