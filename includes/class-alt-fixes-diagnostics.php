<?php
/** Self-diagnostics and fatal-error reporting for Alt Fixes AI. */
if (!defined('ABSPATH')) exit;

if (!class_exists('Alt_Fixes_Diagnostics')) {
class Alt_Fixes_Diagnostics {
    const LOG_FILE = 'alt-fixes-debug.log';
    const OPTION = 'alt_fixes_last_error';

    public static function boot() {
        register_shutdown_function([__CLASS__, 'capture_fatal']);
        add_action('admin_menu', [__CLASS__, 'admin_menu'], 99);
        add_action('admin_notices', [__CLASS__, 'admin_notice']);
    }

    public static function capture_fatal() {
        $error = error_get_last();
        if (!$error || empty($error['type'])) return;
        $fatal_types = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array((int)$error['type'], $fatal_types, true)) return;

        $data = [
            'time' => current_time('mysql', true),
            'type' => self::type_name($error['type']),
            'message' => isset($error['message']) ? (string)$error['message'] : '',
            'file' => isset($error['file']) ? (string)$error['file'] : '',
            'line' => isset($error['line']) ? (int)$error['line'] : 0,
            'php' => PHP_VERSION,
            'wp' => defined('WP_VERSION') ? WP_VERSION : 'unknown',
            'plugin_version' => defined('ALT_FIXES_VERSION') ? ALT_FIXES_VERSION : 'unknown',
            'memory' => function_exists('memory_get_usage') ? memory_get_usage(true) : 0,
        ];

        $payload = wp_json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($payload) {
            $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : [];
            if (!empty($uploads['basedir'])) {
                @file_put_contents(trailingslashit($uploads['basedir']) . self::LOG_FILE, $payload . PHP_EOL, FILE_APPEND | LOCK_EX);
            }
            @error_log('[Alt Fixes AI] ' . $payload);
        }
        if (function_exists('update_option')) {
            update_option(self::OPTION, $data, false);
        }
    }

    public static function type_name($type) {
        $map = [E_ERROR=>'E_ERROR', E_PARSE=>'E_PARSE', E_CORE_ERROR=>'E_CORE_ERROR', E_COMPILE_ERROR=>'E_COMPILE_ERROR', E_USER_ERROR=>'E_USER_ERROR'];
        return isset($map[$type]) ? $map[$type] : 'PHP_ERROR_' . (int)$type;
    }

    public static function admin_menu() {
        add_media_page('Alt Fixes Diagnostics', 'Diagnostics', 'manage_options', 'alt-fixes-diagnostics', [__CLASS__, 'render']);
    }

    public static function admin_notice() {
        if (!current_user_can('manage_options')) return;
        $error = get_option(self::OPTION, []);
        if (!is_array($error) || empty($error['message'])) return;
        echo '<div class="notice notice-error"><p><strong>Alt Fixes AI found a previous fatal error.</strong> ' . esc_html($error['type'] . ': ' . $error['message']) . '</p><p>Open <a href="' . esc_url(admin_url('upload.php?page=alt-fixes-diagnostics')) . '">Alt Fixes Diagnostics</a> for the exact file, line, PHP version and environment checks.</p></div>';
    }

    public static function run_checks() {
        $checks = [];
        $checks['php_version'] = [version_compare(PHP_VERSION, '7.4', '>='), 'PHP ' . PHP_VERSION . ' (recommended minimum: 7.4)'];
        $checks['mbstring'] = [function_exists('mb_substr'), function_exists('mb_substr') ? 'mbstring available' : 'mbstring missing; OCR/alt cleanup may fail'];
        $checks['json'] = [function_exists('json_encode'), function_exists('json_encode') ? 'JSON available' : 'JSON extension missing'];
        $required = [
            'includes/providers/interface-alt-fixes-provider.php',
            'includes/providers/class-alt-fixes-openai-provider.php',
            'includes/class-alt-fixes-context.php',
            'includes/class-alt-fixes-engine.php',
            'includes/class-alt-fixes-queue.php',
            'includes/class-alt-fixes-browser.php',
        ];
        foreach ($required as $file) {
            $path = ALT_FIXES_PATH . $file;
            $checks['file_' . md5($file)] = [file_exists($path) && is_readable($path), $file . (file_exists($path) ? ' exists' : ' is missing')];
        }
        $loaded = [];
        foreach (['Alt_Fixes_Queue','Alt_Fixes_Engine','Alt_Fixes_Browser','Alt_Fixes_Context','Alt_Fixes_OpenAI_Provider'] as $class) {
            if (class_exists($class)) $loaded[] = $class;
        }
        $checks['classes'] = [count($loaded) === 5, 'Core classes loaded: ' . ($loaded ? implode(', ', $loaded) : 'none')];
        $checks['rest_request'] = [class_exists('WP_REST_Request'), 'WP_REST_Request available'];
        $checks['debug_log'] = [is_writable(self::log_dir()), 'Diagnostic log directory: ' . self::log_dir()];
        return $checks;
    }

    public static function log_dir() {
        $uploads = wp_upload_dir();
        return !empty($uploads['basedir']) ? $uploads['basedir'] : WP_CONTENT_DIR;
    }

    public static function render() {
        if (!current_user_can('manage_options')) return;
        $checks = self::run_checks();
        $last = get_option(self::OPTION, []);
        echo '<div class="wrap"><h1>Alt Fixes AI Diagnostics</h1>';
        echo '<p>This page is designed to tell you exactly what failed instead of relying on WordPress\'s generic activation message.</p>';
        echo '<table class="widefat striped" style="max-width:1000px"><thead><tr><th>Check</th><th>Status</th><th>Details</th></tr></thead><tbody>';
        foreach ($checks as $name => $check) {
            echo '<tr><td>' . esc_html($name) . '</td><td><strong>' . ($check[0] ? 'PASS' : 'FAIL') . '</strong></td><td>' . esc_html($check[1]) . '</td></tr>';
        }
        echo '</tbody></table>';
        if (is_array($last) && !empty($last['message'])) {
            echo '<h2>Last captured fatal error</h2><pre style="background:#fff;padding:15px;border:1px solid #ccd0d4;white-space:pre-wrap">' . esc_html(print_r($last, true)) . '</pre>';
        } else {
            echo '<h2>Last captured fatal error</h2><p>No fatal error has been captured yet.</p>';
        }
        $log = trailingslashit(self::log_dir()) . self::LOG_FILE;
        echo '<p><strong>Diagnostic log:</strong> <code>' . esc_html($log) . '</code></p>';
        echo '<p><strong>Tip:</strong> If activation fails, refresh this page after WordPress returns you to Plugins. The exact fatal is also written to the PHP error log and the Alt Fixes diagnostic log.</p></div>';
    }
}
Alt_Fixes_Diagnostics::boot();
}
