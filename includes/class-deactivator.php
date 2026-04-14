<?php
namespace BPES;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin deactivation handler.
 */
class Deactivator {

    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
