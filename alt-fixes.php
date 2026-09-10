<?php
/**
 * Plugin Name: Alt Fixes AI
 * Description: AI-assisted image alt text suggestions with WordPress context and human approval.
 * Version: 0.1.0
 * Author: beebus-builds
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

define('ALT_FIXES_VERSION', '0.1.0');

define('ALT_FIXES_OPTION', 'alt_fixes_settings');

add_action('admin_menu', function () {
    add_media_page('Alt Fixes AI', 'Alt Fixes AI', 'manage_options', 'alt-fixes-ai', 'alt_fixes_render_admin');
});

add_action('admin_init', function () {
    register_setting('alt_fixes', ALT_FIXES_OPTION, [
        'sanitize_callback' => function ($value) {
            return [
                'provider' => sanitize_text_field($value['provider'] ?? 'openai'),
                'api_key' => sanitize_text_field($value['api_key'] ?? ''),
                'model' => sanitize_text_field($value['model'] ?? 'gpt-4o-mini'),
            ];
        },
    ]);
});

add_action('rest_api_init', function () {
    register_rest_route('alt-fixes/v1', '/scan', [
        'methods' => 'GET',
        'permission_callback' => function () { return current_user_can('upload_files'); },
        'callback' => 'alt_fixes_scan',
    ]);

    register_rest_route('alt-fixes/v1', '/suggest/(?P<id>\d+)', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('upload_files'); },
        'callback' => 'alt_fixes_suggest',
    ]);

    register_rest_route('alt-fixes/v1', '/approve/(?P<id>\d+)', [
        'methods' => 'POST',
        'permission_callback' => function () { return current_user_can('upload_files'); },
        'callback' => 'alt_fixes_approve',
    ]);
});

function alt_fixes_scan() {
    $ids = get_posts([
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'posts_per_page' => 100,
        'fields' => 'ids',
    ]);

    $items = [];
    foreach ($ids as $id) {
        $alt = get_post_meta($id, '_wp_attachment_image_alt', true);
        $items[] = [
            'id' => $id,
            'title' => get_the_title($id),
            'url' => wp_get_attachment_image_url($id, 'medium'),
            'alt' => $alt,
            'needs_alt' => trim((string) $alt) === '',
        ];
    }

    return rest_ensure_response($items);
}

function alt_fixes_suggest(WP_REST_Request $request) {
    $id = absint($request['id']);
    $file = get_attached_file($id);
    if (!$file || !file_exists($file)) {
        return new WP_Error('missing_image', 'Image file could not be found.', ['status' => 404]);
    }

    $settings = get_option(ALT_FIXES_OPTION, []);
    if (empty($settings['api_key'])) {
        return new WP_Error('missing_api_key', 'Configure an AI provider API key first.', ['status' => 400]);
    }

    $mime = get_post_mime_type($id);
    $bytes = file_get_contents($file);
    $data_url = 'data:' . $mime . ';base64,' . base64_encode($bytes);
    $title = get_the_title($id);
    $prompt = 'Generate concise, accurate WCAG-friendly alt text for this image. Describe the meaningful visual content, not the file name. Do not start with "image of" or "picture of". Return only the suggested alt text. Image title/context: ' . $title;

    if (($settings['provider'] ?? 'openai') !== 'openai') {
        return new WP_Error('unsupported_provider', 'Only the OpenAI adapter is implemented in this MVP.', ['status' => 400]);
    }

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'timeout' => 60,
        'headers' => [
            'Authorization' => 'Bearer ' . $settings['api_key'],
            'Content-Type' => 'application/json',
        ],
        'body' => wp_json_encode([
            'model' => $settings['model'] ?? 'gpt-4o-mini',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $data_url]],
                ],
            ]],
            'max_tokens' => 100,
        ]),
    ]);

    if (is_wp_error($response)) return $response;
    $code = wp_remote_retrieve_response_code($response);
    $body = json_decode(wp_remote_retrieve_body($response), true);
    if ($code >= 400) {
        return new WP_Error('provider_error', $body['error']['message'] ?? 'AI provider request failed.', ['status' => 502]);
    }

    $suggestion = trim($body['choices'][0]['message']['content'] ?? '');
    if ($suggestion === '') return new WP_Error('empty_suggestion', 'The AI provider returned no suggestion.', ['status' => 502]);

    update_post_meta($id, '_alt_fixes_suggestion', sanitize_text_field($suggestion));
    return rest_ensure_response(['id' => $id, 'suggestion' => $suggestion]);
}

function alt_fixes_approve(WP_REST_Request $request) {
    $id = absint($request['id']);
    $suggestion = sanitize_text_field($request->get_param('alt'));
    if ($suggestion === '') {
        $suggestion = get_post_meta($id, '_alt_fixes_suggestion', true);
    }
    if ($suggestion === '') return new WP_Error('empty_alt', 'No alt text supplied.', ['status' => 400]);

    update_post_meta($id, '_wp_attachment_image_alt', $suggestion);
    delete_post_meta($id, '_alt_fixes_suggestion');
    return rest_ensure_response(['id' => $id, 'alt' => $suggestion, 'approved' => true]);
}

function alt_fixes_render_admin() {
    $settings = get_option(ALT_FIXES_OPTION, []);
    ?>
    <div class="wrap">
        <h1>Alt Fixes AI</h1>
        <p>Analyze your media library and generate human-reviewable alt text suggestions.</p>
        <form method="post" action="options.php">
            <?php settings_fields('alt_fixes'); ?>
            <table class="form-table">
                <tr><th>Provider</th><td><select name="<?php echo esc_attr(ALT_FIXES_OPTION); ?>[provider]"><option value="openai">OpenAI</option></select></td></tr>
                <tr><th>API key</th><td><input type="password" class="regular-text" name="<?php echo esc_attr(ALT_FIXES_OPTION); ?>[api_key]" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" autocomplete="off"></td></tr>
                <tr><th>Vision model</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(ALT_FIXES_OPTION); ?>[model]" value="<?php echo esc_attr($settings['model'] ?? 'gpt-4o-mini'); ?>"></td></tr>
            </table>
            <?php submit_button('Save settings'); ?>
        </form>
        <hr>
        <button class="button button-primary" id="alt-fixes-scan">Scan image library</button>
        <div id="alt-fixes-results" style="margin-top:20px"></div>
    </div>
    <script>
    (() => {
      const root = document.getElementById('alt-fixes-results');
      document.getElementById('alt-fixes-scan').onclick = async () => {
        root.textContent = 'Scanning…';
        const r = await fetch(ajaxurl.replace('admin-ajax.php','wp-json/alt-fixes/v1/scan'), {credentials:'same-origin'});
        const items = await r.json();
        root.innerHTML = items.filter(x => x.needs_alt).map(x => `<div style="display:flex;gap:12px;align-items:center;margin:10px 0;padding:10px;background:#fff;border:1px solid #ddd"><img src="${x.url || ''}" width="80" height="80" style="object-fit:cover"><div><strong>${x.title || '(untitled)'}</strong><br><button class="button suggest" data-id="${x.id}">Suggest alt text</button><span class="result" style="margin-left:10px"></span></div></div>`).join('') || '<p>No images without alt text were found in the first 100 images.</p>';
        root.querySelectorAll('.suggest').forEach(btn => btn.onclick = async () => {
          const result = btn.parentElement.querySelector('.result'); btn.disabled=true; result.textContent='Analyzing…';
          const r = await fetch(`${location.origin}${location.pathname.replace(/[^/]*$/, '')}wp-json/alt-fixes/v1/suggest/${btn.dataset.id}`, {method:'POST',credentials:'same-origin',headers:{'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'}});
          const data = await r.json(); result.textContent = data.suggestion ? ` ${data.suggestion}` : ` ${data.message || 'Failed'}`; btn.disabled=false;
        });
      };
    })();
    </script>
    <?php
}
