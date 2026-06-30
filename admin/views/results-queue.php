<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<div class="wrap repose-admin">
    <h1>Results Review Queue</h1>

    <?php
    global $wpdb;

    $tab = sanitize_text_field( $_GET['rh_tab'] ?? 'pending_review' );
    $allowed_tabs = array( 'pending_review', 'reported', 'all' );
    if ( ! in_array( $tab, $allowed_tabs, true ) ) $tab = 'pending_review';

    $where_map = array(
        'pending_review' => "status = 'pending_review'",
        'reported'       => "status = 'reported'",
        'all'            => '1=1',
    );

    $counts_raw = $wpdb->get_results(
        "SELECT status, COUNT(*) as cnt FROM {$wpdb->prefix}repose_results GROUP BY status",
        OBJECT_K
    );
    $cnt = function( $s ) use ( $counts_raw ) {
        return isset( $counts_raw[ $s ] ) ? (int) $counts_raw[ $s ]->cnt : 0;
    };
    $total_all = array_sum( array_column( (array) $counts_raw, 'cnt' ) );

    // Always newest first
    $results = $wpdb->get_results(
        "SELECT * FROM {$wpdb->prefix}repose_results
         WHERE {$where_map[$tab]}
         ORDER BY uploaded_at DESC"
    );

    $base_url = admin_url( 'admin.php?page=repose-results-queue' );
    $tabs = array(
        'pending_review' => array( 'Pending Review', $cnt('pending_review'), '#856404', '#fff3cd' ),
        'reported'       => array( 'Approved',        $cnt('reported'),       '#2e7d4f', '#d4edda' ),
        'all'            => array( 'All Results',     $total_all,             '#1a6e8c', '#e8f4f8' ),
    );
    ?>

    <div style="display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid #ddd;padding-bottom:0;">
        <?php foreach ( $tabs as $key => list( $label, $count, $fc, $bg ) ) :
            $active = $tab === $key; ?>
        <a href="<?php echo esc_url( $base_url . '&rh_tab=' . $key ); ?>"
           style="text-decoration:none;padding:8px 14px;font-size:13px;font-weight:600;
                  border-radius:6px 6px 0 0;
                  background:<?php echo $active ? '#fff' : '#f4f4f4'; ?>;
                  color:<?php echo $active ? '#1a6e8c' : '#555'; ?>;
                  border:1px solid <?php echo $active ? '#ddd' : 'transparent'; ?>;
                  border-bottom:<?php echo $active ? '2px solid #fff' : '1px solid transparent'; ?>;
                  margin-bottom:-2px;">
            <?php echo esc_html( $label ); ?>
            <span style="margin-left:4px;background:<?php echo $bg ?>;color:<?php echo $fc ?>;
                         border-radius:10px;padding:1px 7px;font-size:11px;">
                <?php echo $count; ?>
            </span>
        </a>
        <?php endforeach; ?>
    </div>

    <?php if ( empty( $results ) ) : ?>
        <p class="repose-empty">
            <?php echo $tab === 'pending_review'
                ? '&#10003; No results currently require review.'
                : 'No records found in this view.'; ?>
        </p>
    <?php else : ?>

    <p style="color:#888;font-size:12px;margin:0 0 8px;">Showing newest first &mdash; <?php echo count($results); ?> record(s)</p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:70px">ID</th>
                    <th style="width:80px">Order</th>
                    <th style="width:110px">Reference</th>
                    <th>Test Type</th>
                    <th style="width:120px">Status</th>
                    <th style="width:130px">Uploaded &#9660;</th>
                    <th style="width:150px">Approved</th>
                    <th style="width:110px">Patient UID</th>
                    <th style="width:90px">Source</th>
                    <th style="width:<?php echo $tab === 'pending_review' ? '290px' : '130px'; ?>">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $results as $result ) :
                $uploader   = $result->uploaded_by ? get_user_by( 'id', $result->uploaded_by ) : null;
                $approver   = $result->approved_by ? get_user_by( 'id', $result->approved_by ) : null;
                $is_hl7     = ( strpos( $result->file_path, 'json-result-' ) === 0 );
                $is_pending = $result->status === 'pending_review';

                // Admin always uses nonce (they are logged in)
                $admin_nonce = wp_create_nonce( 'repose_download_' . $result->id );
                if ( $is_hl7 ) {
                    $file_url = add_query_arg( array(
                        'action'    => 'repose_generate_hl7_pdf',
                        'result_id' => $result->id,
                        'nonce'     => $admin_nonce,
                    ), admin_url( 'admin-ajax.php' ) );
                } else {
                    $file_url = add_query_arg( array(
                        'action'    => 'repose_view_result',
                        'result_id' => $result->id,
                        'nonce'     => $admin_nonce,
                    ), admin_url( 'admin-ajax.php' ) );
                }

                $status_map = array(
                    'pending_review' => array( 'Pending Review', '#856404', '#fff3cd' ),
                    'reported'       => array( 'Approved',       '#2e7d4f', '#d4edda' ),
                );
                list( $slabel, $sfc, $sbg ) = $status_map[ $result->status ] ?? array( ucfirst($result->status), '#333', '#eee' );
            ?>
                <tr>
                    <td><?php echo esc_html( $result->id ); ?></td>
                    <td><a href="<?php echo get_edit_post_link( $result->order_id ); ?>">#<?php echo esc_html( $result->order_id ); ?></a></td>
                    <td><?php echo esc_html( $result->reference_num ); ?></td>
                    <td><?php echo esc_html( $result->test_type ); ?></td>
                    <td>
                        <span style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;
                                     font-weight:600;background:<?php echo esc_attr($sbg); ?>;color:<?php echo esc_attr($sfc); ?>;">
                            <?php echo esc_html( $slabel ); ?>
                        </span>
                    </td>
                    <td style="font-size:12px;"><?php echo esc_html( $result->uploaded_at ); ?></td>
                    <td style="font-size:12px;">
                        <?php if ( $result->approved_at ) {
                            echo esc_html( $result->approved_at );
                            if ( $approver ) echo '<br><span style="color:#888;font-size:11px;">' . esc_html( $approver->display_name ) . '</span>';
                        } else {
                            echo '<span style="color:#ccc;">&#8212;</span>';
                        } ?>
                    </td>
                    <td style="font-size:12px;">
                        <?php
                        // Show patient UID for this result
                        $res_order = wc_get_order( $result->order_id );
                        $res_patient_uid = $res_order ? $res_order->get_meta('_repose_result_patient_uid') : '';
                        if ( ! $res_patient_uid && $res_order ) {
                            $res_patient_uid = $res_order->get_meta('_repose_patient_1_uid');
                        }
                        if ( $res_patient_uid ) {
                            $reg_pid = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}repose_patients WHERE patient_uid=%s", $res_patient_uid ) );
                            if ( $reg_pid ) {
                                echo '<a href="'.esc_url(admin_url('admin.php?page=repose-patient-registry&patient_id='.$reg_pid)).'" style="font-weight:600;color:#1a6e8c;">'.esc_html($res_patient_uid).'</a>';
                            } else {
                                echo esc_html($res_patient_uid);
                            }
                        } else {
                            echo '<span style="color:#ccc;">—</span>';
                        }
                        ?>
                    </td>
                    <td><?php
                        if ( $is_hl7 ) {
                            echo '<span style="background:#1a6e8c;color:#fff;padding:2px 6px;border-radius:3px;font-size:11px;">API / HL7</span>';
                        } elseif ( $uploader ) {
                            echo esc_html( $uploader->display_name );
                        } else {
                            echo 'System';
                        }
                    ?></td>
                    <td class="repose-result-actions" data-result-id="<?php echo esc_attr( $result->id ); ?>">
                        <!-- View Result is ALWAYS visible regardless of status -->
                        <a href="<?php echo esc_url( $file_url ); ?>" class="button button-small" target="_blank">View Result</a>
                        <button class="button button-small btn-add-note">Add Note</button>
                        <button class="button button-small" onclick="rhRqAssignPatient(<?php echo $result->id;?>,<?php echo $result->order_id;?>)" title="Assign this result to a specific patient">&#128101; Assign Patient</button>
                        <?php if ( $is_pending ) : ?>
                        <label style="font-size:11px;white-space:nowrap;display:inline-flex;align-items:center;gap:3px;">
                            <input type="checkbox" class="chk-schedule-review"> 24h Review
                        </label>
                        <button class="button button-small button-primary btn-approve-result">Approve &amp; Notify</button>
                        <?php else : ?>
                        <span style="font-size:11px;color:#2e7d4f;font-weight:600;">&#10003; Notified</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr class="repose-note-row" id="note-row-<?php echo $result->id; ?>" style="display:none;">
                    <td colspan="9" style="padding:12px 16px;background:#f9f9f9;border-top:1px dashed #ddd;">
                        <?php
                        $notes = HN_Repose_Results_Manager::get_notes( $result->id );
                        if ( $notes ) : ?>
                        <strong style="display:block;margin-bottom:6px;">Existing Notes:</strong>
                        <ul style="margin:0 0 12px;padding-left:18px;">
                        <?php foreach ( $notes as $note ) : ?>
                            <li style="font-size:12px;margin-bottom:4px;">
                                <span style="background:<?php echo $note->visibility==='patient'?'#d4edda':'#f8d7da'; ?>;
                                             color:<?php echo $note->visibility==='patient'?'#155724':'#721c24'; ?>;
                                             border-radius:3px;padding:1px 5px;font-size:10px;margin-right:4px;">
                                    <?php echo esc_html( $note->visibility ); ?>
                                </span>
                                <?php echo esc_html( $note->note ); ?>
                                <span style="color:#aaa;font-size:11px;"> &mdash; <?php echo esc_html( $note->created_at ); ?></span>
                            </li>
                        <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>

                        <strong>Comment Templates:</strong>
                        <select class="repose-template-picker" data-result-id="<?php echo $result->id; ?>" style="margin-left:6px;">
                            <option value="">&#8212; Insert template &#8212;</option>
                            <?php foreach ( Repose_Comment_Library::get_all() as $tpl ) : ?>
                                <option value="<?php echo esc_attr( $tpl->body ); ?>" data-vis="<?php echo esc_attr( $tpl->visibility ); ?>"><?php echo esc_html( $tpl->title ); ?> (<?php echo esc_html( $tpl->visibility ); ?>)</option>
                            <?php endforeach; ?>
                        </select>

                        <div style="margin-top:8px;">
                            <textarea class="repose-note-text" rows="3"
                                      placeholder="Type a note..." style="width:100%;box-sizing:border-box;"></textarea>
                            <div style="display:flex;gap:8px;margin-top:6px;align-items:center;">
                                <select class="repose-note-visibility">
                                    <option value="internal">Internal only</option>
                                    <option value="patient">Patient-visible</option>
                                </select>
                                <button class="button button-primary button-small btn-save-note">Save Note</button>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

