<?php
/**
 * Plugin Name: TSV Site Backup
 * Description: Export and import WordPress posts (including media) between instances with post type mapping.
 * Version: 0.9.0
 * Update URI: false
 * Author: Hersteller.io
 * Text Domain: tsv-site-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'POST_MIGRATOR_VERSION', '0.9.0' );
define( 'POST_MIGRATOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'POST_MIGRATOR_URL', plugin_dir_url( __FILE__ ) );

require_once POST_MIGRATOR_DIR . 'includes/admin.php';
require_once POST_MIGRATOR_DIR . 'includes/export.php';
require_once POST_MIGRATOR_DIR . 'includes/import.php';
require_once POST_MIGRATOR_DIR . 'includes/media.php';
require_once POST_MIGRATOR_DIR . 'includes/users.php';
require_once POST_MIGRATOR_DIR . 'includes/settings.php';
