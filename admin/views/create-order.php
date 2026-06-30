<?php
/**
 * Create Order — admin can create a new order, search customer by email,
 * add multiple patients (each with individual test assignment), authorise and transmit.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Fetch WC products for test selection
$products = wc_get_products( array( 'status' => 'publish', 'limit' => 200, 'return' => 'objects' ) );
$product_options = '';
foreach ( $products as $prod ) {
    $product_options .= '<option value="' . esc_attr($prod->get_id()) . '" data-name="' . esc_attr($prod->get_name()) . '" data-price="' . esc_attr($prod->get_price()) . '">' . esc_html($prod->get_name()) . ' — £' . number_format((float)$prod->get_price(),2) . '</option>';
}
?>
<style>
.rh-create-section { background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;margin-bottom:20px; }
.rh-create-section h3 { margin:0 0 14px;font-size:14px;font-weight:700;color:#1a6e8c;border-bottom:2px solid #1a6e8c;padding-bottom:7px; }
.rh-fl { display:block;font-size:12px;font-weight:600;margin-bottom:3px;color:#374151; }
.rh-fi { width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box; }
.rh-fi:focus { border-color:#1a6e8c;outline:none;box-shadow:0 0 0 2px rgba(26,110,140,.15); }
.rh-g2 { display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px; }
.rh-g3 { display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px; }
.rh-patient-card { background:#f7fbfd;border:1px solid #b3d4e0;border-radius:6px;padding:14px;margin-bottom:14px; }
.rh-patient-card-header { display:flex;align-items:center;justify-content:space-between;margin-bottom:12px; }
.rh-patient-card-header strong { font-size:13px;color:#1a6e8c; }
.rh-test-row { display:flex;gap:8px;align-items:center;margin-bottom:6px; }
.rh-test-row select { flex:1; }
.rh-remove-test { background:none;border:none;color:#c0392b;cursor:pointer;font-size:18px;padding:0 4px;line-height:1; }
.rh-add-test-btn { background:none;border:1px dashed #1a6e8c;color:#1a6e8c;padding:4px 12px;border-radius:4px;cursor:pointer;font-size:12px;font-weight:600;margin-top:4px; }
.rh-add-test-btn:hover { background:#e8f4f8; }
</style>

<div class="wrap repose-admin">
    <h1>Create New Order</h1>
    <p style="color:#666;font-size:13px;margin-top:-12px;margin-bottom:20px;">Create an order from scratch, assign tests per patient, then authorise and transmit to the laboratory.</p>

    <div id="rh-co-notice" style="display:none;padding:10px 14px;margin:0 0 16px;border-radius:4px;font-size:13px;"></div>

    <!-- ── Customer ──────────────────────────────────────────────────────── -->
    <div class="rh-create-section">
        <h3>1. Customer / Billing Details</h3>

        <div style="background:#f0f7fb;border:1px solid #b3d4e0;border-radius:6px;padding:12px 14px;margin-bottom:14px;">
            <label class="rh-fl" style="color:#1a6e8c;">🔍 Search by customer email (auto-fill fields)</label>
            <div style="display:flex;gap:8px;">
                <input type="email" id="co-search-email" class="rh-fi" style="flex:1;" placeholder="customer@example.com">
                <button type="button" class="button" onclick="rhCoSearchCustomer()">Find Customer</button>
            </div>
            <div id="co-search-result" style="margin-top:6px;font-size:12px;color:#555;"></div>
        </div>

        <div class="rh-g3">
            <div><label class="rh-fl">First Name *</label><input type="text" id="co-first-name" class="rh-fi" placeholder="First name"></div>
            <div><label class="rh-fl">Last Name *</label><input type="text" id="co-last-name" class="rh-fi" placeholder="Last name"></div>
            <div><label class="rh-fl">Email *</label><input type="email" id="co-email" class="rh-fi" placeholder="email@example.com"></div>
            <div><label class="rh-fl">Phone</label><input type="text" id="co-phone" class="rh-fi" placeholder="Phone number"></div>
            <div style="grid-column:span 2;"><label class="rh-fl">Address Line 1</label><input type="text" id="co-addr1" class="rh-fi" placeholder="Address line 1"></div>
            <div><label class="rh-fl">Address Line 2</label><input type="text" id="co-addr2" class="rh-fi" placeholder="Address line 2"></div>
            <div><label class="rh-fl">City / Town</label><input type="text" id="co-city" class="rh-fi" placeholder="City"></div>
            <div><label class="rh-fl">Postcode</label><input type="text" id="co-postcode" class="rh-fi" placeholder="Postcode"></div>
        </div>
        <input type="hidden" id="co-wc-user-id" value="">
    </div>

    <!-- ── Patients ───────────────────────────────────────────────────────── -->
    <div class="rh-create-section">
        <h3>2. Patients & Test Assignment</h3>
        <p style="font-size:12px;color:#666;margin:0 0 14px;">Each patient must have their own details. Assign one or more tests individually per patient.</p>

        <div id="co-patients-container">
            <!-- Patient 1 rendered by JS below -->
        </div>

        <button type="button" id="co-add-patient-btn" class="button" onclick="rhCoAddPatient()" style="margin-top:4px;">
            + Add Another Patient
        </button>
        <span id="co-patient-limit-msg" style="display:none;font-size:12px;color:#856404;margin-left:10px;">Maximum 5 patients per order.</span>
    </div>

    <!-- ── Order Notes ────────────────────────────────────────────────────── -->
    <div class="rh-create-section">
        <h3>3. Order Notes (optional)</h3>
        <textarea id="co-order-notes" class="rh-fi" rows="3" placeholder="Internal notes about this order — not sent to customer."></textarea>
    </div>

    <!-- ── Actions ───────────────────────────────────────────────────────── -->
    <div class="rh-create-section" style="background:#f0f7fb;">
        <h3>4. Authorise & Transmit</h3>
        <p style="font-size:13px;color:#555;margin:0 0 14px;">
            <strong>Save to Queue</strong> — saves the order to the Auth Queue for review before transmitting.<br>
            <strong>Authorise &amp; Transmit Now</strong> — immediately generates the lab CSV and transmits to the laboratory.
        </p>
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button type="button" class="button button-primary" onclick="rhCoSubmit('queue')" id="co-btn-queue">Save to Auth Queue</button>
            <button type="button" class="button" onclick="rhCoSubmit('transmit')" id="co-btn-transmit"
                    style="background:#2e7d4f;border-color:#1f5c39;color:#fff;">&#9654; Authorise &amp; Transmit Now</button>
            <span id="co-submit-feedback" style="display:none;align-self:center;font-size:13px;color:#2e7d4f;font-weight:600;"></span>
        </div>
    </div>
</div>

<script>
var rhCoAjax = <?php echo json_encode([
    'url'     => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('repose_admin_nonce'),
    'authUrl' => admin_url('admin.php?page=repose-auth-queue'),
]); ?>;

var rhCoProducts = <?php echo json_encode( array_map( function($p) {
    return [ 'id' => $p->get_id(), 'name' => $p->get_name(), 'price' => $p->get_price() ];
}, $products ) ); ?>;

var rhCoPatients = [];
var rhCoPatientCount = 0;
var MAX_CO_PATIENTS = 5;

// ── Render a product dropdown ─────────────────────────────────────────────
function rhCoProductSelect(nameAttr) {
    var sel = '<select name="'+nameAttr+'" class="rh-fi" style="flex:1;">' +
        '<option value="">— Select Test —</option>';
    rhCoProducts.forEach(function(p){
        sel += '<option value="'+p.id+'" data-name="'+escHtml(p.name)+'">'+escHtml(p.name)+' — £'+parseFloat(p.price).toFixed(2)+'</option>';
    });
    sel += '</select>';
    return sel;
}
function escHtml(s){ var d=document.createElement('div');d.textContent=s;return d.innerHTML; }

// ── Add test row inside a patient card ────────────────────────────────────
function rhCoAddTestRow(pIdx) {
    var container = document.getElementById('co-tests-'+pIdx);
    var rowIdx = container.children.length;
    var div = document.createElement('div');
    div.className = 'rh-test-row';
    div.innerHTML = rhCoProductSelect('co_patient_'+pIdx+'_test[]') +
        '<button type="button" class="rh-remove-test" onclick="this.parentNode.remove()" title="Remove test">&times;</button>';
    container.appendChild(div);
}

// ── Render a patient card ─────────────────────────────────────────────────
function rhCoRenderPatient(pIdx) {
    var isFirst = pIdx === 0;
    var label   = isFirst ? 'Patient 1' : 'Additional Patient ' + (pIdx+1);
    var removeBtn = isFirst ? '' : '<button type="button" class="button button-small" style="color:#c0392b;" onclick="rhCoRemovePatient('+pIdx+')">Remove</button>';

    var html = '<div class="rh-patient-card" id="co-patient-card-'+pIdx+'">' +
        '<div class="rh-patient-card-header">' +
            '<strong>'+label+'</strong>' + removeBtn +
        '</div>' +
        '<div class="rh-g3">' +
            '<div><label class="rh-fl">Forename *</label><input type="text" id="co-p'+pIdx+'-forename" class="rh-fi" placeholder="Forename"></div>' +
            '<div><label class="rh-fl">Surname *</label><input type="text" id="co-p'+pIdx+'-surname" class="rh-fi" placeholder="Surname"></div>' +
            '<div><label class="rh-fl">Date of Birth * (DD/MM/YYYY)</label><input type="text" id="co-p'+pIdx+'-dob" class="rh-fi rh-flatpickr" placeholder="DD/MM/YYYY" readonly></div>' +
            '<div><label class="rh-fl">Sex at Birth *</label>' +
                '<select id="co-p'+pIdx+'-sex" class="rh-fi">' +
                    '<option value="">Please select</option>' +
                    '<option value="male">Male</option>' +
                    '<option value="female">Female</option>' +
                '</select></div>' +
            '<div style="grid-column:span 2;"><label class="rh-fl">Additional Notes (optional)</label>' +
                '<input type="text" id="co-p'+pIdx+'-notes" class="rh-fi" placeholder="Symptoms or relevant info"></div>' +
        '</div>' +
        '<label class="rh-fl" style="margin-bottom:6px;">Tests for this patient *</label>' +
        '<div id="co-tests-'+pIdx+'"></div>' +
        '<button type="button" class="rh-add-test-btn" onclick="rhCoAddTestRow('+pIdx+')">+ Add Test</button>' +
    '</div>';

    var container = document.getElementById('co-patients-container');
    container.insertAdjacentHTML('beforeend', html);
    rhCoAddTestRow(pIdx); // start with one test row
    rhCoPatients.push(pIdx);
}

function rhCoAddPatient() {
    if (rhCoPatients.length >= MAX_CO_PATIENTS) {
        document.getElementById('co-patient-limit-msg').style.display = 'inline';
        return;
    }
    rhCoRenderPatient(rhCoPatientCount++);
    document.getElementById('co-add-patient-btn').style.display = rhCoPatients.length >= MAX_CO_PATIENTS ? 'none' : '';
    document.getElementById('co-patient-limit-msg').style.display = rhCoPatients.length >= MAX_CO_PATIENTS ? 'inline' : 'none';
}

function rhCoRemovePatient(pIdx) {
    var card = document.getElementById('co-patient-card-'+pIdx);
    if (card) card.remove();
    rhCoPatients = rhCoPatients.filter(function(i){ return i !== pIdx; });
    document.getElementById('co-add-patient-btn').style.display = '';
    document.getElementById('co-patient-limit-msg').style.display = 'none';
}

// ── Customer email search ─────────────────────────────────────────────────
function rhCoSearchCustomer() {
    var email = document.getElementById('co-search-email').value.trim();
    if (!email) return;
    var res = document.getElementById('co-search-result');
    res.textContent = 'Searching...';
    var body = 'action=repose_lookup_customer_email&nonce='+encodeURIComponent(rhCoAjax.nonce)+'&email='+encodeURIComponent(email);
    fetch(rhCoAjax.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(function(r){return r.json();}).then(function(r){
            if (!r || !r.success) { res.style.color='#c0392b'; res.textContent='Search failed.'; return; }
            var u = r.data.user;
            var patients = r.data.patients || [];

            if (u) {
                document.getElementById('co-first-name').value = u.first_name||'';
                document.getElementById('co-last-name').value  = u.last_name||'';
                document.getElementById('co-email').value      = u.email||email;
                document.getElementById('co-phone').value      = u.billing_phone||'';
                document.getElementById('co-addr1').value      = u.billing_address_1||'';
                document.getElementById('co-addr2').value      = u.billing_address_2||'';
                document.getElementById('co-city').value       = u.billing_city||'';
                document.getElementById('co-postcode').value   = u.billing_postcode||'';
                document.getElementById('co-wc-user-id').value = u.id||'';
            } else {
                document.getElementById('co-email').value = email;
            }

            res.style.color = '#2e7d4f';

            if (patients.length > 0) {
                // Rebuild patient cards from registry records
                document.getElementById('co-patients-container').innerHTML = '';
                rhCoPatients = []; rhCoPatientCount = 0;
                patients.slice(0, MAX_CO_PATIENTS).forEach(function(p) {
                    var pIdx = rhCoPatientCount;
                    rhCoRenderPatient(rhCoPatientCount++);
                    var f=document.getElementById('co-p'+pIdx+'-forename');
                    var s=document.getElementById('co-p'+pIdx+'-surname');
                    var d=document.getElementById('co-p'+pIdx+'-dob');
                    var x=document.getElementById('co-p'+pIdx+'-sex');
                    if(f) f.value=p.forename||'';
                    if(s) s.value=p.surname||'';
                    if(d) d.value=p.dob||'';
                    if(x) x.value=p.sex||'';
                });
                res.textContent = '\u2713 Found '+(u&&u.id>0?'registered customer':'guest')+' + '+patients.length+' known patient(s). All fields pre-filled from registry.';
            } else if (u) {
                var f0=document.getElementById('co-p0-forename'),s0=document.getElementById('co-p0-surname');
                if(f0&&!f0.value) f0.value=u.first_name||'';
                if(s0&&!s0.value) s0.value=u.last_name||'';
                res.textContent = '\u2713 '+(u.id>0?'Registered customer':'Guest order')+' found: '+u.first_name+' '+u.last_name+'. Billing pre-filled. No registry patients yet.';
            } else {
                res.style.color='#856404';
                res.textContent = 'No records found for this email. Please fill in manually.';
            }
        }).catch(function(){ res.style.color='#c0392b'; res.textContent='Search failed.'; });
}

// ── Collect all form data ─────────────────────────────────────────────────
function rhCoCollect() {
    var data = {
        first_name : document.getElementById('co-first-name').value.trim(),
        last_name  : document.getElementById('co-last-name').value.trim(),
        email      : document.getElementById('co-email').value.trim(),
        phone      : document.getElementById('co-phone').value.trim(),
        addr1      : document.getElementById('co-addr1').value.trim(),
        addr2      : document.getElementById('co-addr2').value.trim(),
        city       : document.getElementById('co-city').value.trim(),
        postcode   : document.getElementById('co-postcode').value.trim(),
        wc_user_id : document.getElementById('co-wc-user-id').value,
        order_notes: document.getElementById('co-order-notes').value.trim(),
        patients   : [],
    };

    var valid = true;
    var errors = [];

    if (!data.first_name || !data.last_name) errors.push('Customer first and last name are required.');
    if (!data.email) errors.push('Customer email is required.');

    rhCoPatients.forEach(function(pIdx) {
        var forename = (document.getElementById('co-p'+pIdx+'-forename')||{}).value || '';
        var surname  = (document.getElementById('co-p'+pIdx+'-surname')||{}).value  || '';
        var dob      = (document.getElementById('co-p'+pIdx+'-dob')||{}).value      || '';
        var sex      = (document.getElementById('co-p'+pIdx+'-sex')||{}).value      || '';
        var notes    = (document.getElementById('co-p'+pIdx+'-notes')||{}).value    || '';

        if (!forename || !surname || !dob || !sex) {
            errors.push('Patient '+(pIdx+1)+': all required fields must be completed.');
            valid = false;
        }

        // Collect tests for this patient
        var testSelects = document.querySelectorAll('#co-tests-'+pIdx+' select');
        var tests = [];
        testSelects.forEach(function(sel){
            if (sel.value) tests.push({ product_id: sel.value, name: sel.options[sel.selectedIndex].dataset.name || sel.options[sel.selectedIndex].text.split(' — ')[0] });
        });

        if (!tests.length) { errors.push('Patient '+(pIdx+1)+': at least one test must be assigned.'); valid=false; }

        data.patients.push({ forename:forename.trim(), surname:surname.trim(), dob:dob, sex:sex, notes:notes.trim(), tests:tests });
    });

    return { data:data, valid:valid&&!errors.length, errors:errors };
}

// ── Submit ────────────────────────────────────────────────────────────────
function rhCoSubmit(mode) {
    var collected = rhCoCollect();
    if (!collected.valid) { alert(collected.errors.join('\n')); return; }

    var btnId = mode==='queue' ? 'co-btn-queue' : 'co-btn-transmit';
    var btn = document.getElementById(btnId);
    btn.disabled = true;
    document.getElementById('co-btn-queue').disabled = true;
    document.getElementById('co-btn-transmit').disabled = true;
    btn.textContent = 'Processing...';

    var body = 'action=repose_create_manual_order&nonce='+encodeURIComponent(rhCoAjax.nonce)
        + '&mode='+encodeURIComponent(mode)
        + '&payload='+encodeURIComponent(JSON.stringify(collected.data));

    fetch(rhCoAjax.url,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(function(r){return r.json();})
        .then(function(r){
            document.getElementById('co-btn-queue').disabled=false;
            document.getElementById('co-btn-transmit').disabled=false;
            btn.textContent = mode==='queue' ? 'Save to Auth Queue' : '\u25BA Authorise & Transmit Now';
            if (r && r.success) {
                var fb = document.getElementById('co-submit-feedback');
                fb.textContent = '\u2713 ' + (r.data.message||'Order created!');
                fb.style.display = 'inline';
                if (r.data.order_id) {
                    setTimeout(function(){ window.location.href = rhCoAjax.authUrl; }, 1500);
                }
            } else {
                var msg = (r&&r.data) ? (typeof r.data==='string'?r.data:r.data.message||'Failed.') : 'Failed.';
                alert(msg);
            }
        })
        .catch(function(){ document.getElementById('co-btn-queue').disabled=false; document.getElementById('co-btn-transmit').disabled=false; alert('Network error.'); });
}

// ── Init ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function(){
    rhCoRenderPatient(rhCoPatientCount++);
});
</script>
