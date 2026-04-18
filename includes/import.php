<?php
if (!defined('ABSPATH')) exit;

function sb_extract_zip(string $zip_path): string|WP_Error {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('zip_unavailable', 'ZipArchive nicht verfügbar.');
    }
    $extract_dir = wp_tempnam('sb-import-');
    @unlink($extract_dir);
    wp_mkdir_p($extract_dir);

    $zip = new ZipArchive();
    if ($zip->open($zip_path) !== true) {
        return new WP_Error('zip_open_failed', 'ZIP konnte nicht geöffnet werden.');
    }
    $zip->extractTo($extract_dir);
    $zip->close();
    return $extract_dir;
}

function sb_read_manifest(string $extract_dir): array|WP_Error {
    $manifest_path = trailingslashit($extract_dir) . 'manifest.json';
    if (!file_exists($manifest_path)) {
        return new WP_Error('no_manifest', 'manifest.json nicht gefunden.');
    }
    $json = file_get_contents($manifest_path);
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['posts'], $data['post_type'], $data['count'])) {
        return new WP_Error('invalid_manifest', 'Ungültiges manifest.json.');
    }
    return $data;
}

function sb_find_existing_post(array $post_data, string $target_type): ?int {
    $existing = get_page_by_path($post_data['post_name'], OBJECT, $target_type);
    return $existing ? (int) $existing->ID : null;
}

function sb_posts_are_identical(int $existing_id, array $import_data): bool {
    $post = get_post($existing_id);
    if (!$post) return false;
    return $post->post_title   === $import_data['post_title']
        && $post->post_content === $import_data['post_content']
        && $post->post_excerpt === $import_data['post_excerpt'];
}

function sb_import_single_post(array $post_data, string $target_type, string $collision, string $media_dir): array {
    $existing_id = sb_find_existing_post($post_data, $target_type);

    if ($existing_id && $collision === 'skip') {
        if (sb_posts_are_identical($existing_id, $post_data)) {
            return ['status' => 'skipped', 'title' => $post_data['post_title']];
        }
    }

    $args = [
        'post_title'   => $post_data['post_title'],
        'post_content' => $post_data['post_content'],
        'post_excerpt' => $post_data['post_excerpt'],
        'post_status'  => $post_data['post_status'] ?? 'draft',
        'post_date'    => $post_data['post_date'] ?? current_time('mysql'),
        'post_name'    => $post_data['post_name'],
        'post_type'    => $target_type,
    ];

    if ($existing_id && $collision === 'override') {
        $args['ID'] = $existing_id;
        $post_id = wp_update_post($args, true);
        $action = 'updated';
    } elseif ($existing_id && $collision === 'skip') {
        // Nicht identisch, aber skip = trotzdem überspringen
        return ['status' => 'skipped', 'title' => $post_data['post_title']];
    } else {
        $post_id = wp_insert_post($args, true);
        $action = 'created';
    }

    if (is_wp_error($post_id)) {
        return ['status' => 'error', 'title' => $post_data['post_title'], 'error' => $post_id->get_error_message()];
    }

    // Custom Fields
    if (!empty($post_data['meta']) && is_array($post_data['meta'])) {
        foreach ($post_data['meta'] as $key => $values) {
            delete_post_meta($post_id, $key);
            foreach ((array) $values as $value) {
                add_post_meta($post_id, $key, maybe_unserialize($value));
            }
        }
    }

    // Taxonomien
    if (!empty($post_data['terms']) && is_array($post_data['terms'])) {
        foreach ($post_data['terms'] as $taxonomy => $terms) {
            $slugs = array_column($terms, 'slug');
            wp_set_object_terms($post_id, $slugs, $taxonomy);
        }
    }

    // Medien
    if (!empty($post_data['attachments'])) {
        sb_import_attachments($post_id, $post_data['attachments'], $media_dir);
    }

    return ['status' => $action, 'title' => $post_data['post_title']];
}

add_action('wp_ajax_sb_import', 'sb_ajax_import');
function sb_ajax_import() {
    check_ajax_referer('sb_import', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nicht autorisiert.'], 403);
    }

    if (empty($_FILES['sb_zip']['tmp_name'])) {
        wp_send_json_error(['message' => 'Keine ZIP-Datei hochgeladen.']);
    }

    $file     = $_FILES['sb_zip'];
    $zip_tmp  = $file['tmp_name'];
    $zip_type = $file['type'];

    $allowed = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
    if (!in_array($zip_type, $allowed, true) && !str_ends_with($file['name'], '.zip')) {
        wp_send_json_error(['message' => 'Nur ZIP-Dateien erlaubt.']);
    }

    $extract_dir = sb_extract_zip($zip_tmp);
    if (is_wp_error($extract_dir)) {
        wp_send_json_error(['message' => $extract_dir->get_error_message()]);
    }

    $manifest = sb_read_manifest($extract_dir);
    if (is_wp_error($manifest)) {
        wp_send_json_error(['message' => $manifest->get_error_message()]);
    }

    $source_type = sanitize_key($_POST['source_type'] ?? '');
    $target_type = sanitize_key($_POST['target_type'] ?? $source_type);
    $collision   = in_array($_POST['collision'] ?? '', ['skip', 'override'], true)
                   ? $_POST['collision'] : 'skip';
    $media_dir   = trailingslashit($extract_dir);

    $created = $updated = $skipped = $errors = [];

    foreach ($manifest['posts'] as $post_data) {
        $result = sb_import_single_post($post_data, $target_type, $collision, $media_dir);
        switch ($result['status']) {
            case 'created':  $created[]  = $result['title']; break;
            case 'updated':  $updated[]  = $result['title']; break;
            case 'skipped':  $skipped[]  = $result['title']; break;
            case 'error':    $errors[]   = $result['title'] . ': ' . ($result['error'] ?? ''); break;
        }
    }

    // Temporäres Verzeichnis aufräumen
    array_map('unlink', glob($extract_dir . '/*.*'));
    @rmdir($extract_dir . '/media');
    @rmdir($extract_dir);

    wp_send_json_success([
        'created' => $created,
        'updated' => $updated,
        'skipped' => $skipped,
        'errors'  => $errors,
    ]);
}
