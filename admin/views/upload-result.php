<?php
/**
 * Upload Lab Result — with patient search to assign the result to a specific patient.
 */
if ( ! defined( 'ABSPATH' ) ) exit;
?>
<div class="wrap repose-admin">
    <h1>Upload Lab Result PDF</h1>
    <p style="color:#666;margin-top:0;">Upload a laboratory report PDF. Search for the patient it belongs to, then associate it with their order.</p>

    <div id="repose-upload-feedback" style="display:none;" class="notice notice-success is-dismissible"><p></p></div>
    <div id="repose-upload-error"    style="display:none;" class="notice notice-error is-dismissible"><p></p></div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">

        <!-- Left: Patient Search -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
            <h3 style="margin:0 0 14px;color:#1a6e8c;font-size:14px;border-bottom:2px solid #1a6e8c;padding-bottom:7px;">
                Step 1 — Find the Patient
            </h3>
            <p style="font-size:12px;color:#666;margin:0 0 12px;">Search by patient name, Patient UID (RHP-XXXXX) or email address.</p>

            <div style="display:flex;gap:8px;margin-bottom:10px;">
                <input type="text" id="ur-patient-search" placeholder="Name, RHP-00001, or email…"
                       style="flex:1;padding:8px 10px;border:1px solid #ccc;border-radius:4px;font-size:13px;"
                       oninput="urSearchPatient()">
                <button type="button" class="button" onclick="urSearchPatient()">Search</button>
            </div>

            <div id="ur-patient-results" style="max-height:340px;overflow-y:auto;border:1px solid #e5e7eb;border-radius:4px;display:none;"></div>

            <!-- Selected patient display -->
            <div id="ur-selected-patient" style="display:none;margin-top:12px;padding:12px 14px;background:#f0f7fb;border:1px solid #b3d4e0;border-radius:6px;">
                <div style="font-size:11px;font-weight:600;color:#1a6e8c;text-transform:uppercase;margin-bottom:4px;">Selected Patient</div>
                <div id="ur-selected-name" style="font-size:14px;font-weight:700;color:#111;"></div>
                <div id="ur-selected-uid"  style="font-size:12px;color:#1a6e8c;font-weight:600;"></div>
                <div id="ur-selected-meta" style="font-size:12px;color:#666;margin-top:2px;"></div>
                <div style="margin-top:10px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;">Order this result belongs to</label>
                    <select id="ur-patient-order" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;">
                        <option value="">— Select order —</option>
                    </select>
                    <p style="font-size:11px;color:#888;margin:4px 0 0;">Shows the patient's orders. Choose the one this result PDF is for.</p>
                </div>
                <button type="button" class="button button-small" onclick="urClearPatient()" style="margin-top:8px;color:#c0392b;">&#10005; Clear Selection</button>
            </div>

            <input type="hidden" id="ur-patient-id"  value="">
            <input type="hidden" id="ur-patient-uid" value="">
        </div>

        <!-- Right: Upload form -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
            <h3 style="margin:0 0 14px;color:#1a6e8c;font-size:14px;border-bottom:2px solid #1a6e8c;padding-bottom:7px;">
                Step 2 — Upload the Report
            </h3>

            <form id="repose-upload-form" enctype="multipart/form-data">
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;">Order ID *</label>
                    <input type="number" name="order_id" id="ur-order-id" class="regular-text" required min="1"
                           style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"
                           placeholder="Will auto-fill when you select a patient order above">
                    <p style="font-size:11px;color:#888;margin:3px 0 0;">Or type an Order ID manually if you know it.</p>
                </div>
                <div style="margin-bottom:12px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;">Test Type *</label>
                    <input type="text" name="test_type" id="ur-test-type" class="regular-text" required
                           style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"
                           placeholder="e.g. MRSA Screen, Full Blood Count">
                </div>
                <div style="margin-bottom:16px;">
                    <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#374151;">Result PDF *</label>
                    <input type="file" name="repose_result_file" id="repose_result_file" accept="application/pdf" required
                           style="width:100%;padding:7px 0;font-size:13px;">
                    <p style="font-size:11px;color:#888;margin:3px 0 0;">PDF files only.</p>
                </div>

                <!-- Patient assignment confirmation -->
                <div id="ur-patient-confirm" style="display:none;margin-bottom:14px;padding:10px 12px;background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;font-size:12px;color:#155724;">
                    &#10003; This result will be assigned to patient <strong id="ur-confirm-name"></strong> (<span id="ur-confirm-uid"></span>)
                </div>

                <?php wp_nonce_field( 'repose_admin_nonce', 'nonce' ); ?>
                <button type="submit" class="button button-primary" style="width:100%;padding:10px;">Upload &amp; Assign Result</button>
            </form>
        </div>
    </div>
</div>

