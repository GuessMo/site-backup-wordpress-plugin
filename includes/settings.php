<?php
if (!defined('ABSPATH')) exit;

function sb_get_settings_whitelist() {
    return array(
        'general' => array(
            'label' => 'Allgemein',
            'keys'  => array('blogname', 'blogdescription', 'siteurl', 'home', 'admin_email', 'blogpublic'),
        ),
        'permalinks' => array(
            'label' => 'Permalinks',
            'keys'  => array('permalink_structure', 'category_base', 'tag_base'),
        ),
        'media' => array(
            'label' => 'Medien',
            'keys'  => array(
                'thumbnail_size_w', 'thumbnail_size_h',
                'medium_size_w', 'medium_size_h',
                'large_size_w', 'large_size_h',
                'uploads_use_yearmonth_folders',
            ),
        ),
    );
}

function sb_get_theme_plugin_option_keys() {
    global $wpdb;
    $prefixes = array('tsvd_%', 'sb_%', 'klaro_%');
    $keys = array();
    foreach ($prefixes as $prefix) {
        $results = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                $prefix
            )
        );
        $keys = array_merge($keys, $results);
    }
    $keys = array_filter($keys, function($k) {
        return !str_contains($k, 'transient') && !str_contains($k, '_user_roles');
    });
    return array_values(array_unique($keys));
}

function sb_get_all_allowed_keys() {
    $whitelist = sb_get_settings_whitelist();
    $keys = array();
    foreach ($whitelist as $group) {
        $keys = array_merge($keys, $group['keys']);
    }
    $keys = array_merge($keys, sb_get_theme_plugin_option_keys());
    return array_unique($keys);
}

add_action('wp_ajax_sb_export_settings', 'sb_ajax_export_settings');
function sb_ajax_export_settings() {
    check_ajax_referer('sb_export_settings', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    $requested_keys = isset($_POST['keys']) && is_array($_POST['keys'])
        ? array_map('sanitize_key', $_POST['keys'])
        : array();

    if (empty($requested_keys)) {
        wp_send_json_error(array('message' => 'Keine Einstellungen ausgewählt.'));
    }

    $allowed  = sb_get_all_allowed_keys();
    $settings = array();
    foreach ($requested_keys as $key) {
        if (!in_array($key, $allowed, true)) continue;
        $settings[$key] = get_option($key);
    }

    $manifest = array(
        'exported_at' => date('c'),
        'source_url'  => site_url(),
        'count'       => count($settings),
        'settings'    => $settings,
    );

    $upload_dir = wp_upload_dir();
    $export_dir = trailingslashit($upload_dir['basedir']) . 'sb-exports';
    wp_mkdir_p($export_dir);
    $timestamp = date('Ymd-His');
    $zip_path  = $export_dir . "/sb-settings-{$timestamp}.zip";

    if (!class_exists('ZipArchive')) {
        wp_send_json_error(array('message' => 'ZipArchive nicht verfügbar.'));
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        wp_send_json_error(array('message' => 'ZIP konnte nicht erstellt werden.'));
    }
    $zip->addFromString('settings.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->close();

    $url = trailingslashit($upload_dir['baseurl']) . "sb-exports/sb-settings-{$timestamp}.zip";
    wp_send_json_success(array('url' => $url, 'count' => count($settings)));
}

add_action('wp_ajax_sb_import_settings', 'sb_ajax_import_settings');
function sb_ajax_import_settings() {
    check_ajax_referer('sb_import_settings', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    if (empty($_FILES['sb_settings_zip']['tmp_name'])) {
        wp_send_json_error(array('message' => 'Keine ZIP-Datei hochgeladen.'));
    }

    $file = $_FILES['sb_settings_zip'];
    if (!str_ends_with($file['name'], '.zip')) {
        wp_send_json_error(array('message' => 'Nur ZIP-Dateien erlaubt.'));
    }

    $extract_dir = sb_extract_zip($file['tmp_name']);
    if (is_wp_error($extract_dir)) {
        wp_send_json_error(array('message' => $extract_dir->get_error_message())); 
    }

    $json_path = trailingslashit($extract_dir) . 'settings.json';
    if (!file_exists($json_path)) {
        wp_send_json_error(array('message' => 'settings.json nicht gefunden.'));
    }

    $manifest = json_decode(file_get_contents($json_path), true);
    if (!is_array($manifest) || !isset($manifest['settings'])) {
        wp_send_json_error(array('message' => 'Ungültiges settings.json.'));
    }

    $protected         = array('siteurl', 'home');
    $allowed           = sb_get_all_allowed_keys();
    $updated           = array();
    $skipped_protected = array();
    $skipped_invalid   = array();
    $warnings          = array();

    foreach ($manifest['settings'] as $key => $value) {
        $key = sanitize_key($key);
        if (in_array($key, $protected, true)) {
            $skipped_protected[] = $key;
            continue;
        }
        if (!in_array($key, $allowed, true)) {
            $skipped_invalid[] = $key;
            continue;
        }
        update_option($key, $value);
        $updated[] = $key;
    }

    @array_map('unlink', glob($extract_dir . '/*'));
    @rmdir($extract_dir);

    wp_send_json_success(array(
        'updated'           => $updated,
        'skipped_protected' => $skipped_protected,
        'skipped_invalid'   => $skipped_invalid,
        'warnings'          => $warnings,
    ));
}
