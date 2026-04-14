<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap bpes-settings-wrap">
    <h1><?php esc_html_e( 'BP Events Sync — Agenda', 'bp-events-sync' ); ?></h1>

    <hr />

    <h2><?php esc_html_e( 'Shortcode', 'bp-events-sync' ); ?></h2>

    <div class="bpes-code-block">
        <code>[bpes_agenda slug="your-event-slug" cache="300"]</code>
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
                <td><code>slug</code></td>
                <td><strong><?php esc_html_e( 'Yes', 'bp-events-sync' ); ?></strong></td>
                <td><?php esc_html_e( 'Event slug from the CMS (e.g. "health-summit-2026"). Only live agendas are returned.', 'bp-events-sync' ); ?></td>
            </tr>
            <tr>
                <td><code>cache</code></td>
                <td><?php esc_html_e( 'No', 'bp-events-sync' ); ?></td>
                <td><?php esc_html_e( 'Cache duration in seconds. Default: 300 (5 min). Set 0 to disable.', 'bp-events-sync' ); ?></td>
            </tr>
        </tbody>
    </table>

    <hr />

    <h2><?php esc_html_e( 'Examples', 'bp-events-sync' ); ?></h2>

    <div class="bpes-code-block">
        <code>[bpes_agenda slug="health-summit-2026"]</code>
    </div>

    <div class="bpes-code-block">
        <code>[bpes_agenda slug="health-summit-2026" cache="0"]</code>
    </div>

    <hr />

    <!-- ═══ Colour Settings ═══ -->
    <h2><?php esc_html_e( 'Agenda Colours', 'bp-events-sync' ); ?></h2>
    <p class="description"><?php esc_html_e( 'Customise the accent colours used in the agenda (banners, labels, time badges, borders). Fonts and text colours are inherited from your theme automatically.', 'bp-events-sync' ); ?></p>

    <form method="post" action="options.php">
        <?php settings_fields( 'bpes_agenda_colours_group' ); ?>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">
                    <label for="bpes_agenda_accent"><?php esc_html_e( 'Accent Colour', 'bp-events-sync' ); ?></label>
                </th>
                <td>
                    <input type="color" id="bpes_agenda_accent_picker"
                           value="<?php echo esc_attr( get_option( \BPES\Settings::OPT_AGENDA_ACCENT, '#2d5a3d' ) ); ?>"
                           class="bpes-color-picker" />
                    <input type="text" id="bpes_agenda_accent"
                           name="<?php echo esc_attr( \BPES\Settings::OPT_AGENDA_ACCENT ); ?>"
                           value="<?php echo esc_attr( get_option( \BPES\Settings::OPT_AGENDA_ACCENT, '#2d5a3d' ) ); ?>"
                           class="bpes-color-hex" maxlength="7" placeholder="#2d5a3d" />
                    <span class="description"><?php esc_html_e( 'Banners, session type labels, speaker label, stream headers.', 'bp-events-sync' ); ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="bpes_agenda_accent_light"><?php esc_html_e( 'Light Accent', 'bp-events-sync' ); ?></label>
                </th>
                <td>
                    <input type="color" id="bpes_agenda_accent_light_picker"
                           value="<?php echo esc_attr( get_option( \BPES\Settings::OPT_AGENDA_ACCENT_LIGHT, '#dce8df' ) ); ?>"
                           class="bpes-color-picker" />
                    <input type="text" id="bpes_agenda_accent_light"
                           name="<?php echo esc_attr( \BPES\Settings::OPT_AGENDA_ACCENT_LIGHT ); ?>"
                           value="<?php echo esc_attr( get_option( \BPES\Settings::OPT_AGENDA_ACCENT_LIGHT, '#dce8df' ) ); ?>"
                           class="bpes-color-hex" maxlength="7" placeholder="#dce8df" />
                    <span class="description"><?php esc_html_e( 'Time badge background.', 'bp-events-sync' ); ?></span>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="bpes_agenda_accent_border"><?php esc_html_e( 'Border Colour', 'bp-events-sync' ); ?></label>
                </th>
                <td>
                    <input type="color" id="bpes_agenda_accent_border_picker"
                           value="<?php echo esc_attr( get_option( \BPES\Settings::OPT_AGENDA_ACCENT_BORDER, '#b0bfb5' ) ); ?>"
                           class="bpes-color-picker" />
                    <input type="text" id="bpes_agenda_accent_border"
                           name="<?php echo esc_attr( \BPES\Settings::OPT_AGENDA_ACCENT_BORDER ); ?>"
                           value="<?php echo esc_attr( get_option( \BPES\Settings::OPT_AGENDA_ACCENT_BORDER, '#b0bfb5' ) ); ?>"
                           class="bpes-color-hex" maxlength="7" placeholder="#b0bfb5" />
                    <span class="description"><?php esc_html_e( 'Card left border, time badge border.', 'bp-events-sync' ); ?></span>
                </td>
            </tr>
        </table>

        <?php submit_button( __( 'Save Colours', 'bp-events-sync' ) ); ?>
    </form>
</div>