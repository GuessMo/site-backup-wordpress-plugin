<?php
if (!defined('ABSPATH')) exit;

function sb_get_posts_for_export(string $post_type, int $year = 0): array {
    $args = [
        'post_type'      => $post_type,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'nopaging'       => true,
    ];
    if ($year > 0) {
        $args['date_query'] = [[
            'after'     => ['year' => $year, 'month' => 1, 'day' => 1],
            'inclusive' => true,
        ]];
    }
    $query = new WP_Query($args);
    return $query->posts;
}

function sb_build_manifest(array $posts, string $post_type): array {
    $data = [];
    foreach ($posts as $post) {
        $meta  = get_post_meta($post->ID);
        $terms = [];
        $taxonomies = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $tax) {
            $t = wp_get_object_terms($post->ID, $tax, ['fields' => 'all']);
            if (!is_wp_error($t)) {
                $terms[$tax] = array_map(fn($term) => [
                    'term_id' => $term->term_id,
                    'name'    => $term->name,
                    'slug'    => $term->slug,
                ], $t);
            }
        }
        $attachments = sb_collect_attachment_names($post);
        $data[] = [
            'ID'            => $post->ID,
            'post_title'    => $post->post_title,
            'post_content'  => $post->post_content,
            'post_excerpt'  => $post->post_excerpt,
            'post_status'   => $post->post_status,
            'post_date'     => $post->post_date,
            'post_type'     => $post->post_type,
            'post_name'     => $post->post_name,
            'meta'          => $meta,
            'terms'         => $terms,
            'attachments'   => $attachments,
        ];
    }
    return [
        'exported_at' => date('c'),
        'post_type'   => $post_type,
        'count'       => count($posts),
        'posts'       => $data,
    ];
}

add_action('wp_ajax_sb_export', 'sb_ajax_export');
function sb_ajax_export() {
    check_ajax_referer('sb_export', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nicht autorisiert.'], 403);
    }

    $post_type = sanitize_key($_POST['post_type'] ?? 'post');
    $year      = absint($_POST['year'] ?? 0);

    $posts    = sb_get_posts_for_export($post_type, $year);
    $manifest = sb_build_manifest($posts, $post_type);
    $zip_path = sb_create_export_zip($manifest, $posts);

    if (is_wp_error($zip_path)) {
        wp_send_json_error(['message' => $zip_path->get_error_message()]);
    }

    $download_url = sb_get_export_download_url($zip_path);
    $titles = array_map(fn($p) => $p->post_title, $posts);

    wp_send_json_success([
        'download_url' => $download_url,
        'count'        => count($posts),
        'titles'       => $titles,
    ]);
}
