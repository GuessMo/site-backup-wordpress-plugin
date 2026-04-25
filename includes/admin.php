<?php
if (!defined('ABSPATH')) exit;

add_action('admin_menu', 'sb_register_admin_page');
function sb_register_admin_page() {
    add_menu_page(
        'Site Backup',
        'Site Backup',
        'manage_options',
        'site-backup',
        'sb_render_admin_page',
        'dashicons-backup',
        80
    );
}

add_action('admin_enqueue_scripts', 'sb_enqueue_admin_assets');
function sb_enqueue_admin_assets($hook) {
    if ($hook !== 'toplevel_page_site-backup') return;
    wp_enqueue_style('sb-admin', plugin_dir_url(dirname(__FILE__)) . 'assets/admin.css', [], '0.1.0');
    wp_enqueue_script('sb-admin', plugin_dir_url(dirname(__FILE__)) . 'assets/admin.js', [], '0.1.0', true);
    wp_localize_script('sb-admin', 'siteBackup', [
        'nonce'            => wp_create_nonce('sb_export'),
        'importNonce'      => wp_create_nonce('sb_import'),
        'postsNonce'       => wp_create_nonce('sb_get_posts'),
        'allPostsNonce'    => wp_create_nonce('sb_get_all_posts'),
        'exportUsersNonce'    => wp_create_nonce('sb_export_users'),
        'importUsersNonce'    => wp_create_nonce('sb_import_users'),
        'exportSettingsNonce' => wp_create_nonce('sb_export_settings'),
        'importSettingsNonce' => wp_create_nonce('sb_import_settings'),
        'ajaxUrl'             => admin_url('admin-ajax.php'),
        'peekNonce'    => wp_create_nonce('sb_peek_manifest'),
        'splitMaxMb'   => 50,
        'availableCpts' => array_values(array_map(
            fn($pt) => ['name' => $pt->name, 'label' => $pt->label],
            array_filter(
                get_post_types(['show_ui' => true], 'objects'),
                fn($pt) => !in_array($pt->name, [
                    'attachment', 'revision', 'nav_menu_item', 'custom_css',
                    'customize_changeset', 'oembed_cache', 'user_request',
                    'wp_block', 'wp_template', 'wp_template_part',
                    'wp_global_styles', 'wp_navigation',
                ], true)
            )
        )),
    ]);
}

