/* Repose Healthcare — Admin JS v1.1.2 */

/* Wait for both DOM and jQuery to be ready */
document.addEventListener('DOMContentLoaded', function () {

    if (typeof jQuery === 'undefined') {
        console.error('Repose: jQuery not loaded');
        return;
    }
    if (typeof reposeAdmin === 'undefined') {
        console.error('Repose: reposeAdmin not localised');
        return;
    }

    var $  = jQuery;
    var nonce   = reposeAdmin.nonce;
    var ajaxUrl = reposeAdmin.ajaxUrl;

    /* ── Utility ───────────────────────────────────────────────── */

    function showNotice(msg, type) {
        var cls = (type === 'error') ? 'notice-error' : 'notice-success';
        var $n  = $('<div class="notice ' + cls + ' is-dismissible" style="margin:10px 0 0"><p></p></div>');
        $n.find('p').text(msg);
        $('.repose-admin h1').after($n);
        setTimeout(function () { $n.fadeOut(400, function () { $n.remove(); }); }, 5000);
    }

    /* Get order-id stored on the actions <td> in the same row as any button */
    function orderIdFrom(btn) {
        return $(btn).closest('tr').find('td[data-order-id]').attr('data-order-id');
    }


    /* ── Approve & Transmit ────────────────────────────────────── */

    $(document).on('click', '.btn-approve-order', function () {
        var btn     = this;
        var orderId = orderIdFrom(btn);
        if (!orderId) { alert('Cannot find order ID. Please refresh.'); return; }
        if (!confirm('Approve and transmit order #' + orderId + ' to the laboratory?')) return;

        $(btn).prop('disabled', true).text('Processing...');

        $.post(ajaxUrl, { action: 'repose_approve_order', order_id: orderId, nonce: nonce })
         .done(function (r) {
             if (r && r.success) {
                 showNotice(r.data.message || 'Order approved and transmitted.');
                 $('#queue-row-' + orderId + ', #edit-row-' + orderId).fadeOut(400, function () { $(this).remove(); });
             } else {
                 showNotice((r && r.data) ? r.data : 'Action failed.', 'error');
                 $(btn).prop('disabled', false).text('Approve & Transmit');
             }
         })
         .fail(function () {
             showNotice('Network error — please try again.', 'error');
             $(btn).prop('disabled', false).text('Approve & Transmit');
         });
    });

    /* ── Reject modal ──────────────────────────────────────────── */

    var pendingRejectId = null;

    $(document).on('click', '.btn-reject-order', function () {
        var orderId = orderIdFrom(this);
        if (!orderId) { alert('Cannot find order ID. Please refresh.'); return; }
        pendingRejectId = orderId;
        document.getElementById('reject-reason').value = '';
        document.getElementById('repose-reject-modal').classList.add('is-visible');
    });

    $(document).on('click', '#cancel-reject', function () {
        document.getElementById('repose-reject-modal').classList.remove('is-visible');
    });

    /* Click outside modal inner box closes it */
    $(document).on('click', '#repose-reject-modal', function (e) {
        if (e.target.id === 'repose-reject-modal') {
            e.target.classList.remove('is-visible');
        }
    });

    $(document).on('click', '#confirm-reject', function () {
        var reason = document.getElementById('reject-reason').value.trim();
        if (!reason) { alert('Please provide a reason for rejection.'); return; }
        if (!pendingRejectId) { alert('No order selected.'); return; }

        var btn = this;
        $(btn).prop('disabled', true).text('Rejecting...');

        $.post(ajaxUrl, { action: 'repose_reject_order', order_id: pendingRejectId, reason: reason, nonce: nonce })
         .done(function (r) {
             document.getElementById('repose-reject-modal').classList.remove('is-visible');
             $(btn).prop('disabled', false).text('Confirm Reject');
             if (r && r.success) {
                 showNotice(r.data.message || 'Order rejected.');
                 var id = pendingRejectId;
                 $('#queue-row-' + id + ', #edit-row-' + id).fadeOut(400, function () { $(this).remove(); });
                 pendingRejectId = null;
             } else {
                 showNotice((r && r.data) ? r.data : 'Action failed.', 'error');
             }
         })
         .fail(function () {
             document.getElementById('repose-reject-modal').classList.remove('is-visible');
             $(btn).prop('disabled', false).text('Confirm Reject');
             showNotice('Network error — please try again.', 'error');
         });
    });

    /* ── Inline Edit ───────────────────────────────────────────── */

    $(document).on('click', '.btn-edit-order', function () {
        var orderId = orderIdFrom(this);
        if (!orderId) { alert('Cannot find order ID. Please refresh.'); return; }

        var editRow = document.getElementById('edit-row-' + orderId);
        if (!editRow) { alert('Edit row not found for order #' + orderId); return; }

        /* Close all other open edit rows */
        document.querySelectorAll('tr[id^="edit-row-"]').forEach(function (row) {
            if (row.id !== 'edit-row-' + orderId) row.style.display = 'none';
        });

        /* Toggle this row */
        editRow.style.display = (editRow.style.display === 'none' || editRow.style.display === '') ? 'table-row' : 'none';
    });

    $(document).on('click', '.btn-cancel-edit', function () {
        $(this).closest('tr').hide();
    });

    $(document).on('click', '.btn-save-edit', function () {
        var btn   = this;
        var $wrap = $(btn).closest('.repose-inline-edit');
        var orderId = $wrap.attr('data-order-id');
        if (!orderId) { alert('Cannot find order ID.'); return; }

        $(btn).prop('disabled', true).text('Saving...');

        $.post(ajaxUrl, {
            action     : 'repose_save_order_edit',
            nonce      : nonce,
            order_id   : orderId,
            forename   : $wrap.find('.edit-forename').val(),
            surname    : $wrap.find('.edit-surname').val(),
            sex        : $wrap.find('.edit-sex').val(),
            dob        : $wrap.find('.edit-dob').val(),
            order_type : $wrap.find('.edit-order-type').val(),
            email      : $wrap.find('.edit-email').val(),
            edit_note  : $wrap.find('.edit-note').val()
        })
        .done(function (r) {
            $(btn).prop('disabled', false).text('Save Changes');
            if (r && r.success) {
                showNotice(r.data.message || 'Fields saved.');
                var name = ($wrap.find('.edit-forename').val() + ' ' + $wrap.find('.edit-surname').val()).trim();
                $('#queue-row-' + orderId + ' td:nth-child(2)').text(name);
                $wrap.find('.edit-feedback').text(r.data.message || 'Saved.').show().delay(3000).fadeOut();
            } else {
                showNotice((r && r.data) ? r.data : 'Save failed.', 'error');
            }
        })
        .fail(function () {
            $(btn).prop('disabled', false).text('Save Changes');
            showNotice('Network error — please try again.', 'error');
        });
    });

    /* ── Results Queue ─────────────────────────────────────────── */

    $(document).on('click', '.btn-add-note', function () {
        var resultId = $(this).closest('tr').find('.repose-result-actions').attr('data-result-id');
        $('#note-row-' + resultId).toggle();
    });

    $(document).on('change', '.repose-template-picker', function () {
        var body = $(this).val();
        var vis  = $(this).find(':selected').attr('data-vis');
        if (!body) return;
        var $td = $(this).closest('td');
        $td.find('.repose-note-text').val(body);
        $td.find('.repose-note-visibility').val(vis);
        $(this).val('');
    });

    $(document).on('click', '.btn-save-note', function () {
        var $td        = $(this).closest('td');
        var resultId   = $td.closest('tr').attr('id').replace('note-row-', '');
        var note       = $td.find('.repose-note-text').val().trim();
        var visibility = $td.find('.repose-note-visibility').val();
        if (!note) { alert('Please enter a note.'); return; }

        $.post(ajaxUrl, { action: 'repose_add_note', result_id: resultId, note: note, visibility: visibility, nonce: nonce })
         .done(function (r) {
             if (r && r.success) {
                 showNotice('Note saved.');
                 $td.find('.repose-note-text').val('');
                 var $ul = $td.find('ul');
                 if (!$ul.length) { $td.prepend('<strong>Existing Notes:</strong><ul></ul>'); $ul = $td.find('ul'); }
                 $ul.append('<li>[' + $('<span>').text(visibility).html() + '] ' + $('<span>').text(note).html() + ' <button class="btn-delete-note">Delete</button></li>');
             } else { showNotice((r && r.data) ? r.data : 'Failed.', 'error'); }
         });
    });
    $(document).on('click', '.btn-delete-note', function () {
        var $li = $(this).closest('li');
        var noteId = $(this).attr('data-note-id');
        if (!noteId) { alert('Cannot find note ID.'); return; }
        if (!confirm('Delete this note?')) return;

        $.post(ajaxUrl, { action: 'repose_delete_note', note_id: noteId, nonce: nonce })
         .done(function (r) {
             if (r && r.success) {
                 showNotice(r.data.message || 'Note deleted.');
                 $li.fadeOut(400, function () { $(this).remove(); });
             } else { showNotice((r && r.data) ? r.data : 'Failed.', 'error'); }
            });
    });
    $(document).on('click', '.btn-approve-result', function () {
        var btn      = this;
        var $actions = $(btn).closest('.repose-result-actions');
        var resultId = $actions.attr('data-result-id');
        var sched    = $actions.find('.chk-schedule-review').is(':checked');
        if (!confirm('Approve result and notify the patient?')) return;

        $(btn).prop('disabled', true).text('Approving...');
        $.post(ajaxUrl, { action: 'repose_approve_result', result_id: resultId, schedule_review: sched ? 1 : 0, nonce: nonce })
         .done(function (r) {
             $(btn).prop('disabled', false).text('Approve & Notify');
             if (r && r.success) {
                 showNotice(r.data.message || 'Approved.');
                 $actions.closest('tr').add('#note-row-' + resultId).fadeOut(400, function () { $(this).remove(); });
             } else { showNotice((r && r.data) ? r.data : 'Failed.', 'error'); }
         });
    });

    /* ── Comment Library ───────────────────────────────────────── */

    $('#btn-new-template').on('click', function () {
        $('#tmpl-id').val(0);
        $('#tmpl-title, #tmpl-body').val('');
        $('#tmpl-visibility').val('patient');
        $('#template-form-title').text('New Template');
        $('#template-form').slideDown();
    });

    $(document).on('click', '.btn-edit-template', function () {
        var $b = $(this);
        $('#tmpl-id').val($b.attr('data-id'));
        $('#tmpl-title').val($b.attr('data-title'));
        $('#tmpl-body').val($b.attr('data-body'));
        $('#tmpl-visibility').val($b.attr('data-vis'));
        $('#template-form-title').text('Edit Template');
        $('#template-form').slideDown();
        $('html,body').animate({ scrollTop: $('#template-form').offset().top - 40 }, 300);
    });

    $('#btn-cancel-template').on('click', function () { $('#template-form').slideUp(); });

    $('#btn-save-template').on('click', function () {
        var id    = $('#tmpl-id').val();
        var title = $('#tmpl-title').val().trim();
        var body  = $('#tmpl-body').val().trim();
        var vis   = $('#tmpl-visibility').val();
        if (!title || !body) { alert('Title and body are required.'); return; }

        $.post(ajaxUrl, { action: 'repose_save_template', template_id: id, title: title, body: body, visibility: vis, nonce: nonce })
         .done(function (r) {
             if (r && r.success) {
                 $('#tmpl-feedback').text(r.data.message).show().delay(2000).fadeOut();
                 if (parseInt(id) > 0) {
                     $('#tpl-row-' + id + ' td:eq(0)').text(title);
                     $('#tpl-row-' + id + ' td:eq(1)').text(body);
                     $('#tpl-row-' + id + ' td:eq(2)').text(vis.charAt(0).toUpperCase() + vis.slice(1));
                     $('#tpl-row-' + id + ' .btn-edit-template').attr({ 'data-title': title, 'data-body': body, 'data-vis': vis });
                 } else { location.reload(); }
                 $('#template-form').slideUp();
             } else { showNotice((r && r.data) ? r.data : 'Failed.', 'error'); }
         });
    });

    $(document).on('click', '.btn-delete-template', function () {
        var id = $(this).attr('data-id');
        if (!confirm('Delete this template?')) return;
        $.post(ajaxUrl, { action: 'repose_delete_template', template_id: id, nonce: nonce })
         .done(function (r) {
             if (r && r.success) { $('#tpl-row-' + id).fadeOut(400, function () { $(this).remove(); }); }
             else { showNotice((r && r.data) ? r.data : 'Failed.', 'error'); }
         });
    });


    /* ── Batch CSV Send ───────────────────────────────────────── */

    var $batchBtn = $('#btn-send-batch');
    if ($batchBtn.length) {
        $batchBtn.on('click', function () {
            if (!confirm('Generate and send a batch CSV for all pending transmitted orders now?')) return;
            $batchBtn.prop('disabled', true).text('Sending...');
            var $fb = $('#batch-feedback');
            $.post(ajaxUrl, { action: 'repose_send_batch_csv', nonce: nonce })
             .done(function (r) {
                 $batchBtn.prop('disabled', false).text('Generate & Send Batch CSV Now');
                 if (r && r.success) {
                     $fb.text('✓ ' + (r.data.message || 'Batch sent.')).show();
                     setTimeout(function () { $fb.fadeOut(); }, 6000);
                 } else {
                     $fb.css('color','#c0392b').text('✗ ' + ((r && r.data) ? r.data : 'Failed.')).show();
                 }
             })
             .fail(function () {
                 $batchBtn.prop('disabled', false).text('Generate & Send Batch CSV Now');
                 $fb.css('color','#c0392b').text('✗ Network error.').show();
             });
        });
    }

    /* ── TDL Test Code inputs — force uppercase ───────────────── */

    $(document).on('input', '.tdl-code-input', function () {
        var pos = this.selectionStart;
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        this.setSelectionRange(pos, pos);
    });

    /* ── Flatpickr: init on all .rh-flatpickr inputs ─────────── */
    function rhInitFlatpickrAll(scope) {
        if ( typeof flatpickr === 'undefined' ) return;
        (scope || document).querySelectorAll('.rh-flatpickr').forEach(function(el) {
            if ( el._flatpickr ) return;
            el._flatpickr = flatpickr(el, {
                dateFormat   : 'd/m/Y',
                allowInput   : true,
                disableMobile: true,
            });
        });
    }
    rhInitFlatpickrAll();

    // Also re-init when an edit panel is opened (mutation observer)
    var fpObserver = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(node) {
                if ( node.nodeType === 1 ) rhInitFlatpickrAll(node);
            });
        });
    });
    fpObserver.observe(document.body, { childList: true, subtree: true });

}); /* end DOMContentLoaded */
