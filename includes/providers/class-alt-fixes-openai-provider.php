<?php
/**
 * OpenAI vision provider for Alt Fixes AI.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Alt_Fixes_OpenAI_Provider implements Alt_Fixes_Provider {
    private $api_key;
    private $model;

    public function __construct($api_key, $model = 'gpt-5.6-luna') {
        $this->api_key = trim((string) $api_key);
        $this->model = trim((string) $model) ?: 'gpt-5.6-luna';
    }

    public function suggest($image_data_url, array $context = []) {
        if ($this->api_key === '') {
            return new WP_Error('missing_api_key', 'Configure an OpenAI API key first.', ['status' => 400]);
        }

        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'timeout' => 90,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'model' => $this->model,
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $this->build_prompt($context)],
                        ['type' => 'input_image', 'image_url' => $image_data_url],
                    ],
                ]],
                'max_output_tokens' => 220,
            ]),
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code >= 400) {
            return new WP_Error(
                'provider_error',
                $body['error']['message'] ?? 'OpenAI request failed.',
                ['status' => 502]
            );
        }

        $text = $this->extract_text($body);
        if ($text === '') {
            return new WP_Error('empty_suggestion', 'OpenAI returned no image analysis.', ['status' => 502]);
        }

        $analysis = json_decode($text, true);
        if (!is_array($analysis)) {
            return new WP_Error('invalid_analysis', 'The vision provider returned invalid analysis JSON.', ['status' => 502]);
        }

        $purpose = sanitize_key($analysis['purpose'] ?? 'informative');
        $allowed_purposes = ['informative', 'decorative', 'logo', 'product', 'chart', 'diagram', 'screenshot', 'linked_control', 'text', 'complex'];
        if (!in_array($purpose, $allowed_purposes, true)) {
            $purpose = 'informative';
        }

        $confidence = isset($analysis['confidence']) ? (float) $analysis['confidence'] : 0.5;
        $confidence = max(0, min(1, $confidence));
        $decorative = !empty($analysis['decorative']) || $purpose === 'decorative';
        $alt = sanitize_text_field((string) ($analysis['alt'] ?? ''));

        return [
            'alt' => $decorative ? '' : $alt,
            'purpose' => $purpose,
            'decorative' => $decorative,
            'confidence' => $confidence,
            'review_reason' => sanitize_text_field((string) ($analysis['review_reason'] ?? '')),
            'evidence' => sanitize_text_field((string) ($analysis['evidence'] ?? '')),
            'model' => $this->model,
            'raw' => $body,
        ];
    }

    private function build_prompt(array $context) {
        $json = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "You are an expert accessibility editor performing image-purpose analysis for a real website. Analyze both the pixels and the supplied WordPress context. Return JSON only with exactly these keys: alt, purpose, decorative, confidence, review_reason, evidence.\n\nPurpose must be one of: informative, decorative, logo, product, chart, diagram, screenshot, linked_control, text, complex.\n\nRules:\n- Decide what the image contributes to the page, not just what objects appear in it.\n- If decorative, return alt as an empty string and decorative=true. Do not invent a description for decorative artwork, spacing graphics, or redundant imagery.\n- For informative images, describe the important visual information needed to understand the surrounding content.\n- For logos, identify the organization only when supported by visible text or supplied context.\n- For products, identify the product only when supported by the image/context; mention distinguishing visible features only when useful.\n- For charts/diagrams, summarize the key information rather than describing every visual element; include exact values only when clearly readable.\n- For screenshots, describe the relevant interface/content rather than every UI detail.\n- For linked controls, describe the action or destination/function if the context supports it.\n- If meaningful text is visible, transcribe only the text needed to convey the image's purpose.\n- Never invent names, locations, brands, statistics, dates, relationships, or other facts.\n- Prefer concise alt text, normally 5-15 words; use longer text only when essential. Do not start with 'image of' or 'picture of'.\n- If the image is complex enough that a short alt cannot convey its essential meaning, provide the best concise alt and explain why human review is needed.\n- Confidence must be a number from 0 to 1. Lower confidence when text is unreadable, the purpose is ambiguous, or context conflicts with the image.\n- review_reason should be empty when no human review is needed.\n- evidence should briefly state the visual/context evidence supporting the classification.\n\nWordPress context:\n" . $json;
    }

    private function extract_text(array $body) {
        if (!empty($body['output_text']) && is_string($body['output_text'])) {
            return $this->clean_text($body['output_text']);
        }

        if (!empty($body['output']) && is_array($body['output'])) {
            foreach ($body['output'] as $item) {
                if (!empty($item['content']) && is_array($item['content'])) {
                    foreach ($item['content'] as $content) {
                        if (!empty($content['text']) && is_string($content['text'])) {
                            return $this->clean_text($content['text']);
                        }
                    }
                }
            }
        }

        return '';
    }

    private function clean_text($text) {
        $text = trim((string) $text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        return trim($text);
    }
}
