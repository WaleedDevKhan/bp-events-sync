<?php
namespace BPES;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin activation handler.
 */
class Activator {

    public static function activate(): void {
        // Set default options if they don't exist.
        foreach ( Settings::DEFAULTS as $key => $value ) {
            if ( get_option( $key ) === false ) {
                add_option( $key, $value );
            }
        }

        // Flush rewrite rules after CPT changes.
        flush_rewrite_rules();

        // Clear OPCache so updated plugin files are picked up immediately.
        if ( function_exists( 'opcache_reset' ) ) {
            opcache_reset();
        }
    }
}
