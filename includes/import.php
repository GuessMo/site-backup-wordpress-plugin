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

function sb_import_single_post(array $post_data, string $target_type, string $collision, string $media_dir, string $old_domain = '', string $new_domain = ''): array {
    if ($old_domain && $old_domain !== $new_domain) {
        sb_replace_domain_in_post($post_data, $old_domain, $new_domain);
    }

    if (!post_type_exists($target_type)) {
        return ['status' => 'skipped_no_cpt', 'title' => $post_data['post_title'], 'cpt' => $target_type];
    }

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

function sb_detect_source_domain(array $manifest): string {
    if (empty($manifest['source_url'])) {
        return '';
    }
    $parsed = parse_url($manifest['source_url']);
    if (empty($parsed['host'])) {
        return '';
    }
    $scheme = !empty($parsed['scheme']) ? $parsed['scheme'] : 'https';
    return $scheme . '://' . $parsed['host'];
}

function sb_replace_domain_in_value(mixed $value, string $old, string $new): mixed {
    if (is_array($value)) {
        foreach ($value as $k => $v) {
            $value[$k] = sb_replace_domain_in_value($v, $old, $new);
        }
        return $value;
    }
    if (!is_string($value)) {
        return $value;
    }
    if (is_serialized($value)) {
        $unserialized = @unserialize($value, ['allowed_classes' => false]);
        if ($unserialized !== false || $value === serialize(false)) {
            $replaced = sb_replace_domain_in_value($unserialized, $old, $new);
            return serialize($replaced);
        }
    }
    return str_replace($old, $new, $value);
}

function sb_replace_domain_in_post(array &$post_data, string $old_domain, string $new_domain): void {
    if (empty($old_domain) || $old_domain === $new_domain) {
        return;
    }
    foreach (['post_content', 'post_excerpt'] as $field) {
        if (isset($post_data[$field])) {
            $post_data[$field] = sb_replace_domain_in_value($post_data[$field], $old_domain, $new_domain);
        }
    }
    if (!empty($post_data['meta']) && is_array($post_data['meta'])) {
        foreach ($post_data['meta'] as $key => $values) {
            $post_data['meta'][$key] = array_map(
                fn($v) => sb_replace_domain_in_value($v, $old_domain, $new_domain),
                (array) $values
            );
        }
    }
}

function sb_get_upload_error_message(int $upload_error): string {
    switch ($upload_error) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $upload_max = ini_get('upload_max_filesize') ?: 'unbekannt';
            $post_max = ini_get('post_max_size') ?: 'unbekannt';
            return 'Die ZIP-Datei überschreitet das Upload-Limit des Servers '
                . '(upload_max_filesize=' . $upload_max . ', post_max_size=' . $post_max . ').';
        case UPLOAD_ERR_PARTIAL:
            return 'Die ZIP-Datei wurde nur teilweise hochgeladen. Bitte erneut versuchen.';
        case UPLOAD_ERR_NO_FILE:
            return 'Keine ZIP-Datei hochgeladen. Bitte eine ZIP-Datei auswählen.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Serverfehler beim Upload: Temporäres Verzeichnis fehlt.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Serverfehler beim Upload: Datei konnte nicht geschrieben werden.';
        case UPLOAD_ERR_EXTENSION:
            return 'Der Upload wurde durch eine Server-Erweiterung abgebrochen.';
        default:
            return 'Unbekannter Upload-Fehler (Code ' . $upload_error . ').';
    }
}

function sb_get_uploaded_zip_file(string $field): array|WP_Error {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return new WP_Error('missing_upload_field', 'Kein Datei-Upload-Feld empfangen.');
    }

    $file = $_FILES[$field];
    $upload_error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($upload_error !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_failed', sb_get_upload_error_message($upload_error));
    }

    $tmp_name = (string) ($file['tmp_name'] ?? '');
    if ($tmp_name === '' || !file_exists($tmp_name)) {
        return new WP_Error('upload_tmp_missing', 'Upload unvollständig: Temporäre Datei nicht gefunden.');
    }

    return $file;
}

