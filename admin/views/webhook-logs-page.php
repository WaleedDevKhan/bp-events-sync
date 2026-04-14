<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$logs = array_reverse( (array) get_option( \BPES\Settings::OPT_WEBHOOK_LOGS, [] ) ); // newest first
?>
<div class="wrap bpes-settings-wrap">
    <h1><?php esc_html_e( 'Webhook Logs', 'bp-events-sync' ); ?></h1>
    <p class="description">
        <?php esc_html_e( 'Shows the last 50 webhook events received from the Events CMS. Newest first.', 'bp-events-sync' ); ?>
    </p>

    <div style="margin-bottom:16px; display:flex; align-items:center; gap:12px;">
        <button type="button" id="bpes-webhook-refresh-logs" class="button button-secondary">
            <?php esc_html_e( 'Refresh', 'bp-events-sync' ); ?>
        </button>
        <button type="button" id="bpes-webhook-clear-logs" class="button button-secondary" style="color:#b32d2e; border-color:#b32d2e;">
            <?php esc_html_e( 'Clear Logs', 'bp-events-sync' ); ?>
        </button>
        <span id="bpes-logs-status" class="bpes-status-msg"></span>
    </div>

    <?php if ( empty( $logs ) ) : ?>
        <p class="description"><?php esc_html_e( 'No webhook events received yet.', 'bp-events-sync' ); ?></p>
    <?php else : ?>
        <table class="widefat striped" id="bpes-webhook-logs-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Time', 'bp-events-sync' ); ?></th>
                    <th><?php esc_html_e( 'Event', 'bp-events-sync' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'bp-events-sync' ); ?></th>
                    <th><?php esc_html_e( 'Action', 'bp-events-sync' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'bp-events-sync' ); ?></th>
                    <th><?php esc_html_e( 'Message', 'bp-events-sync' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $entry ) : ?>
                    <?php
                    $status     = $entry['status'] ?? '';
                    $badge_style = match ( $status ) {
                        'success' => 'background:#d1fae5;color:#065f46;',
                        'error'   => 'background:#fee2e2;color:#991b1b;',
                        'ignored' => 'background:#f3f4f6;color:#6b7280;',
                        default   => 'background:#fef3c7;color:#92400e;',
                    };
                    ?>
                    <tr>
                        <td style="white-space:nowrap;">
                            <?php echo esc_html( date_i18n( 'd M Y H:i:s', $entry['ts'] ?? 0 ) ); ?>
                        </td>
                        <td><?php echo esc_html( $entry['event_name'] ?? '—' ); ?></td>
                        <td><?php echo esc_html( $entry['type'] ?? '—' ); ?></td>
                        <td><?php echo esc_html( $entry['action'] ?? '—' ); ?></td>
                        <td>
                            <span style="display:inline-block; padding:2px 8px; border-radius:4px; font-size:12px; font-weight:600; <?php echo esc_attr( $badge_style ); ?>">
                                <?php echo esc_html( ucfirst( $status ) ); ?>
                            </span>
                        </td>
                        <td><?php echo esc_html( $entry['message'] ?? '' ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
(function($) {
    $('#bpes-webhook-refresh-logs').on('click', function() {
        location.reload();
    });

    $('#bpes-webhook-clear-logs').on('click', function() {
        if (!confirm('Clear all webhook logs?')) return;

        var $btn    = $(this);
        var $status = $('#bpes-logs-status');

        $btn.prop('disabled', true);
        $status.removeClass('success error').addClass('loading').text('Clearing…');

        $.post(bpesAdmin.ajaxUrl, {
            action: 'bpes_webhook_clear_logs',
            nonce:  bpesAdmin.nonce
        }, function(response) {
            $btn.prop('disabled', false);
            $status.removeClass('loading');
            if (response.success) {
                $status.addClass('success').text(response.data.message);
                $('#bpes-webhook-logs-table').fadeOut(300, function() { $(this).remove(); });
            } else {
                $status.addClass('error').text(response.data.message || 'Failed.');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            $status.removeClass('loading').addClass('error').text('Request failed.');
        });
    });
})(jQuery);
</script>
