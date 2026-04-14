<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * @var \WP_Post_Type[] $registered_cpts Available custom post types.
 */
?>
<div class="wrap bpes-settings-wrap">
    <h1><?php esc_html_e( 'BP Events Sync', 'bp-events-sync' ); ?></h1>
    <p class="description"><?php esc_html_e( 'Sync speakers, judges, and sponsors from the central BP Events CMS.', 'bp-events-sync' ); ?></p>

    <hr />

    <!-- ═══ Section 1: API Credentials ═══ -->
    <form method="post" action="options.php" id="bpes-settings-form">
        <?php settings_fields( 'bpes_settings_group' ); ?>

        <h2><?php esc_html_e( 'API Connection', 'bp-events-sync' ); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="bpes_base_url"><?php esc_html_e( 'Base URL', 'bp-events-sync' ); ?></label></th>
                <td>
                    <input type="url" id="bpes_base_url" name="<?php echo esc_attr( \BPES\Settings::OPT_BASE_URL ); ?>"
                           value="<?php echo esc_attr( get_option( \BPES\Settings::OPT_BASE_URL, 'https://assets.events.businesspost.ie' ) ); ?>"
                           class="regular-text" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bpes_cf_client_id"><?php esc_html_e( 'CF Access Client ID', 'bp-events-sync' ); ?></label></th>
                <td>
                    <input type="text" id="bpes_cf_client_id" name="<?php echo esc_attr( \BPES\Settings::OPT_CF_CLIENT_ID ); ?>"
                           value="<?php echo esc_attr( get_option( \BPES\Settings::OPT_CF_CLIENT_ID, '' ) ); ?>"
                           class="regular-text" autocomplete="off" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bpes_cf_client_secret"><?php esc_html_e( 'CF Access Client Secret', 'bp-events-sync' ); ?></label></th>
                <td>
                    <input type="password" id="bpes_cf_client_secret" name="<?php echo esc_attr( \BPES\Settings::OPT_CF_CLIENT_SECRET ); ?>"
                           value="<?php echo esc_attr( get_option( \BPES\Settings::OPT_CF_CLIENT_SECRET, '' ) ); ?>"
                           class="regular-text" autocomplete="off" />
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="bpes_event_name"><?php esc_html_e( 'Event Name', 'bp-events-sync' ); ?></label></th>
                <td>
                    <input type="text" id="bpes_event_name" name="<?php echo esc_attr( \BPES\Settings::OPT_EVENT_NAME ); ?>"
                           value="<?php echo esc_attr( get_option( \BPES\Settings::OPT_EVENT_NAME, '' ) ); ?>"
                           class="regular-text" placeholder="e.g. Health Summit" />
                    <p class="description"><?php esc_html_e( 'The event name as it appears in the CMS API.', 'bp-events-sync' ); ?></p>
                </td>
            </tr>
        </table>

        <p>
            <button type="button" id="bpes-test-connection" class="button button-secondary">
                <?php esc_html_e( 'Test Connection', 'bp-events-sync' ); ?>
            </button>
            <span id="bpes-connection-status" class="bpes-status-msg"></span>
        </p>

        <hr />

        <!-- ═══ Section 2: CPT Configuration ═══ -->
        <h2><?php esc_html_e( 'Content Type Mapping', 'bp-events-sync' ); ?></h2>
        <p class="description"><?php esc_html_e( 'Select which content types this site uses and map them to the registered CPTs. Taxonomies are auto-detected.', 'bp-events-sync' ); ?></p>

        <?php
        $types = [
            'speakers' => __( 'Speakers', 'bp-events-sync' ),
            'judges'   => __( 'Judges', 'bp-events-sync' ),
            'sponsors' => __( 'Sponsors', 'bp-events-sync' ),
        ];

        $enabled_cpts = get_option( \BPES\Settings::OPT_ENABLED_CPTS, [] );
        $cpt_mapping  = get_option( \BPES\Settings::OPT_CPT_MAPPING, [] );
        $tax_mapping  = get_option( \BPES\Settings::OPT_TAX_MAPPING, [] );
        ?>

        <?php foreach ( $types as $type_key => $type_label ) : ?>
        <div class="bpes-cpt-block" data-type="<?php echo esc_attr( $type_key ); ?>">
            <h3>
                <label>
                    <input type="checkbox"
                           class="bpes-cpt-toggle"
                           name="<?php echo esc_attr( \BPES\Settings::OPT_ENABLED_CPTS ); ?>[]"
                           value="<?php echo esc_attr( $type_key ); ?>"
                           <?php checked( in_array( $type_key, $enabled_cpts, true ) ); ?> />
                    <?php echo esc_html( $type_label ); ?>
                </label>
            </h3>

            <div class="bpes-cpt-config" style="<?php echo in_array( $type_key, $enabled_cpts, true ) ? '' : 'display:none;'; ?>">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label><?php esc_html_e( 'WordPress CPT Slug', 'bp-events-sync' ); ?></label></th>
                        <td>
                            <select class="bpes-cpt-select" name="<?php echo esc_attr( \BPES\Settings::OPT_CPT_MAPPING ); ?>[<?php echo esc_attr( $type_key ); ?>]">
                                <option value=""><?php esc_html_e( '— Select CPT —', 'bp-events-sync' ); ?></option>
                                <?php foreach ( $registered_cpts as $cpt ) : ?>
                                    <option value="<?php echo esc_attr( $cpt->name ); ?>"
                                            <?php selected( $cpt_mapping[ $type_key ] ?? '', $cpt->name ); ?>>
                                        <?php echo esc_html( $cpt->labels->name . ' (' . $cpt->name . ')' ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Detected Taxonomies', 'bp-events-sync' ); ?></th>
                        <td>
                            <div class="bpes-tax-list" data-type="<?php echo esc_attr( $type_key ); ?>">
                                <p class="description"><?php esc_html_e( 'Select a CPT above to auto-detect taxonomies.', 'bp-events-sync' ); ?></p>
                            </div>
                        </td>
                    </tr>
                </table>
            </div>
        </div>
        <?php endforeach; ?>

        <hr />

        <?php submit_button( __( 'Save Settings', 'bp-events-sync' ) ); ?>
    </form>

    <hr />

    <!-- ═══ Section 4: Manual Sync ═══ -->
    <h2><?php esc_html_e( 'Manual Sync', 'bp-events-sync' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Use "Sync by Name/Slug" for the first sync on a site (matches existing posts by name). Use "Sync by ID" for all subsequent syncs (faster, uses stored CMS ID).', 'bp-events-sync' ); ?>
    </p>

    <?php
    $last_sync    = get_option( \BPES\Settings::OPT_LAST_SYNC, [] );
    $initial_done = get_option( \BPES\Settings::OPT_INITIAL_SYNC_DONE, [] );
    ?>

    <div id="bpes-sync-panel">
        <?php foreach ( $types as $type_key => $type_label ) : ?>
            <?php if ( in_array( $type_key, $enabled_cpts, true ) ) : ?>
            <div class="bpes-sync-row" data-type="<?php echo esc_attr( $type_key ); ?>">
                <h3><?php echo esc_html( $type_label ); ?></h3>

                <div class="bpes-sync-meta">
                    <?php if ( ! empty( $last_sync[ $type_key ] ) ) : ?>
                        <span class="bpes-last-sync">
                            <?php printf( esc_html__( 'Last sync: %s', 'bp-events-sync' ), esc_html( $last_sync[ $type_key ] ) ); ?>
                        </span>
                    <?php else : ?>
                        <span class="bpes-last-sync bpes-never"><?php esc_html_e( 'Never synced', 'bp-events-sync' ); ?></span>
                    <?php endif; ?>

                    <?php if ( ! empty( $initial_done[ $type_key ] ) ) : ?>
                        <span class="bpes-badge bpes-badge-ok"><?php esc_html_e( 'Initial sync done', 'bp-events-sync' ); ?></span>
                    <?php else : ?>
                        <span class="bpes-badge bpes-badge-pending"><?php esc_html_e( 'Initial sync pending', 'bp-events-sync' ); ?></span>
                    <?php endif; ?>
                </div>

                <div class="bpes-sync-buttons">
                    <button type="button" class="button button-secondary bpes-sync-btn" data-type="<?php echo esc_attr( $type_key ); ?>" data-mode="name">
                        <?php esc_html_e( 'Sync by Name/Slug', 'bp-events-sync' ); ?>
                    </button>
                    <button type="button" class="button button-primary bpes-sync-btn" data-type="<?php echo esc_attr( $type_key ); ?>" data-mode="id">
                        <?php esc_html_e( 'Sync by ID', 'bp-events-sync' ); ?>
                    </button>
                </div>

                <div class="bpes-sync-results" data-type="<?php echo esc_attr( $type_key ); ?>" style="display:none;">
                    <div class="bpes-stats"></div>
                    <details>
                        <summary><?php esc_html_e( 'Sync Log', 'bp-events-sync' ); ?></summary>
                        <pre class="bpes-log"></pre>
                    </details>
                </div>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if ( empty( $enabled_cpts ) ) : ?>
            <p class="description"><?php esc_html_e( 'Enable and save at least one content type above to see sync options.', 'bp-events-sync' ); ?></p>
        <?php endif; ?>
    </div>

    <hr />

    <!-- ═══ Section 5: Webhook Sync ═══ -->
    <h2><?php esc_html_e( 'Webhook Sync', 'bp-events-sync' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Receive instant sync notifications from the Events CMS instead of relying on the scheduled cron. Run a manual sync first to import all existing data, then register below to keep everything up to date automatically.', 'bp-events-sync' ); ?>
    </p>

    <table class="form-table">
        <tr>
            <th scope="row"><?php esc_html_e( 'Webhook URL', 'bp-events-sync' ); ?></th>
            <td>
                <code><?php echo esc_html( rest_url( 'bpes/v1/sync' ) ); ?></code>
                <p class="description"><?php esc_html_e( 'This is the URL the Events CMS will POST to when data changes.', 'bp-events-sync' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'HMAC Secret', 'bp-events-sync' ); ?></th>
            <td>
                <?php $secret = ( new \BPES\Settings() )->get_webhook_secret(); ?>
                <code id="bpes-webhook-secret"><?php echo esc_html( substr( $secret, 0, 8 ) . str_repeat( '•', 24 ) ); ?></code>
                <button type="button" id="bpes-webhook-regenerate" class="button button-secondary" style="margin-left:8px;">
                    <?php esc_html_e( 'Regenerate Secret', 'bp-events-sync' ); ?>
                </button>
                <span id="bpes-webhook-regenerate-status" class="bpes-status-msg"></span>
                <p class="description"><?php esc_html_e( 'Auto-generated. Regenerating invalidates the current registration — you must re-register after.', 'bp-events-sync' ); ?></p>
            </td>
        </tr>
        <tr>
            <th scope="row"><?php esc_html_e( 'Status', 'bp-events-sync' ); ?></th>
            <td>
                <?php if ( get_option( \BPES\Settings::OPT_WEBHOOK_ENABLED ) ) : ?>
                    <span class="bpes-badge bpes-badge-ok"><?php esc_html_e( 'Registered', 'bp-events-sync' ); ?></span>
                <?php else : ?>
                    <span class="bpes-badge bpes-badge-pending"><?php esc_html_e( 'Not registered', 'bp-events-sync' ); ?></span>
                <?php endif; ?>
            </td>
        </tr>
    </table>

    <div style="display:flex; gap:8px; align-items:center; margin-top:8px;">
        <button type="button" id="bpes-webhook-register" class="button button-primary">
            <?php esc_html_e( 'Register with CMS', 'bp-events-sync' ); ?>
        </button>
        <button type="button" id="bpes-webhook-unregister" class="button button-secondary">
            <?php esc_html_e( 'Unregister', 'bp-events-sync' ); ?>
        </button>
        <span id="bpes-webhook-status" class="bpes-status-msg"></span>
    </div>

    <hr />

    <!-- ═══ Section 6: Clear Cache ═══ -->
    <h2><?php esc_html_e( 'Clear Cache', 'bp-events-sync' ); ?></h2>
    <p class="description">
        <?php esc_html_e( 'Clear cached API responses for gallery and agenda shortcodes. Use this after making changes in the CMS to see updates immediately on the website.', 'bp-events-sync' ); ?>
    </p>

    <div class="bpes-cache-panel">
        <button type="button" id="bpes-clear-cache" class="button button-secondary">
            <?php esc_html_e( 'Clear All Caches', 'bp-events-sync' ); ?>
        </button>
        <span id="bpes-cache-status" class="bpes-status-msg"></span>
    </div>
</div>