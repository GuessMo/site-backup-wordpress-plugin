<?php
if (!defined('ABSPATH')) exit;

function sb_collect_attachment_names(WP_Post $post): array {
    $upload_dir = wp_upload_dir();
    $base_dir   = trailingslashit($upload_dir['basedir']);
    $result     = [];
    $seen_ids   = [];

    $add_attachment = function (int $att_id) use ($base_dir, &$result, &$seen_ids): void {
        if ($att_id <= 0 || in_array($att_id, $seen_ids, true)) return;
        $file = get_attached_file($att_id);
        if (!$file || !file_exists($file)) return;
        $relative      = str_replace($base_dir, '', $file);
        $media_folders = [];
        $folder_terms  = wp_get_object_terms($att_id, 'media_folder', ['fields' => 'slugs']);
        if (!is_wp_error($folder_terms)) {
            $media_folders = $folder_terms;
        }
        $result[]   = [
            'id'            => $att_id,
            'file'          => $file,
            'relative'      => $relative,
            'media_folders' => $media_folders,
        ];
        $seen_ids[] = $att_id;
    };

    foreach (get_attached_media('', $post->ID) as $att) {
        $add_attachment($att->ID);
    }

    $meta_keys = apply_filters('sb_attachment_meta_keys', ['_thumbnail_id', 'animal_images'], $post);
    foreach ($meta_keys as $key) {
        $value = get_post_meta($post->ID, $key, true);
        if (empty($value)) continue;
        $ids = is_array($value)
            ? array_map('intval', $value)
            : [intval($value)];
        foreach ($ids as $att_id) {
            $add_attachment($att_id);
        }
    }

    return $result;
}

function sb_collect_attachments(WP_Post $post): array {
    return sb_collect_attachment_names($post);
}

function sb_can_convert_webp(): bool {
    if (function_exists('imagewebp') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng')) {
        return true;
    }
    return class_exists('Imagick');
}

