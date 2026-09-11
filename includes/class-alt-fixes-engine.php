<?php
/**
 * Core alt-text generation engine.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Alt_Fixes_Engine {
    public static function suggest($attachment_id) {
        $attachment_id = absint($attachment_id);
        $file = get_attached_file($attachment_id);

        if (!$file || !file_exists($file)) {
            return new WP_Error('missing_image', 'Image file could not be found.', ['status' => 404]);
        }

        $settings = get_option(ALT_FIXES_OPTION, []);
        $provider_name = $settings['provider'] ?? 'openai';
        $context = Alt_Fixes_Context::for_attachment($attachment_id);

        $bytes = file_get_contents($file);
        if ($bytes === false) {
            return new WP_Error('read_error', 'The image file could not be read.', ['status' => 500]);
        }

        $mime = get_post_mime_type($attachment_id) ?: 'image/jpeg';
        $data_url = 'data:' . $mime . ';base64,' . base64_encode($bytes);
        $provider = self::provider($provider_name, $settings);

        if (is_wp_error($provider)) {
            return $provider;
        }

        $result = $provider->suggest($data_url, $context);
        if (is_wp_error($result)) {
            return $result;
        }

        $analysis = [
            'purpose' => sanitize_key($result['purpose'] ?? 'informative'),
            'decorative' => !empty($result['decorative']),
            'confidence' => isset($result['confidence']) ? max(0, min(1, (float) $result['confidence'])) : null,
            'review_reason' => sanitize_text_field($result['review_reason'] ?? ''),
            'evidence' => sanitize_text_field($result['evidence'] ?? ''),
            'model' => sanitize_text_field($result['model'] ?? ''),
            'generated_at' => current_time('mysql', true),
        ];

        $alt = sanitize_text_field($result['alt'] ?? '');
        $is_decorative = !empty($analysis['decorative']);
        if ($alt === '' && !$is_decorative) {
            return new WP_Error('empty_suggestion', 'The AI provider returned an empty suggestion.', ['status' => 502]);
        }

        update_post_meta($attachment_id, '_alt_fixes_suggestion', $alt);
        update_post_meta($attachment_id, '_alt_fixes_analysis', $analysis);

        return [
            'id' => $attachment_id,
            'suggestion' => $alt,
            'analysis' => $analysis,
            'context' => $context,
        ];
    }

    private static function provider($name, array $settings) {
        switch ($name) {
            case 'openai':
                return new Alt_Fixes_OpenAI_Provider(
                    $settings['api_key'] ?? '',
                    $settings['model'] ?? 'gpt-5.6-luna'
                );
            default:
                return new WP_Error('unsupported_provider', 'The selected AI provider is not implemented.', ['status' => 400]);
        }
    }
}
