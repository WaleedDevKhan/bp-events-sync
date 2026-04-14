<?php
namespace BPES;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralised read/write access to every plugin option.
 *
 * All option keys are prefixed with `bpes_` to avoid collisions.
 */
class Settings {

    /* ─── Option Keys ───────────────────────────────────────────────────── */
    const OPT_BASE_URL        = 'bpes_base_url';
    const OPT_CF_CLIENT_ID    = 'bpes_cf_client_id';
    const OPT_CF_CLIENT_SECRET = 'bpes_cf_client_secret';
    const OPT_EVENT_NAME      = 'bpes_event_name';
    const OPT_ENABLED_CPTS    = 'bpes_enabled_cpts';       // [ 'speakers', 'judges', 'sponsors' ]
    const OPT_CPT_MAPPING     = 'bpes_cpt_mapping';        // [ 'speakers' => 'speaker', … ]
    const OPT_TAX_MAPPING     = 'bpes_tax_mapping';        // [ 'speakers' => 'speaker_category', … ]
    const OPT_LAST_SYNC       = 'bpes_last_sync';          // JSON per-type timestamp
    const OPT_INITIAL_SYNC_DONE = 'bpes_initial_sync_done'; // [ 'speakers' => true, … ]
    const OPT_CRON_ENABLED    = 'bpes_cron_enabled';
    const OPT_CRON_INTERVAL   = 'bpes_cron_interval';      // in minutes
    const OPT_LAST_DELETION_SYNC = 'bpes_last_deletion_sync'; // unix timestamp
    const OPT_AGENDA_ACCENT        = 'bpes_agenda_accent';
    const OPT_AGENDA_ACCENT_LIGHT  = 'bpes_agenda_accent_light';
    const OPT_AGENDA_ACCENT_BORDER = 'bpes_agenda_accent_border';
    const OPT_WEBHOOK_ENABLED      = 'bpes_webhook_enabled';   // bool
    const OPT_WEBHOOK_SECRET       = 'bpes_webhook_secret';    // pre-shared HMAC secret
    const OPT_WEBHOOK_LOGS         = 'bpes_webhook_logs';      // array of last 50 log entries

    /* ─── Defaults ──────────────────────────────────────────────────────── */
    const DEFAULTS = [
        self::OPT_BASE_URL          => 'https://assets.events.businesspost.ie',
        self::OPT_CF_CLIENT_ID      => '',
        self::OPT_CF_CLIENT_SECRET  => '',
        self::OPT_EVENT_NAME        => '',
        self::OPT_ENABLED_CPTS      => [],
        self::OPT_CPT_MAPPING       => [],
        self::OPT_TAX_MAPPING       => [],
        self::OPT_LAST_SYNC         => [],
        self::OPT_INITIAL_SYNC_DONE => [],
        self::OPT_CRON_ENABLED      => false,
        self::OPT_CRON_INTERVAL     => 30,
        self::OPT_LAST_DELETION_SYNC => 0,
        self::OPT_AGENDA_ACCENT       => '#2d5a3d',
        self::OPT_AGENDA_ACCENT_LIGHT => '#dce8df',
        self::OPT_AGENDA_ACCENT_BORDER => '#b0bfb5',
    ];

    /* ─── Getters ───────────────────────────────────────────────────────── */

    public function get_base_url(): string {
        return untrailingslashit( (string) get_option( self::OPT_BASE_URL, self::DEFAULTS[ self::OPT_BASE_URL ] ) );
    }

    public function get_cf_client_id(): string {
        return (string) get_option( self::OPT_CF_CLIENT_ID, '' );
    }

    public function get_cf_client_secret(): string {
        return (string) get_option( self::OPT_CF_CLIENT_SECRET, '' );
    }

    public function get_event_name(): string {
        return (string) get_option( self::OPT_EVENT_NAME, '' );
    }

    /**
     * @return string[] e.g. [ 'speakers', 'judges', 'sponsors' ]
     */
    public function get_enabled_cpts(): array {
        $val = get_option( self::OPT_ENABLED_CPTS, [] );
        return is_array( $val ) ? $val : [];
    }

    /**
     * Get the WordPress CPT slug mapped to one of our types.
     *
     * @param string $type 'speakers' | 'judges' | 'sponsors'
     * @return string The registered CPT slug, e.g. 'speaker'.
     */
    public function get_cpt_slug( string $type ): string {
        $map = get_option( self::OPT_CPT_MAPPING, [] );
        return $map[ $type ] ?? '';
    }