function sb_convert_to_webp(string $src_path, int $quality = 80): array {
    if (!file_exists($src_path)) {
        return ['success' => false, 'path' => $src_path, 'error' => 'file_not_found'];
    }

    $ext = strtolower(pathinfo($src_path, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        return ['success' => false, 'path' => $src_path, 'error' => 'unsupported_format'];
    }

    $webp_path = preg_replace('/\.(jpe?g|png)$/i', '.webp', $src_path);

    if (file_exists($webp_path) && filemtime($webp_path) >= filemtime($src_path)) {
        return [
            'success' => true,
            'path' => $webp_path,
            'converted' => true,
            'original_size' => filesize($src_path),
            'webp_size' => filesize($webp_path),
        ];
    }

    if (function_exists('imagewebp') && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng')) {
        $src_img = match ($ext) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($src_path),
            'png' => @imagecreatefrompng($src_path),
            default => null,
        };

        if (!$src_img) {
            return ['success' => false, 'path' => $src_path, 'error' => 'gd_load_failed'];
        }

        imagesavealpha($src_img, true);
        $result = imagewebp($src_img, $webp_path, $quality);
        imagedestroy($src_img);

        if (!$result || !file_exists($webp_path)) {
            return ['success' => false, 'path' => $src_path, 'error' => 'gd_save_failed'];
        }

        return [
            'success' => true,
            'path' => $webp_path,
            'converted' => true,
            'original_size' => filesize($src_path),
            'webp_size' => filesize($webp_path),
        ];
    }

    if (class_exists('Imagick')) {
        try {
            $imagick = new Imagick($src_path);
            $imagick->setImageFormat('webp');
            $imagick->setImageCompressionQuality($quality);
            $imagick->writeImage($webp_path);
            $imagick->destroy();

            if (!file_exists($webp_path)) {
                return ['success' => false, 'path' => $src_path, 'error' => 'imagick_save_failed'];
            }

            return [
                'success' => true,
                'path' => $webp_path,
                'converted' => true,
                'original_size' => filesize($src_path),
                'webp_size' => filesize($webp_path),
            ];
        } catch (Exception $e) {
            return ['success' => false, 'path' => $src_path, 'error' => 'imagick_exception: ' . $e->getMessage()];
        }
    }

    return ['success' => false, 'path' => $src_path, 'error' => 'no_converter'];
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

function sb_create_export_zips(array $manifest, array $posts, int $max_mb = 50, bool $convert_webp = true): array|WP_Error {
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
    $manifest_posts = array_values($manifest['posts']);
    $skip_webp = !$convert_webp || !sb_can_convert_webp();

    $make_zip_path = fn() => $export_dir . "sb-export-{$post_type}-{$timestamp}-part{$part}.zip";

    $open_zip = function(string $path) {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE) !== true) {
            return new WP_Error('zip_open_failed', "ZIP konnte nicht geöffnet werden: {$path}");
        }
        return $zip;
    };

    $finalize_chunk = function($zip, string $zip_path, array $chunk_posts_data, array $converted_files = []) use ($manifest) {
        $chunk_manifest           = $manifest;
        $chunk_manifest['posts']  = $chunk_posts_data;
        $chunk_manifest['count'] = count($chunk_posts_data);

        if (!empty($converted_files)) {
            $chunk_manifest['webp_converted'] = $converted_files;
        }

        $zip->addFromString('manifest.json', wp_json_encode($chunk_manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->close();
        return $zip_path;
    };

    $zip_path         = $make_zip_path();
    $zip              = $open_zip($zip_path);
    if (is_wp_error($zip)) return $zip;

    $chunk_posts_data = [];
    $current_bytes    = 0;
    $converted_files  = [];

    foreach ($posts as $index => $post) {
        $post_manifest = $manifest_posts[$index];
        $attachments   = $post_manifest['attachments'] ?? [];

        $att_files  = [];
        $post_bytes = 0;
        $post_converted = [];

        foreach ($attachments as $att) {
            $file = $att['file'] ?? '';
            if ($file && file_exists($file)) {
                $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                $is_image = in_array($file_ext, ['jpg', 'jpeg', 'png'], true);

                if ($is_image && !$skip_webp) {
                    $webp_result = sb_convert_to_webp($file);
                    if ($webp_result['success']) {
                        $webp_path    = $webp_result['path'];
                        $relative   = preg_replace('/\.(jpe?g|png)$/i', '.webp', $att['relative']);

                        $att_files[] = [
                            'file'      => $webp_path,
                            'relative' => $relative,
                        ];
                        $post_bytes += $webp_result['webp_size'];

                        $post_converted[] = [
                            'original'  => $att['relative'],
                            'webp'      => $relative,
                            'orig_size' => $webp_result['original_size'],
                            'webp_size' => $webp_result['webp_size'],
                        ];
                        continue;
                    }
                }

                $att_files[] = ['file' => $file, 'relative' => $att['relative']];
                $post_bytes  += filesize($file);
            }
        }

        if (!empty($post_converted)) {
            $converted_files = array_merge($converted_files, $post_converted);
        }

        if ($current_bytes > 0 && ($current_bytes + $post_bytes) > $max_bytes) {
            $zip_paths[]      = $finalize_chunk($zip, $zip_path, $chunk_posts_data, $converted_files);
            $part++;
            $chunk_posts_data = [];
            $current_bytes   = 0;
            $converted_files = [];
            $zip_path        = $make_zip_path();
            $zip             = $open_zip($zip_path);
            if (is_wp_error($zip)) return $zip;
        }

        foreach ($att_files as $att) {
            $zip->addFile($att['file'], 'media/' . $att['relative']);
        }

        $chunk_posts_data[] = $post_manifest;
        $current_bytes     += $post_bytes;
    }

    if (!empty($chunk_posts_data)) {
        $zip_paths[] = $finalize_chunk($zip, $zip_path, $chunk_posts_data, $converted_files);
    } else {
        $zip->close();
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

function sb_import_attachments(int $post_id, array $attachments, string $media_dir): array {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';

    $id_map = [];

    foreach ($attachments as $attachment) {
        $relative = $attachment['relative'] ?? '';
        if (empty($relative)) continue;

        $file = trailingslashit($media_dir) . 'media/' . $relative;
        if (!file_exists($file)) continue;

        $old_id = isset($attachment['id']) ? (int) $attachment['id'] : 0;

        $existing = get_posts([
            'post_type'      => 'attachment',
            'meta_key'       => '_wp_attached_file',
            'meta_value'     => $relative,
            'numberposts'    => 1,
            'fields'        => 'ids',
        ]);
        if (!empty($existing)) {
            if ($old_id > 0) {
                $id_map[$old_id] = (int) $existing[0];
            }
            continue;
        }

        $file_array = [
            'name'     => basename($file),
            'tmp_name' => $file,
        ];
        $new_id = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($new_id)) {
            error_log('sb_import_attachments error: ' . $new_id->get_error_message());
            continue;
        }

        if ($old_id > 0) {
            $id_map[$old_id] = $new_id;
        }

        $folders = $attachment['media_folders'] ?? [];
        if (!empty($folders) && taxonomy_exists('media_folder')) {
            wp_set_object_terms($new_id, $folders, 'media_folder', true);
        }
    }

    return $id_map;
}