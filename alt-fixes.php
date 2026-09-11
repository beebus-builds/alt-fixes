<?php
/**
 * Plugin Name: Alt Fixes AI
 * Description: AI-assisted image alt text suggestions with WordPress context and human approval.
 * Version: 0.6.0
 * Author: beebus-builds
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) exit;

define('ALT_FIXES_VERSION', '0.6.0');
define('ALT_FIXES_OPTION', 'alt_fixes_settings');
define('ALT_FIXES_PATH', plugin_dir_path(__FILE__));
define('ALT_FIXES_URL', plugin_dir_url(__FILE__));

require_once ALT_FIXES_PATH . 'includes/providers/interface-alt-fixes-provider.php';
require_once ALT_FIXES_PATH . 'includes/providers/class-alt-fixes-openai-provider.php';
require_once ALT_FIXES_PATH . 'includes/class-alt-fixes-context.php';
require_once ALT_FIXES_PATH . 'includes/class-alt-fixes-engine.php';
require_once ALT_FIXES_PATH . 'includes/class-alt-fixes-queue.php';

register_activation_hook(__FILE__, 'alt_fixes_activate');
function alt_fixes_activate() {
    Alt_Fixes_Queue::install();
}

register_deactivation_hook(__FILE__, 'alt_fixes_deactivate');
function alt_fixes_deactivate() {
    Alt_Fixes_Queue::unschedule_maintenance();
}

// Database upgrades use a versioned install routine instead of running
// dbDelta() on every admin request. The routine itself is cheap when current.
add_action('plugins_loaded', function () {
    Alt_Fixes_Queue::install();
});

// Action Scheduler APIs are only used after its init phase. WP-Cron is also
// scheduled here when Action Scheduler is unavailable.
add_action('init', function () {
    Alt_Fixes_Queue::schedule_maintenance();
}, 20);

add_action('admin_menu', function () {
    $hook = add_media_page('Alt Fixes AI', 'Alt Fixes AI', 'manage_options', 'alt-fixes-ai', 'alt_fixes_render_admin');
    add_action("admin_enqueue_scripts-{$hook}", 'alt_fixes_enqueue_admin_assets');
});

function alt_fixes_enqueue_admin_assets() {
    wp_enqueue_style('alt-fixes-admin', ALT_FIXES_URL . 'admin/assets/admin.css', [], ALT_FIXES_VERSION);
    wp_enqueue_script('alt-fixes-admin', ALT_FIXES_URL . 'admin/assets/admin.js', [], ALT_FIXES_VERSION, true);
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
    $routes = [
        ['/scan', 'GET', 'alt_fixes_scan'],
        ['/suggest/(?P<id>\d+)', 'POST', 'alt_fixes_suggest'],
        ['/approve/(?P<id>\d+)', 'POST', 'alt_fixes_approve'],
        ['/skip/(?P<id>\d+)', 'POST', 'alt_fixes_skip'],
        ['/bulk-suggest', 'POST', 'alt_fixes_bulk_suggest'],
        ['/bulk-approve', 'POST', 'alt_fixes_bulk_approve'],
        ['/jobs/(?P<job_id>\d+)', 'GET', 'alt_fixes_job_status'],
    ];
    foreach ($routes as [$route, $method, $callback]) {
        register_rest_route('alt-fixes/v1', $route, [
            'methods' => $method,
            'permission_callback' => 'alt_fixes_rest_permission',
            'callback' => $callback,
        ]);
    }
});

function alt_fixes_rest_permission() {
    return current_user_can('manage_options');
}

function alt_fixes_get_status($id, $alt = null, $suggestion = null) {
    $stored = (string) get_post_meta($id, '_alt_fixes_status', true);
    if ($stored !== '') return $stored;
    if ($alt === null) $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
    if ($suggestion === null) $suggestion = (string) get_post_meta($id, '_alt_fixes_suggestion', true);
    return trim($alt) === '' ? ($suggestion !== '' ? 'suggested' : 'missing') : 'approved';
}

function alt_fixes_scan(WP_REST_Request $request) {
    $page = max(1, absint($request->get_param('page')) ?: 1);
    $per_page = min(100, max(1, absint($request->get_param('per_page')) ?: 24));
    $status = sanitize_key($request->get_param('status') ?: 'all');
    $allowed = ['all', 'missing', 'queued', 'processing', 'suggested', 'approved', 'skipped', 'failed'];
    if (!in_array($status, $allowed, true)) $status = 'all';

    $ids = get_posts([
        'post_type' => 'attachment', 'post_mime_type' => 'image', 'post_status' => 'inherit',
        'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'DESC',
    ]);

    $filtered = [];
    foreach ($ids as $id) {
        $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $suggestion = (string) get_post_meta($id, '_alt_fixes_suggestion', true);
        $item_status = alt_fixes_get_status($id, $alt, $suggestion);
        if ($status === 'all' || $item_status === $status) $filtered[] = $id;
    }

    $total = count($filtered);
    $pages = $total ? (int) ceil($total / $per_page) : 0;
    $offset = ($page - 1) * $per_page;
    $page_ids = array_slice($filtered, $offset, $per_page);
    $items = [];

    foreach ($page_ids as $id) {
        $alt = (string) get_post_meta($id, '_wp_attachment_image_alt', true);
        $suggestion = (string) get_post_meta($id, '_alt_fixes_suggestion', true);
        $analysis = get_post_meta($id, '_alt_fixes_analysis', true);
        $items[] = [
            'id' => (int) $id,
            'title' => get_the_title($id),
            'url' => wp_get_attachment_image_url($id, 'medium'),
            'alt' => $alt,
            'suggestion' => $suggestion,
            'status' => alt_fixes_get_status($id, $alt, $suggestion),
            'purpose' => is_array($analysis) ? ($analysis['purpose'] ?? null) : null,
            'confidence' => is_array($analysis) && isset($analysis['confidence']) ? (float) $analysis['confidence'] : null,
            'review_reason' => is_array($analysis) ? ($analysis['review_reason'] ?? '') : '',
        ];
    }

    return rest_ensure_response(['items'=>$items,'page'=>$page,'per_page'=>$per_page,'total'=>$total,'pages'=>$pages]);
}

function alt_fixes_validate_image($id) {
    return get_post_type($id) === 'attachment' && strpos((string) get_post_mime_type($id), 'image/') === 0;
}

function alt_fixes_store_suggestion($id, $result) {
    $suggestion = (string) ($result['suggestion'] ?? $result['alt'] ?? '');
    if ($suggestion !== '') {
        update_post_meta($id, '_alt_fixes_suggestion', sanitize_text_field($suggestion));
        update_post_meta($id, '_alt_fixes_status', 'suggested');
    }
    if (!empty($result['analysis']) && is_array($result['analysis'])) update_post_meta($id, '_alt_fixes_analysis', $result['analysis']);
    return $suggestion;
}

function alt_fixes_suggest(WP_REST_Request $request) {
    $id = absint($request['id']);
    if (!alt_fixes_validate_image($id)) return new WP_Error('invalid_image','The requested attachment is not an image.', ['status'=>400]);
    $result = Alt_Fixes_Engine::suggest($id);
    if (is_wp_error($result)) return $result;
    alt_fixes_store_suggestion($id, $result);
    return rest_ensure_response($result);
}

function alt_fixes_approve(WP_REST_Request $request) {
    $id = absint($request['id']);
    if (!alt_fixes_validate_image($id)) return new WP_Error('invalid_attachment','The requested attachment does not exist.', ['status'=>404]);
    $alt = sanitize_text_field($request->get_param('alt'));
    if ($alt === '') $alt = (string) get_post_meta($id, '_alt_fixes_suggestion', true);
    if ($alt === '') return new WP_Error('empty_alt','No alt text supplied.', ['status'=>400]);
    update_post_meta($id, '_wp_attachment_image_alt', $alt);
    update_post_meta($id, '_alt_fixes_status', 'approved');
    delete_post_meta($id, '_alt_fixes_suggestion');
    return rest_ensure_response(['id'=>$id,'alt'=>$alt,'status'=>'approved','approved'=>true]);
}

function alt_fixes_skip(WP_REST_Request $request) {
    $id = absint($request['id']);
    if (!alt_fixes_validate_image($id)) return new WP_Error('invalid_image','The requested attachment is not an image.', ['status'=>400]);
    update_post_meta($id, '_alt_fixes_status', 'skipped');
    return rest_ensure_response(['id'=>$id,'status'=>'skipped']);
}

function alt_fixes_bulk_suggest(WP_REST_Request $request) {
    $ids = $request->get_param('ids');
    if (!is_array($ids)) return new WP_Error('invalid_ids','IDs must be an array.', ['status'=>400]);
    $ids = array_values(array_unique(array_filter(array_map('absint',$ids))));
    if (!$ids || count($ids) > 500) return new WP_Error('batch_limit','Select between 1 and 500 images per queue batch.', ['status'=>400]);

    $job_ids = Alt_Fixes_Queue::enqueue($ids);
    if (!$job_ids) return new WP_Error('queue_error','No valid images could be queued.', ['status'=>400]);

    return rest_ensure_response([
        'queued' => count($job_ids),
        'job_ids' => $job_ids,
        'progress' => Alt_Fixes_Queue::get_progress($job_ids),
    ]);
}

function alt_fixes_job_status(WP_REST_Request $request) {
    $job_id = absint($request['job_id']);
    $jobs = Alt_Fixes_Queue::get_jobs([$job_id]);
    if (!$jobs) return new WP_Error('job_not_found','The requested job was not found.', ['status'=>404]);
    $progress = Alt_Fixes_Queue::get_progress([$job_id]);
    return rest_ensure_response(['job' => $jobs[0], 'progress' => $progress]);
}

function alt_fixes_bulk_approve(WP_REST_Request $request) {
    $items = $request->get_param('items');
    if (!is_array($items) || !$items) return new WP_Error('invalid_items','Items must be supplied.', ['status'=>400]);
    if (count($items) > 25) return new WP_Error('batch_limit','Approve at most 25 images per batch.', ['status'=>400]);
    $results=[];
    foreach ($items as $item) {
        $id=absint($item['id'] ?? 0); $alt=sanitize_text_field($item['alt'] ?? '');
        if (!alt_fixes_validate_image($id) || $alt==='') continue;
        update_post_meta($id,'_wp_attachment_image_alt',$alt);
        update_post_meta($id,'_alt_fixes_status','approved');
        delete_post_meta($id,'_alt_fixes_suggestion');
        $results[]=['id'=>$id,'approved'=>true,'alt'=>$alt];
    }
    return rest_ensure_response(['results'=>$results]);
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
                <tr><th>Provider</th><td><select name="<?php echo esc_attr(ALT_FIXES_OPTION); ?>[provider]"><option value="openai">OpenAI</option></select></td></tr>
                <tr><th>API key</th><td><input type="password" class="regular-text" name="<?php echo esc_attr(ALT_FIXES_OPTION); ?>[api_key]" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>" autocomplete="off"></td></tr>
                <tr><th>Vision model</th><td><input type="text" class="regular-text" name="<?php echo esc_attr(ALT_FIXES_OPTION); ?>[model]" value="<?php echo esc_attr($settings['model'] ?? 'gpt-5.6-luna'); ?>"></td></tr>
            </table>
            <?php submit_button('Save settings'); ?>
        </form>
        <hr>
        <button class="button button-primary" id="alt-fixes-scan">Scan image library</button>
        <div id="alt-fixes-results" style="margin-top:20px"></div>
    </div>
    <?php
}