    /**
     * Get the full CPT mapping array.
     */
    public function get_cpt_mapping(): array {
        $val = get_option( self::OPT_CPT_MAPPING, [] );
        return is_array( $val ) ? $val : [];
    }

    /**
     * Get the taxonomy slug mapped for a given CPT type.
     *
     * @param string $type 'speakers' | 'judges' | 'sponsors'
     * @return string The taxonomy slug.
     */
    public function get_tax_slug( string $type ): string {
        $map = get_option( self::OPT_TAX_MAPPING, [] );
        return $map[ $type ] ?? '';
    }

    /**
     * Get the full taxonomy mapping array.
     */
    public function get_tax_mapping(): array {
        $val = get_option( self::OPT_TAX_MAPPING, [] );
        return is_array( $val ) ? $val : [];
    }

    /**
     * Check if initial sync has been done for a type.
     */
    public function is_initial_sync_done( string $type ): bool {
        $data = get_option( self::OPT_INITIAL_SYNC_DONE, [] );
        return ! empty( $data[ $type ] );
    }

    /**
     * Get last sync timestamps.
     */
    public function get_last_sync(): array {
        $val = get_option( self::OPT_LAST_SYNC, [] );
        return is_array( $val ) ? $val : [];
    }

    public function is_cron_enabled(): bool {
        return (bool) get_option( self::OPT_CRON_ENABLED, false );
    }

    public function get_cron_interval(): int {
        return (int) get_option( self::OPT_CRON_INTERVAL, 30 );
    }

    public function get_last_deletion_sync(): int {
        return (int) get_option( self::OPT_LAST_DELETION_SYNC, 0 );
    }

    public function get_agenda_accent(): string {
        return (string) get_option( self::OPT_AGENDA_ACCENT, '#2d5a3d' );
    }

    public function get_agenda_accent_light(): string {
        return (string) get_option( self::OPT_AGENDA_ACCENT_LIGHT, '#dce8df' );
    }

    public function get_agenda_accent_border(): string {
        return (string) get_option( self::OPT_AGENDA_ACCENT_BORDER, '#b0bfb5' );
    }

    public function is_webhook_enabled(): bool {
        return (bool) get_option( self::OPT_WEBHOOK_ENABLED, false );
    }

    /**
     * Get the webhook HMAC secret, auto-generating one if it doesn't exist yet.
     */
    public function get_webhook_secret(): string {
        $secret = (string) get_option( self::OPT_WEBHOOK_SECRET, '' );
        if ( empty( $secret ) ) {
            $secret = bin2hex( random_bytes( 32 ) );
            update_option( self::OPT_WEBHOOK_SECRET, $secret );
        }
        return $secret;
    }

    /* ─── Setters ───────────────────────────────────────────────────────── */

    /**
     * Update the last deletion sync unix timestamp.
     */
    public function update_last_deletion_sync(): void {
        update_option( self::OPT_LAST_DELETION_SYNC, time() );
    }

    /**
     * Mark initial sync as done for a given type.
     */
    public function set_initial_sync_done( string $type ): void {
        $data = get_option( self::OPT_INITIAL_SYNC_DONE, [] );
        $data[ $type ] = true;
        update_option( self::OPT_INITIAL_SYNC_DONE, $data );
    }

    /**
     * Update the last sync timestamp for a type.
     */
    public function update_last_sync( string $type ): void {
        $data = get_option( self::OPT_LAST_SYNC, [] );
        $data[ $type ] = current_time( 'mysql' );
        update_option( self::OPT_LAST_SYNC, $data );
    }

    /* ─── Validation ────────────────────────────────────────────────────── */

    /**
     * Check that the minimum required settings are present.
     */
    public function is_configured(): bool {
        return ! empty( $this->get_cf_client_id() )
            && ! empty( $this->get_cf_client_secret() )
            && ! empty( $this->get_event_name() )
            && ! empty( $this->get_enabled_cpts() );
    }

    /**
     * Check configuration is complete for a specific CPT type.
     */
    public function is_type_configured( string $type ): bool {
        return in_array( $type, $this->get_enabled_cpts(), true )
            && ! empty( $this->get_cpt_slug( $type ) )
            && ! empty( $this->get_tax_slug( $type ) );
    }
}