<?php
namespace BPES;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main Plugin singleton.
 *
 * Wires up every subsystem: settings, admin UI, AJAX handlers, cron.
 */
class Plugin {

    /** @var self|null */
    private static $instance = null;

    /** @var Settings */
    public $settings;

    /** @var API_Client */
    public $api;

    /** @var Sync_Engine */
    public $sync;

    /** @var Admin_Page */
    public $admin;

    /** @var Gallery */
    public $gallery;

    /** @var Agenda */
    public $agenda;

    /** @var Webhook_Receiver */
    public $webhook;

    /**
     * Singleton accessor.
     */
    public static function instance(): self {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->settings = new Settings();
        $this->api      = new API_Client( $this->settings );
        $this->sync     = new Sync_Engine( $this->api, $this->settings );

        if ( is_admin() ) {
            $this->admin = new Admin_Page( $this->settings, $this->sync );
        }

        $this->gallery = new Gallery( $this->settings );
        $this->agenda  = new Agenda( $this->settings );
        $this->webhook = new Webhook_Receiver( $this->settings, $this->sync );

        $this->register_hooks();
    }

    /**
     * Register global hooks.
     */
    private function register_hooks(): void {
        // AJAX endpoints for admin sync actions.
        add_action( 'wp_ajax_bpes_run_sync',              [ $this, 'ajax_run_sync' ] );
        add_action( 'wp_ajax_bpes_detect_taxonomies',     [ $this, 'ajax_detect_taxonomies' ] );
        add_action( 'wp_ajax_bpes_test_connection',       [ $this, 'ajax_test_connection' ] );
        add_action( 'wp_ajax_bpes_clear_cache',           [ $this, 'ajax_clear_cache' ] );
        add_action( 'wp_ajax_bpes_webhook_register',      [ $this, 'ajax_webhook_register' ] );
        add_action( 'wp_ajax_bpes_webhook_unregister',    [ $this, 'ajax_webhook_unregister' ] );
        add_action( 'wp_ajax_bpes_webhook_regenerate',    [ $this, 'ajax_webhook_regenerate' ] );
        add_action( 'wp_ajax_bpes_webhook_clear_logs',    [ $this, 'ajax_webhook_clear_logs' ] );

        // Webhook REST endpoint (runs on front-end too, not just admin).
        $this->webhook->register_hooks();

        // Auto-sort synced CPT archives by menu_order.
        add_action( 'pre_get_posts', [ $this, 'sort_archives_by_menu_order' ] );

        // Auto-sort JetEngine listings by menu_order for speakers/judges.
        add_filter( 'jet-engine/listing/grid/posts-query-args', [ $this, 'sort_jet_listing_by_menu_order' ] );

        // Auto-sort JetEngine terms listings by tier_sort_order for sponsor tiers.
        // add_filter( 'jet-engine/listing/grid/terms-query-args', [ $this, 'sort_jet_terms_by_tier_order' ] );

    }

    /* ─── Archive Sorting ───────────────────────────────────────────────── */

    /**
     * Sort synced CPT archives by menu_order automatically.
     */
    public function sort_archives_by_menu_order( \WP_Query $query ): void {
        if ( is_admin() || ! $query->is_main_query() ) {
            return;
        }

        if ( ! ( $query->is_post_type_archive() || $query->is_tax() ) ) {
            return;
        }

        $post_type = $query->get( 'post_type' );

        // Get mapped CPT slugs for speakers and judges only (not sponsors).
        $cpt_mapping  = $this->settings->get_cpt_mapping();
        $sortable     = [];

        if ( ! empty( $cpt_mapping['speakers'] ) ) {
            $sortable[] = $cpt_mapping['speakers'];
        }
        if ( ! empty( $cpt_mapping['judges'] ) ) {
            $sortable[] = $cpt_mapping['judges'];
        }

        if ( is_string( $post_type ) && in_array( $post_type, $sortable, true ) ) {
            $query->set( 'orderby', 'menu_order' );
            $query->set( 'order', 'ASC' );
        }
    }

