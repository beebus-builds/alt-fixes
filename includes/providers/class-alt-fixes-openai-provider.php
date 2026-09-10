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

        $prompt = $this->build_prompt($context);
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
                        ['type' => 'input_text', 'text' => $prompt],
                        ['type' => 'input_image', 'image_url' => $image_data_url],
                    ],
                ]],
                'max_output_tokens' => 120,
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
            return new WP_Error('empty_suggestion', 'OpenAI returned no alt-text suggestion.', ['status' => 502]);
        }

        return [
            'alt' => $text,
            'raw' => $body,
            'model' => $this->model,
        ];
    }

    private function build_prompt(array $context) {
        $json = wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return "You are an accessibility-focused image alt-text editor. Analyze the image and the supplied WordPress context. Return JSON only with keys: alt, purpose, decorative, confidence, review_reason.\n\nRules:\n- Describe the image's meaningful visual information and its purpose in context.\n- Prefer concise alt text, normally around 5-15 words; use more only when the image genuinely needs it.\n- Do not begin with 'image of', 'picture of', or similar filler.\n- Never invent identities, locations, numbers, brands, or facts that cannot be supported by the image/context.\n- If the image is purely decorative and adds no information, set decorative to true and alt to an empty string.\n- If the image contains meaningful readable text, include the relevant text when necessary for understanding.\n- If the image is a logo, product, chart, screenshot, linked control, or other functional image, describe its function rather than merely listing visual details.\n- Set confidence from 0 to 1.\n- Set review_reason when human review is advisable; otherwise use an empty string.\n\nWordPress context:\n" . $json;
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
        $decoded = json_decode($text, true);
        if (is_array($decoded) && isset($decoded['alt'])) {
            return sanitize_text_field((string) $decoded['alt']);
        }
        return sanitize_text_field($text);
    }
}
