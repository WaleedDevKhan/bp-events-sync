<?php
namespace BPES;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Sync Engine.
 *
 * Handles the two-phase sync strategy:
 *   Phase 1 (initial / "name" mode) — match by normalised title → slug → create.
 *   Phase 2 (ongoing / "id" mode)   — look up by stored external CMS ID.
 *
 * Also manages taxonomy term creation and assignment.
 */
class Sync_Engine {

    /** Meta key used to store the external CMS unique ID on each post. */
    const META_EXTERNAL_ID = '_bpes_external_id';

    /** Meta key prefix for storing API field data. */
    const META_PREFIX = '_bpes_';

    /** @var API_Client */
    private $api;

    /** @var Settings */
    private $settings;

    /** @var array Running log of sync actions for UI feedback. */
    private $log = [];

    public function __construct( API_Client $api, Settings $settings ) {
        $this->api      = $api;
        $this->settings = $settings;
    }

    /* ─── Public Entry Points ───────────────────────────────────────────── */

    /**
     * Run sync for a given CPT type.
     *
     * @param string $type 'speakers' | 'judges' | 'sponsors'
     * @param string $mode 'name' (initial) | 'id' (ongoing)
     * @return array|\WP_Error Summary of sync actions or error.
     */
    public function run( string $type, string $mode = 'id' ) {
        $this->log = [];

        if ( ! $this->settings->is_type_configured( $type ) ) {
            return new \WP_Error( 'bpes_not_configured', "Type '{$type}' is not fully configured." );
        }

        // Fetch items from API.
        $items = $this->fetch_items( $type );

        if ( is_wp_error( $items ) ) {
            return $items;
        }

        // Filter to BPES_MIN_SYNC_YEAR and above.
        $items = $this->filter_by_year( $items );

        $this->log( sprintf( 'Fetched %d items from API (after year filter).', count( $items ) ) );

        $stats = [
            'created'  => 0,
            'updated'  => 0,
            'skipped'  => 0,
            'errors'   => 0,
            'total'    => count( $items ),
        ];

        foreach ( $items as $item ) {
            $result = $this->sync_single_item( $item, $type, $mode );

            if ( is_wp_error( $result ) ) {
                $stats['errors']++;
                $this->log( 'Error: ' . $result->get_error_message() );
            } else {
                $stats[ $result ]++;
            }
        }

        // Record sync metadata.
        $this->settings->update_last_sync( $type );

        if ( $mode === 'name' ) {
            $this->settings->set_initial_sync_done( $type );
        }

        // Process deletions from the deletion log.
        $deletion_stats = $this->process_deletions( $type );
        $stats['deleted'] = $deletion_stats;

        $stats['log'] = $this->log;

        $this->clear_server_cache();

        return $stats;
    }

    /**
     * Sync a single item triggered by a webhook.
     *
     * For 'upsert': fetches the item by ID from the CMS and syncs only that post.
     * For 'delete': finds the WP post by external ID and trashes it.
     * Falls back to a full run() if the targeted fetch fails.
     *
     * @param string $type      'speakers' | 'judges' | 'sponsors'
     * @param string $entity_id External CMS ID of the changed item.
     * @param string $action    'upsert' | 'delete'
     * @return array|\WP_Error
     */
    public function run_for_webhook( string $type, string $entity_id, string $action ) {
        $this->log = [];

        if ( ! $this->settings->is_type_configured( $type ) ) {
            return new \WP_Error( 'bpes_not_configured', "Type '{$type}' is not fully configured." );
        }

        $post_type = $this->settings->get_cpt_slug( $type );

        // Deletion — item is already gone from CMS, just trash the WP post.
        if ( $action === 'delete' ) {
            $post_id = $this->find_post_by_external_id( $entity_id, $post_type );

            if ( ! $post_id ) {
                $this->log( "Deleted: not found in WordPress (already removed?)." );
                return [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'deleted' => 0, 'total' => 0, 'log' => $this->log ];
            }

            $title = get_the_title( $post_id );
            wp_trash_post( $post_id );
            $this->log( sprintf( 'Deleted: "%s" (Post ID: %d, matched by ID)', $title, $post_id ) );
            $this->clear_server_cache();
            return [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0, 'deleted' => 1, 'total' => 1, 'log' => $this->log ];
        }

        // Upsert — fetch just this one item from the CMS.
        $item = $this->fetch_item_by_id( $type, $entity_id );

        if ( is_wp_error( $item ) ) {
            // Targeted fetch failed — fall back to full sync so the change isn't missed.
            $this->log( "Targeted fetch failed ({$item->get_error_message()}), falling back to full sync." );
            return $this->run( $type, 'id' );
        }

        // Apply year filter.
        if ( (int) ( $item['event_year'] ?? 0 ) < BPES_MIN_SYNC_YEAR ) {
            $this->log( "Skipped (below min sync year): {$entity_id}" );
            return [ 'created' => 0, 'updated' => 0, 'skipped' => 1, 'errors' => 0, 'deleted' => 0, 'total' => 1, 'log' => $this->log ];
        }

        $result = $this->sync_single_item( $item, $type, 'id' );

        if ( is_wp_error( $result ) ) {
            $this->log( 'Error: ' . $result->get_error_message() );
            return [ 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 1, 'deleted' => 0, 'total' => 1, 'log' => $this->log ];
        }

        $this->clear_server_cache();

        return [
            'created' => $result === 'created' ? 1 : 0,
            'updated' => $result === 'updated' ? 1 : 0,
            'skipped' => $result === 'skipped' ? 1 : 0,
            'errors'  => 0,
            'deleted' => 0,
            'total'   => 1,
            'log'     => $this->log,
        ];
    }

