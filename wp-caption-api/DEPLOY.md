# Deploy to Railway / Render

## Railway
1. Install Railway CLI and login
2. railway init, select Empty Project
3. railway up
4. Set env vars:
   - API_KEY = your secret
   - PORT = 3000
5. Railway provides a public URL e.g. https://wp-caption-api.up.railway.app

## Render
1. New Web Service from Git repo
2. Build Command: npm install
3. Start Command: node server.js
4. Add Environment Variable API_KEY
5. Render gives you https://wp-caption-api.onrender.com

## WordPress plugin call
```php
$api = 'https://wp-caption-api.onrender.com/caption';
$api_key = 'change_me_to_a_secure_random_string';

$response = wp_remote_post($api, [
    'headers' => [
        'Content-Type' => 'application/json',
        'x-api-key' => $api_key
    ],
    'body' => wp_json_encode(['image' => $image_url]),
    'timeout' => 60,
]);
```

First request will load model ~30-60s. Keep service warm with a cron ping to /health.