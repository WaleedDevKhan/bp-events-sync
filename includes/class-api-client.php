<?php
namespace BPES;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * HTTP client for the BP Events central CMS API.
 *
 * Handles Cloudflare Access authentication, pagination, and error normalisation.
 */
class API_Client {

    /** @var Settings */
    private $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;
    }

    /* ─── Public Methods ────────────────────────────────────────────────── */

    /**
     * Test the API connection by fetching page 1 of sponsors with pageSize=1.
     *
     * @return true|\WP_Error
     */
    public function test_connection() {
        $url = $this->build_url( '/admin/live', [
            'event'    => $this->settings->get_event_name(),
            'pageSize' => 1,
        ] );

        $response = $this->request( $url );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['ok'] ) ) {
            return new \WP_Error( 'bpes_api_error', 'API returned ok=false.' );
        }

        $total = (int) ( $response['total'] ?? 0 );

        if ( $total === 0 ) {
            return new \WP_Error(
                'bpes_no_data',
                sprintf(
                    'Connection successful but no data found for event "%s". Check the event name matches your CMS exactly.',
                    $this->settings->get_event_name()
                )
            );
        }

        return true;
    }

    /**
     * Fetch all sponsors for the configured event.
     *
     * Paginates automatically until all items are retrieved.
     *
     * @return array|\WP_Error Array of sponsor items or error.
     */
    public function fetch_sponsors(): array|\WP_Error {
        return $this->fetch_all_pages( '/admin/sponsorsWithSort/live', [
            'event'    => $this->settings->get_event_name(),
            'pageSize' => 200,
        ] );
    }

    /**
     * Fetch speakers or judges for the configured event.
     *
     * @param string $type 'speaker' | 'judge'
     * @return array|\WP_Error Array of speaker/judge items or error.
     */
    public function fetch_speakers( string $type = 'speaker' ): array|\WP_Error {
        return $this->fetch_all_pages( '/admin/speakers/live', [
            'event'    => $this->settings->get_event_name(),
            'type'     => $type,
            'pageSize' => 200,
        ] );
    }

    /**
     * Fetch a single sponsor by its upload ID.
     *
     * @param string $id Upload ID (UUID) from the CMS.
     * @return array|\WP_Error Sponsor item array or error.
     */
    public function fetch_sponsor_by_id( string $id ): array|\WP_Error {
        $url      = $this->build_url( '/admin/live/' . rawurlencode( $id ) );
        $response = $this->request( $url );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['ok'] ) || empty( $response['item'] ) ) {
            return new \WP_Error( 'bpes_not_found', "Sponsor {$id} not found in CMS." );
        }

        return (array) $response['item'];
    }

    /**
     * Fetch a single speaker or judge by their profile ID.
     *
     * @param string $id Speaker profile ID (UUID) from the CMS.
     * @return array|\WP_Error Speaker item array or error.
     */
    public function fetch_speaker_by_id( string $id ): array|\WP_Error {
        $url      = $this->build_url( '/admin/speakers/live/' . rawurlencode( $id ) );
        $response = $this->request( $url );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['ok'] ) || empty( $response['item'] ) ) {
            return new \WP_Error( 'bpes_not_found', "Speaker {$id} not found in CMS." );
        }

        return (array) $response['item'];
    }

    /**
     * Fetch deletion log entries since a given timestamp.
     *
     * @param int    $since Unix timestamp. Only deletions after this time are returned.
     * @param string $type  Optional. 'sponsor' | 'speaker' to filter by entity type.
     * @return array|\WP_Error Array of deletion entries or error.
     */
    public function fetch_deletions( int $since = 0, string $type = '' ): array|\WP_Error {
        $params = [
            'since' => $since,
        ];

        if ( ! empty( $type ) ) {
            $params['type'] = $type;
        }

        $url      = $this->build_url( '/admin/wp/deletions', $params );
        $response = $this->request( $url );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( empty( $response['ok'] ) ) {
            return new \WP_Error( 'bpes_api_error', 'Deletions API returned ok=false.' );
        }

        return $response['deletions'] ?? [];
    }

    /* ─── Pagination ────────────────────────────────────────────────────── */

    /**
     * Fetch all pages from a paginated endpoint.
     *
     * @param string $path   API path.
     * @param array  $params Query parameters.
     * @return array|\WP_Error All items merged or error.
     */
    private function fetch_all_pages( string $path, array $params ): array|\WP_Error {
        $all_items = [];
        $page      = 1;

        do {
            $params['page'] = $page;
            $url             = $this->build_url( $path, $params );
            $response        = $this->request( $url );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            if ( empty( $response['ok'] ) ) {
                return new \WP_Error(
                    'bpes_api_error',
                    sprintf( 'API error on page %d: ok=false', $page )
                );
            }

            $items     = $response['items'] ?? [];
            $all_items = array_merge( $all_items, $items );
            $total     = (int) ( $response['total'] ?? 0 );
            $page_size = (int) ( $response['pageSize'] ?? 200 );
            $page++;

        } while ( count( $all_items ) < $total && ! empty( $items ) );

        return $all_items;
    }

    /* ─── HTTP Layer ────────────────────────────────────────────────────── */

    /**
     * Make an authenticated GET request to the API.
     *
     * @param string $url Full URL.
     * @return array|\WP_Error Decoded JSON body or error.
     */
    private function request( string $url ): array|\WP_Error {
        $args = [
            'timeout' => 30,
            'headers' => [
                'CF-Access-Client-Id'     => $this->settings->get_cf_client_id(),
                'CF-Access-Client-Secret' => $this->settings->get_cf_client_secret(),
                'Accept'                  => 'application/json',
            ],
        ];

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            $this->log( 'HTTP error: ' . $response->get_error_message(), $url );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code < 200 || $code >= 300 ) {
            $msg = sprintf( 'API returned HTTP %d: %s', $code, wp_trim_words( $body, 30 ) );
            $this->log( $msg, $url );
            return new \WP_Error( 'bpes_http_error', $msg );
        }

        $data = json_decode( $body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            $msg = 'JSON decode error: ' . json_last_error_msg();
            $this->log( $msg, $url );
            return new \WP_Error( 'bpes_json_error', $msg );
        }

        return $data;
    }

    /* ─── Helpers ────────────────────────────────────────────────────────── */

    /**
     * Build a full URL from a path and query parameters.
     */
    private function build_url( string $path, array $params = [] ): string {
        $base = $this->settings->get_base_url();
        $url  = $base . '/' . ltrim( $path, '/' );

        if ( ! empty( $params ) ) {
            $url .= '?' . http_build_query( $params );
        }

        return $url;
    }

    /**
     * Log an error for debugging.
     */
    private function log( string $message, string $url = '' ): void {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[BP Events Sync] %s | URL: %s',
                $message,
                $url
            ) );
        }
    }
}