    /**
     * Fetch a single item from the CMS API by its external ID.
     */
    private function fetch_item_by_id( string $type, string $entity_id ): array|\WP_Error {
        switch ( $type ) {
            case 'sponsors':
                return $this->api->fetch_sponsor_by_id( $entity_id );
            case 'speakers':
            case 'judges':
                return $this->api->fetch_speaker_by_id( $entity_id );
            default:
                return new \WP_Error( 'bpes_invalid_type', "Unknown type: {$type}" );
        }
    }

    /* ─── Deletions ─────────────────────────────────────────────────────── */

    /**
     * Process deletions from the CMS deletion log.
     *
     * Only acts on posts that have an explicit deletion entry in the API —
     * never infers deletion from a missing list item.
     *
     * Logic:
     *   1. Remove the specific event_year category from the post.
     *   2. For sponsors, also remove the tier child term under that year.
     *   3. If the post has no remaining year categories → trash it.
     *   4. If it still has other year categories → keep it alive.
     *
     * @param string $type 'speakers' | 'judges' | 'sponsors'
     * @return int Number of posts trashed.
     */
    private function process_deletions( string $type ): int {
        $since = $this->settings->get_last_deletion_sync();

        // Map our plugin types to API entity types.
        $api_type_map = [
            'speakers' => 'speaker',
            'judges'   => 'speaker', // judges use 'speaker' type in API
            'sponsors' => 'sponsor',
        ];

        $api_type = $api_type_map[ $type ] ?? '';

        if ( empty( $api_type ) ) {
            return 0;
        }

        $deletions = $this->api->fetch_deletions( $since, $api_type );

        if ( is_wp_error( $deletions ) ) {
            $this->log( 'Failed to fetch deletions: ' . $deletions->get_error_message() );
            return 0;
        }

        if ( empty( $deletions ) ) {
            $this->log( 'No deletions found since last sync.' );
            $this->settings->update_last_deletion_sync();
            return 0;
        }

        $post_type      = $this->settings->get_cpt_slug( $type );
        $taxonomy       = $this->settings->get_tax_slug( $type );
        $trashed        = 0;
        $configured_ev  = $this->normalise_event_name( $this->settings->get_event_name() );

        foreach ( $deletions as $deletion ) {
            $entity_id  = $deletion['entity_id'] ?? '';
            $event_year = (string) (int) ( $deletion['event_year'] ?? 0 );
            $event_name = $deletion['event_name'] ?? '';

            if ( empty( $entity_id ) ) {
                continue;
            }

            // Skip deletions for events other than the one configured on this site.
            if ( ! empty( $configured_ev ) && $this->normalise_event_name( $event_name ) !== $configured_ev ) {
                continue;
            }

            // Skip deletions before our minimum sync year.
            if ( $event_year !== '0' && (int) $event_year < BPES_MIN_SYNC_YEAR ) {
                continue;
            }

            $post_id = $this->find_post_by_external_id( $entity_id, $post_type );

            if ( ! $post_id ) {
                $this->log( sprintf(
                    'Deletion skipped: "%s" (ID: %s) — not found in WordPress.',
                    $deletion['entity_name'] ?? 'Unknown',
                    $entity_id
                ) );
                continue;
            }

            $entity_name = $deletion['entity_name'] ?? get_the_title( $post_id );

            // Remove the specific year term from this post.
            if ( ! empty( $taxonomy ) && $event_year !== '0' ) {
                $this->remove_year_terms( $post_id, $event_year, $taxonomy, $type );

                $this->log( sprintf(
                    'Removed year "%s" from: "%s" (Post ID: %d)',
                    $event_year,
                    $entity_name,
                    $post_id
                ) );
            }

            // Check if the post has any remaining year categories.
            $remaining_terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'all' ] );

