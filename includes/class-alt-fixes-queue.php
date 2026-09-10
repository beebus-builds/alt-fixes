<?php
/**
 * Background job queue for AI image analysis.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Alt_Fixes_Queue {
    const TABLE_SUFFIX = 'alt_fixes_jobs';
    const GROUP = 'alt-fixes-ai';
    const HOOK = 'alt_fixes_process_job';
    const MAX_ATTEMPTS = 3;

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE_SUFFIX;
    }

    public static function install() {
        global $wpdb;

        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            attachment_id bigint(20) unsigned NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'queued',
            attempts tinyint(3) unsigned NOT NULL DEFAULT 0,
            error text NULL,
            created_at datetime NOT NULL,
            started_at datetime NULL,
            completed_at datetime NULL,
            PRIMARY KEY (id),
            KEY attachment_id (attachment_id),
            KEY status (status),
            KEY status_attachment (status, attachment_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function enqueue($attachment_ids) {
        global $wpdb;

        self::install();
        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $attachment_ids))));
        $job_ids = [];

        foreach ($ids as $attachment_id) {
            if (!alt_fixes_validate_image($attachment_id)) {
                continue;
            }

            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM " . self::table_name() . " WHERE attachment_id = %d AND status IN ('queued','processing') ORDER BY id DESC LIMIT 1",
                $attachment_id
            ));

            if ($existing) {
                $job_ids[] = (int) $existing;
                continue;
            }

            $wpdb->insert(
                self::table_name(),
                [
                    'attachment_id' => $attachment_id,
                    'status' => 'queued',
                    'attempts' => 0,
                    'created_at' => current_time('mysql', true),
                ],
                ['%d', '%s', '%d', '%s']
            );

            $job_id = (int) $wpdb->insert_id;
            if (!$job_id) {
                continue;
            }

            update_post_meta($attachment_id, '_alt_fixes_status', 'queued');
            $job_ids[] = $job_id;
            self::schedule($job_id);
        }

        return $job_ids;
    }

    private static function schedule($job_id, $delay = 0) {
        $timestamp = time() + max(0, (int) $delay);

        if (function_exists('as_enqueue_async_action')) {
            if ($delay > 0 && function_exists('as_schedule_single_action')) {
                as_schedule_single_action($timestamp, self::HOOK, [$job_id], self::GROUP, false);
            } else {
                as_enqueue_async_action(self::HOOK, [$job_id], self::GROUP, false);
            }
            return;
        }

        wp_schedule_single_event($timestamp, self::HOOK, [$job_id]);
    }

    public static function process($job_id) {
        global $wpdb;

        $table = self::table_name();
        $job = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", absint($job_id)), ARRAY_A);
        if (!$job) {
            return;
        }

        if (in_array($job['status'], ['completed', 'skipped'], true)) {
            return;
        }

        $attachment_id = (int) $job['attachment_id'];
        $attempts = (int) $job['attempts'] + 1;

        $wpdb->update(
            $table,
            [
                'status' => 'processing',
                'attempts' => $attempts,
                'started_at' => current_time('mysql', true),
                'error' => null,
            ],
            ['id' => (int) $job['id']],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );
        update_post_meta($attachment_id, '_alt_fixes_status', 'processing');

        if (!alt_fixes_validate_image($attachment_id)) {
            self::fail($job['id'], 'The attachment is no longer a valid image.', $attempts, false);
            return;
        }

        $result = Alt_Fixes_Engine::suggest($attachment_id);
        if (is_wp_error($result)) {
            $message = $result->get_error_message();
            $retry = $attempts < self::MAX_ATTEMPTS;
            self::fail($job['id'], $message, $attempts, $retry);
            return;
        }

        alt_fixes_store_suggestion($attachment_id, $result);
        $wpdb->update(
            $table,
            [
                'status' => 'completed',
                'error' => null,
                'completed_at' => current_time('mysql', true),
            ],
            ['id' => (int) $job['id']],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    private static function fail($job_id, $message, $attempts, $retry) {
        global $wpdb;

        $table = self::table_name();
        $message = sanitize_text_field($message);

        if ($retry) {
            $wpdb->update(
                $table,
                ['status' => 'queued', 'error' => $message],
                ['id' => (int) $job_id],
                ['%s', '%s'],
                ['%d']
            );
            $delay = min(300, 30 * (2 ** max(0, $attempts - 1)));
            self::schedule($job_id, $delay);
            return;
        }

        $wpdb->update(
            $table,
            ['status' => 'failed', 'error' => $message, 'completed_at' => current_time('mysql', true)],
            ['id' => (int) $job_id],
            ['%s', '%s', '%s'],
            ['%d']
        );
        $attachment_id = (int) $wpdb->get_var($wpdb->prepare("SELECT attachment_id FROM {$table} WHERE id = %d", (int) $job_id));
        if ($attachment_id) {
            update_post_meta($attachment_id, '_alt_fixes_status', 'failed');
        }
    }

    public static function get_progress($job_ids) {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $job_ids))));
        if (!$ids) {
            return ['total' => 0, 'queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT status, COUNT(*) AS count FROM " . self::table_name() . " WHERE id IN ({$placeholders}) GROUP BY status",
            $ids
        ), ARRAY_A);

        $progress = ['total' => count($ids), 'queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            if (isset($progress[$row['status']])) {
                $progress[$row['status']] = (int) $row['count'];
            }
        }
        $progress['done'] = $progress['completed'] + $progress['failed'];
        $progress['percent'] = $progress['total'] ? (int) round(($progress['done'] / $progress['total']) * 100) : 0;
        return $progress;
    }

    public static function get_jobs($job_ids) {
        global $wpdb;

        $ids = array_values(array_unique(array_filter(array_map('absint', (array) $job_ids))));
        if (!$ids) return [];

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        return $wpdb->get_results($wpdb->prepare(
            "SELECT id, attachment_id, status, attempts, error, created_at, started_at, completed_at FROM " . self::table_name() . " WHERE id IN ({$placeholders}) ORDER BY id ASC",
            $ids
        ), ARRAY_A);
    }
}

add_action(Alt_Fixes_Queue::HOOK, ['Alt_Fixes_Queue', 'process']);
