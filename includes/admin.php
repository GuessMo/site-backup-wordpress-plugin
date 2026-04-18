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
        'nonce'         => wp_create_nonce('sb_export'),
        'importNonce'   => wp_create_nonce('sb_import'),
        'postsNonce'    => wp_create_nonce('sb_get_posts'),
        'allPostsNonce' => wp_create_nonce('sb_get_all_posts'),
        'ajaxUrl'       => admin_url('admin-ajax.php'),
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
                        <th><label for="sb-source-type">Quell-Post-Type</label></th>
                        <td>
                            <select name="source_type" id="sb-source-type">
                                <?php foreach ($post_types as $pt): ?>
                                    <option value="<?= esc_attr($pt->name) ?>"><?= esc_html($pt->label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sb-target-type">Ziel-Post-Type</label></th>
                        <td>
                            <select name="target_type" id="sb-target-type">
                                <?php foreach ($post_types as $pt): ?>
                                    <option value="<?= esc_attr($pt->name) ?>"><?= esc_html($pt->label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
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
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary">Importieren</button>
                </p>
            </form>
            <div id="sb-import-result"></div>
        </div>
    </div>
    <?php
}
