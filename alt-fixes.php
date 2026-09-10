<?php
/**
 * Plugin Name: Alt Fixes AI
 * Description: AI-assisted image alt text suggestions with WordPress context and human approval.
 * Version: 0.2.0
 * Author: beebus-builds
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ALT_FIXES_VERSION', '0.2.0');
define('ALT_FIXES_OPTION', 'alt_fixes_settings');
define('ALT_FIXES_PATH', plugin_dir_path(__FILE__));

require_once ALT_FIXES_PATH . 'includes/providers/interface-alt-fixes-provider.php';
require_once ALT_FIXES_PATH . 'includes/providers/class-alt-fixes-openai-provider.php';
require_once ALT_FIXES_PATH . 'includes/class-alt-fixes-context.php';
require_once ALT_FIXES_PATH . 'includes/class-alt-fixes-engine.php';

add_action('admin_menu', function () {
    add_media_page('Alt Fixes AI', 'Alt Fixes AI', 'manage_options', 'alt-fixes-ai', 'alt_fixes_render_admin');
});

add_action('admin_init', function () {
    register_setting('alt_fixes', ALT_FIXES_OPTION, [
        'sanitize_callback' => function ($value) {
            return [
                'provider' => sanitize_key($value['provider'] ?? 'openai'),
                'api_key' => sanitize_text_field($value['api_key'] ?? ''),
                'model' => sanitize_text_field($value['model'] ?? 'gpt-5.6-luna'),
            ];
        },
    ]);
});

add_action('rest_api_init', function () {
    register_rest_route('alt-fixes/v1', '/scan', [
        'methods' => 'GET',
        'permission_callback' => function () {
            return current_user_can('upload_files');
        },
        'callback' => 'alt_fixes_scan',
    ]);

    register_rest_route('alt-fixes/v1', '/suggest/(?P<id>\d+)', [
        'methods' => 'POST',
        'permission_callback' => function () {
            return current_user_can('upload_files');
        },
        'callback' => 'alt_fixes_suggest',
    ]);

    register_rest_route('alt-fixes/v1', '/approve/(?P<id>\d+)', [
        'methods' => 'POST',
        'permission_callback' => function () {
            return current_user_can('upload_files');
        },
        'callback' => 'alt_fixes_approve',
    ]);
});

function alt_fixes_scan(WP_REST_Request $request) {
    $page = max(1, absint($request->get_param('page')));
    $per_page = min(100, max(1, absint($request->get_param('per_page')) ?: 50));

    $query = new WP_Query([
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'DESC',
    ]);

    $items = [];
    foreach ($query->posts as $id) {
        $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $suggestion = (string) get_post_meta($id, '_alt_fixes_suggestion', true);
        $analysis = get_post_meta($id, '_alt_fixes_analysis', true);

        $items[] = [
            'id' => (int) $id,
            'title' => get_the_title($id),
            'url' => wp_get_attachment_image_url($id, 'medium'),
            'alt' => $alt,
            'suggestion' => $suggestion,
            'analysis' => is_array($analysis) ? $analysis : null,
            'needs_alt' => trim($alt) === '',
            'has_suggestion' => $suggestion !== '',
        ];
    }

    return rest_ensure_response([
        'items' => $items,
        'page' => $page,
        'per_page' => $per_page,
        'total' => (int) $query->found_posts,
        'pages' => (int) $query->max_num_pages,
    ]);
}

function alt_fixes_suggest(WP_REST_Request $request) {
    $id = absint($request['id']);
    if (get_post_type($id) !== 'attachment' || strpos((string) get_post_mime_type($id), 'image/') !== 0) {
        return new WP_Error('invalid_image', 'The requested attachment is not an image.', ['status' => 400]);
    }

    return rest_ensure_response(Alt_Fixes_Engine::suggest($id));
}

function alt_fixes_approve(WP_REST_Request $request) {
    $id = absint($request['id']);
    if (get_post_type($id) !== 'attachment') {
        return new WP_Error('invalid_attachment', 'The requested attachment does not exist.', ['status' => 404]);
    }

    $suggestion = sanitize_text_field($request->get_param('alt'));
    if ($suggestion === '') {
        $suggestion = (string) get_post_meta($id, '_alt_fixes_suggestion', true);
    }
    if ($suggestion === '') {
        return new WP_Error('empty_alt', 'No alt text supplied.', ['status' => 400]);
    }

    update_post_meta($id, '_wp_attachment_image_alt', $suggestion);
    delete_post_meta($id, '_alt_fixes_suggestion');

    return rest_ensure_response([
        'id' => $id,
        'alt' => $suggestion,
        'approved' => true,
    ]);
}

function alt_fixes_render_admin() {
    $settings = get_option(ALT_FIXES_OPTION, []);
    ?>
    <div class="wrap">
        <h1>Alt Fixes AI</h1>
        <p>Analyze your media library and generate context-aware, human-reviewable alt text suggestions.</p>
        <form method="post" action="options.php">
            <?php settings_fields('alt_fixes'); ?>
            <table class="form-table">
                <tr>
                    <th>Provider</th>
                    <td><select name="<?php echo esc_attr(ALT_FIXES_OPTION); ?>[provider]"><option value="openai">OpenAI</option></select></td>
                </tr>
                <tr>
                    <th>API key</th>
                    <td><input type="password" class="regular-text" name="<?php echo esc_attr(ALT_FIXES_OPTION); ?>[api_key]" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" autocomplete="off"></td>
                </tr>
                <tr>
                    <th>Vision model</th>
                    <td><input type="text" class="regular-text" name="<?php echo esc_attr(ALT_FIXES_OPTION); ?>[model]" value="<?php echo esc_attr($settings['model'] ?? 'gpt-5.6-luna'); ?>"></td>
                </tr>
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
      const nonce = '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>';
      const api = '<?php echo esc_js(rest_url('alt-fixes/v1')); ?>';

      document.getElementById('alt-fixes-scan').onclick = async () => {
        root.textContent = 'Scanning…';
        try {
          const r = await fetch(`${api}/scan?per_page=50`, {credentials:'same-origin', headers:{'X-WP-Nonce': nonce}});
          const data = await r.json();
          if (!r.ok) throw new Error(data.message || 'Scan failed');

          root.innerHTML = data.items.filter(x => x.needs_alt).map(x => `
            <div style="display:flex;gap:12px;align-items:center;margin:10px 0;padding:10px;background:#fff;border:1px solid #ddd">
              <img src="${x.url || ''}" width="80" height="80" style="object-fit:cover">
              <div>
                <strong>${escapeHtml(x.title || '(untitled)')}</strong><br>
                <button class="button suggest" data-id="${x.id}">Suggest alt text</button>
                <button class="button approve" data-id="${x.id}" style="display:none">Approve</button>
                <span class="result" style="margin-left:10px"></span>
              </div>
            </div>`).join('') || '<p>No images without alt text were found on this page.</p>';

          root.querySelectorAll('.suggest').forEach(btn => btn.onclick = () => suggest(btn));
          root.querySelectorAll('.approve').forEach(btn => btn.onclick = () => approve(btn));
        } catch (error) {
          root.textContent = error.message;
        }
      };

      async function suggest(btn) {
        const row = btn.parentElement;
        const result = row.querySelector('.result');
        const approveBtn = row.querySelector('.approve');
        btn.disabled = true;
        result.textContent = 'Analyzing image + page context…';
        try {
          const r = await fetch(`${api}/suggest/${btn.dataset.id}`, {method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': nonce}});
          const data = await r.json();
          if (!r.ok) throw new Error(data.message || 'Suggestion failed');
          result.textContent = data.suggestion || '';
          approveBtn.style.display = data.suggestion ? 'inline-block' : 'none';
        } catch (error) {
          result.textContent = error.message;
        } finally {
          btn.disabled = false;
        }
      }

      async function approve(btn) {
        const row = btn.parentElement;
        const result = row.querySelector('.result');
        btn.disabled = true;
        try {
          const r = await fetch(`${api}/approve/${btn.dataset.id}`, {
            method:'POST', credentials:'same-origin', headers:{'X-WP-Nonce': nonce, 'Content-Type':'application/json'},
            body: JSON.stringify({alt: result.textContent.trim()})
          });
          const data = await r.json();
          if (!r.ok) throw new Error(data.message || 'Approval failed');
          result.textContent = `Approved: ${data.alt}`;
          btn.style.display = 'none';
          row.querySelector('.suggest').style.display = 'none';
        } catch (error) {
          result.textContent = error.message;
          btn.disabled = false;
        }
      }

      function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
      }
    })();
    </script>
    <?php
}
