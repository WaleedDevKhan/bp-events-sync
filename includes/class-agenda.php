<?php
namespace BPES;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Agenda Shortcode.
 *
 * Renders the event agenda from the public API using the `page_blocks` array,
 * which provides pre-ordered blocks of two kinds:
 *   - "section"      → standalone main-stage items (sessions, breaks, banners)
 *   - "stream_group" → parallel breakout streams with sections inside each
 *
 * Usage: [bpes_agenda slug="health-summit-2026" cache="300"]
 */
class Agenda {

    /** @var Settings */
    private $settings;

    /** @var array Cached map of lowercase speaker name → permalink URL. */
    private $speaker_urls = [];

    public function __construct( Settings $settings ) {
        $this->settings = $settings;

        add_shortcode( 'bpes_agenda', [ $this, 'render' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
    }

    /**
     * Register frontend CSS (only loaded when shortcode is used).
     */
    public function register_assets(): void {
        wp_register_style(
            'bpes-agenda',
            BPES_PLUGIN_URL . 'assets/css/agenda.css',
            [],
            BPES_VERSION
        );
    }

    /**
     * Render the agenda shortcode.
     */
    public function render( $atts ): string {
        $atts = shortcode_atts( [
            'slug'  => '',
            'cache' => 300,
        ], $atts, 'bpes_agenda' );

        $slug  = sanitize_text_field( $atts['slug'] );
        $cache = absint( $atts['cache'] );

        if ( empty( $slug ) ) {
            return '<p class="bpes-agenda-error">Agenda error: slug attribute is required.</p>';
        }

        $data = $this->get_agenda( $slug, $cache );

        if ( is_wp_error( $data ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                return '<p class="bpes-agenda-error">Agenda error: ' . esc_html( $data->get_error_message() ) . '</p>';
            }
            return '';
        }

        $page_blocks = $data['page_blocks'] ?? [];

        if ( empty( $page_blocks ) ) {
            return '<p class="bpes-agenda-empty">No agenda available for this event.</p>';
        }

        wp_enqueue_style( 'bpes-agenda' );

        return $this->build_html( $page_blocks );
    }

    /**
     * Fetch agenda from the public endpoint with transient caching.
     */
    private function get_agenda( string $slug, int $cache ) {
        $cache_key = 'bpes_agenda_' . md5( $slug );

        if ( $cache > 0 ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        $url = $this->settings->get_base_url() . '/r2/agenda/' . urlencode( $slug );

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
            return new \WP_Error( 'bpes_agenda_http', sprintf( 'Agenda API returned HTTP %d', $code ) );
        }

        $data = json_decode( $body, true );

        if ( empty( $data['ok'] ) ) {
            return new \WP_Error( 'bpes_agenda_api', 'Agenda API returned ok=false.' );
        }

        if ( $cache > 0 ) {
            set_transient( $cache_key, $data, $cache );
        }

        return $data;
    }

    /**
     * Build the agenda HTML by walking through page_blocks in order.
     */
    private function build_html( array $page_blocks ): string {
        // Build speaker name → URL cache for linking.
        $this->build_speaker_url_cache( $page_blocks );

        $accent        = esc_attr( $this->settings->get_agenda_accent() );
        $accent_light  = esc_attr( $this->settings->get_agenda_accent_light() );
        $accent_border = esc_attr( $this->settings->get_agenda_accent_border() );

        ob_start();
        ?>
        <div class="bpes-agenda" style="--bpes-accent: <?php echo $accent; ?>; --bpes-accent-light: <?php echo $accent_light; ?>; --bpes-accent-border: <?php echo $accent_border; ?>;">
            <?php foreach ( $page_blocks as $block ) :
                $kind = $block['kind'] ?? '';

                if ( $kind === 'section' ) {
                    $this->render_section_block( $block );
                } elseif ( $kind === 'stream_group' ) {
                    $this->render_stream_group( $block );
                }
            endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a standalone section block (main stage).
     *
     * special_title determines the rendering:
     *   "banner"  → green section heading
     *   "break"   → simple break card
     *   "session" → regular session card (no label)
     *   anything else → session card with special_title as uppercase label
     */
    private function render_section_block( array $section ): void {
        $raw_special   = $section['special_title'] ?? '';
        $special_title = $this->normalise( $raw_special );
        $title         = $section['title'] ?? '';
        $subtitle      = $section['subtitle'] ?? '';
        $speakers      = $section['speakers'] ?? [];
        $moderators    = $section['moderators'] ?? [];
        $time          = $section['time_value'] ?? '';

        if ( $special_title === 'banner' ) {
            $this->render_banner( $title );
            return;
        }

        if ( $special_title === 'break' ) {
            $this->render_break_card( $time, $title );
            return;
        }

        // "session", "section", or empty → no label; anything else → show original as label.
        $label = ( in_array( $special_title, [ 'session', 'section', '' ], true ) ) ? '' : $raw_special;

        $this->render_session_card( $time, $label, $title, $subtitle, $speakers, $moderators );
    }

    /**
     * Render a stream group (parallel breakout streams).
     */
    private function render_stream_group( array $block ): void {
        $streams   = $block['streams'] ?? [];
        $col_count = count( $streams );

        if ( $col_count === 0 ) {
            return;
        }
        ?>
        <div class="bpes-agenda-streams" style="--bpes-stream-cols: <?php echo esc_attr( $col_count ); ?>;">
            <!-- Stream headers -->
            <div class="bpes-agenda-stream-headers">
                <?php foreach ( $streams as $stream ) : ?>
                    <div class="bpes-agenda-stream-header">
                        <div class="bpes-agenda-stream-name"><?php echo esc_html( $stream['name'] ?? '' ); ?></div>
                        <?php if ( ! empty( $stream['subtitle'] ) ) : ?>
                            <div class="bpes-agenda-stream-subtitle"><?php echo esc_html( $stream['subtitle'] ); ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Stream columns -->
            <div class="bpes-agenda-stream-grid">
                <?php foreach ( $streams as $stream ) : ?>
                    <div class="bpes-agenda-stream-column">
                        <?php
                        $sections = $stream['sections'] ?? [];
                        foreach ( $sections as $section ) :
                            $raw_special = $section['special_title'] ?? '';
                            $s_special   = $this->normalise( $raw_special );
                            $s_title     = $section['title'] ?? '';
                            $s_sub       = $section['subtitle'] ?? '';
                            $s_time      = $section['time_value'] ?? '';
                            $s_spkrs     = $section['speakers'] ?? [];
                            $s_mods      = $section['moderators'] ?? [];

                            if ( $s_special === 'banner' ) {
                                $this->render_banner( $s_title );
                            } elseif ( $s_special === 'break' ) {
                                $this->render_break_card( $s_time, $s_title );
                            } else {
                                $label = ( in_array( $s_special, [ 'session', 'section', '' ], true ) ) ? '' : $raw_special;
                                $this->render_session_card( $s_time, $label, $s_title, $s_sub, $s_spkrs, $s_mods );
                            }
                        endforeach;
                        ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }

    /* ─── Rendering Helpers ──────────────────────────────────────────── */

    /**
     * Render a section banner (green divider heading).
     */
    private function render_banner( string $title ): void {
        if ( empty( $title ) ) {
            return;
        }
        ?>
        <div class="bpes-agenda-banner">
            <h3><?php echo esc_html( $title ); ?></h3>
        </div>
        <?php
    }

    /**
     * Render a break card.
     */
    private function render_break_card( string $time, string $title ): void {
        ?>
        <?php $this->render_time_badge( $time ); ?>
        <div class="bpes-agenda-card bpes-agenda-break">
            <div class="bpes-agenda-card-body">
                <h4 class="bpes-agenda-title"><?php echo esc_html( $title ); ?></h4>
            </div>
        </div>
        <?php
    }

    /**
     * Render a session card.
     */
    private function render_session_card( string $time, string $special_title, string $title, string $subtitle, array $speakers, array $moderators = [] ): void {
        // Any special_title that isn't banner/break/session/empty shows as a label.
        $skip_labels = [ 'banner', 'break', 'session', 'section', '' ];
        $show_label  = ! in_array( $this->normalise( $special_title ), $skip_labels, true );
        ?>
        <?php $this->render_time_badge( $time ); ?>
        <div class="bpes-agenda-card bpes-agenda-session">
            <div class="bpes-agenda-card-body">
                <?php if ( $show_label ) : ?>
                    <div class="bpes-agenda-session-type"><?php echo esc_html( strtoupper( $special_title ) . ':' ); ?></div>
                <?php endif; ?>

                <?php if ( ! empty( $title ) ) : ?>
                    <h4 class="bpes-agenda-title"><?php echo esc_html( $title ); ?></h4>
                <?php endif; ?>

                <?php if ( ! empty( $subtitle ) ) : ?>
                    <div class="bpes-agenda-subtitle"><?php echo wp_kses_post( $subtitle ); ?></div>
                <?php endif; ?>

                <?php if ( ! empty( $speakers ) ) : ?>
                    <div class="bpes-agenda-speakers">
                        <div class="bpes-agenda-speakers-label">
                            <?php echo esc_html( count( $speakers ) > 1 ? 'Speakers:' : 'Speaker:' ); ?>
                        </div>
                        <?php foreach ( $speakers as $speaker ) :
                            $this->render_speaker( $speaker );
                        endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ( ! empty( $moderators ) ) : ?>
                    <div class="bpes-agenda-speakers bpes-agenda-moderators">
                        <div class="bpes-agenda-speakers-label">
                            <?php echo esc_html( count( $moderators ) > 1 ? 'Moderators:' : 'Moderator:' ); ?>
                        </div>
                        <?php foreach ( $moderators as $moderator ) :
                            $this->render_speaker( $moderator );
                        endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render a time badge as a wrapper div above the card.
     */
    private function render_time_badge( string $time ): void {
        if ( empty( $time ) ) {
            return;
        }

        $formatted = $this->format_time( $time );
        ?>
        <div class="bpes-agenda-time-wrapper">
            <div class="bpes-agenda-time">
                <svg class="bpes-agenda-clock-icon" width="16" height="16" viewBox="0 0 16 16" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.5"/>
                    <path d="M8 4V8L10.5 10.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                <span><?php echo esc_html( $formatted ); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Render a speaker row.
     */
    private function render_speaker( array $speaker ): void {
        $name         = $speaker['name'] ?? '';
        $title        = $speaker['title'] ?? '';
        $organisation = $speaker['organisation'] ?? '';
        $photo_url    = $speaker['photo_url'] ?? '';
        $link         = $this->get_speaker_url( $name );

        $role = '';
        if ( $title && $organisation ) {
            $role = $title . ', ' . $organisation;
        } elseif ( $title ) {
            $role = $title;
        } elseif ( $organisation ) {
            $role = $organisation;
        }
        ?>
        <div class="bpes-agenda-speaker">
            <?php if ( $link ) : ?><a href="<?php echo esc_url( $link ); ?>" class="bpes-agenda-speaker-link"><?php endif; ?>
            <div class="bpes-agenda-speaker-photo">
                <?php if ( ! empty( $photo_url ) ) : ?>
                    <img src="<?php echo esc_url( $photo_url ); ?>" alt="<?php echo esc_attr( $name ); ?>" loading="lazy" />
                <?php else : ?>
                    <div class="bpes-agenda-speaker-placeholder"></div>
                <?php endif; ?>
            </div>
            <div class="bpes-agenda-speaker-info">
                <div class="bpes-agenda-speaker-name"><?php echo esc_html( $name ); ?></div>
                <?php if ( ! empty( $role ) ) : ?>
                    <div class="bpes-agenda-speaker-role"><?php echo esc_html( $role ); ?></div>
                <?php endif; ?>
            </div>
            <?php if ( $link ) : ?></a><?php endif; ?>
        </div>
        <?php
    }

    /**
     * Format 24h time to 12h with AM/PM.
     */
    private function format_time( string $time ): string {
        $parts = explode( ':', $time );
        $hour  = (int) ( $parts[0] ?? 0 );
        $min   = $parts[1] ?? '00';
        $ampm  = $hour >= 12 ? 'PM' : 'AM';

        if ( $hour === 0 ) {
            $hour = 12;
        } elseif ( $hour > 12 ) {
            $hour -= 12;
        }

        return $hour . ':' . $min . $ampm;
    }

    /**
     * Normalise a special_title value for comparison.
     *
     * Lowercases, replaces hyphens and underscores with spaces,
     * collapses multiple spaces, and trims.
     *
     * e.g. "Panel-Discussion" → "panel discussion"
     *      "OPENING_ADDRESS"  → "opening address"
     *      "panel  discussion" → "panel discussion"
     */
    private function normalise( string $value ): string {
        $value = strtolower( $value );
        $value = str_replace( [ '-', '_' ], ' ', $value );
        $value = preg_replace( '/\s+/', ' ', $value );
        return trim( $value );
    }

    /**
     * Build a cache of speaker/moderator name → WordPress permalink.
     *
     * Collects all unique speaker names from page_blocks, then runs a single
     * query to find matching posts in the speaker/judge CPTs.
     */
    private function build_speaker_url_cache( array $page_blocks ): void {
        $this->speaker_urls = [];

        // Collect all unique speaker/moderator names.
        $names = [];
        foreach ( $page_blocks as $block ) {
            if ( $block['kind'] === 'section' ) {
                $names = array_merge( $names, $this->extract_names( $block ) );
            } elseif ( $block['kind'] === 'stream_group' ) {
                foreach ( $block['streams'] ?? [] as $stream ) {
                    foreach ( $stream['sections'] ?? [] as $section ) {
                        $names = array_merge( $names, $this->extract_names( $section ) );
                    }
                }
            }
        }

        $names = array_unique( array_filter( $names ) );

        if ( empty( $names ) ) {
            return;
        }

        // Get the speaker and judge CPT slugs.
        $cpt_mapping = $this->settings->get_cpt_mapping();
        $post_types  = [];

        if ( ! empty( $cpt_mapping['speakers'] ) ) {
            $post_types[] = $cpt_mapping['speakers'];
        }
        if ( ! empty( $cpt_mapping['judges'] ) ) {
            $post_types[] = $cpt_mapping['judges'];
        }

        if ( empty( $post_types ) ) {
            return;
        }

        // Single query to find all matching posts.
        $query = new \WP_Query( [
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'fields'         => 'ids',
        ] );

        if ( empty( $query->posts ) ) {
            return;
        }

        // Build the name → URL map.
        foreach ( $query->posts as $post_id ) {
            $post_title = mb_strtolower( get_the_title( $post_id ) );
            $this->speaker_urls[ $post_title ] = get_permalink( $post_id );
        }
    }

    /**
     * Extract speaker and moderator names from a section.
     */
    private function extract_names( array $section ): array {
        $names = [];
        foreach ( $section['speakers'] ?? [] as $s ) {
            if ( ! empty( $s['name'] ) ) {
                $names[] = $s['name'];
            }
        }
        foreach ( $section['moderators'] ?? [] as $m ) {
            if ( ! empty( $m['name'] ) ) {
                $names[] = $m['name'];
            }
        }
        return $names;
    }

    /**
     * Get the WordPress permalink for a speaker by name.
     *
     * @return string|null Permalink URL or null if not found.
     */
    private function get_speaker_url( string $name ): ?string {
        $key = mb_strtolower( $name );
        return $this->speaker_urls[ $key ] ?? null;
    }
}