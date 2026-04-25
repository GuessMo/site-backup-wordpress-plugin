<?php
/**
 * Plugin Name: TSV Site Backup
 * Description: Export and import WordPress posts (including media) between instances with post type mapping. PHP 5.6 / WP 5.4+ compatible.
 * Version: 0.9.6
 * Update URI: false
 * Author: Hersteller.io
 * Text Domain: tsv-site-backup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'POST_MIGRATOR_VERSION', '0.9.6' );
define( 'POST_MIGRATOR_DIR', plugin_dir_path( __FILE__ ) );
define( 'POST_MIGRATOR_URL', plugin_dir_url( __FILE__ ) );

// PHP 5.6 / 7.x Polyfills
if ( ! function_exists( 'str_starts_with' ) ) {
    function str_starts_with( $haystack, $needle ) {
        return strncmp( $haystack, $needle, strlen( $needle ) ) === 0;
    }
}
if ( ! function_exists( 'str_ends_with' ) ) {
    function str_ends_with( $haystack, $needle ) {
        return $needle === '' || substr( $haystack, -strlen( $needle ) ) === $needle;
    }
}
if ( ! function_exists( 'str_contains' ) ) {
    function str_contains( $haystack, $needle ) {
        return $needle === '' || strpos( $haystack, $needle ) !== false;
    }
}

require_once POST_MIGRATOR_DIR . 'includes/admin.php';
require_once POST_MIGRATOR_DIR . 'includes/export.php';
require_once POST_MIGRATOR_DIR . 'includes/import.php';
require_once POST_MIGRATOR_DIR . 'includes/media.php';
require_once POST_MIGRATOR_DIR . 'includes/users.php';
require_once POST_MIGRATOR_DIR . 'includes/settings.php';
