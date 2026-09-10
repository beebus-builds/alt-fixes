# WP Caption API

Local image captioning service for WordPress plugins using Xenova/vit-gpt2-image-captioning with @xenova/transformers.

## Features
- Image-to-text caption / alt text generation offline
- Quantized model (~240MB) matching caption-worker.js setup
- REST endpoint `/caption` accepts multipart file, base64 data URL, or image URL
- CORS enabled for WordPress

## Setup

```bash
cd wp-caption-api
npm install
npm start
```

Server runs on http://localhost:3000

## API

POST /caption

Accepts multipart/form-data with field `image` or JSON body `{ "image": "data:..."} ` or `{ "image": "https://..." }`

Response:
```json
{ "caption": "a dog playing in the park", "alt_text": "a dog playing in the park" }
```

GET /health returns model status.

## WordPress Plugin Integration

PHP example with API key:
```php
function wp_caption_api_generate($image_url) {
    $api = 'http://localhost:3000/caption';
    $api_key = 'change_me_to_a_secure_random_string';
    $response = wp_remote_post($api, [
        'headers' => [
            'Content-Type' => 'application/json',
            'x-api-key' => $api_key
        ],
        'body' => json_encode(['image' => $image_url]),
        'timeout' => 60,
    ]);
    if (is_wp_error($response)) return '';
    $data = json_decode(wp_remote_retrieve_body($response), true);
    return $data['alt_text'] ?? $data['caption'] ?? '';
}
```
Set API_KEY env var in production. If API_KEY is empty, auth is disabled.

Use on upload to set alt text:
```php
add_filter('wp_generate_attachment_metadata', function($metadata, $attachment_id) {
    $url = wp_get_attachment_url($attachment_id);
    $caption = wp_caption_api_generate($url);
    if ($caption) {
        update_post_meta($attachment_id, '_wp_attachment_image_alt', $caption);
    }
    return $metadata;
}, 10, 2);
```

First request loads the model. Keep service running.