<script>
var urAjax = <?php echo json_encode(['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('repose_admin_nonce')]); ?>;
var urSearchTimer = null;

function urSearchPatient() {
    clearTimeout(urSearchTimer);
    urSearchTimer = setTimeout(function(){
        var term = document.getElementById('ur-patient-search').value.trim();
        if (term.length < 2) { document.getElementById('ur-patient-results').style.display='none'; return; }
        var body='action=repose_search_patients&nonce='+encodeURIComponent(urAjax.nonce)+'&term='+encodeURIComponent(term);
        fetch(urAjax.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
            .then(function(r){return r.json();}).then(function(r){
                var res=document.getElementById('ur-patient-results');
                if(!r||!r.success||!r.data.patients.length){
                    res.innerHTML='<div style="padding:12px;font-size:13px;color:#888;">No patients found. Try a different search term.</div>';
                    res.style.display='block'; return;
                }
                var html='';
                r.data.patients.forEach(function(p){
                    html+='<div onclick="urSelectPatient('+p.id+',\''+escAttr(p.patient_uid)+'\',\''+escAttr(p.forename)+'\',\''+escAttr(p.surname)+'\',\''+escAttr(p.dob)+'\',\''+escAttr(p.email)+'\')"'+
                        ' style="padding:10px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;font-size:13px;" '+
                        'onmouseover="this.style.background=\'#f0f7fb\'" onmouseout="this.style.background=\'\'">' +
                        '<strong>'+escHtml(p.forename+' '+p.surname)+'</strong> ' +
                        '<span style="background:#e8f4f8;color:#1a6e8c;font-size:11px;padding:1px 7px;border-radius:8px;font-weight:600;">'+escHtml(p.patient_uid)+'</span>' +
                        '<div style="font-size:11px;color:#888;margin-top:2px;">DOB: '+escHtml(p.dob||'—')+' &nbsp;|&nbsp; '+escHtml(p.email||'—')+'</div>'+
                        '<div style="font-size:11px;color:#1a6e8c;">'+p.test_count+' test(s) on record</div>'+
                    '</div>';
                });
                res.innerHTML=html; res.style.display='block';
            });
    }, 350);
}

function escHtml(s){ var d=document.createElement('div');d.textContent=String(s||'');return d.innerHTML; }
function escAttr(s){ return String(s||'').replace(/'/g,"\\'"); }

function urSelectPatient(id,uid,forename,surname,dob,email){
    document.getElementById('ur-patient-id').value  = id;
    document.getElementById('ur-patient-uid').value = uid;
    document.getElementById('ur-selected-name').textContent = forename+' '+surname;
    document.getElementById('ur-selected-uid').textContent  = uid;
    document.getElementById('ur-selected-meta').textContent = 'DOB: '+(dob||'—')+' | '+(email||'—');
    document.getElementById('ur-selected-patient').style.display='block';
    document.getElementById('ur-patient-results').style.display='none';
    document.getElementById('ur-confirm-name').textContent = forename+' '+surname;
    document.getElementById('ur-confirm-uid').textContent  = uid;
    document.getElementById('ur-patient-confirm').style.display='block';

    // Load this patient's orders
    var body='action=repose_get_patient_orders&nonce='+encodeURIComponent(urAjax.nonce)+'&patient_id='+id;
    fetch(urAjax.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(function(r){return r.json();}).then(function(r){
            var sel=document.getElementById('ur-patient-order');
            sel.innerHTML='<option value="">— Select order —</option>';
            if(r&&r.success&&r.data.orders.length){
                r.data.orders.forEach(function(o){
                    var opt=document.createElement('option');
                    opt.value=o.order_id;
                    opt.textContent='Order #'+o.order_id+' — '+o.test_name+' ('+o.status+')';
                    sel.appendChild(opt);
                });
            } else {
                sel.innerHTML+='<option value="" disabled>No orders found for this patient</option>';
            }
        });
}

function urClearPatient(){
    document.getElementById('ur-patient-id').value='';
    document.getElementById('ur-patient-uid').value='';
    document.getElementById('ur-selected-patient').style.display='none';
    document.getElementById('ur-patient-confirm').style.display='none';
    document.getElementById('ur-order-id').value='';
    document.getElementById('ur-patient-search').value='';
}

// Auto-fill order ID when order selected
document.getElementById('ur-patient-order').addEventListener('change',function(){
    if(this.value) document.getElementById('ur-order-id').value=this.value;
});

// Upload form submission
jQuery(function($){
    $('#repose-upload-form').on('submit',function(e){
        e.preventDefault();
        var fd=new FormData(this);
        fd.append('action','repose_upload_result');
        fd.append('nonce',urAjax.nonce);
        fd.append('patient_id',  document.getElementById('ur-patient-id').value);
        fd.append('patient_uid', document.getElementById('ur-patient-uid').value);
        $.ajax({
            url:urAjax.url, method:'POST', data:fd, processData:false, contentType:false,
            success:function(r){
                if(r.success){
                    $('#repose-upload-feedback p').text(r.data.message);
                    $('#repose-upload-feedback').show();
                    $('#repose-upload-form')[0].reset();
                    urClearPatient();
                } else {
                    $('#repose-upload-error p').text(r.data||'Upload failed.');
                    $('#repose-upload-error').show();
                }
            }
        });
    });
});
</script>