            // Filter to only top-level year terms (parent = 0).
            $remaining_years = [];
            if ( ! is_wp_error( $remaining_terms ) ) {
                foreach ( $remaining_terms as $term ) {
                    if ( (int) $term->parent === 0 ) {
                        $remaining_years[] = $term;
                    }
                }
            }

            if ( empty( $remaining_years ) ) {
                // No year categories left — trash the post.
                $result = wp_trash_post( $post_id );

                if ( $result ) {
                    $trashed++;
                    $this->log( sprintf(
                        'Trashed: "%s" (Post ID: %d) — no remaining year categories.',
                        $entity_name,
                        $post_id
                    ) );
                } else {
                    $this->log( sprintf(
                        'Failed to trash: "%s" (Post ID: %d)',
                        $entity_name,
                        $post_id
                    ) );
                }
            } else {
                $year_names = array_map( function ( $t ) { return $t->name; }, $remaining_years );
                $this->log( sprintf(
                    'Kept: "%s" (Post ID: %d) — still assigned to: %s',
                    $entity_name,
                    $post_id,
                    implode( ', ', $year_names )
                ) );
            }
        }

        // Update the deletion sync timestamp only after successful processing.
        $this->settings->update_last_deletion_sync();

        return $trashed;
    }

    /**
     * Remove a specific year term and its child tier terms from a post.
     *
     * @param int    $post_id   WordPress post ID.
     * @param string $year      The year to remove (e.g. "2026").
     * @param string $taxonomy  Taxonomy slug.
     * @param string $type      Plugin type ('speakers', 'judges', 'sponsors').
     */
    private function remove_year_terms( int $post_id, string $year, string $taxonomy, string $type ): void {
        $year_term = term_exists( $year, $taxonomy );

        if ( ! $year_term ) {
            return;
        }

        $year_term_id = is_array( $year_term ) ? (int) $year_term['term_id'] : (int) $year_term;
        $terms_to_remove = [ $year_term_id ];

        // For sponsors, also find and remove tier child terms under this year.
        if ( $type === 'sponsors' ) {
            $child_terms = get_terms( [
                'taxonomy'   => $taxonomy,
                'parent'     => $year_term_id,
                'hide_empty' => false,
                'fields'     => 'ids',
            ] );

            if ( ! is_wp_error( $child_terms ) && ! empty( $child_terms ) ) {
                // Only remove child terms that are actually assigned to this post.
                $post_terms = wp_get_object_terms( $post_id, $taxonomy, [ 'fields' => 'ids' ] );

                if ( ! is_wp_error( $post_terms ) ) {
                    $child_to_remove = array_intersect( $child_terms, $post_terms );
                    $terms_to_remove = array_merge( $terms_to_remove, $child_to_remove );
                }
            }
        }

        wp_remove_object_terms( $post_id, $terms_to_remove, $taxonomy );
    }

    /* ─── Fetch ─────────────────────────────────────────────────────────── */

    /**
     * Fetch items from the correct API endpoint based on type.
     */
    private function fetch_items( string $type ): array|\WP_Error {
        switch ( $type ) {
            case 'speakers':
                return $this->api->fetch_speakers( 'speaker' );
            case 'judges':
                return $this->api->fetch_speakers( 'judge' );
            case 'sponsors':
                return $this->api->fetch_sponsors();
            default:
                return new \WP_Error( 'bpes_invalid_type', "Unknown type: {$type}" );
        }
    }

    /**
     * Filter out items below the minimum sync year.
     */
    private function filter_by_year( array $items ): array {
        return array_filter( $items, function ( $item ) {
            $year = (int) ( $item['event_year'] ?? 0 );
            return $year >= BPES_MIN_SYNC_YEAR;
        } );
    }

    /* ─── Single Item Sync ──────────────────────────────────────────────── */

    /**
     * Sync one API item to WordPress.
     *
     * @return string|\WP_Error 'created' | 'updated' | 'skipped' or WP_Error.
     */
    private function sync_single_item( array $item, string $type, string $mode ) {
        $external_id = $this->get_external_id( $item, $type );
        $title       = $this->get_item_title( $item, $type );
        $post_type   = $this->settings->get_cpt_slug( $type );

        if ( empty( $external_id ) || empty( $title ) ) {
            return new \WP_Error( 'bpes_missing_data', "Item missing ID or title: " . wp_json_encode( $item ) );
        }

        $post_id      = null;
        $action       = 'created';
        $match_method = '';

        if ( $mode === 'id' ) {
            // Phase 2: Look up by stored external ID first.
            $post_id = $this->find_post_by_external_id( $external_id, $post_type );

            if ( $post_id ) {
                $match_method = 'by ID';
            } else {
                $post_id = $this->find_post_by_title( $title, $post_type );
                if ( $post_id ) {
                    $match_method = 'by name';
                } else {
                    $post_id = $this->find_post_by_slug( $this->to_slug( $title ), $post_type );
                    if ( $post_id ) {
                        $match_method = 'by slug';
                    }
                }
            }
        } elseif ( $mode === 'name' ) {
            // Phase 1: Strictly match by name → slug only.
            $post_id = $this->find_post_by_title( $title, $post_type );
            if ( $post_id ) {
                $match_method = 'by name';
            } else {
                $post_id = $this->find_post_by_slug( $this->to_slug( $title ), $post_type );
                if ( $post_id ) {
                    $match_method = 'by slug';
                }
            }
        }

        if ( $post_id ) {
            $action = 'updated';
        }

        // Create or update the post.
        $post_id = $this->upsert_post( $post_id, $item, $type );

        if ( is_wp_error( $post_id ) ) {
            return $post_id;
        }

        // Store the external CMS ID.
        update_post_meta( $post_id, self::META_EXTERNAL_ID, sanitize_text_field( $external_id ) );

        // Save all API fields as meta.
        $this->save_meta( $post_id, $item, $type );

        // Sync featured image from R2.
        $this->sync_featured_image( $post_id, $item, $type );

        // Handle taxonomy / categories.
        $this->sync_taxonomy_terms( $post_id, $item, $type );

        $match_note = ( $action === 'updated' && $match_method ) ? ", matched {$match_method}" : '';
        $this->log( sprintf( '%s: "%s" (Post ID: %d%s)', ucfirst( $action ), $title, $post_id, $match_note ) );

        return $action;
    }

    /* ─── Post Lookup Methods ───────────────────────────────────────────── */

    /**
     * Find a post by its stored external CMS ID.
     */
    private function find_post_by_external_id( string $external_id, string $post_type ): ?int {
        $query = new \WP_Query( [
            'post_type'      => $post_type,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_query'     => [
                [
                    'key'   => self::META_EXTERNAL_ID,
                    'value' => $external_id,
                ],
            ],
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        return ! empty( $query->posts ) ? (int) $query->posts[0] : null;
    }

    /**
     * Find a post by normalised title.
     */
    private function find_post_by_title( string $title, string $post_type ): ?int {
        global $wpdb;

        $post_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE LOWER(post_title) = %s
               AND post_type = %s
               AND post_status IN ('publish', 'draft', 'pending', 'private')
             LIMIT 1",
            mb_strtolower( $title ),
            $post_type
        ) );

        return $post_id ? (int) $post_id : null;
    }

    /**
     * Find a post by slug.
     */
    private function find_post_by_slug( string $slug, string $post_type ): ?int {
        $query = new \WP_Query( [
            'post_type'      => $post_type,
            'name'           => $slug,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );

        return ! empty( $query->posts ) ? (int) $query->posts[0] : null;
    }

    /* ─── Post Create / Update ──────────────────────────────────────────── */

    /**
     * Insert or update a WordPress post from API item data.
     *
     * @param int|null $post_id Existing post ID or null to create new.
     * @return int|\WP_Error Post ID or error.
     */
    private function upsert_post( ?int $post_id, array $item, string $type ) {
        $post_type  = $this->settings->get_cpt_slug( $type );
        $title      = $this->get_item_title( $item, $type );
        $content    = $this->get_item_content( $item, $type );

        $post_data = [
            'post_type'    => $post_type,
            'post_title'   => sanitize_text_field( $title ),
            'post_content' => wp_kses_post( $content ),
            'post_status'  => 'publish',
        ];

        // Only set menu_order for speakers and judges (not sponsors).
        if ( in_array( $type, [ 'speakers', 'judges' ], true ) ) {
            $post_data['menu_order'] = (int) ( $item['sort_order'] ?? 0 );
        }

        if ( $post_id ) {
            $post_data['ID'] = $post_id;
            $result = wp_update_post( $post_data, true );
        } else {
            $post_data['post_name'] = sanitize_title( $title );
            $result = wp_insert_post( $post_data, true );
        }

        return $result;
    }

    /* ─── Meta Data ─────────────────────────────────────────────────────── */

    /**
     * Save API fields as post meta.
     */
    private function save_meta( int $post_id, array $item, string $type ): void {
        $fields = $this->get_meta_fields( $type );

        // For speakers/judges: concatenate title + organisation into the existing job-title meta.
        if ( in_array( $type, [ 'speakers', 'judges' ], true ) ) {
            $job_title    = trim( $item['title'] ?? '' );
            $organisation = trim( $item['organisation'] ?? '' );

            if ( $job_title && $organisation ) {
                $combined = $job_title . ', ' . $organisation;
            } else {
                $combined = $job_title ?: $organisation;
            }

            if ( $combined ) {
                update_post_meta( $post_id, 'job-title', sanitize_text_field( $combined ) );
                update_post_meta( $post_id, 'position', sanitize_text_field( $combined ) );
            }
        }

        // For sponsors: save website to the existing 'url' meta property.
        if ( $type === 'sponsors' && ! empty( $item['website'] ) ) {
            update_post_meta( $post_id, 'url', esc_url_raw( $item['website'] ) );
        }

        foreach ( $fields as $api_key => $meta_key ) {
            if ( isset( $item[ $api_key ] ) ) {
                $value = $item[ $api_key ];

                // Build public image URL from R2 keys.
                if ( in_array( $api_key, [ 'image_r2_key', 'object_key' ], true ) && ! empty( $value ) ) {
                    update_post_meta( $post_id, self::META_PREFIX . 'image_url', esc_url_raw( BPES_R2_BASE_URL . $value ) );
                }

                update_post_meta( $post_id, self::META_PREFIX . $meta_key, sanitize_text_field( $value ) );
            }
        }
    }

    /**
     * Map of API field → meta key suffix for each type.
     */
    private function get_meta_fields( string $type ): array {
        $common = [
            'event_name'    => 'event_name',
            'event_year'    => 'event_year',
            'contact_email' => 'contact_email',
        ];

        switch ( $type ) {
            case 'speakers':
            case 'judges':
                return array_merge( $common, [
                    'title'              => 'job_title',
                    'organisation'       => 'organisation',
                    'bio'                => 'bio',
                    'image_r2_key'       => 'image_r2_key',
                    'profile_image_path' => 'profile_image_path',
                    'created_at'         => 'api_created_at',
                    'updated_at'         => 'api_updated_at',
                ] );

            case 'sponsors':
                return array_merge( $common, [
                    'tier'           => 'tier',
                    'website'        => 'website',
                    'about'          => 'about',
                    'object_key'     => 'object_key',
                    'logo_filename'  => 'logo_filename',
                ] );

            default:
                return $common;
        }
    }

    /* ─── Featured Image Sync ───────────────────────────────────────────── */

    /**
     * Download and set the featured image from R2.
     *
     * Compares the current R2 key stored in meta against the API value.
     * If unchanged, skips the download. If changed, downloads the new image,
     * sets it as featured, and optionally deletes the old attachment.
     *
     * @param int    $post_id WordPress post ID.
     * @param array  $item    API item data.
     * @param string $type    Plugin type.
     */
    private function sync_featured_image( int $post_id, array $item, string $type ): void {
        $image_key = $this->get_image_r2_key( $item, $type );

        if ( empty( $image_key ) ) {
            return;
        }

        // Check if the image key has changed since last sync.
        $stored_key = get_post_meta( $post_id, self::META_PREFIX . 'current_image_key', true );

        if ( $stored_key === $image_key ) {
            // Image hasn't changed — skip download.
            return;
        }

        $image_url = BPES_R2_BASE_URL . $image_key;

        // Download and attach the image.
        $attachment_id = $this->sideload_image( $image_url, $post_id );

        if ( is_wp_error( $attachment_id ) ) {
            $this->log( sprintf(
                'Failed to download image for Post ID %d: %s',
                $post_id,
                $attachment_id->get_error_message()
            ) );
            return;
        }

        // Delete the old featured image attachment if it was set by us.
        $old_attachment_id = get_post_meta( $post_id, self::META_PREFIX . 'attachment_id', true );

        if ( $old_attachment_id && (int) $old_attachment_id !== $attachment_id ) {
            wp_delete_attachment( (int) $old_attachment_id, true );
        }

        // Set as featured image.
        set_post_thumbnail( $post_id, $attachment_id );

        // Store the new image key and attachment ID for future comparison.
        update_post_meta( $post_id, self::META_PREFIX . 'current_image_key', sanitize_text_field( $image_key ) );
        update_post_meta( $post_id, self::META_PREFIX . 'attachment_id', $attachment_id );
    }

    /**
     * Get the R2 image key from an API item.
     *
     * @param array  $item API item data.
     * @param string $type Plugin type.
     * @return string The R2 key or empty string.
     */
    private function get_image_r2_key( array $item, string $type ): string {
        if ( $type === 'sponsors' ) {
            return (string) ( $item['object_key'] ?? '' );
        }
        // speakers & judges
        return (string) ( $item['image_r2_key'] ?? '' );
    }

    /**
     * Download an image from a URL and attach it to the WordPress media library.
     *
     * Uses wp_remote_get with appropriate headers to avoid Cloudflare blocking,
     * then sideloads into the media library.
     *
     * @param string $url     Public image URL.
     * @param int    $post_id Post to attach the image to.
     * @return int|\WP_Error Attachment ID or error.
     */
    private function sideload_image( string $url, int $post_id ) {
        // Ensure required WordPress functions are available.
        if ( ! function_exists( 'media_handle_sideload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Download from public R2 URL (no auth needed, bypassed via Cloudflare WAF rule).
        $response = wp_remote_get( $url, [
            'timeout'    => 60,
            'headers'    => [
                'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . get_bloginfo( 'url' ),
            ],
        ] );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code !== 200 ) {
            return new \WP_Error(
                'bpes_image_download_failed',
                sprintf( 'Image download returned HTTP %d for: %s', $code, $url )
            );
        }

        $body = wp_remote_retrieve_body( $response );

        if ( empty( $body ) ) {
            return new \WP_Error( 'bpes_image_empty', 'Downloaded image is empty.' );
        }

        // Extract filename and content type.
        $filename     = sanitize_file_name( basename( wp_parse_url( $url, PHP_URL_PATH ) ) );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );

        // Write to a temp file.
        $tmp_file = wp_tempnam( $filename );
        file_put_contents( $tmp_file, $body );

        $file_array = [
            'name'     => $filename,
            'type'     => $content_type,
            'tmp_name' => $tmp_file,
            'error'    => 0,
            'size'     => strlen( $body ),
        ];

        // Sideload into the media library.
        $attachment_id = media_handle_sideload( $file_array, $post_id );

        // Clean up temp file if sideload failed.
        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $tmp_file );
            return $attachment_id;
        }

        return (int) $attachment_id;
    }

    /* ─── Taxonomy Sync ─────────────────────────────────────────────────── */

    /**
     * Create and assign taxonomy terms for a synced post.
     *
     * Speakers/Judges: assign event_year as a term (e.g. "2026").
     * Sponsors:        assign event_year as parent, tier as child.
     *
     * Additive only — never removes existing term assignments.
     */
    private function sync_taxonomy_terms( int $post_id, array $item, string $type ): void {
        $taxonomy   = $this->settings->get_tax_slug( $type );
        $event_year = (string) (int) ( $item['event_year'] ?? 0 );

        if ( empty( $taxonomy ) || $event_year === '0' ) {
            return;
        }

        // Ensure the year term exists.
        $year_term_id = $this->ensure_term_exists( $event_year, $taxonomy );

        if ( is_wp_error( $year_term_id ) ) {
            $this->log( 'Failed to create year term: ' . $year_term_id->get_error_message() );
            return;
        }

        // Assign year term (additive — append = true).
        wp_set_object_terms( $post_id, [ (int) $year_term_id ], $taxonomy, true );

        // Sponsors also get a tier child term.
        if ( $type === 'sponsors' && ! empty( $item['tier'] ) ) {
            // Convert "category_sponsor" → "Category Sponsor"
            $tier_label   = ucwords( str_replace( '_', ' ', sanitize_text_field( $item['tier'] ) ) );
            $tier_term_id = $this->ensure_term_exists( $tier_label, $taxonomy, $year_term_id );

            if ( is_wp_error( $tier_term_id ) ) {
                $this->log( 'Failed to create tier term: ' . $tier_term_id->get_error_message() );
                return;
            }

            wp_set_object_terms( $post_id, [ (int) $tier_term_id ], $taxonomy, true );

            // Save tier_sort_order as term meta so JetEngine listings can sort by it.
            if ( isset( $item['tier_sort_order'] ) ) {
                update_term_meta( (int) $tier_term_id, '_bpes_tier_sort_order', (int) $item['tier_sort_order'] );
            }
        }
    }

    /**
     * Ensure a taxonomy term exists; create it if not.
     *
     * @param string   $name     Term name.
     * @param string   $taxonomy Taxonomy slug.
     * @param int|null $parent   Parent term ID for hierarchical terms.
     * @return int|\WP_Error Term ID or error.
     */
    private function ensure_term_exists( string $name, string $taxonomy, ?int $parent = null ) {
        $args = [];

        if ( $parent ) {
            $args['parent'] = $parent;
        }

        // Check if term already exists.
        $existing = term_exists( $name, $taxonomy, $parent );

        if ( $existing ) {
            return is_array( $existing ) ? (int) $existing['term_id'] : (int) $existing;
        }

        // Create the term.
        $insert_args = [];
        if ( $parent ) {
            $insert_args['parent'] = $parent;
        }

        $result = wp_insert_term( $name, $taxonomy, $insert_args );

        if ( is_wp_error( $result ) ) {
            // If term exists error (race condition), try to get it.
            if ( $result->get_error_code() === 'term_exists' ) {
                $term_id = $result->get_error_data();
                return (int) $term_id;
            }
            return $result;
        }

        return (int) $result['term_id'];
    }

    /* ─── Helpers ────────────────────────────────────────────────────────── */

    /**
     * Clear server-side caches after a sync so changes appear immediately.
     * Clears OPCache and Nginx FastCGI cache (WordOps default path).
     */
    private function clear_server_cache(): void {
        if ( function_exists( 'opcache_reset' ) ) {
            opcache_reset();
        }

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
     * Extract external CMS ID from an item based on type.
     */
    private function get_external_id( array $item, string $type ): string {
        if ( $type === 'sponsors' ) {
            return (string) ( $item['upload_id'] ?? '' );
        }
        // speakers & judges.
        return (string) ( $item['id'] ?? '' );
    }

    /**
     * Extract the display title from an item.
     */
    private function get_item_title( array $item, string $type ): string {
        if ( $type === 'sponsors' ) {
            return (string) ( $item['company_name'] ?? '' );
        }
        return (string) ( $item['name'] ?? '' );
    }

    /**
     * Extract content for the post body.
     */
    private function get_item_content( array $item, string $type ): string {
        if ( $type === 'sponsors' ) {
            return (string) ( $item['about'] ?? '' );
        }
        return (string) ( $item['bio'] ?? '' );
    }

    /**
     * Convert a string into a WordPress-style slug.
     */
    private function to_slug( string $text ): string {
        return sanitize_title( mb_strtolower( trim( $text ) ) );
    }

    /**
     * Normalise an event name for comparison.
     * Lowercases, strips whitespace, and collapses spaces.
     */
    private function normalise_event_name( string $name ): string {
        $name = strtolower( trim( $name ) );
        $name = preg_replace( '/\s+/', ' ', $name );
        return $name;
    }

    /**
     * Add a message to the sync log.
     */
    private function log( string $message ): void {
        $this->log[] = '[' . current_time( 'H:i:s' ) . '] ' . $message;
    }
}