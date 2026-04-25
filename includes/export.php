<?php
if (!defined('ABSPATH')) exit;

function sb_get_posts_for_export($post_type, $year = 0, $post_ids = array()) {
    $args = array(
        'post_type'      => $post_type,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'nopaging'       => true,
    );
    if (!empty($post_ids)) {
        $args['post__in'] = $post_ids;
    } elseif ($year > 0) {
        $args['date_query'] = array(array(
            'after'     => array('year' => $year, 'month' => 1, 'day' => 1),
            'inclusive' => true,
        ));
    }
    $query = new WP_Query($args);
    return $query->posts;
}

add_action('wp_ajax_sb_get_posts', 'sb_ajax_get_posts');
function sb_ajax_get_posts() {
    check_ajax_referer('sb_get_posts', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    $post_type = sanitize_key(isset($_POST['post_type']) ? $_POST['post_type'] : 'post');

    $query = new WP_Query(array(
        'post_type'      => $post_type,
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ));

    $posts = array();
    foreach ($query->posts as $id) {
        $post  = get_post($id);
        $title = get_the_title($id);
        $posts[] = array(
            'id'     => $id,
            'title'  => $title ? $title : '(kein Titel)',
            'date'   => get_the_date('d.m.Y', $id),
            'status' => $post->post_status,
        );
    }

    wp_send_json_success($posts);
}

add_action('wp_ajax_sb_get_all_posts', 'sb_ajax_get_all_posts');
function sb_ajax_get_all_posts() {
    check_ajax_referer('sb_get_all_posts', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    $sb_cpt_blacklist = array(
        'attachment', 'revision', 'nav_menu_item', 'custom_css',
        'customize_changeset', 'oembed_cache', 'user_request',
        'wp_block', 'wp_template', 'wp_template_part',
        'wp_global_styles', 'wp_navigation',
    );
    $post_types = array_filter(
        get_post_types(array('show_ui' => true), 'objects'),
        function($pt) use ($sb_cpt_blacklist) {
            return !in_array($pt->name, $sb_cpt_blacklist, true);
        }
    );
    $result = array();

    foreach ($post_types as $type_obj) {
        $query = new WP_Query(array(
            'post_type'      => $type_obj->name,
            'post_status'    => 'any',
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
            'fields'         => 'ids',
        ));

        if (empty($query->posts)) continue;

        $posts = array();
        foreach ($query->posts as $id) {
            $post  = get_post($id);
            $title = get_the_title($id);
            $posts[] = array(
                'id'     => $id,
                'title'  => $title ? $title : '(kein Titel)',
                'date'   => get_the_date('d.m.Y', $id),
                'status' => $post->post_status,
            );
        }

        $result[$type_obj->name] = array(
            'label' => $type_obj->label,
            'posts' => $posts,
        );
    }

    wp_send_json_success($result);
}

function sb_build_manifest(array $posts) {
    update_post_caches($posts);

    $data            = array();
    $post_types_used = array();
    foreach ($posts as $post) {
        $post_types_used[] = $post->post_type;
        $meta              = get_post_meta($post->ID);
        $terms             = array();
        $taxonomies        = get_object_taxonomies($post->post_type);
        foreach ($taxonomies as $tax) {
            $t = wp_get_object_terms($post->ID, $tax, array('fields' => 'all'));
            if (!is_wp_error($t)) {
                $terms[$tax] = array_map(function($term) {
                    return array(
                        'term_id' => $term->term_id,
                        'name'    => $term->name,
                        'slug'    => $term->slug,
                    );
                }, $t);
            }
        }
        $attachments = sb_collect_attachment_names($post);
        $data[] = array(
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
        );
    }
    return array(
        'exported_at' => date('c'),
        'source_url'  => site_url(),
        'post_types'  => array_values(array_unique($post_types_used)),
        'count'       => count($posts),
        'posts'       => $data,
    );
}

add_action('wp_ajax_sb_export', 'sb_ajax_export');
function sb_ajax_export() {
    check_ajax_referer('sb_export', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    $post_ids = array();
    if (!empty($_POST['post_ids']) && is_array($_POST['post_ids'])) {
        $post_ids = array_values(array_filter(array_map('absint', $_POST['post_ids'])));
    }

    if (empty($post_ids)) {
        wp_send_json_error(array('message' => 'Keine Posts ausgewählt.'));
    }

    $max_mb = isset($_POST['max_mb']) ? absint($_POST['max_mb']) : 50;
    $max_mb = max(10, min(500, $max_mb));

    $posts_per_zip = isset($_POST['posts_per_zip']) ? absint($_POST['posts_per_zip']) : 10;
    if ($posts_per_zip < 1) $posts_per_zip = 10;
    if ($posts_per_zip > 50) $posts_per_zip = 50;

    $chunks = array_chunk($post_ids, $posts_per_zip);
    $total_parts = count($chunks);

    wp_send_json_success(array(
        'total_parts' => $total_parts,
        'parts' => $chunks,
    ));
}

add_action('wp_ajax_sb_export_part', 'sb_ajax_export_part');
function sb_ajax_export_part() {
    check_ajax_referer('sb_export', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    $post_ids = array();
    if (!empty($_POST['post_ids']) && is_array($_POST['post_ids'])) {
        $post_ids = array_values(array_filter(array_map('absint', $_POST['post_ids'])));
    }

    if (empty($post_ids)) {
        wp_send_json_error(array('message' => 'Keine Posts ausgewählt.'));
    }

    $max_mb = isset($_POST['max_mb']) ? absint($_POST['max_mb']) : 50;
    $max_mb = max(10, min(500, $max_mb));

    $posts = get_posts(array(
        'post__in'       => $post_ids,
        'post_type'      => 'any',
        'post_status'    => 'any',
        'posts_per_page' => -1,
        'orderby'        => 'post__in',
    ));

    $manifest = sb_build_manifest($posts);
    $zip_paths = sb_create_export_zips($manifest, $posts, $max_mb, false);

    if (is_wp_error($zip_paths)) {
        wp_send_json_error(array('message' => $zip_paths->get_error_message()));
    }

    $download_urls = array_map('sb_get_export_download_url', $zip_paths);

    wp_send_json_success(array(
        'urls'  => $download_urls,
        'count' => count($posts),
    ));
}
