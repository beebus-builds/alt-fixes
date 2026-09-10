<?php
/**
 * Plugin Name: Alt Fixes AI
 * Description: AI-assisted image alt text suggestions with WordPress context and human approval.
 * Version: 0.3.0
 * Author: beebus-builds
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ALT_FIXES_VERSION', '0.3.0');
define('ALT_FIXES_OPTION', 'alt_fixes_settings');
define('ALT_FIXES_PATH', plugin_dir_path(__FILE__));
define('ALT_FIXES_URL', plugin_dir_url(__FILE__));

require_once ALT_FIXES_PATH . 'includes/providers/interface-alt-fixes-provider.php';
require_once ALT_FIXES_PATH . 'includes/providers/class-alt-fixes-openai-provider.php';
require_once ALT_FIXES_PATH . 'includes/class-alt-fixes-context.php';
require_once ALT_FIXES_PATH . 'includes/class-alt-fixes-engine.php';

add_action('admin_menu', function () {
    $hook = add_media_page('Alt Fixes AI', 'Alt Fixes AI', 'manage_options', 'alt-fixes-ai', 'alt_fixes_render_admin');
    add_action("admin_enqueue_scripts-{$hook}", 'alt_fixes_enqueue_admin_assets');
});

function alt_fixes_enqueue_admin_assets() {
    wp_enqueue_style(
        'alt-fixes-admin',
        ALT_FIXES_URL . 'admin/assets/admin.css',
        [],
        ALT_FIXES_VERSION
    );

    wp_enqueue_script(
        'alt-fixes-admin',
        ALT_FIXES_URL . 'admin/assets/admin.js',
        [],
        ALT_FIXES_VERSION,
        true
    );

    wp_localize_script('alt-fixes-admin', 'AltFixesAdmin', [
        'root' => trailingslashit(rest_url('alt-fixes/v1')),
        'nonce' => wp_create_nonce('wp_rest'),
    ]);
}

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
        'permission_callback' => 'alt_fixes_rest_permission',
        'callback' => 'alt_fixes_scan',
        'args' => [
            'page' => ['default' => 1, 'sanitize_callback' => 'absint'],
            'per_page' => ['default' => 50, 'sanitize_callback' => 'absint'],
            'status' => ['default' => 'all', 'sanitize_callback' => 'sanitize_key'],
        ],
    ]);

    register_rest_route('alt-fixes/v1', '/suggest/(?P<id>\d+)', [
        'methods' => 'POST',
        'permission_callback' => 'alt_fixes_rest_permission',
        'callback' => 'alt_fixes_suggest',
    ]);

    register_rest_route('alt-fixes/v1', '/approve/(?P<id>\d+)', [
        'methods' => 'POST',
        'permission_callback' => 'alt_fixes_rest_permission',
        'callback' => 'alt_fixes_approve',
    ]);

    register_rest_route('alt-fixes/v1', '/skip/(?P<id>\d+)', [
        'methods' => 'POST',
        'permission_callback' => 'alt_fixes_rest_permission',
        'callback' => 'alt_fixes_skip',
    ]);
});

function alt_fixes_rest_permission() {
    return current_user_can('upload_files');
}

function alt_fixes_scan(WP_REST_Request $request) {
    $page = max(1, absint($request->get_param('page')) ?: 1);
    $per_page = min(100, max(1, absint($request->get_param('per_page')) ?: 50));
    $status = sanitize_key($request->get_param('status') ?: 'all');
    $allowed_statuses = ['all', 'missing', 'suggested', 'approved', 'skipped'];

    if (!in_array($status, $allowed_statuses, true)) {
        $status = 'all';
    }

    $meta_query = [];
    if ($status === 'missing') {
        $meta_query[] = [
            'relation' => 'OR',
            ['key' => '_alt_fixes_status', 'compare' => 'NOT EXISTS'],
            ['key' => '_alt_fixes_status', 'value' => 'missing'],
        ];
    } elseif ($status !== 'all') {
        $meta_query[] = [
            'key' => '_alt_fixes_status',
            'value' => $status,
        ];
    }

    $query_args = [
        'post_type' => 'attachment',
        'post_mime_type' => 'image',
        'post_status' => 'inherit',
        'posts_per_page' => $per_page,
        'paged' => $page,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'DESC',
    ];

    if ($meta_query) {
        $query_args['meta_query'] = $meta_query;
    }

    $query = new WP_Query($query_args);
    $items = [];

    foreach ($query->posts as $id) {
        $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $suggestion = (string) get_post_meta($id, '_alt_fixes_suggestion', true);
        $analysis = get_post_meta($id, '_alt_fixes_analysis', true);
        $stored_status = (string) get_post_meta($id, '_alt_fixes_status', true);

        if ($stored_status === '') {
            $stored_status = $alt !== '' ? 'approved' : ($suggestion !== '' ? 'suggested' : 'missing');
        }

        $items[] = [
            'id' => (int) $id,
            'title' => get_the_title($id),
            'url' => wp_get_attachment_image_url($id, 'medium'),
            'alt' => $alt,
            'suggestion' => $suggestion,
            'analysis' => is_array($analysis) ? $analysis : null,
            'status' => $stored_status,
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

    $result = Alt_Fixes_Engine::suggest($id);
    if (is_wp_error($result)) {
        return $result;
    }

    $suggestion = (string) ($result['suggestion'] ?? $result['alt'] ?? '');
    if ($suggestion !== '') {
        update_post_meta($id, '_alt_fixes_suggestion', $suggestion);
        update_post_meta($id, '_alt_fixes_status', 'suggested');
    }

    if (isset($result['analysis']) && is_array($result['analysis'])) {
        update_post_meta($id, '_alt_fixes_analysis', $result['analysis']);
    }

    return rest_ensure_response($result);
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
    update_post_meta($id, '_alt_fixes_status', 'approved');
    delete_post_meta($id, '_alt_fixes_suggestion');

    return rest_ensure_response([
        'id' => $id,
        'alt' => $suggestion,
        'status' => 'approved',
        'approved' => true,
    ]);
}

function alt_fixes_skip(WP_REST_Request $request) {
    $id = absint($request['id']);
    if (get_post_type($id) !== 'attachment') {
        return new WP_Error('invalid_attachment', 'The requested attachment does not exist.', ['status' => 404]);
    }

    update_post_meta($id, '_alt_fixes_status', 'skipped');

    return rest_ensure_response([
        'id' => $id,
        'status' => 'skipped',
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
    <?php
}