<!-- Assign Patient to Result modal -->
<div id="rh-rq-assign-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:999999;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:28px 32px;border-radius:8px;width:500px;max-width:90%;box-shadow:0 8px 40px rgba(0,0,0,.3);max-height:85vh;overflow-y:auto;">
        <h3 style="margin:0 0 6px;color:#1a6e8c;">Assign Result to Patient</h3>
        <p style="margin:0 0 14px;font-size:13px;color:#555;">Result #<span id="rh-rq-result-id-label"></span> &mdash; Order #<span id="rh-rq-order-id-label"></span></p>
        <input type="hidden" id="rh-rq-result-id" value="">
        <input type="hidden" id="rh-rq-order-id"  value="">

        <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Search patient by name, UID or email</label>
        <div style="display:flex;gap:8px;margin-bottom:8px;">
            <input type="text" id="rh-rq-search-input" placeholder="e.g. John Smith, RHP-00001, john@email.com"
                   style="flex:1;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;"
                   oninput="rhRqSearch()">
            <button type="button" class="button" onclick="rhRqSearch()">Search</button>
        </div>

        <div id="rh-rq-search-results" style="max-height:220px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:4px;margin-bottom:12px;display:none;"></div>

        <div id="rh-rq-selected-patient" style="display:none;padding:10px 12px;background:#f0f7fb;border:1px solid #b3d4e0;border-radius:4px;margin-bottom:14px;font-size:13px;">
            <strong id="rh-rq-sel-name"></strong> &nbsp;
            <span id="rh-rq-sel-uid" style="color:#1a6e8c;font-weight:600;"></span>
            <button type="button" onclick="rhRqClearSel()" style="float:right;background:none;border:none;color:#c0392b;cursor:pointer;font-size:12px;">&#10005; Clear</button>
        </div>

        <input type="hidden" id="rh-rq-sel-patient-id" value="">
        <button type="button" class="button button-primary" onclick="rhRqConfirmAssign()">Assign to This Patient</button>
        <button type="button" class="button" onclick="document.getElementById('rh-rq-assign-modal').style.display='none'" style="margin-left:8px;">Cancel</button>
        <span id="rh-rq-assign-fb" style="display:none;margin-left:10px;font-size:13px;color:#2e7d4f;"></span>
    </div>
