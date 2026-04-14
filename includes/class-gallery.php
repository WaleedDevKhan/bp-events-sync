<?php
namespace BPES;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Photo Gallery.
 *
 * Registers the [bpes_gallery] shortcode that fetches event photos
 * from the public photos API endpoint and renders a masonry grid
 * with a fullscreen lightbox.
 *
 * Usage: [bpes_gallery event_id="uuid" columns="4" cache="3600"]
 */
class Gallery {

    /** @var Settings */
    private $settings;

    public function __construct( Settings $settings ) {
        $this->settings = $settings;

        add_shortcode( 'bpes_gallery', [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
    }

    /**
     * Register frontend CSS and JS (only loaded when shortcode is used).
     */
    public function register_assets(): void {
        $lg_version = '2.8.3';
        $cdn_base   = "https://cdnjs.cloudflare.com/ajax/libs/lightgallery/{$lg_version}";

        // LightGallery core CSS.
        wp_register_style( 'lightgallery', "{$cdn_base}/css/lightgallery-bundle.min.css", [], $lg_version );

        // Plugin gallery grid CSS.
        wp_register_style( 'bpes-gallery', BPES_PLUGIN_URL . 'assets/css/gallery.css', [ 'lightgallery' ], BPES_VERSION );

        // LightGallery core JS.
        wp_register_script( 'lightgallery', "{$cdn_base}/lightgallery.umd.min.js", [], $lg_version, true );

        // LightGallery plugins.
        wp_register_script( 'lg-zoom', "{$cdn_base}/plugins/zoom/lg-zoom.umd.min.js", [ 'lightgallery' ], $lg_version, true );
        wp_register_script( 'lg-thumbnail', "{$cdn_base}/plugins/thumbnail/lg-thumbnail.umd.min.js", [ 'lightgallery' ], $lg_version, true );
        wp_register_script( 'lg-fullscreen', "{$cdn_base}/plugins/fullscreen/lg-fullscreen.umd.min.js", [ 'lightgallery' ], $lg_version, true );
        wp_register_script( 'lg-share', "{$cdn_base}/plugins/share/lg-share.umd.min.js", [ 'lightgallery' ], $lg_version, true );

        // Plugin gallery init JS.
        wp_register_script( 'bpes-gallery', BPES_PLUGIN_URL . 'assets/js/gallery.js', [
            'lightgallery', 'lg-zoom', 'lg-thumbnail', 'lg-fullscreen', 'lg-share',
        ], BPES_VERSION, true );
    }

    /**
     * Render the gallery shortcode.
     */
    public function render( $atts ): string {
        $atts = shortcode_atts( [
            'event_id' => '',
            'columns'  => 4,
            'cache'    => 3600,
        ], $atts, 'bpes_gallery' );

        $event_id = sanitize_text_field( $atts['event_id'] );
        $columns  = absint( $atts['columns'] );
        $cache    = absint( $atts['cache'] );

        if ( empty( $event_id ) ) {
            return '<p class="bpes-gallery-error">Gallery error: event_id attribute is required.</p>';
        }

        if ( $columns < 1 || $columns > 6 ) {
            $columns = 4;
        }

        // Fetch photos (with caching).
        $photos = $this->get_photos( $event_id, $cache );

        if ( is_wp_error( $photos ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                return '<p class="bpes-gallery-error">Gallery error: ' . esc_html( $photos->get_error_message() ) . '</p>';
            }
            return '';
        }

        if ( empty( $photos ) ) {
            return '<p class="bpes-gallery-empty">No photos available for this event.</p>';
        }

        // Enqueue assets only when the shortcode is actually used.
        wp_enqueue_style( 'bpes-gallery' );
        wp_enqueue_script( 'bpes-gallery' );

        return $this->build_html( $photos, $columns );
    }

    /**
     * Fetch photos from the public endpoint with transient caching.
     *
     * @param string $event_id Event UUID.
     * @param int    $cache    Cache duration in seconds.
     * @return array|\WP_Error Array of photo objects or error.
     */
    private function get_photos( string $event_id, int $cache ) {
        $cache_key = 'bpes_gallery_' . md5( $event_id );

        if ( $cache > 0 ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $url = $this->settings->get_base_url() . '/r2/photos/' . urlencode( $event_id );

        // Public endpoint — no auth headers needed.
        $response = wp_remote_get( $url, [
            'timeout' => 30,
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );

        if ( $code !== 200 ) {
            return new \WP_Error( 'bpes_gallery_http', sprintf( 'Photos API returned HTTP %d', $code ) );
        }

        $data = json_decode( $body, true );

        if ( empty( $data['ok'] ) ) {
            return new \WP_Error( 'bpes_gallery_api', 'Photos API returned ok=false.' );
        }

        $photos = $data['photos'] ?? [];

        if ( $cache > 0 && ! empty( $photos ) ) {
            set_transient( $cache_key, $photos, $cache );
        }

        return $photos;
    }

    /**
     * Build the gallery HTML.
     */
    private function build_html( array $photos, int $columns ): string {
        ob_start();
        ?>
        <div class="bpes-gallery" data-columns="<?php echo esc_attr( $columns ); ?>">
            <div class="bpes-gallery-grid" id="bpes-lightgallery-<?php echo esc_attr( wp_unique_id() ); ?>">
                <?php foreach ( $photos as $index => $photo ) : ?>
                    <div class="bpes-gallery-item"
                         data-src="<?php echo esc_url( $photo['mid'] ); ?>"
                         data-download-url="<?php echo esc_url( $photo['full'] ); ?>"
                         data-sub-html="&nbsp;"
                         data-thumb="<?php echo esc_url( $photo['thumb'] ); ?>"
                         data-zoom-src="<?php echo esc_url( $photo['full'] ); ?>">
                        <img
                            src="<?php echo esc_url( $photo['thumb'] ); ?>"
                            alt="Event photo <?php echo esc_attr( $index + 1 ); ?>"
                            loading="lazy"
                        />
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }
}