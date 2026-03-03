(function($) {
    'use strict';

    var currentFormData = null;

    // --- Utility ---

    function escHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str || ''));
        return div.innerHTML;
    }

    var typeLabels = {
        text: 'Text',
        email: 'Email',
        textarea: 'Textarea',
        tel: 'Phone',
        select: 'Dropdown',
        radio: 'Radio',
        acceptance: 'Checkbox',
        checkbox: 'Multi-Checkbox',
        date: 'Date',
        number: 'Number',
        upload: 'File Upload',
        hidden: 'Hidden'
    };

    // --- Connection ---

    $('#ehsf-connect').on('click', function() {
        var $btn    = $(this);
        var $status = $('#ehsf-connect-status');
        var token   = $('#ehsf-token').val().trim();

        if (!token) {
            $status.text('Please enter an access token.').removeClass('ehsf-msg-success').addClass('ehsf-msg-error');
            return;
        }

        $btn.prop('disabled', true);
        $status.text(ehsfAdmin.strings.connecting).removeClass('ehsf-msg-error ehsf-msg-success');

        $.post(ehsfAdmin.ajax_url, {
            action: 'ehsf_connect',
            nonce:  ehsfAdmin.nonce,
            token:  token
        })
        .done(function(response) {
            if (response.success) {
                $status.text(ehsfAdmin.strings.connected + ' Portal ID: ' + response.data.portal_id)
                       .addClass('ehsf-msg-success');
                setTimeout(function() { location.reload(); }, 1200);
            } else {
                $status.text(response.data.message).addClass('ehsf-msg-error');
            }
        })
        .fail(function() {
            $status.text('Network error. Please try again.').addClass('ehsf-msg-error');
        })
        .always(function() {
            $btn.prop('disabled', false);
        });
    });

    // Allow pressing Enter in the token field to connect.
    $('#ehsf-token').on('keypress', function(e) {
        if (e.which === 13) {
            e.preventDefault();
            $('#ehsf-connect').trigger('click');
        }
    });

    $('#ehsf-disconnect').on('click', function() {
        if (!confirm('Disconnect from HubSpot? Your generated forms will continue to work.')) {
            return;
        }

        $.post(ehsfAdmin.ajax_url, {
            action: 'ehsf_disconnect',
            nonce:  ehsfAdmin.nonce
        }).done(function() {
            location.reload();
        });
    });

    // --- Fetch & Preview ---

    $('#ehsf-fetch-preview').on('click', function() {
        var $btn       = $(this);
        var $status    = $('#ehsf-fetch-status');
        var embedCode  = $('#ehsf-embed-code').val().trim();

        if (!embedCode) {
            $status.text('Please paste a HubSpot form embed code.').addClass('ehsf-msg-error');
            return;
        }

        $btn.prop('disabled', true);
        $status.text(ehsfAdmin.strings.fetching).removeClass('ehsf-msg-error ehsf-msg-success');
        $('#ehsf-preview').hide();
        $('#ehsf-result').hide();

        $.post(ehsfAdmin.ajax_url, {
            action:     'ehsf_fetch_form',
            nonce:      ehsfAdmin.nonce,
            embed_code: embedCode
        })
        .done(function(response) {
            if (response.success) {
                currentFormData = response.data;
                $status.text('').removeClass('ehsf-msg-error');
                renderPreview(response.data);
            } else {
                $status.text(response.data.message).addClass('ehsf-msg-error');
            }
        })
        .fail(function() {
            $status.text('Network error. Please try again.').addClass('ehsf-msg-error');
        })
        .always(function() {
            $btn.prop('disabled', false);
        });
    });

    function renderPreview(data) {
        $('#ehsf-form-name').html(
            '<strong>' + escHtml(data.form_name) + '</strong> ' +
            '<code>' + escHtml(data.form_id) + '</code>'
        );

        var $tbody = $('#ehsf-fields-table tbody').empty();

        $.each(data.fields, function(i, field) {
            if (field.hidden) {
                return; // Skip hidden fields in preview.
            }
            $tbody.append(
                '<tr>' +
                '<td><code>' + escHtml(field.name) + '</code></td>' +
                '<td>' + escHtml(field.label) + '</td>' +
                '<td><code>' + escHtml(field.hs_type) + '</code></td>' +
                '<td>' + escHtml(typeLabels[field.el_type] || field.el_type) + '</td>' +
                '<td>' + (field.required ? '<span class="dashicons dashicons-yes" style="color:#46b450;"></span>' : '') + '</td>' +
                '</tr>'
            );
        });

        $('#ehsf-preview').slideDown(200);
    }

    // --- Create Template ---

    $('#ehsf-create-template').on('click', function() {
        if (!currentFormData) {
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).text(ehsfAdmin.strings.generating);

        $.post(ehsfAdmin.ajax_url, {
            action:    'ehsf_create_template',
            nonce:     ehsfAdmin.nonce,
            portal_id: currentFormData.portal_id,
            form_id:   currentFormData.form_id
        })
        .done(function(response) {
            if (response.success) {
                $('#ehsf-edit-link').attr('href', response.data.edit_url);
                $('#ehsf-result').slideDown(200);
                // Reset the form for next use.
                currentFormData = null;
                $('#ehsf-embed-code').val('');
                $('#ehsf-preview').slideUp(200);
            } else {
                alert(response.data.message);
            }
        })
        .fail(function() {
            alert('Network error. Please try again.');
        })
        .always(function() {
            $btn.prop('disabled', false).text('Create Elementor Template');
        });
    });

    // --- Delete Template ---

    $(document).on('click', '.ehsf-delete-template', function() {
        if (!confirm(ehsfAdmin.strings.confirm_delete)) {
            return;
        }

        var $btn = $(this);
        var templateId = $btn.data('id');

        $btn.prop('disabled', true);

        $.post(ehsfAdmin.ajax_url, {
            action:      'ehsf_delete_template',
            nonce:       ehsfAdmin.nonce,
            template_id: templateId
        })
        .done(function(response) {
            if (response.success) {
                $btn.closest('tr').fadeOut(300, function() {
                    $(this).remove();
                });
            } else {
                alert(response.data.message);
                $btn.prop('disabled', false);
            }
        })
        .fail(function() {
            alert('Network error. Please try again.');
            $btn.prop('disabled', false);
        });
    });

})(jQuery);
