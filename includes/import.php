<?php
if (!defined('ABSPATH')) exit;

function sb_extract_zip($zip_path) {
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

function sb_read_manifest($extract_dir) {
    $manifest_path = trailingslashit($extract_dir) . 'manifest.json';
    if (!file_exists($manifest_path)) {
        return new WP_Error('no_manifest', 'manifest.json nicht gefunden.');
    }
    $json = file_get_contents($manifest_path);
    $data = json_decode($json, true);
    if (!is_array($data) || !isset($data['posts'], $data['count'])) {
        return new WP_Error('invalid_manifest', 'Ungültiges manifest.json.');
    }
    if (!isset($data['post_types']) && isset($data['post_type'])) {
        $data['post_types'] = array($data['post_type']);
    }
    return $data;
}

function sb_find_existing_post(array $post_data, $target_type) {
    $existing = get_page_by_path($post_data['post_name'], OBJECT, $target_type);
    return $existing ? (int) $existing->ID : null;
}

function sb_posts_are_identical($existing_id, array $import_data) {
    $post = get_post($existing_id);
    if (!$post) return false;
    return $post->post_title   === $import_data['post_title']
        && $post->post_content === $import_data['post_content']
        && $post->post_excerpt === $import_data['post_excerpt'];
}

function sb_import_single_post(array $post_data, $target_type, $collision, $media_dir, $old_domain = '', $new_domain = '') {
    if ($old_domain && $old_domain !== $new_domain) {
        sb_replace_domain_in_post($post_data, $old_domain, $new_domain);
    }

    if (!post_type_exists($target_type)) {
        return array('status' => 'skipped_no_cpt', 'title' => $post_data['post_title'], 'cpt' => $target_type);
    }

    $existing_id = sb_find_existing_post($post_data, $target_type);

    if ($existing_id && $collision === 'skip') {
        if (sb_posts_are_identical($existing_id, $post_data)) {
            return array('status' => 'skipped', 'title' => $post_data['post_title']);
        }
    }

    $args = array(
        'post_title'   => $post_data['post_title'],
        'post_content' => $post_data['post_content'],
        'post_excerpt' => $post_data['post_excerpt'],
        'post_status'  => isset($post_data['post_status']) ? $post_data['post_status'] : 'draft',
        'post_date'    => isset($post_data['post_date'])   ? $post_data['post_date']   : current_time('mysql'),
        'post_name'    => $post_data['post_name'],
        'post_type'    => $target_type,
    );

    if ($existing_id && $collision === 'override') {
        $args['ID'] = $existing_id;
        $post_id    = wp_update_post($args, true);
        $action     = 'updated';
    } elseif ($existing_id && $collision === 'skip') {
        return array('status' => 'skipped', 'title' => $post_data['post_title']);
    } else {
        $post_id = wp_insert_post($args, true);
        $action  = 'created';
    }

    if (is_wp_error($post_id)) {
        return array('status' => 'error', 'title' => $post_data['post_title'], 'error' => $post_id->get_error_message());
    }

    if (!empty($post_data['meta']) && is_array($post_data['meta'])) {
        foreach ($post_data['meta'] as $key => $values) {
            delete_post_meta($post_id, $key);
            foreach ((array) $values as $value) {
                add_post_meta($post_id, $key, maybe_unserialize($value));
            }
        }
    }

    if (!empty($post_data['terms']) && is_array($post_data['terms'])) {
        foreach ($post_data['terms'] as $taxonomy => $terms) {
            $slugs = array_column($terms, 'slug');
            wp_set_object_terms($post_id, $slugs, $taxonomy);
        }
    }

    $id_map = array();
    $force_attachments = ($collision === 'override');
    if (!empty($post_data['attachments'])) {
        $id_map = sb_import_attachments($post_id, $post_data['attachments'], $media_dir, $force_attachments);
    }

    // Update image URLs in post_content with new attachment URLs
    if (!empty($id_map) && !empty($post_data['post_content'])) {
        $content = $post_data['post_content'];
        
        // Remove blob: URLs - these are invalid and shouldn't be in content
        $content = preg_replace('/blob:[^\s"<>]+/', '', $content);
        
        // Replace old attachment IDs with new ones in HTML
        foreach ($id_map as $old_id => $new_id) {
            // Replace wp-image-XX class references
            $content = str_replace('wp-image-' . $old_id, 'wp-image-' . $new_id, $content);
            // Replace attachment_id=XX in links
            $content = str_replace('attachment_id=' . $old_id, 'attachment_id=' . $new_id, $content);
        }
        
        // Update the post with cleaned content
        wp_update_post(array('ID' => $post_id, 'post_content' => $content));
    }

    if (!empty($id_map)) {
        $remap_keys = apply_filters('sb_attachment_meta_keys', array('_thumbnail_id', 'animal_images'), get_post($post_id));
        foreach ($remap_keys as $key) {
            $value = get_post_meta($post_id, $key, true);
            if (empty($value)) continue;

            if (is_array($value)) {
                $new_value = array_map(function($v) use ($id_map) {
                    return isset($id_map[(int) $v]) ? $id_map[(int) $v] : $v;
                }, $value);
                update_post_meta($post_id, $key, $new_value);
            } elseif (is_numeric($value) && isset($id_map[(int) $value])) {
                update_post_meta($post_id, $key, $id_map[(int) $value]);
            }
        }
    }

    return array('status' => $action, 'title' => $post_data['post_title']);
}

function sb_detect_source_domain(array $manifest) {
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

function sb_replace_domain_in_value($value, $old, $new) {
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
        $unserialized = @unserialize($value);
        if ($unserialized !== false || $value === serialize(false)) {
            $replaced = sb_replace_domain_in_value($unserialized, $old, $new);
            return serialize($replaced);
        }
    }
    return str_replace($old, $new, $value);
}

function sb_replace_domain_in_post(array &$post_data, $old_domain, $new_domain) {
    if (empty($old_domain) || $old_domain === $new_domain) {
        return;
    }
    foreach (array('post_content', 'post_excerpt') as $field) {
        if (isset($post_data[$field])) {
            $post_data[$field] = sb_replace_domain_in_value($post_data[$field], $old_domain, $new_domain);
        }
    }
    if (!empty($post_data['meta']) && is_array($post_data['meta'])) {
        foreach ($post_data['meta'] as $key => $values) {
            $post_data['meta'][$key] = array_map(function($v) use ($old_domain, $new_domain) {
                return sb_replace_domain_in_value($v, $old_domain, $new_domain);
            }, (array) $values);
        }
    }
}

function sb_get_upload_error_message($upload_error) {
    switch ($upload_error) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $upload_max = ini_get('upload_max_filesize') ?: 'unbekannt';
            $post_max   = ini_get('post_max_size') ?: 'unbekannt';
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

function sb_get_uploaded_zip_file($field) {
    if (!isset($_FILES[$field]) || !is_array($_FILES[$field])) {
        return new WP_Error('missing_upload_field', 'Kein Datei-Upload-Feld empfangen.');
    }

    $file         = $_FILES[$field];
    $upload_error = isset($file['error']) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
    if ($upload_error !== UPLOAD_ERR_OK) {
        return new WP_Error('upload_failed', sb_get_upload_error_message($upload_error));
    }

    $tmp_name = isset($file['tmp_name']) ? (string) $file['tmp_name'] : '';
    if ($tmp_name === '' || !file_exists($tmp_name)) {
        return new WP_Error('upload_tmp_missing', 'Upload unvollständig: Temporäre Datei nicht gefunden.');
    }

    return $file;
}

function sb_chunk_upload_init($filename, $total_chunks) {
    $filename = sanitize_file_name($filename);
    if (empty($filename)) {
        return new WP_Error('missing_filename', 'Kein Dateiname.');
    }

    $upload_dir = wp_upload_dir();
    $chunk_dir = $upload_dir['basedir'] . '/sb-chunks/' . $filename;
    wp_mkdir_p($chunk_dir);

    return array(
        'chunk_dir'     => $chunk_dir,
        'total'       => (int) $total_chunks,
        'filename'    => $filename,
    );
}

function sb_chunk_upload_append($filename, $chunk_index, $chunk_data) {
    $filename = sanitize_file_name($filename);
    $chunk_dir = wp_upload_dir()['basedir'] . '/sb-chunks/' . $filename;
    $chunk_file = $chunk_dir . '/part-' . $chunk_index;

    if (!is_dir($chunk_dir)) {
        return new WP_Error('chunk_dir_missing', 'Chunk-Verzeichnis nicht gefunden.');
    }

    $data = base64_decode($chunk_data);
    if ($data === false || strlen($data) < 1024) {
        return new WP_Error('invalid_chunk', 'Ungültiger Chunk.');
    }

    if (file_put_contents($chunk_file, $data) === false) {
        return new WP_Error('write_failed', 'Chunk konnte nicht geschrieben werden.');
    }

    return true;
}

function sb_chunk_upload_merge($filename, $total_chunks) {
    $filename = sanitize_file_name($filename);
    $chunk_dir = wp_upload_dir()['basedir'] . '/sb-chunks/' . $filename;
    $upload_dir = wp_upload_dir();
    $final_dir = $upload_dir['basedir'] . '/sb-exports';
    wp_mkdir_p($final_dir);

    $final_file = $final_dir . '/' . $filename;
    $final_handle = fopen($final_file, 'wb');
    if (!$final_handle) {
        return new WP_Error('merge_failed', 'Konnte Zieldatei nicht erstellen.');
    }

    for ($i = 0; $i < $total_chunks; $i++) {
        $chunk_file = $chunk_dir . '/part-' . $i;
        if (!file_exists($chunk_file)) {
            fclose($final_handle);
            return new WP_Error('chunk_missing', "Chunk {$i} fehlt.");
        }

        $chunk_data = file_get_contents($chunk_file);
        if ($chunk_data === false) {
            fclose($final_handle);
            return new WP_Error('chunk_read_failed', "Chunk {$i} konnte nicht gelesen werden.");
        }

        fwrite($final_handle, $chunk_data);
        @unlink($chunk_file);
    }

    fclose($final_handle);
    @rmdir($chunk_dir);

    if (!file_exists($final_file)) {
        return new WP_Error('merge_incomplete', 'Zusammenführung fehlgeschlagen.');
    }

    return array(
        'tmp_name' => $final_file,
        'name'     => $filename,
        'type'     => 'application/zip',
        'error'    => 0,
    );
}

function sb_get_server_zip_file($filename) {
    $filename = sanitize_file_name($filename);
    if (empty($filename)) {
        return new WP_Error('missing_filename', 'Kein Dateiname angegeben.');
    }

    if (!str_ends_with($filename, '.zip')) {
        return new WP_Error('invalid_extension', 'Nur .zip-Dateien erlaubt.');
    }

    $upload_dir = wp_upload_dir();
    $export_dir  = trailingslashit($upload_dir['basedir']) . 'sb-exports';
    $file_path  = $export_dir . '/' . $filename;

    if (!file_exists($file_path)) {
        return new WP_Error('file_not_found', "Datei nicht gefunden: {$filename}");
    }

    if (!is_readable($file_path)) {
        return new WP_Error('file_not_readable', "Datei nicht lesbar: {$filename}");
    }

    return array(
        'name'     => $filename,
        'tmp_name' => $file_path,
        'type'    => 'application/zip',
        'error'   => 0,
    );
}

add_action('wp_ajax_sb_peek_manifest', 'sb_ajax_peek_manifest');
function sb_ajax_peek_manifest() {
    check_ajax_referer('sb_peek_manifest', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    $server_filename = isset($_POST['sb_zip_file']) ? $_POST['sb_zip_file'] : '';
    if (!empty($server_filename)) {
        $file = sb_get_server_zip_file($server_filename);
    } else {
        $file = sb_get_uploaded_zip_file('sb_zip');
    }

    if (is_wp_error($file)) {
        wp_send_json_error(array('message' => $file->get_error_message()));
    }

    $extract_dir = sb_extract_zip($file['tmp_name']);
    if (is_wp_error($extract_dir)) {
        wp_send_json_error(array('message' => $extract_dir->get_error_message()));
    }

    $manifest_path = trailingslashit($extract_dir) . 'manifest.json';
    if (!file_exists($manifest_path)) {
        wp_send_json_error(array('message' => 'Kein manifest.json in der ZIP.'));
    }

    $manifest = json_decode(file_get_contents($manifest_path), true);

    array_map('unlink', (array) glob($extract_dir . '/*.*'));
    @rmdir($extract_dir);

    if (!is_array($manifest)) {
        wp_send_json_error(array('message' => 'Ungültiges manifest.json.'));
    }

    $post_types = isset($manifest['post_types']) ? $manifest['post_types'] : array();
    if (empty($post_types) && !empty($manifest['posts'])) {
        $post_types = array_values(array_unique(array_column($manifest['posts'], 'post_type')));
    }

    wp_send_json_success(array('post_types' => $post_types));
}

add_action('wp_ajax_sb_chunk_init', 'sb_ajax_chunk_init');
function sb_ajax_chunk_init() {
    check_ajax_referer('sb_import', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    $filename    = sanitize_file_name(isset($_POST['filename']) ? $_POST['filename'] : '');
    $totalChunks = absint(isset($_POST['total_chunks']) ? $_POST['total_chunks'] : 0);

    if (empty($filename) || !$totalChunks) {
        wp_send_json_error(array('message' => 'Filename und total_chunks erforderlich.'));
    }

    if (!str_ends_with($filename, '.zip')) {
        wp_send_json_error(array('message' => 'Nur .zip erlaubt.'));
    }

    $result = sb_chunk_upload_init($filename, $totalChunks);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array(
        'chunk_dir'  => $result['chunk_dir'],
        'total'      => $result['total'],
    ));
}

add_action('wp_ajax_sb_chunk_append', 'sb_ajax_chunk_append');
function sb_ajax_chunk_append() {
    check_ajax_referer('sb_import', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    $filename     = sanitize_file_name(isset($_POST['filename']) ? $_POST['filename'] : '');
    $chunk_index = absint(isset($_POST['chunk_index']) ? $_POST['chunk_index'] : -1);
    $chunk_data  = isset($_POST['chunk_data']) ? $_POST['chunk_data'] : '';

    if (empty($filename) || $chunk_index < 0 || empty($chunk_data)) {
        wp_send_json_error(array('message' => 'filename, chunk_index und chunk_data erforderlich.'));
    }

    $result = sb_chunk_upload_append($filename, $chunk_index, $chunk_data);
    if (is_wp_error($result)) {
        wp_send_json_error(array('message' => $result->get_error_message()));
    }

    wp_send_json_success(array('ok' => true, 'chunk' => $chunk_index));
}

add_action('wp_ajax_sb_chunk_merge', 'sb_ajax_chunk_merge');
function sb_ajax_chunk_merge() {
    check_ajax_referer('sb_import', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    $filename     = sanitize_file_name(isset($_POST['filename']) ? $_POST['filename'] : '');
    $totalChunks = absint(isset($_POST['total_chunks']) ? $_POST['total_chunks'] : 0);

    if (empty($filename) || !$totalChunks) {
        wp_send_json_error(array('message' => 'filename und total_chunks erforderlich.'));
    }

    $file = sb_chunk_upload_merge($filename, $totalChunks);
    if (is_wp_error($file)) {
        wp_send_json_error(array('message' => $file->get_error_message()));
    }

    $_FILES['sb_zip'] = array(
        'name'     => $file['name'],
        'tmp_name' => $file['tmp_name'],
        'type'    => $file['type'],
        'error'   => $file['error'],
    );

    $manifest = sb_extract_zip($file['tmp_name']);
    if (is_wp_error($manifest)) {
        wp_send_json_error(array('message' => 'ZIP konnte nicht entpackt werden: ' . $manifest->get_error_message()));
    }

    $manifest = sb_read_manifest($manifest);
    if (is_wp_error($manifest)) {
        wp_send_json_error(array('message' => $manifest->get_error_message()));
    }

    $post_types = isset($manifest['post_types']) ? $manifest['post_types'] : array();
    if (empty($post_types) && !empty($manifest['posts'])) {
        $post_types = array_values(array_unique(array_column($manifest['posts'], 'post_type')));
    }

    wp_send_json_success(array('post_types' => $post_types));
}

add_action('wp_ajax_sb_import', 'sb_ajax_import');
function sb_ajax_import() {
    check_ajax_referer('sb_import', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    $server_filename = isset($_POST['sb_zip_file']) ? $_POST['sb_zip_file'] : '';
    if (!empty($server_filename)) {
        $file = sb_get_server_zip_file($server_filename);
    } else {
        $file = sb_get_uploaded_zip_file('sb_zip');
    }

    if (is_wp_error($file)) {
        wp_send_json_error(array('message' => $file->get_error_message()));
    }

    $zip_tmp  = $file['tmp_name'];
    $zip_type = $file['type'];

    $allowed = array('application/zip', 'application/x-zip-compressed', 'application/octet-stream');
    if (!in_array($zip_type, $allowed, true) && !str_ends_with($file['name'], '.zip')) {
        wp_send_json_error(array('message' => 'Nur ZIP-Dateien erlaubt.'));
    }

    $extract_dir = sb_extract_zip($zip_tmp);
    if (is_wp_error($extract_dir)) {
        wp_send_json_error(array('message' => $extract_dir->get_error_message()));
    }

    $manifest = sb_read_manifest($extract_dir);
    if (is_wp_error($manifest)) {
        wp_send_json_error(array('message' => $manifest->get_error_message()));
    }

    $old_domain = sb_detect_source_domain($manifest);
    $new_domain = '';
    if ($old_domain) {
        $site       = site_url();
        $parsed     = parse_url($site);
        $new_domain = (!empty($parsed['scheme']) ? $parsed['scheme'] : 'https')
                    . '://' . (isset($parsed['host']) ? $parsed['host'] : '');
    }

    $source_type    = sanitize_key(isset($_POST['source_type']) ? $_POST['source_type'] : '');
    $target_type    = sanitize_key(isset($_POST['target_type']) ? $_POST['target_type'] : $source_type);
    $collision_raw  = isset($_POST['collision']) ? $_POST['collision'] : '';
    $collision      = in_array($collision_raw, array('skip', 'override'), true) ? $collision_raw : 'skip';
    $media_dir      = trailingslashit($extract_dir);

    $cpt_map = array();
    if (!empty($_POST['cpt_map']) && is_array($_POST['cpt_map'])) {
        foreach ($_POST['cpt_map'] as $src => $dst) {
            $cpt_map[sanitize_key($src)] = sanitize_key($dst);
        }
    }

    $created = $updated = $skipped = $skipped_mapped = $skipped_no_cpt = $errors = array();

    foreach ($manifest['posts'] as $post_data) {
        $src_type    = isset($post_data['post_type']) ? $post_data['post_type'] : $target_type;
        $mapped_type = isset($cpt_map[$src_type])     ? $cpt_map[$src_type]     : $src_type;

        if ($mapped_type === 'skip') {
            $skipped_mapped[] = isset($post_data['post_title']) ? $post_data['post_title'] : '?';
            continue;
        }

        $result = sb_import_single_post($post_data, $mapped_type, $collision, $media_dir, $old_domain, $new_domain);
        switch ($result['status']) {
            case 'created':
                $created[] = $result['title'];
                break;
            case 'updated':
                $updated[] = $result['title'];
                break;
            case 'skipped':
                $skipped[] = $result['title'];
                break;
            case 'skipped_no_cpt':
                $skipped_no_cpt[] = (isset($result['title']) ? $result['title'] : '?')
                                  . ' (' . (isset($result['cpt']) ? $result['cpt'] : '') . ')';
                break;
            case 'error':
                $errors[] = (isset($result['title']) ? $result['title'] : '?')
                          . ': ' . (isset($result['error']) ? $result['error'] : '');
                break;
        }
    }

    array_map('unlink', glob($extract_dir . '/*.*'));
    @rmdir($extract_dir . '/media');
    @rmdir($extract_dir);

    wp_send_json_success(array(
        'created'        => $created,
        'updated'        => $updated,
        'skipped'        => $skipped,
        'skipped_mapped' => $skipped_mapped,
        'skipped_no_cpt' => $skipped_no_cpt,
        'errors'         => $errors,
    ));
}
