<?php
if (!defined('ABSPATH')) exit;

add_action('wp_ajax_sb_export_users', 'sb_ajax_export_users');
function sb_ajax_export_users() {
    check_ajax_referer('sb_export_users', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    $role = sanitize_key(isset($_POST['role']) ? $_POST['role'] : '');

    $args = array('number' => -1);
    if (!empty($role)) {
        $args['role'] = $role;
    }
    $users = get_users($args);

    $meta_blacklist = array(
        'session_tokens', 'wp_user_level', 'wp_capabilities',
    );

    $export_data = array();
    foreach ($users as $user) {
        $meta_raw = get_user_meta($user->ID);
        $meta = array();
        foreach ($meta_raw as $key => $values) {
            if (in_array($key, $meta_blacklist, true)) continue;
            if (str_starts_with($key, '_transient_')) continue;
            $meta[$key] = count($values) === 1 ? maybe_unserialize($values[0]) : array_map('maybe_unserialize', $values);
        }

        $export_data[] = array(
            'user_login'      => $user->user_login,
            'user_email'      => $user->user_email,
            'display_name'    => $user->display_name,
            'user_registered' => $user->user_registered,
            'roles'           => $user->roles,
            'meta'            => $meta,
        );
    }

    $manifest = array(
        'exported_at' => date('c'),
        'source_url'  => site_url(),
        'count'       => count($export_data),
        'users'       => $export_data,
    );

    $upload_dir = wp_upload_dir();
    $export_dir = trailingslashit($upload_dir['basedir']) . 'sb-exports';
    wp_mkdir_p($export_dir);
    $timestamp = date('Ymd-His');
    $zip_path  = $export_dir . "/sb-users-{$timestamp}.zip";

    if (!class_exists('ZipArchive')) {
        wp_send_json_error(array('message' => 'ZipArchive nicht verfügbar.'));
    }

    $zip = new ZipArchive();
    if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        wp_send_json_error(array('message' => 'ZIP konnte nicht erstellt werden.'));
    }
    $zip->addFromString('users.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    $zip->close();

    $url = trailingslashit($upload_dir['baseurl']) . "sb-exports/sb-users-{$timestamp}.zip";
    wp_send_json_success(array('url' => $url, 'count' => count($export_data)));
}

add_action('wp_ajax_sb_import_users', 'sb_ajax_import_users');
function sb_ajax_import_users() {
    check_ajax_referer('sb_import_users', 'nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'Nicht autorisiert.'), 403);
    }

    if (empty($_FILES['sb_users_zip']['tmp_name'])) {
        wp_send_json_error(array('message' => 'Keine ZIP-Datei hochgeladen.'));
    }

    $file = $_FILES['sb_users_zip'];
    if (!str_ends_with($file['name'], '.zip')) {
        wp_send_json_error(array('message' => 'Nur ZIP-Dateien erlaubt.'));
    }

    $extract_dir = sb_extract_zip($file['tmp_name']);
    if (is_wp_error($extract_dir)) {
        wp_send_json_error(array('message' => $extract_dir->get_error_message()));
    }

    $json_path = trailingslashit($extract_dir) . 'users.json';
    if (!file_exists($json_path)) {
        wp_send_json_error(array('message' => 'users.json nicht gefunden.'));
    }

    $manifest = json_decode(file_get_contents($json_path), true);
    if (!is_array($manifest) || !isset($manifest['users'])) {
        wp_send_json_error(array('message' => 'Ungültiges users.json.'));
    }

    $created = array();
    $updated = array();
    $errors  = array();
    $has_new = false;

    foreach ($manifest['users'] as $u) {
        $email   = sanitize_email(isset($u['user_email'])   ? $u['user_email']   : '');
        $login   = sanitize_user(isset($u['user_login'])    ? $u['user_login']   : '');
        $display = sanitize_text_field(isset($u['display_name']) ? $u['display_name'] : $login);
        $roles   = array_map('sanitize_key', (array) (isset($u['roles']) ? $u['roles'] : array('subscriber')));
        $primary = $roles[0];

        $existing_id = email_exists($email);

        if ($existing_id === false) {
            $user_id = wp_insert_user(array(
                'user_login'   => $login,
                'user_email'   => $email,
                'display_name' => $display,
                'role'         => $primary,
                'user_pass'    => wp_generate_password(24),
            ));
            if (is_wp_error($user_id)) {
                $errors[] = $login . ': ' . $user_id->get_error_message();
                continue;
            }
            $has_new   = true;
            $created[] = $login;
        } else {
            $user_id = $existing_id;
            wp_update_user(array(
                'ID'           => $user_id,
                'display_name' => $display,
                'role'         => $primary,
            ));
            $updated[] = $login;
        }

        $wp_user = new WP_User($user_id);
        foreach (array_slice($roles, 1) as $extra_role) {
            $wp_user->add_role($extra_role);
        }

        if (!empty($u['meta']) && is_array($u['meta'])) {
            foreach ($u['meta'] as $key => $value) {
                update_user_meta($user_id, sanitize_key($key), $value);
            }
        }
    }

    @array_map('unlink', glob($extract_dir . '/*'));
    @rmdir($extract_dir);

    wp_send_json_success(array(
        'created'             => $created,
        'updated'             => $updated,
        'errors'              => $errors,
        'password_reset_hint' => $has_new,
    ));
}
