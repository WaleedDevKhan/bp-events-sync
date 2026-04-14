/**
 * BP Events Sync — Admin JS
 */
(function ($) {
    'use strict';

    var admin = bpesAdmin;

    /* ─── CPT Toggle ────────────────────────────────────────────────── */
    $(document).on('change', '.bpes-cpt-toggle', function () {
        var $block  = $(this).closest('.bpes-cpt-block');
        var $config = $block.find('.bpes-cpt-config');

        if (this.checked) {
            $config.slideDown(200);
        } else {
            $config.slideUp(200);
        }
    });

    /* ─── CPT Select → Auto-detect Taxonomies ───────────────────────── */
    $(document).on('change', '.bpes-cpt-select', function () {
        var $select  = $(this);
        var postType = $select.val();
        var type     = $select.closest('.bpes-cpt-block').data('type');
        var $taxList = $('.bpes-tax-list[data-type="' + type + '"]');

        if (!postType) {
            $taxList.html('<p class="description">Select a CPT above to auto-detect taxonomies.</p>');
            return;
        }

        $taxList.html('<span class="bpes-spinner"></span> Detecting taxonomies…');

        $.post(admin.ajaxUrl, {
            action:    'bpes_detect_taxonomies',
            nonce:     admin.nonce,
            post_type: postType
        }, function (response) {
            if (!response.success || !response.data.length) {
                $taxList.html('<p class="description">No taxonomies found for this CPT.</p>');
                return;
            }

            var currentMapping = admin.settings.taxMapping || {};
            var selectedTax    = currentMapping[type] || '';
            var html           = '';

            // Use the hidden field name for the taxonomy mapping.
            var fieldName = 'bpes_tax_mapping[' + type + ']';

            $.each(response.data, function (i, tax) {
                var checked = (tax.slug === selectedTax) ? ' checked' : '';
                var badge   = tax.hierarchical ? ' (hierarchical)' : ' (flat)';

                html += '<div class="bpes-tax-item">';
                html += '<label>';
                html += '<input type="radio" name="' + fieldName + '" value="' + tax.slug + '"' + checked + ' />';
                html += ' ' + tax.label + ' <code>' + tax.slug + '</code>';
                html += '<small>' + badge + '</small>';
                html += '</label>';
                html += '</div>';
            });

            $taxList.html(html);
        }).fail(function () {
            $taxList.html('<p class="description" style="color:#d63638;">Failed to detect taxonomies.</p>');
        });
    });

    // Auto-detect taxonomies on page load for already-selected CPTs.
    $(document).ready(function () {
        $('.bpes-cpt-select').each(function () {
            if ($(this).val()) {
                $(this).trigger('change');
            }
        });
    });

    /* ─── Test Connection ───────────────────────────────────────────── */
    $(document).on('click', '#bpes-test-connection', function () {
        var $btn    = $(this);
        var $status = $('#bpes-connection-status');

        $btn.prop('disabled', true);
        $status.removeClass('success error').addClass('loading').text(admin.strings.testing);

        $.post(admin.ajaxUrl, {
            action: 'bpes_test_connection',
            nonce:  admin.nonce
        }, function (response) {
            $btn.prop('disabled', false);
            $status.removeClass('loading');

            if (response.success) {
                $status.addClass('success').text(admin.strings.connected);
            } else {
                $status.addClass('error').text(response.data.message || admin.strings.error);
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.removeClass('loading').addClass('error').text('Request failed.');
        });
    });

    /* ─── Manual Sync ───────────────────────────────────────────────── */
    $(document).on('click', '.bpes-sync-btn', function () {
        var $btn     = $(this);
        var type     = $btn.data('type');
        var mode     = $btn.data('mode');
        var $row     = $btn.closest('.bpes-sync-row');
        var $results = $row.find('.bpes-sync-results');
        var $stats   = $results.find('.bpes-stats');
        var $log     = $results.find('.bpes-log');

        // Confirm for name/slug sync.
        if (mode === 'name') {
            if (!confirm(admin.strings.confirmName)) {
                return;
            }
        }

        // Disable all sync buttons during operation.
        $('.bpes-sync-btn').prop('disabled', true);
        $btn.text(admin.strings.syncing).append('<span class="bpes-spinner"></span>');

        $results.hide();

        $.post(admin.ajaxUrl, {
            action:    'bpes_run_sync',
            nonce:     admin.nonce,
            cpt_type:  type,
            sync_mode: mode
        }, function (response) {
            $('.bpes-sync-btn').prop('disabled', false);

            // Restore button text.
            $row.find('.bpes-sync-btn[data-mode="name"]').text('Sync by Name/Slug');
            $row.find('.bpes-sync-btn[data-mode="id"]').text('Sync by ID');

            if (response.success) {
                var d = response.data;

                $stats.html(
                    '<span>Total: <strong>' + d.total + '</strong></span>' +
                    '<span class="created">Created: ' + d.created + '</span>' +
                    '<span class="updated">Updated: ' + d.updated + '</span>' +
                    '<span class="skipped">Skipped: ' + d.skipped + '</span>' +
                    '<span class="deleted">Deleted: ' + (d.deleted || 0) + '</span>' +
                    '<span class="errors">Errors: ' + d.errors + '</span>'
                );

                $log.text((d.log || []).join('\n'));
                $results.slideDown(200);
            } else {
                $stats.html('<span class="errors">' + (response.data.message || admin.strings.error) + '</span>');
                $log.text('');
                $results.slideDown(200);
            }
        }).fail(function () {
            $('.bpes-sync-btn').prop('disabled', false);
            $row.find('.bpes-sync-btn[data-mode="name"]').text('Sync by Name/Slug');
            $row.find('.bpes-sync-btn[data-mode="id"]').text('Sync by ID');

            $stats.html('<span class="errors">Request failed. Check your connection.</span>');
            $log.text('');
            $results.slideDown(200);
        });
    });

    /* ─── Colour Picker ↔ Hex Input Sync ────────────────────────────── */
    $(document).on('input', '.bpes-color-picker', function () {
        $(this).next('.bpes-color-hex').val($(this).val());
    });

    $(document).on('input', '.bpes-color-hex', function () {
        var val = $(this).val();
        // Only update picker if it's a valid hex colour.
        if (/^#[0-9A-Fa-f]{6}$/.test(val)) {
            $(this).prev('.bpes-color-picker').val(val);
        }
    });

    /* ─── Webhook: Register ────────────────────────────────────────── */
    $(document).on('click', '#bpes-webhook-register', function () {
        var $btn    = $(this);
        var $status = $('#bpes-webhook-status');

        $btn.prop('disabled', true);
        $status.removeClass('success error').addClass('loading').text('Registering…');

        $.post(admin.ajaxUrl, {
            action: 'bpes_webhook_register',
            nonce:  admin.nonce
        }, function (response) {
            $btn.prop('disabled', false);
            $status.removeClass('loading');

            if (response.success) {
                $status.addClass('success').text(response.data.message);
                // Update status badge without a full page reload.
                $('.bpes-badge.bpes-badge-pending').replaceWith('<span class="bpes-badge bpes-badge-ok">Registered</span>');
            } else {
                $status.addClass('error').text(response.data.message || 'Registration failed.');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.removeClass('loading').addClass('error').text('Request failed.');
        });
    });

    /* ─── Webhook: Unregister ───────────────────────────────────────── */
    $(document).on('click', '#bpes-webhook-unregister', function () {
        if (!confirm('Unregister this site from the Events CMS? You can re-register at any time.')) {
            return;
        }

        var $btn    = $(this);
        var $status = $('#bpes-webhook-status');

        $btn.prop('disabled', true);
        $status.removeClass('success error').addClass('loading').text('Unregistering…');

        $.post(admin.ajaxUrl, {
            action: 'bpes_webhook_unregister',
            nonce:  admin.nonce
        }, function (response) {
            $btn.prop('disabled', false);
            $status.removeClass('loading');

            if (response.success) {
                $status.addClass('success').text(response.data.message);
                $('.bpes-badge.bpes-badge-ok').replaceWith('<span class="bpes-badge bpes-badge-pending">Not registered</span>');
            } else {
                $status.addClass('error').text(response.data.message || 'Unregister failed.');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.removeClass('loading').addClass('error').text('Request failed.');
        });
    });

    /* ─── Webhook: Regenerate Secret ────────────────────────────────── */
    $(document).on('click', '#bpes-webhook-regenerate', function () {
        if (!confirm('Regenerate the secret? You must re-register with the CMS after doing this.')) {
            return;
        }

        var $btn    = $(this);
        var $status = $('#bpes-webhook-regenerate-status');

        $btn.prop('disabled', true);
        $status.removeClass('success error').addClass('loading').text('Regenerating…');

        $.post(admin.ajaxUrl, {
            action: 'bpes_webhook_regenerate',
            nonce:  admin.nonce
        }, function (response) {
            $btn.prop('disabled', false);
            $status.removeClass('loading');

            if (response.success) {
                $status.addClass('success').text(response.data.message);
            } else {
                $status.addClass('error').text(response.data.message || 'Failed.');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.removeClass('loading').addClass('error').text('Request failed.');
        });
    });

    /* ─── Clear Cache ───────────────────────────────────────────────── */
    $(document).on('click', '#bpes-clear-cache', function () {
        var $btn    = $(this);
        var $status = $('#bpes-cache-status');

        $btn.prop('disabled', true);
        $status.removeClass('success error').addClass('loading').text('Clearing…');

        $.post(admin.ajaxUrl, {
            action: 'bpes_clear_cache',
            nonce:  admin.nonce
        }, function (response) {
            $btn.prop('disabled', false);
            $status.removeClass('loading');

            if (response.success) {
                $status.addClass('success').text(response.data.message);
            } else {
                $status.addClass('error').text(response.data.message || 'Failed to clear cache.');
            }
        }).fail(function () {
            $btn.prop('disabled', false);
            $status.removeClass('loading').addClass('error').text('Request failed.');
        });
    });

})(jQuery);