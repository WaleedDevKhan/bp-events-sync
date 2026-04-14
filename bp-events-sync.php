<?php
/**
 * Plugin Name: BP Events Sync
 * Plugin URI:  https://businesspost.ie
 * Description: Syncs speakers, judges, and sponsors from the BP Events central CMS API into local WordPress CPTs.
 * Version:     1.0.3
 * Author:      Business Post
 * Author URI:  https://businesspost.ie
 * Text Domain: bp-events-sync
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ── Plugin Constants ────────────────────────────────────────────────────────
define( 'BPES_VERSION', '1.0.3' );
define( 'BPES_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BPES_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'BPES_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'BPES_MIN_SYNC_YEAR', 2026 );
define( 'BPES_R2_BASE_URL', 'https://assets.events.businesspost.ie/r2/' );

// ── Plugin Update Checker ────────────────────────────────────────────────────
require_once BPES_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$bpes_updater = PucFactory::buildUpdateChecker(
    'https://github.com/WaleedDevKhan/bp-events-sync/',
    __FILE__,
    'bp-events-sync'
);
$bpes_updater->setBranch( 'main' );
$bpes_updater->setAuthentication( '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' );
$bpes_updater->getVcsApi()->enableReleaseAssets();

// ── Autoloader ──────────────────────────────────────────────────────────────
spl_autoload_register( function ( $class ) {
    $prefix    = 'BPES\\';
    $base_dir  = BPES_PLUGIN_DIR . 'includes/';

    if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
        return;
    }

    $relative = substr( $class, strlen( $prefix ) );
    // BPES\API_Client → includes/class-api-client.php
    $file = $base_dir . 'class-' . strtolower( str_replace( [ '\\', '_' ], [ '/', '-' ], $relative ) ) . '.php';

    if ( file_exists( $file ) ) {
        require_once $file;
    }
});

// ── Boot ────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    BPES\Plugin::instance();
});

// ── Activation / Deactivation ───────────────────────────────────────────────
register_activation_hook( __FILE__, [ 'BPES\\Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'BPES\\Deactivator', 'deactivate' ] );
