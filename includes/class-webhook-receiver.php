<?php
namespace BPES;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Webhook receiver for push-based sync from the Events CMS.
 *
 * Registers a REST endpoint that the CMS calls whenever a sponsor,
 * speaker, or judge is added, updated, or deleted. Verifies the
 * HMAC-SHA256 signature, then triggers the existing sync engine.
 */
class Webhook_Receiver {

    /** @var Settings */
    private $settings;

    /** @var Sync_Engine */
    private $sync;

    public function __construct( Settings $settings, Sync_Engine $sync ) {
        $this->settings = $settings;
        $this->sync     = $sync;
    }

    public function register_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_route' ] );
        add_action( 'bpes_agenda_cache_clear', [ $this, 'run_agenda_cache_clear' ] );
    }

    /**
     * Register POST /wp-json/bpes/v1/sync
     */
    public function register_route(): void {
        register_rest_route( 'bpes/v1', '/sync', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle' ],
            'permission_callback' => '__return_true', // auth via HMAC signature below
        ] );
    }

    /**
     * Handle an incoming webhook from the Events CMS.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function handle( \WP_REST_Request $request ): \WP_REST_Response {

        if ( ! $this->settings->is_webhook_enabled() ) {
            $this->log( '', '', '', 'ignored', 'Webhooks are disabled on this site.' );
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Webhooks disabled' ], 403 );
        }

        // Verify HMAC-SHA256 signature
        $sig      = $request->get_header( 'x_bpes_signature' );
        $body     = $request->get_body();
        $secret   = $this->settings->get_webhook_secret();
        $expected = hash_hmac( 'sha256', $body, $secret );

        if ( ! hash_equals( $expected, (string) $sig ) ) {
            $this->log( '', '', '', 'error', 'Invalid signature — secret may be out of sync.' );
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Invalid signature' ], 401 );
        }

        $data       = $request->get_json_params();
        $event_name = sanitize_text_field( $data['event_name'] ?? '' );
        $type       = sanitize_text_field( $data['type'] ?? '' );
        $action     = sanitize_text_field( $data['action'] ?? '' );

        // Only act if this matches our configured event
        $configured_event = $this->settings->get_event_name();
        if ( empty( $event_name ) || strtolower( $event_name ) !== strtolower( $configured_event ) ) {
            $this->log( $event_name, $type, $action, 'ignored', "Event '{$event_name}' does not match configured event '{$configured_event}'." );
            return new \WP_REST_Response( [ 'ok' => true, 'message' => 'Event not matched, ignored' ], 200 );
        }

        // Agenda webhooks — schedule a cache clear 3 minutes from now.
        // Debounce: cancel any pending clear first so rapid edits don't stack up.
        if ( $type === 'agenda' ) {
            $pending = wp_next_scheduled( 'bpes_agenda_cache_clear' );
            if ( $pending ) {
                wp_unschedule_event( $pending, 'bpes_agenda_cache_clear' );
            }
            wp_schedule_single_event( time() + 120, 'bpes_agenda_cache_clear' );
            return new \WP_REST_Response( [ 'ok' => true, 'message' => 'Agenda cache clear scheduled' ], 200 );
        }

        if ( ! in_array( $type, [ 'sponsors', 'speakers', 'judges' ], true ) ) {
            $this->log( $event_name, $type, $action, 'error', "Unknown type '{$type}'." );
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'Unknown type' ], 400 );
        }

        if ( ! $this->settings->is_type_configured( $type ) ) {
            $this->log( $event_name, $type, $action, 'ignored', "Type '{$type}' is not enabled on this site." );
            return new \WP_REST_Response( [ 'ok' => true, 'message' => "Type '{$type}' not enabled on this site, ignored" ], 200 );
        }

        // Run targeted sync if entity_id is present, otherwise full sync (e.g. reorder).
        $entity_id = sanitize_text_field( $data['entity_id'] ?? '' );

        if ( ! empty( $entity_id ) ) {
            $result = $this->sync->run_for_webhook( $type, $entity_id, $action );
        } else {
            $result = $this->sync->run( $type, 'id' );
        }

        if ( is_wp_error( $result ) ) {
            $this->log( $event_name, $type, $action, 'error', $result->get_error_message() );
            return new \WP_REST_Response(
                [ 'ok' => false, 'error' => $result->get_error_message() ],
                500
            );
        }

        $errors  = (int) ( $result['errors'] ?? 0 );
        $synced  = ( $result['created'] ?? 0 ) + ( $result['updated'] ?? 0 ) + ( $result['deleted'] ?? 0 );

        // Detect non-critical failures in the engine log (e.g. image download errors).
        $has_warnings = (bool) array_filter( $result['log'] ?? [], function ( $line ) {
            return preg_match( '/(Failed|Error:)/i', $line );
        } );

        if ( $errors > 0 && $synced === 0 ) {
            $status = 'error';   // Hard failure — nothing was synced.
        } elseif ( $has_warnings ) {
            $status = 'warning'; // Synced OK but something minor failed (e.g. image).
        } else {
            $status = 'success';
        }

        // Build summary line.
        $summary = sprintf(
            'Created: %d, Updated: %d, Deleted: %d, Errors: %d.',
            $result['created'] ?? 0,
            $result['updated'] ?? 0,
            $result['deleted'] ?? 0,
            $errors
        );

        // Strip [HH:MM:SS] prefix from engine log lines.
        $strip_ts = function ( string $line ): string {
            return preg_replace( '/^\[\d{2}:\d{2}:\d{2}\]\s*/', '', $line );
        };

        // Append item-level detail lines (what was created/updated/deleted and how).
        $detail_lines = array_filter( $result['log'] ?? [], function ( $line ) {
            return preg_match( '/(Created:|Updated:|Deleted:)/i', $line );
        } );
        if ( ! empty( $detail_lines ) ) {
            $summary .= ' ' . implode( ' | ', array_map( $strip_ts, $detail_lines ) );
        }

        // Append any error or warning lines so failures are always visible.
        $error_lines = array_filter( $result['log'] ?? [], function ( $line ) {
            return preg_match( '/(Error:|Failed|falling back)/i', $line );
        } );
        if ( ! empty( $error_lines ) ) {
            $summary .= ' — ' . implode( ' | ', array_map( $strip_ts, $error_lines ) );
        }

        $this->log( $event_name, $type, $action, $status, $summary );

        return new \WP_REST_Response( [
            'ok'     => true,
            'type'   => $type,
            'action' => $action,
            'result' => $result,
        ], 200 );
    }

    /**
     * WP-Cron callback — clears agenda caches after the 2-minute delay.
     */
    public function run_agenda_cache_clear(): void {
        $this->clear_agenda_cache();
        $event_name = $this->settings->get_event_name();
        $this->log( $event_name, 'agenda', 'updated', 'success', 'Agenda cache cleared.' );
    }

    /**
     * Clear all agenda transients and FastCGI cache.
     */
    private function clear_agenda_cache(): void {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_bpes_agenda_%'
             OR option_name LIKE '_transient_timeout_bpes_agenda_%'"
        );

        wp_cache_flush();

        $cache_dir = '/var/run/nginx-cache';
        if ( is_dir( $cache_dir ) ) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $cache_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ( $iterator as $file ) {
                if ( $file->isFile() ) {
                    @unlink( $file->getPathname() );
                }
            }
        }
    }

    /**
     * Append a log entry (keeps last 50).
     *
     * Uses MySQL GET_LOCK() for true atomicity — unlike transients, this blocks
     * concurrent requests at the DB level so no log entries are lost.
     */
    private function log( string $event_name, string $type, string $action, string $status, string $message ): void {
        global $wpdb;

        // Acquire a MySQL advisory lock so concurrent webhook requests queue up.
        $wpdb->query( "SELECT GET_LOCK('bpes_webhook_log', 5)" );

        // Read directly from DB — bypasses WordPress's object cache (which is
        // pre-loaded per-process at request startup and would otherwise return
        // the same stale value in both concurrent requests).
        $raw  = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
            Settings::OPT_WEBHOOK_LOGS
        ) );
        $logs = $raw ? (array) maybe_unserialize( $raw ) : [];

        $logs[] = [
            'ts'         => time(),
            'event_name' => $event_name,
            'type'       => $type,
            'action'     => $action,
            'status'     => $status,
            'message'    => $message,
        ];

        // Keep only the latest 50 entries.
        if ( count( $logs ) > 50 ) {
            $logs = array_slice( $logs, -50 );
        }

        // Write directly to DB — INSERT if row doesn't exist yet, UPDATE otherwise.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %s, 'yes')
             ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)",
            Settings::OPT_WEBHOOK_LOGS,
            maybe_serialize( $logs )
        ) );

        // Bust the WordPress object cache so the next get_option() call in any
        // code path returns the value we just wrote, not the startup snapshot.
        wp_cache_delete( 'alloptions', 'options' );
        wp_cache_set( Settings::OPT_WEBHOOK_LOGS, $logs, 'options' );

        $wpdb->query( "SELECT RELEASE_LOCK('bpes_webhook_log')" );
    }
}