function sb_render_admin_page() {
    $post_types = get_post_types(['public' => true], 'objects');
    $current_year = (int) date('Y');
    ?>
    <div class="wrap sb-wrap">
        <h1>Site Backup</h1>
        <nav class="sb-tabs">
            <button class="sb-tab active" data-tab="export">Export</button>
            <button class="sb-tab" data-tab="import">Import</button>
            <button class="sb-tab" data-tab="users">Benutzer</button>
            <button class="sb-tab" data-tab="settings">Einstellungen</button>
        </nav>

        <div class="sb-tab-content" id="sb-tab-export">
            <h2>Posts exportieren</h2>
            <form id="sb-export-form">
                <div class="sb-post-list-toolbar">
                    <button type="button" id="sb-toggle-all" class="button">Alle auswählen</button>
                    <span id="sb-selected-count">0 ausgewählt</span>
                    <span id="sb-loading-posts" style="display:none;">Lade Posts…</span>
                </div>
                <div id="sb-post-groups"></div>
                <p class="submit">
                    <button type="submit" id="sb-export-btn" class="button button-primary" disabled>Exportieren</button>
                </p>
            </form>
            <div id="sb-export-result"></div>
        </div>

        <div class="sb-tab-content" id="sb-tab-import" style="display:none;">
            <h2>Posts importieren</h2>
            <form id="sb-import-form" enctype="multipart/form-data">
                <table class="form-table">
                    <tr>
                        <th><label for="sb-collision">Bei vorhandenem Post</label></th>
                        <td>
                            <select name="collision" id="sb-collision">
                                <option value="skip">Überspringen wenn identisch</option>
                                <option value="override">Überschreiben</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sb-zip">Export-ZIP</label></th>
                        <td><input type="file" name="sb_zip" id="sb-zip" accept=".zip"></td>
                    </tr>
                    <tr>
                        <th><label for="sb-zip-file">Oder vom Server</label></th>
                        <td>
                            <input type="text" name="sb_zip_file" id="sb-zip-file" placeholder="dateiname.zip" style="width:250px;">
                            <p class="description">ZIP via FTP nach <code>wp-content/uploads/sb-exports/</code> hochladen und hier eingeben.</p>
                        </td>
                    </tr>
                </table>
                <div id="sb-cpt-mapping" style="display:none; margin: 12px 0;">
                    <h3>CPT-Zuordnung</h3>
                    <p class="description">Ordne jeden Quell-Post-Type dem gewünschten Ziel-Post-Type zu.</p>
                    <table class="widefat" id="sb-cpt-map-table">
                        <thead><tr><th>Quell-CPT (aus ZIP)</th><th>Ziel-CPT (diese Instanz)</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
                <p class="submit">
                    <button type="submit" class="button button-primary">Importieren</button>
                </p>
            </form>
            <div id="sb-import-result"></div>
        </div>

        <div class="sb-tab-content" id="sb-tab-users" style="display:none;">
            <h2>Benutzer exportieren</h2>
            <table class="form-table">
                <tr>
                    <th><label for="sb-users-role">Rolle filtern</label></th>
                    <td>
                        <select id="sb-users-role">
                            <option value="">Alle Rollen</option>
                            <option value="administrator">Administrator</option>
                            <option value="editor">Editor</option>
                            <option value="author">Autor</option>
                            <option value="contributor">Mitarbeiter</option>
                            <option value="subscriber">Abonnent</option>
                        </select>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="button" id="sb-export-users-btn" class="button button-primary">Benutzer exportieren</button>
            </p>
            <div id="sb-export-users-result"></div>

            <hr>

            <h2>Benutzer importieren</h2>
            <table class="form-table">
                <tr>
                    <th><label for="sb-users-zip">Benutzer-ZIP</label></th>
                    <td><input type="file" id="sb-users-zip" accept=".zip"></td>
                </tr>
            </table>
            <p class="submit">
                <button type="button" id="sb-import-users-btn" class="button button-primary">Importieren</button>
            </p>
            <div id="sb-import-users-result"></div>
        </div>

        <div class="sb-tab-content" id="sb-tab-settings" style="display:none;">
            <h2>Einstellungen exportieren</h2>
            <?php
            $settings_whitelist = sb_get_settings_whitelist();
            $theme_keys = sb_get_theme_plugin_option_keys();
            if (!empty($theme_keys)) {
                $settings_whitelist['theme_plugin'] = [
                    'label' => 'Theme & Plugin-Einstellungen',
                    'keys'  => $theme_keys,
                ];
            }
            $protected_keys = ['siteurl', 'home'];
            foreach ($settings_whitelist as $group_key => $group): ?>
                <fieldset style="border:1px solid #ddd; border-radius:4px; padding:12px 16px; margin-bottom:12px;">
                    <legend style="font-weight:600; padding:0 6px;"><?= esc_html($group['label']) ?></legend>
                    <?php foreach ($group['keys'] as $option_key): ?>
                        <label style="display:block; margin:4px 0;">
                            <input type="checkbox" name="sb_setting_keys[]" value="<?= esc_attr($option_key) ?>">
                            <code><?= esc_html($option_key) ?></code>
                            <?php if (in_array($option_key, $protected_keys, true)): ?>
                                <span style="color:#888; font-size:0.8em;">(wird beim Import nicht überschrieben)</span>
                            <?php endif; ?>
                        </label>
                    <?php endforeach; ?>
                </fieldset>
            <?php endforeach; ?>
            <p class="submit">
                <button type="button" id="sb-select-all-settings" class="button">Alle auswählen</button>
                <button type="button" id="sb-export-settings-btn" class="button button-primary">Ausgewählte exportieren</button>
            </p>
            <div id="sb-export-settings-result"></div>

            <hr>

            <h2>Einstellungen importieren</h2>
            <table class="form-table">
                <tr>
                    <th><label for="sb-settings-zip">Settings-ZIP</label></th>
                    <td><input type="file" id="sb-settings-zip" accept=".zip"></td>
                </tr>
            </table>
            <p class="submit">
                <button type="button" id="sb-import-settings-btn" class="button button-primary">Importieren</button>
            </p>
            <div id="sb-import-settings-result"></div>
        </div>
    </div>
    <?php
}
