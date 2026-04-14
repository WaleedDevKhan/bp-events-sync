<?php
namespace BPES;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin settings page.
 *
 * Renders the plugin configuration UI and enqueues admin assets.
 */
class Admin_Page {

    /** @var Settings */
    private $settings;

    /** @var Sync_Engine */
    private $sync;

    public function __construct( Settings $settings, Sync_Engine $sync ) {
        $this->settings = $settings;
        $this->sync     = $sync;

        add_action( 'admin_menu',    [ $this, 'register_menu' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    /* ─── Menu ──────────────────────────────────────────────────────────── */

    public function register_menu(): void {
        add_menu_page(
            'BP Events Sync',
            'BP Events Sync',
            'manage_options',
            'bpes-settings',
            [ $this, 'render_page' ],
            'dashicons-update',
            80
        );

        add_submenu_page(
            'bpes-settings',
            'Settings',
            'Settings',
            'manage_options',
            'bpes-settings',
            [ $this, 'render_page' ]
        );

        add_submenu_page(
            'bpes-settings',
            'Gallery',
            'Gallery',
            'manage_options',
            'bpes-gallery',
            [ $this, 'render_gallery_page' ]
        );

        add_submenu_page(
            'bpes-settings',
            'Agenda',
            'Agenda',
            'manage_options',
            'bpes-agenda',
            [ $this, 'render_agenda_page' ]
        );

        add_submenu_page(
            'bpes-settings',
            'Webhook Logs',
            'Webhook Logs',
            'manage_options',
            'bpes-webhook-logs',
            [ $this, 'render_webhook_logs_page' ]
        );
    }

    /* ─── Register Settings ─────────────────────────────────────────────── */

    public function register_settings(): void {
        // API Credentials.
        register_setting( 'bpes_settings_group', Settings::OPT_BASE_URL, [
            'type'              => 'string',
            'sanitize_callback' => 'esc_url_raw',
            'default'           => 'https://assets.events.businesspost.ie',
        ] );
        register_setting( 'bpes_settings_group', Settings::OPT_CF_CLIENT_ID, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'bpes_settings_group', Settings::OPT_CF_CLIENT_SECRET, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ] );
        register_setting( 'bpes_settings_group', Settings::OPT_EVENT_NAME, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
        ] );

        // CPT & Taxonomy mappings (saved via JS/AJAX, registered here for REST).
        register_setting( 'bpes_settings_group', Settings::OPT_ENABLED_CPTS, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_array' ],
        ] );
        register_setting( 'bpes_settings_group', Settings::OPT_CPT_MAPPING, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_array' ],
        ] );
        register_setting( 'bpes_settings_group', Settings::OPT_TAX_MAPPING, [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize_array' ],
        ] );

        // Agenda colours (separate settings group for the Agenda page form).
        register_setting( 'bpes_agenda_colours_group', Settings::OPT_AGENDA_ACCENT, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#2d5a3d',
        ] );
        register_setting( 'bpes_agenda_colours_group', Settings::OPT_AGENDA_ACCENT_LIGHT, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#dce8df',
        ] );
        register_setting( 'bpes_agenda_colours_group', Settings::OPT_AGENDA_ACCENT_BORDER, [
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_hex_color',
            'default'           => '#b0bfb5',
        ] );
    }

    /**
     * Sanitize an array of strings.
     */
    public function sanitize_array( $value ): array {
        if ( ! is_array( $value ) ) {
            return [];
        }
        return array_map( 'sanitize_text_field', $value );
    }

    /* ─── Assets ────────────────────────────────────────────────────────── */

    public function enqueue_assets( string $hook ): void {
        if ( ! in_array( $hook, [ 'toplevel_page_bpes-settings', 'bp-events-sync_page_bpes-gallery', 'bp-events-sync_page_bpes-agenda', 'bp-events-sync_page_bpes-webhook-logs' ], true ) ) {
            return;
        }

        wp_enqueue_style(
            'bpes-admin',
            BPES_PLUGIN_URL . 'admin/css/admin.css',
            [],
            BPES_VERSION
        );

        wp_enqueue_script(
            'bpes-admin',
            BPES_PLUGIN_URL . 'admin/js/admin.js',
            [ 'jquery' ],
            BPES_VERSION,
            true
        );

        // Pass data to JS.
        wp_localize_script( 'bpes-admin', 'bpesAdmin', [
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'bpes_sync_nonce' ),
            'strings' => [
                'syncing'    => __( 'Syncing…', 'bp-events-sync' ),
                'success'    => __( 'Sync complete!', 'bp-events-sync' ),
                'error'      => __( 'Sync failed.', 'bp-events-sync' ),
                'testing'    => __( 'Testing connection…', 'bp-events-sync' ),
                'connected'  => __( 'Connected!', 'bp-events-sync' ),
                'confirmName' => __( 'This will match posts by name/slug. Use this for initial sync. Continue?', 'bp-events-sync' ),
            ],
            'settings' => [
                'enabledCpts' => $this->settings->get_enabled_cpts(),
                'cptMapping'  => $this->settings->get_cpt_mapping(),
                'taxMapping'  => $this->settings->get_tax_mapping(),
                'lastSync'    => $this->settings->get_last_sync(),
                'initialDone' => get_option( Settings::OPT_INITIAL_SYNC_DONE, [] ),
            ],
        ] );
    }

    /* ─── Render ────────────────────────────────────────────────────────── */

    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Collect all registered CPTs for the dropdown.
        $registered_cpts = get_post_types( [ 'public' => true, '_builtin' => false ], 'objects' );

        include BPES_PLUGIN_DIR . 'admin/views/settings-page.php';
    }

    /**
     * Render the Gallery instructions page.
     */
    public function render_gallery_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        include BPES_PLUGIN_DIR . 'admin/views/gallery-page.php';
    }

    /**
     * Render the Agenda instructions page.
     */
    public function render_agenda_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        include BPES_PLUGIN_DIR . 'admin/views/agenda-page.php';
    }

    /**
     * Render the Webhook Logs page.
     */
    public function render_webhook_logs_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        include BPES_PLUGIN_DIR . 'admin/views/webhook-logs-page.php';
    }
}