    /**
     * Sort JetEngine listing grids by menu_order for speakers/judges.
     */
    public function sort_jet_listing_by_menu_order( array $args ): array {
        $post_type = $args['post_type'] ?? '';

        // Handle array of post types.
        if ( is_array( $post_type ) && count( $post_type ) === 1 ) {
            $post_type = reset( $post_type );
        }

        if ( ! is_string( $post_type ) ) {
            return $args;
        }

        // Get mapped CPT slugs for speakers and judges only.
        $cpt_mapping = $this->settings->get_cpt_mapping();
        $sortable    = [];

        if ( ! empty( $cpt_mapping['speakers'] ) ) {
            $sortable[] = $cpt_mapping['speakers'];
        }
        if ( ! empty( $cpt_mapping['judges'] ) ) {
            $sortable[] = $cpt_mapping['judges'];
        }

        if ( in_array( $post_type, $sortable, true ) ) {
            $args['orderby'] = 'menu_order';
            $args['order']   = 'ASC';
        }

        return $args;
    }

    /**
     * Sort JetEngine terms listings by tier_sort_order meta for sponsor tier taxonomy.
     */
    public function sort_jet_terms_by_tier_order( array $args ): array {
        $taxonomy = $args['taxonomy'] ?? '';

        if ( is_array( $taxonomy ) && count( $taxonomy ) === 1 ) {
            $taxonomy = reset( $taxonomy );
        }

        if ( ! is_string( $taxonomy ) || empty( $taxonomy ) ) {
            return $args;
        }

        // Only apply to the sponsor taxonomy.
        $sponsor_tax = $this->settings->get_tax_slug( 'sponsors' );

        if ( empty( $sponsor_tax ) || $taxonomy !== $sponsor_tax ) {
            return $args;
        }

        $args['orderby'] = 'tier_order_clause';
        $args['order']   = 'ASC';

        // Named clause avoids the INNER JOIN that top-level meta_key forces,
        // so terms without the meta are still included (sorted last).
        $args['meta_query'] = [
            'relation' => 'OR',
            'tier_order_clause' => [
                'key'     => '_bpes_tier_sort_order',
                'type'    => 'NUMERIC',
                'compare' => 'EXISTS',
            ],
            [
                'key'     => '_bpes_tier_sort_order',
                'compare' => 'NOT EXISTS',
            ],
        ];

        return $args;
    }

    /* ─── AJAX Handlers ─────────────────────────────────────────────────── */

