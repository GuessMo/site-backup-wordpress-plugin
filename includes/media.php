<?php
if (!defined('ABSPATH')) exit;

function sb_collect_attachment_names(WP_Post $post): array {
    $attachments = get_attached_media('', $post->ID);
    $result = [];
    foreach ($attachments as $att) {
        $file = get_attached_file($att->ID);
        if (!$file || !file_exists($file)) continue;
        $upload_dir = wp_upload_dir();
        $relative   = str_replace(trailingslashit($upload_dir['basedir']), '', $file);
        $result[] = [
            'id'       => $att->ID,
            'file'     => $file,
            'relative' => $relative,
        ];
    }
    return $result;
}

function sb_collect_attachments(WP_Post $post): array {
    return sb_collect_attachment_names($post);
}

function sb_create_export_zip(array $manifest, array $posts) {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('zip_unavailable', 'ZipArchive nicht verfügbar.');
    }

    $upload_dir  = wp_upload_dir();
    $export_dir  = trailingslashit($upload_dir['basedir']) . 'sb-exports/';
    if (!wp_mkdir_p($export_dir)) {
        return new WP_Error('mkdir_failed', 'Export-Verzeichnis konnte nicht erstellt werden.');
    }

    $post_type = sanitize_key($manifest['post_type']);
    $timestamp = time();
    $zip_name  = "sb-export-{$post_type}-{$timestamp}.zip";
    $zip_path  = $export_dir . $zip_name;

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return new WP_Error('zip_open_failed', 'ZIP konnte nicht erstellt werden.');
    }

    $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    foreach ($posts as $post) {
        $attachments = sb_collect_attachment_names($post);
        foreach ($attachments as $att) {
            if (file_exists($att['file'])) {
                $zip->addFile($att['file'], 'media/' . $att['relative']);
            }
        }
    }

    $zip->close();
    return $zip_path;
}

function sb_create_export_zips(array $manifest, array $posts, int $max_mb = 50): array|WP_Error {
    if (!class_exists('ZipArchive')) {
        return new WP_Error('zip_unavailable', 'ZipArchive nicht verfügbar.');
    }

    $upload_dir = wp_upload_dir();
    $export_dir = trailingslashit($upload_dir['basedir']) . 'sb-exports/';
    if (!wp_mkdir_p($export_dir)) {
        return new WP_Error('mkdir_failed', 'Export-Verzeichnis konnte nicht erstellt werden.');
    }

    $max_bytes = $max_mb * 1024 * 1024;
    $post_type = sanitize_key($manifest['post_type'] ?? ($manifest['post_types'][0] ?? 'export'));
    $timestamp = time();
    $zip_paths = [];
    $part      = 1;

    // Prebuilt post data from manifest (avoids re-querying the DB per chunk)
    $manifest_posts = array_values($manifest['posts']);

    $make_zip_path = fn() => $export_dir . "sb-export-{$post_type}-{$timestamp}-part{$part}.zip";

    $finalize_chunk = function (string $zip_path, array $chunk_posts_data) use ($manifest): WP_Error|true {
        $chunk_manifest           = $manifest;
        $chunk_manifest['posts']  = $chunk_posts_data;
        $chunk_manifest['count']  = count($chunk_posts_data);
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE) !== true) {
            return new WP_Error('zip_open_failed', "manifest.json konnte nicht geschrieben werden.");
        }
        $zip->addFromString('manifest.json', wp_json_encode($chunk_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();
        return true;
    };

    $zip_path         = $make_zip_path();
    $chunk_posts_data = [];

    foreach ($posts as $index => $post) {
        $chunk_posts_data[] = $manifest_posts[$index];

        // Open ZIP (CREATE opens existing without truncating; OVERWRITE would truncate)
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE) !== true) {
            return new WP_Error('zip_open_failed', "ZIP Part {$part} konnte nicht geöffnet werden.");
        }

        // Add only media files — manifest.json is written once per chunk on finalize
        $attachments = sb_collect_attachment_names($post);
        foreach ($attachments as $att) {
            if (file_exists($att['file'])) {
                $zip->addFile($att['file'], 'media/' . $att['relative']);
            }
        }
        $zip->close();

        $current_size = file_exists($zip_path) ? filesize($zip_path) : 0;

        if ($current_size >= $max_bytes) {
            $result = $finalize_chunk($zip_path, $chunk_posts_data);
            if (is_wp_error($result)) {
                return $result;
            }
            $zip_paths[]      = $zip_path;
            $part++;
            $chunk_posts_data = [];
            $zip_path         = $make_zip_path();
        }
    }

    // Finalize the last (or only) chunk
    if (!empty($chunk_posts_data)) {
        $result = $finalize_chunk($zip_path, $chunk_posts_data);
        if (is_wp_error($result)) {
            return $result;
        }
        $zip_paths[] = $zip_path;
    }

    return $zip_paths;
}

function sb_get_export_download_url(string $zip_path): string {
    $upload_dir = wp_upload_dir();
    return str_replace(
        trailingslashit($upload_dir['basedir']),
        trailingslashit($upload_dir['baseurl']),
        $zip_path
    );
}

function sb_import_attachments(int $post_id, array $attachments, string $media_dir): void {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    foreach ($attachments as $attachment) {
        $relative = $attachment['relative'] ?? '';
        if (empty($relative)) continue;

        $file = trailingslashit($media_dir) . 'media/' . $relative;
        if (!file_exists($file)) continue;

        // Bereits vorhanden?
        $existing = get_posts([
            'post_type'   => 'attachment',
            'meta_key'    => '_wp_attached_file',
            'meta_value'  => $relative,
            'numberposts' => 1,
        ]);
        if (!empty($existing)) continue;

        $file_array = [
            'name'     => basename($file),
            'tmp_name' => $file,
        ];
        $result = media_handle_sideload($file_array, $post_id);
        // Fehler werden still geloggt, Import wird fortgesetzt
        if (is_wp_error($result)) {
            error_log('sb_import_attachments error: ' . $result->get_error_message());
        }
    }
}