add_action('wp_ajax_sb_peek_manifest', 'sb_ajax_peek_manifest');
function sb_ajax_peek_manifest(): void {
    check_ajax_referer('sb_peek_manifest', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nicht autorisiert.'], 403);
    }

    $file = sb_get_uploaded_zip_file('sb_zip');
    if (is_wp_error($file)) {
        wp_send_json_error(['message' => $file->get_error_message()]);
    }

    $extract_dir = sb_extract_zip($file['tmp_name']);
    if (is_wp_error($extract_dir)) {
        wp_send_json_error(['message' => $extract_dir->get_error_message()]);
    }

    $manifest_path = trailingslashit($extract_dir) . 'manifest.json';
    if (!file_exists($manifest_path)) {
        wp_send_json_error(['message' => 'Kein manifest.json in der ZIP.']);
    }

    $manifest = json_decode(file_get_contents($manifest_path), true);

    // Temp aufräumen
    array_map('unlink', (array) glob($extract_dir . '/*.*'));
    @rmdir($extract_dir);

    if (!is_array($manifest)) {
        wp_send_json_error(['message' => 'Ungültiges manifest.json.']);
    }

    // post_types aus manifest (neues Feld) oder aus Posts ableiten
    $post_types = $manifest['post_types'] ?? [];
    if (empty($post_types) && !empty($manifest['posts'])) {
        $post_types = array_values(array_unique(array_column($manifest['posts'], 'post_type')));
    }

    wp_send_json_success(['post_types' => $post_types]);
}

add_action('wp_ajax_sb_import', 'sb_ajax_import');
function sb_ajax_import() {
    check_ajax_referer('sb_import', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Nicht autorisiert.'], 403);
    }

    $file = sb_get_uploaded_zip_file('sb_zip');
    if (is_wp_error($file)) {
        wp_send_json_error(['message' => $file->get_error_message()]);
    }

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

    $old_domain = sb_detect_source_domain($manifest);
    $new_domain = '';
    if ($old_domain) {
        $site   = site_url();
        $parsed = parse_url($site);
        $new_domain = (!empty($parsed['scheme']) ? $parsed['scheme'] : 'https')
                    . '://' . ($parsed['host'] ?? '');
    }

    $source_type = sanitize_key($_POST['source_type'] ?? '');
    $target_type = sanitize_key($_POST['target_type'] ?? $source_type);
    $collision   = in_array($_POST['collision'] ?? '', ['skip', 'override'], true)
                   ? $_POST['collision'] : 'skip';
    $media_dir   = trailingslashit($extract_dir);

    $cpt_map = [];
    if (!empty($_POST['cpt_map']) && is_array($_POST['cpt_map'])) {
        foreach ($_POST['cpt_map'] as $src => $dst) {
            $cpt_map[sanitize_key($src)] = sanitize_key($dst);
        }
    }

    $created = $updated = $skipped = $skipped_mapped = $skipped_no_cpt = $errors = [];

    foreach ($manifest['posts'] as $post_data) {
        $src_type    = $post_data['post_type'] ?? $target_type;
        $mapped_type = $cpt_map[$src_type] ?? $src_type;

        if ($mapped_type === 'skip') {
            $skipped_mapped[] = $post_data['post_title'] ?? '?';
            continue;
        }

        $result = sb_import_single_post($post_data, $mapped_type, $collision, $media_dir, $old_domain, $new_domain);
        switch ($result['status']) {
            case 'created':        $created[]        = $result['title']; break;
            case 'updated':        $updated[]        = $result['title']; break;
            case 'skipped':        $skipped[]        = $result['title']; break;
            case 'skipped_no_cpt': $skipped_no_cpt[] = ($result['title'] ?? '?') . ' (' . ($result['cpt'] ?? '') . ')'; break;
            case 'error':          $errors[]         = ($result['title'] ?? '?') . ': ' . ($result['error'] ?? ''); break;
        }
    }

    // Temporäres Verzeichnis aufräumen
    array_map('unlink', glob($extract_dir . '/*.*'));
    @rmdir($extract_dir . '/media');
    @rmdir($extract_dir);

    wp_send_json_success([
        'created'        => $created,
        'updated'        => $updated,
        'skipped'        => $skipped,
        'skipped_mapped' => $skipped_mapped,
        'skipped_no_cpt' => $skipped_no_cpt,
        'errors'         => $errors,
    ]);
}