    /**
     * Run a manual sync via AJAX.
     */
    public function ajax_run_sync(): void {
        check_ajax_referer( 'bpes_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        $mode     = sanitize_text_field( $_POST['sync_mode'] ?? 'id' );    // 'id' | 'name'
        $cpt_type = sanitize_text_field( $_POST['cpt_type'] ?? '' );       // 'speakers' | 'judges' | 'sponsors'

        if ( ! in_array( $cpt_type, [ 'speakers', 'judges', 'sponsors' ], true ) ) {
            wp_send_json_error( [ 'message' => 'Invalid CPT type.' ] );
        }

        $result = $this->sync->run( $cpt_type, $mode );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    /**
     * Auto-detect taxonomies for a given CPT slug.
     */
    public function ajax_detect_taxonomies(): void {
        check_ajax_referer( 'bpes_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        $post_type = sanitize_text_field( $_POST['post_type'] ?? '' );

        if ( empty( $post_type ) || ! post_type_exists( $post_type ) ) {
            wp_send_json_error( [ 'message' => 'Invalid post type.' ] );
        }

        $taxonomies = get_object_taxonomies( $post_type, 'objects' );
        $result     = [];

        foreach ( $taxonomies as $tax ) {
            $result[] = [
                'slug'         => $tax->name,
                'label'        => $tax->labels->name,
                'hierarchical' => $tax->hierarchical,
            ];
        }

        wp_send_json_success( $result );
    }

    /**
     * Test the API connection.
     */
    public function ajax_test_connection(): void {
        check_ajax_referer( 'bpes_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        $result = $this->api->test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( [ 'message' => 'Connection successful.' ] );
    }

    /**
     * Clear all plugin transient caches (gallery + agenda).
     */
    public function ajax_clear_cache(): void {
        check_ajax_referer( 'bpes_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        global $wpdb;

        // Delete all gallery and agenda transients.
        $deleted = $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '_transient_bpes_gallery_%'
                OR option_name LIKE '_transient_timeout_bpes_gallery_%'
                OR option_name LIKE '_transient_bpes_agenda_%'
                OR option_name LIKE '_transient_timeout_bpes_agenda_%'"
        );

        wp_send_json_success( [
            'message' => sprintf( 'Cache cleared. %d entries removed.', (int) $deleted ),
        ] );
    }

    /* ─── Webhook AJAX Handlers ─────────────────────────────────────────── */

    /**
     * Register this site as a webhook subscriber in the Events CMS.
     */
    public function ajax_webhook_register(): void {
        check_ajax_referer( 'bpes_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        $webhook_url = rest_url( 'bpes/v1/sync' );
        $secret      = $this->settings->get_webhook_secret();
        $event_name  = $this->settings->get_event_name();

        if ( empty( $event_name ) ) {
            wp_send_json_error( [ 'message' => 'Event name is not configured.' ] );
        }

        $response = wp_remote_post(
            $this->settings->get_base_url() . '/admin/webhooks/subscribe',
            [
                'timeout' => 15,
                'headers' => [
                    'CF-Access-Client-Id'     => $this->settings->get_cf_client_id(),
                    'CF-Access-Client-Secret' => $this->settings->get_cf_client_secret(),
                    'Content-Type'            => 'application/json',
                ],
                'body' => wp_json_encode( [
                    'url'        => $webhook_url,
                    'secret'     => $secret,
                    'event_name' => $event_name,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code >= 200 && $code < 300 && ! empty( $body['ok'] ) ) {
            update_option( Settings::OPT_WEBHOOK_ENABLED, 1 );
            wp_send_json_success( [ 'message' => 'Webhook registered successfully.', 'webhook_url' => $webhook_url ] );
        } else {
            $error = $body['error'] ?? $body['message'] ?? 'Unknown error';
            wp_send_json_error( [ 'message' => "CMS returned: {$error}" ] );
        }
    }

    /**
     * Unregister this site's webhook from the Events CMS.
     */
    public function ajax_webhook_unregister(): void {
        check_ajax_referer( 'bpes_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        $webhook_url = rest_url( 'bpes/v1/sync' );
        $event_name  = $this->settings->get_event_name();

        $response = wp_remote_request(
            $this->settings->get_base_url() . '/admin/webhooks/unsubscribe',
            [
                'method'  => 'DELETE',
                'timeout' => 15,
                'headers' => [
                    'CF-Access-Client-Id'     => $this->settings->get_cf_client_id(),
                    'CF-Access-Client-Secret' => $this->settings->get_cf_client_secret(),
                    'Content-Type'            => 'application/json',
                ],
                'body' => wp_json_encode( [
                    'url'        => $webhook_url,
                    'event_name' => $event_name,
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ 'message' => $response->get_error_message() ] );
        }

        update_option( Settings::OPT_WEBHOOK_ENABLED, 0 );
        wp_send_json_success( [ 'message' => 'Webhook unregistered.' ] );
    }

    /**
     * Clear all webhook logs.
     */
    public function ajax_webhook_clear_logs(): void {
        check_ajax_referer( 'bpes_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        update_option( Settings::OPT_WEBHOOK_LOGS, [] );
        wp_send_json_success( [ 'message' => 'Logs cleared.' ] );
    }

    /**
     * Regenerate the webhook secret and re-register with the CMS.
     */
    public function ajax_webhook_regenerate(): void {
        check_ajax_referer( 'bpes_sync_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized.' ] );
        }

        // Generate new secret
        $new_secret = bin2hex( random_bytes( 32 ) );
        update_option( Settings::OPT_WEBHOOK_SECRET, $new_secret );

        wp_send_json_success( [ 'message' => 'Secret regenerated. Click "Register with CMS" to update.' ] );
    }
}