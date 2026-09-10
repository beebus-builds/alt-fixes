<?php
/**
 * Context extraction for Alt Fixes AI.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Alt_Fixes_Context {
    public static function for_attachment($attachment_id) {
        $attachment_id = absint($attachment_id);
        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return [];
        }

        $context = [
            'attachment_title' => get_the_title($attachment_id),
            'filename' => wp_basename(get_attached_file($attachment_id)),
            'caption' => wp_strip_all_tags($attachment->post_excerpt),
            'description' => wp_strip_all_tags($attachment->post_content),
            'parent' => null,
            'usages' => [],
        ];

        if ($attachment->post_parent) {
            $parent = get_post($attachment->post_parent);
            if ($parent) {
                $context['parent'] = self::post_context($parent);
            }
        }

        $url = wp_get_attachment_url($attachment_id);
        if ($url) {
            $context['usages'] = self::find_usages($url, $attachment_id);
        }

        return $context;
    }

    private static function post_context($post) {
        return [
            'id' => (int) $post->ID,
            'type' => $post->post_type,
            'title' => get_the_title($post),
            'excerpt' => wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 60),
        ];
    }

    private static function find_usages($url, $attachment_id) {
        global $wpdb;

        $like_url = '%' . $wpdb->esc_like($url) . '%';
        $like_id = '%wp-image-' . absint($attachment_id) . '%';
        $sql = $wpdb->prepare(
            "SELECT ID, post_type, post_title FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND post_type NOT IN ('attachment', 'revision', 'nav_menu_item')
             AND (post_content LIKE %s OR post_content LIKE %s)
             ORDER BY post_date DESC LIMIT 5",
            $like_url,
            $like_id
        );

        $rows = $wpdb->get_results($sql);
        $usages = [];
        foreach ((array) $rows as $row) {
            $usages[] = [
                'id' => (int) $row->ID,
                'type' => $row->post_type,
                'title' => $row->post_title,
            ];
        }
        return $usages;
    }
}
