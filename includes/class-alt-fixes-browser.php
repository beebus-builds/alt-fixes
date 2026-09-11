<?php
/** Browser-local AI provider helpers. */
if (!defined('ABSPATH')) exit;

class Alt_Fixes_Browser {
    public static function boot() {
        add_action('rest_api_init', [__CLASS__, 'routes']);
    }

    public static function routes() {
        register_rest_route('alt-fixes/v1', '/browser-context/(?P<id>\d+)', [
            'methods' => 'GET',
            'permission_callback' => 'alt_fixes_rest_permission',
            'callback' => [__CLASS__, 'context'],
        ]);
        register_rest_route('alt-fixes/v1', '/browser-suggest/(?P<id>\d+)', [
            'methods' => 'POST',
            'permission_callback' => 'alt_fixes_rest_permission',
            'callback' => [__CLASS__, 'suggest'],
        ]);
    }

    public static function context(WP_REST_Request $request) {
        $id = absint($request['id']);
        if (!alt_fixes_validate_image($id)) {
            return new WP_Error('invalid_image', 'The requested attachment is not an image.', ['status' => 400]);
        }
        return rest_ensure_response([
            'id' => $id,
            'image_url' => wp_get_attachment_image_url($id, 'full'),
            'title' => get_the_title($id),
            'context' => Alt_Fixes_Context::for_attachment($id),
            'provider' => 'browser-local',
            'model' => 'Xenova/vit-gpt2-image-captioning',
        ]);
    }

    public static function suggest(WP_REST_Request $request) {
        $id = absint($request['id']);
        if (!alt_fixes_validate_image($id)) {
            return new WP_Error('invalid_image', 'The requested attachment is not an image.', ['status' => 400]);
        }
        $caption = sanitize_text_field($request->get_param('caption') ?: '');
        if ($caption === '') {
            return new WP_Error('empty_caption', 'The local vision model did not return a caption.', ['status' => 422]);
        }

        $context = Alt_Fixes_Context::for_attachment($id);
        $context['learning'] = Alt_Fixes_Learning::for_prompt($context);
        $alt = self::caption_to_alt($caption, $context);
        $result = Alt_Fixes_Engine::finalize_browser($id, $alt, $caption, $context);
        if (is_wp_error($result)) return $result;
        return rest_ensure_response($result);
    }

    private static function caption_to_alt($caption, array $context) {
        $alt = trim(preg_replace('/\s+/', ' ', $caption));
        $alt = preg_replace('/^(?:a|an|the)\s+(?:photo|photograph|picture|image|graphic)\s+(?:of|showing)\s+/i', '', $alt);
        $alt = preg_replace('/^(?:a|an|the)\s+/i', '', $alt);
        $alt = trim($alt, " \t\n\r\0\x0B.,;:-");

        $rules = $context['learning']['site_rules'] ?? [];
        foreach ((array)($rules['avoid_terms'] ?? []) as $term) {
            $alt = preg_replace('/\b'.preg_quote($term, '/').'\b/i', '', $alt);
        }
        $alt = trim(preg_replace('/\s+/', ' ', $alt));

        if (!empty($rules['preferred_terms'])) {
            // Preferred terms are only guidance; do not invent them when they are absent from the visual caption.
            $alt = $alt;
        }

        $words = preg_split('/\s+/', $alt, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) > 25) $alt = implode(' ', array_slice($words, 0, 25));
        if ($alt !== '') $alt = strtoupper(substr($alt, 0, 1)).substr($alt, 1);
        return $alt;
    }
}

Alt_Fixes_Browser::boot();