</div>

<script>
var rhRqAjax = <?php echo json_encode(['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('repose_admin_nonce')]); ?>;
var rhRqSearchTimer = null;

function rhRqAssignPatient(resultId, orderId){
    document.getElementById('rh-rq-result-id').value = resultId;
    document.getElementById('rh-rq-order-id').value  = orderId;
    document.getElementById('rh-rq-result-id-label').textContent = resultId;
    document.getElementById('rh-rq-order-id-label').textContent  = orderId;
    document.getElementById('rh-rq-search-input').value = '';
    document.getElementById('rh-rq-search-results').style.display = 'none';
    document.getElementById('rh-rq-selected-patient').style.display = 'none';
    document.getElementById('rh-rq-sel-patient-id').value = '';
    document.getElementById('rh-rq-assign-fb').style.display = 'none';
    document.getElementById('rh-rq-assign-modal').style.display = 'block';
    setTimeout(function(){ document.getElementById('rh-rq-search-input').focus(); }, 120);
}

function rhRqSearch(){
    clearTimeout(rhRqSearchTimer);
    rhRqSearchTimer = setTimeout(function(){
        var term = document.getElementById('rh-rq-search-input').value.trim();
        if(term.length < 2){ document.getElementById('rh-rq-search-results').style.display='none'; return; }
        var body='action=repose_search_patients&nonce='+encodeURIComponent(rhRqAjax.nonce)+'&term='+encodeURIComponent(term);
        fetch(rhRqAjax.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
            .then(function(r){return r.json();}).then(function(r){
                var res=document.getElementById('rh-rq-search-results');
                if(!r||!r.success||!r.data.patients.length){
                    res.innerHTML='<div style="padding:10px 12px;font-size:13px;color:#888;">No patients found.</div>';
                    res.style.display='block'; return;
                }
                var html='';
                r.data.patients.forEach(function(p){
                    html+='<div onclick="rhRqSelectPatient('+p.id+',''+String(p.patient_uid||'').replace(/'/g,"\'")+'',''+String((p.forename+' '+p.surname)||'').replace(/'/g,"\'")+'''+')"'+
                        ' style="padding:9px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:13px;"'+
                        ' onmouseover="this.style.background='#f0f7fb'" onmouseout="this.style.background=''">' +
                        '<strong>'+escRqHtml(p.forename+' '+p.surname)+'</strong> '+
                        '<span style="background:#e8f4f8;color:#1a6e8c;font-size:11px;padding:1px 6px;border-radius:8px;">'+escRqHtml(p.patient_uid)+'</span>'+
                        '<div style="font-size:11px;color:#888;">'+escRqHtml(p.email||'—')+'</div></div>';
                });
                res.innerHTML=html; res.style.display='block';
            });
    }, 300);
}

function escRqHtml(s){ var d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML; }

function rhRqSelectPatient(id, uid, name){
    document.getElementById('rh-rq-sel-patient-id').value = id;
    document.getElementById('rh-rq-sel-name').textContent = name;
    document.getElementById('rh-rq-sel-uid').textContent  = uid;
    document.getElementById('rh-rq-selected-patient').style.display = 'block';
    document.getElementById('rh-rq-search-results').style.display   = 'none';
}

function rhRqClearSel(){
    document.getElementById('rh-rq-sel-patient-id').value = '';
    document.getElementById('rh-rq-selected-patient').style.display = 'none';
}

function rhRqConfirmAssign(){
    var resultId  = document.getElementById('rh-rq-result-id').value;
    var patientId = document.getElementById('rh-rq-sel-patient-id').value;
    if(!patientId){ alert('Please search and select a patient first.'); return; }
    var btn=event.target; btn.disabled=true; btn.textContent='Assigning...';
    var body='action=repose_assign_result_patient&nonce='+encodeURIComponent(rhRqAjax.nonce)
        +'&result_id='+encodeURIComponent(resultId)+'&patient_id='+encodeURIComponent(patientId);
    fetch(rhRqAjax.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(function(r){return r.json();}).then(function(r){
            btn.disabled=false; btn.textContent='Assign to This Patient';
            if(r&&r.success){
                document.getElementById('rh-rq-assign-fb').textContent='✓ '+r.data.message;
                document.getElementById('rh-rq-assign-fb').style.display='inline';
                setTimeout(function(){ document.getElementById('rh-rq-assign-modal').style.display='none'; location.reload(); },1200);
            } else { alert((r&&r.data)?r.data:'Failed.'); }
        }).catch(function(){ btn.disabled=false; btn.textContent='Assign to This Patient'; alert('Network error.'); });
}

document.getElementById('rh-rq-assign-modal').addEventListener('click',function(e){ if(e.target===this)this.style.display='none'; });
</script>
</div>
