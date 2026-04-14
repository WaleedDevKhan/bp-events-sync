<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap bpes-settings-wrap">
    <h1><?php esc_html_e( 'BP Events Sync — Gallery', 'bp-events-sync' ); ?></h1>

    <hr />

    <h2><?php esc_html_e( 'Shortcode', 'bp-events-sync' ); ?></h2>

    <div class="bpes-code-block">
        <code>[bpes_gallery event_id="YOUR-EVENT-UUID" columns="4" cache="3600"]</code>
    </div>

    <table class="widefat fixed striped" style="max-width: 700px;">
        <thead>
            <tr>
                <th style="width: 120px;"><?php esc_html_e( 'Parameter', 'bp-events-sync' ); ?></th>
                <th style="width: 80px;"><?php esc_html_e( 'Required', 'bp-events-sync' ); ?></th>
                <th><?php esc_html_e( 'Description', 'bp-events-sync' ); ?></th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><code>event_id</code></td>
                <td><strong><?php esc_html_e( 'Yes', 'bp-events-sync' ); ?></strong></td>
                <td><?php esc_html_e( 'Event UUID from the CMS admin URL.', 'bp-events-sync' ); ?></td>
            </tr>
            <tr>
                <td><code>columns</code></td>
                <td><?php esc_html_e( 'No', 'bp-events-sync' ); ?></td>
                <td><?php esc_html_e( 'Number of grid columns (1–6). Default: 4.', 'bp-events-sync' ); ?></td>
            </tr>
            <tr>
                <td><code>cache</code></td>
                <td><?php esc_html_e( 'No', 'bp-events-sync' ); ?></td>
                <td><?php esc_html_e( 'Cache duration in seconds. Default: 3600 (1 hour). Set 0 to disable.', 'bp-events-sync' ); ?></td>
            </tr>
        </tbody>
    </table>

    <hr />

    <h2><?php esc_html_e( 'Examples', 'bp-events-sync' ); ?></h2>

    <div class="bpes-code-block">
        <code>[bpes_gallery event_id="68cb70dd-508e-4706-a9ad-3d19a97f4fd6"]</code>
    </div>

    <div class="bpes-code-block">
        <code>[bpes_gallery event_id="68cb70dd-508e-4706-a9ad-3d19a97f4fd6" columns="3" cache="7200"]</code>
    </div>
</div>