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
            'ocr_model' => 'Xenova/trocr-small-printed',
        ]);
    }

    public static function suggest(WP_REST_Request $request) {
        $id = absint($request['id']);
        if (!alt_fixes_validate_image($id)) {
            return new WP_Error('invalid_image', 'The requested attachment is not an image.', ['status' => 400]);
        }
        $caption = sanitize_text_field($request->get_param('caption') ?: '');
        $ocr_text = sanitize_textarea_field($request->get_param('ocr_text') ?: '');
        if ($caption === '') {
            return new WP_Error('empty_caption', 'The local vision model did not return a caption.', ['status' => 422]);
        }

        $context = Alt_Fixes_Context::for_attachment($id);
        $context['learning'] = Alt_Fixes_Learning::for_prompt($context);
        $alt = self::caption_to_alt($caption, $ocr_text, $context);
        $result = Alt_Fixes_Engine::finalize_browser($id, $alt, $caption, $ocr_text, $context);
        if (is_wp_error($result)) return $result;
        return rest_ensure_response($result);
    }

    private static function caption_to_alt($caption, $ocr_text, array $context) {
        $alt = trim(preg_replace('/\s+/', ' ', $caption));
        $alt = preg_replace('/^(?:a|an|the)\s+(?:photo|photograph|picture|image|graphic)\s+(?:of|showing)\s+/i', '', $alt);
        $alt = preg_replace('/^(?:a|an|the)\s+/i', '', $alt);
        $alt = trim($alt, " \t\n\r\0\x0B.,;:-");

        $ocr = trim(preg_replace('/\s+/', ' ', $ocr_text));
        if ($ocr !== '' && self::caption_needs_ocr($alt, $ocr)) {
            $ocr_excerpt = mb_substr($ocr, 0, 80);
            $alt = $alt !== '' ? $alt . ' — ' . $ocr_excerpt : $ocr_excerpt;
        }

        $rules = $context['learning']['site_rules'] ?? [];
        foreach ((array)($rules['avoid_terms'] ?? []) as $term) {
            $alt = preg_replace('/\b'.preg_quote($term, '/').'\b/i', '', $alt);
        }
        $alt = trim(preg_replace('/\s+/', ' ', $alt));

        $words = preg_split('/\s+/', $alt, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) > 25) $alt = implode(' ', array_slice($words, 0, 25));
        if ($alt !== '') $alt = strtoupper(substr($alt, 0, 1)).substr($alt, 1);
        return $alt;
    }

    private static function caption_needs_ocr($caption, $ocr) {
        if ($ocr === '') return false;
        if (strlen($caption) < 12) return true;
        if (preg_match('/\b(?:text|sign|logo|banner|screenshot|poster|screen|website|menu|document|receipt|label|headline|title)\b/i', $caption)) return true;
        return (bool)preg_match('/\b(?:https?:\/\/|www\.|\.com\b|\.org\b|\.net\b|[A-Z]{2,}\d{2,})/i', $ocr);
    }
}

Alt_Fixes_Browser::boot();
