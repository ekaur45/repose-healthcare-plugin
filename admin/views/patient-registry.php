<?php
/**
 * Patient Registry — central patient database with unique IDs and test history.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$search   = sanitize_text_field( $_GET['rh_search'] ?? '' );
$page_num = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
$per_page = 25;
$view_id  = (int) ( $_GET['patient_id'] ?? 0 );

// ── SINGLE PATIENT VIEW ───────────────────────────────────────────────────
if ( $view_id ) :
    $patient = Repose_Patient_Registry::get( $view_id );
    if ( ! $patient ) {
        echo '<div class="wrap"><div class="notice notice-error"><p>Patient not found.</p></div></div>';
        return;
    }
    $tests = Repose_Patient_Registry::get_patient_tests( $view_id );
    $back  = admin_url( 'admin.php?page=repose-patient-registry' );
?>
<div class="wrap repose-admin">
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px;">
        <a href="<?php echo esc_url($back); ?>" class="button">&larr; Back to Registry</a>
        <h1 style="margin:0;">Patient — <?php echo esc_html($patient->patient_uid); ?></h1>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px;">
        <!-- Demographics -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
            <h3 style="margin:0 0 16px;color:#1a6e8c;font-size:14px;border-bottom:2px solid #1a6e8c;padding-bottom:8px;">Patient Details</h3>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <tr><td style="padding:5px 0;color:#888;width:120px;">Patient UID</td><td><strong style="color:#1a6e8c;font-size:15px;"><?php echo esc_html($patient->patient_uid); ?></strong></td></tr>
                <tr><td style="padding:5px 0;color:#888;">Full Name</td><td><?php echo esc_html(trim($patient->forename.' '.$patient->surname)); ?></td></tr>
                <tr><td style="padding:5px 0;color:#888;">Date of Birth</td><td><?php echo esc_html($patient->dob); ?></td></tr>
                <tr><td style="padding:5px 0;color:#888;">Sex at Birth</td><td><?php echo esc_html(ucfirst($patient->sex)); ?></td></tr>
                <tr><td style="padding:5px 0;color:#888;">Email</td><td><?php echo esc_html($patient->email); ?></td></tr>
                <tr><td style="padding:5px 0;color:#888;">Phone</td><td><?php echo esc_html($patient->phone ?: '—'); ?></td></tr>
                <tr><td style="padding:5px 0;color:#888;">WC User</td><td><?php echo $patient->wc_user_id ? '<a href="'.get_edit_user_link($patient->wc_user_id).'">User #'.esc_html($patient->wc_user_id).'</a>' : '—'; ?></td></tr>
                <tr><td style="padding:5px 0;color:#888;">Registered</td><td><?php echo esc_html($patient->created_at); ?></td></tr>
                <?php if ( $patient->notes ) : ?>
                <tr><td style="padding:5px 0;color:#888;vertical-align:top;">Notes</td><td style="color:#555;"><?php echo nl2br(esc_html($patient->notes)); ?></td></tr>
                <?php endif; ?>
            </table>
            <div style="margin-top:16px;">
                <button type="button" class="button" onclick="rhOpenEditPatient(<?php echo $view_id; ?>)">Edit Patient Details</button>
            </div>
        </div>

        <!-- Assign new test -->
        <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
            <h3 style="margin:0 0 16px;color:#1a6e8c;font-size:14px;border-bottom:2px solid #1a6e8c;padding-bottom:8px;">Assign a Test to This Patient</h3>
            <p style="font-size:12px;color:#666;margin:0 0 12px;">You can assign a test to this patient directly — useful for walk-in or phone orders.</p>
            <div style="margin-bottom:10px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Order ID (WooCommerce)</label>
                <input type="number" id="rh-assign-order" class="rh-field-input" placeholder="e.g. 1045" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;">
            </div>
            <div style="margin-bottom:10px;">
                <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Test Name</label>
                <input type="text" id="rh-assign-test" class="rh-field-input" placeholder="e.g. MRSA Screen" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;">
            </div>
            <button type="button" class="button button-primary" onclick="rhAssignTest(<?php echo $view_id; ?>)">Assign Test</button>
            <span id="rh-assign-feedback" style="display:none;margin-left:10px;font-size:13px;color:#2e7d4f;"></span>
        </div>
    </div>

    <!-- Test History -->
    <div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:20px;">
        <h3 style="margin:0 0 16px;color:#1a6e8c;font-size:14px;border-bottom:2px solid #1a6e8c;padding-bottom:8px;">Test History (<?php echo count($tests); ?> record<?php echo count($tests)!==1?'s':''; ?>)</h3>
        <?php if ( empty($tests) ) : ?>
            <p style="color:#888;font-size:13px;">No tests assigned to this patient yet.</p>
        <?php else : ?>
        <table class="wp-list-table widefat fixed striped" style="font-size:13px;">
            <thead><tr>
                <th style="width:100px">Order</th>
                <th>Test</th>
                <th style="width:100px">Status</th>
                <th style="width:160px">Assigned</th>
            </tr></thead>
            <tbody>
            <?php foreach ( $tests as $t ) :
                $status_colors = array(
                    'pending'  => array('#856404','#fff3cd'),
                    'complete' => array('#2e7d4f','#d4edda'),
                    'rejected' => array('#842029','#f8d7da'),
                );
                list($fc,$bg) = $status_colors[$t->status] ?? array('#333','#eee');
            ?>
                <tr>
                    <td><a href="<?php echo get_edit_post_link($t->order_id); ?>">#<?php echo esc_html($t->order_id); ?></a></td>
                    <td><?php echo esc_html($t->test_name); ?></td>
                    <td><span style="padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;background:<?php echo $bg;?>;color:<?php echo $fc;?>"><?php echo esc_html(ucfirst($t->status)); ?></span></td>
                    <td style="font-size:12px;color:#666;"><?php echo esc_html($t->assigned_at); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<!-- Edit patient modal -->
<div id="rh-edit-patient-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:999999;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:28px 32px;border-radius:8px;width:500px;max-width:90%;box-shadow:0 8px 40px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto;">
        <h3 style="margin:0 0 16px;color:#1a6e8c;">Edit Patient — <?php echo esc_html($patient->patient_uid); ?></h3>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Forename *</label><input type="text" id="ep-forename" value="<?php echo esc_attr($patient->forename); ?>" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"></div>
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Surname *</label><input type="text" id="ep-surname" value="<?php echo esc_attr($patient->surname); ?>" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"></div>
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Date of Birth *</label><input type="text" id="ep-dob" value="<?php echo esc_attr($patient->dob); ?>" placeholder="DD/MM/YYYY" readonly class="rh-flatpickr" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;cursor:pointer;"></div>
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Sex at Birth *</label><select id="ep-sex" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"><option value="male" <?php selected($patient->sex,'male');?>>Male</option><option value="female" <?php selected($patient->sex,'female');?>>Female</option></select></div>
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Email</label><input type="email" id="ep-email" value="<?php echo esc_attr($patient->email); ?>" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"></div>
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Phone</label><input type="text" id="ep-phone" value="<?php echo esc_attr($patient->phone); ?>" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"></div>
        </div>
        <div style="margin-bottom:16px;"><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Notes</label><textarea id="ep-notes" rows="3" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"><?php echo esc_textarea($patient->notes); ?></textarea></div>
        <button type="button" class="button button-primary" onclick="rhSavePatient(<?php echo $view_id; ?>)">Save Changes</button>
        <button type="button" class="button" onclick="document.getElementById('rh-edit-patient-modal').style.display='none'" style="margin-left:8px;">Cancel</button>
        <span id="rh-ep-feedback" style="display:none;margin-left:10px;font-size:13px;color:#2e7d4f;"></span>
    </div>
</div>

<?php else : // ── PATIENT LIST VIEW ───────────────────────────────────────────────────

$result   = Repose_Patient_Registry::list_patients( $page_num, $per_page, $search );
$patients = $result['rows'];
$total    = $result['total'];
$total_pages = max( 1, ceil( $total / $per_page ) );
$base_url = admin_url( 'admin.php?page=repose-patient-registry' );
?>
<div class="wrap repose-admin">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:4px;">
        <h1 style="margin:0;">Patient Registry</h1>
        <button type="button" class="button button-primary" onclick="rhOpenNewPatient()">+ Register New Patient</button>
    </div>
    <p style="color:#666;font-size:13px;margin-top:6px;">Central patient database. Each patient has a unique ID (RHP-XXXXX) for tracking across all orders and tests.</p>

    <div id="rh-registry-notice" style="display:none;padding:10px 14px;margin:10px 0;border-radius:4px;font-size:13px;"></div>

    <!-- Search -->
    <form method="GET" style="margin-bottom:16px;display:flex;gap:8px;align-items:center;">
        <input type="hidden" name="page" value="repose-patient-registry">
        <input type="text" name="rh_search" value="<?php echo esc_attr($search); ?>"
               placeholder="Search by name, email or Patient ID..."
               style="padding:7px 12px;width:320px;border:1px solid #ccc;border-radius:4px;font-size:13px;">
        <button type="submit" class="button">Search</button>
        <?php if ($search) : ?>
        <a href="<?php echo esc_url($base_url); ?>" class="button">Clear</a>
        <?php endif; ?>
        <span style="margin-left:auto;font-size:13px;color:#666;"><?php echo $total; ?> patient<?php echo $total!==1?'s':'';?></span>
    </form>

    <?php if ( empty($patients) ) : ?>
        <div style="padding:24px;background:#f0f7fb;border:1px dashed #b3d4e0;border-radius:6px;text-align:center;color:#555;">
            <?php echo $search ? 'No patients found matching your search.' : 'No patients registered yet. They will appear here automatically when orders are placed, or you can register them manually.'; ?>
        </div>
    <?php else : ?>
    <table class="wp-list-table widefat fixed striped">
        <thead><tr>
            <th style="width:110px">Patient UID</th>
            <th style="width:180px">Name</th>
            <th style="width:90px">DOB</th>
            <th style="width:70px">Sex</th>
            <th>Email</th>
            <th style="width:80px">Tests</th>
            <th style="width:150px">Last Activity</th>
            <th style="width:100px">Actions</th>
        </tr></thead>
        <tbody>
        <?php foreach ( $patients as $p ) :
            $profile_url = admin_url( 'admin.php?page=repose-patient-registry&patient_id=' . $p->id );
        ?>
            <tr>
                <td><a href="<?php echo esc_url($profile_url); ?>" style="font-weight:700;color:#1a6e8c;"><?php echo esc_html($p->patient_uid); ?></a></td>
                <td><a href="<?php echo esc_url($profile_url); ?>"><?php echo esc_html(trim($p->forename.' '.$p->surname)); ?></a></td>
                <td style="font-size:12px;"><?php echo esc_html($p->dob); ?></td>
                <td><?php echo esc_html(ucfirst($p->sex)); ?></td>
                <td style="font-size:12px;"><?php echo esc_html($p->email ?: '—'); ?></td>
                <td style="text-align:center;">
                    <span style="display:inline-block;background:#e8f4f8;color:#1a6e8c;border-radius:10px;padding:1px 9px;font-size:12px;font-weight:600;"><?php echo (int)$p->test_count; ?></span>
                </td>
                <td style="font-size:12px;color:#666;"><?php echo $p->last_test_at ? esc_html(date('d M Y', strtotime($p->last_test_at))) : '—'; ?></td>
                <td>
                    <a href="<?php echo esc_url($profile_url); ?>" class="button button-small">View</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ( $total_pages > 1 ) : ?>
    <div style="margin-top:16px;">
        <?php for ( $i = 1; $i <= $total_pages; $i++ ) :
            $active = $i === $page_num ? 'button-primary' : '';
            $url = add_query_arg( array( 'paged' => $i, 'rh_search' => $search ), $base_url );
            echo "<a href='".esc_url($url)."' class='button {$active}' style='margin-right:4px'>{$i}</a>";
        endfor; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>
</div>

<!-- New Patient Modal -->
<div id="rh-new-patient-modal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:10;">
    <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);background:#fff;padding:28px 32px;border-radius:8px;width:520px;max-width:90%;box-shadow:0 8px 40px rgba(0,0,0,.3);max-height:90vh;overflow-y:auto;">
        <h3 style="margin:0 0 6px;color:#1a6e8c;">Register New Patient</h3>
        <p style="margin:0 0 16px;font-size:12px;color:#666;">A unique Patient UID (RHP-XXXXX) will be assigned automatically.</p>

        <!-- Customer email lookup -->
        <div style="margin-bottom:16px;padding:12px 14px;background:#f0f7fb;border:1px solid #b3d4e0;border-radius:6px;">
            <label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;color:#1a6e8c;">🔍 Look up existing WooCommerce customer by email</label>
            <div style="display:flex;gap:8px;">
                <input type="email" id="np-lookup-email" placeholder="customer@email.com"
                       style="flex:1;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;">
                <button type="button" class="button" onclick="rhLookupCustomer()">Look Up</button>
            </div>
            <div id="np-lookup-result" style="margin-top:8px;font-size:12px;color:#555;"></div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Forename *</label><input type="text" id="np-forename" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"></div>
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Surname *</label><input type="text" id="np-surname" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"></div>
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Date of Birth * (DD/MM/YYYY)</label><input type="text" id="np-dob" placeholder="DD/MM/YYYY" class="rh-flatpickr" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;cursor:pointer;"></div>
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Sex at Birth *</label><select id="np-sex" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"><option value="">Please select</option><option value="male">Male</option><option value="female">Female</option></select></div>
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Email</label><input type="email" id="np-email" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"></div>
            <div><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Phone</label><input type="text" id="np-phone" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"></div>
        </div>
        <div style="margin-bottom:16px;"><label style="display:block;font-size:12px;font-weight:600;margin-bottom:4px;">Notes</label><textarea id="np-notes" rows="2" style="width:100%;padding:7px 9px;border:1px solid #ccc;border-radius:4px;font-size:13px;box-sizing:border-box;"></textarea></div>
        <input type="hidden" id="np-wc-user-id" value="">
        <button type="button" class="button button-primary" onclick="rhSaveNewPatient()">Register Patient</button>
        <button type="button" class="button" onclick="document.getElementById('rh-new-patient-modal').style.display='none'" style="margin-left:8px;">Cancel</button>
        <span id="rh-np-feedback" style="display:none;margin-left:10px;font-size:13px;color:#2e7d4f;"></span>
    </div>
</div>

<?php endif; // end single/list ?>

<script>
    // initialize flatpickr for DOB fields
    // document.querySelectorAll('.rh-flatpickr').forEach(function(el){
    //     if (typeof flatpickr !== 'undefined') {
    //         flatpickr(el, {dateFormat:'d/m/Y',maxDate:'today'});
    //     }
    // });
    //
var rhRegAjax = <?php echo json_encode(['url'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('repose_admin_nonce'),'base'=>admin_url('admin.php?page=repose-patient-registry')]); ?>;

function rhRegNotice(msg, isError) {
    var el = document.getElementById('rh-registry-notice');
    if (!el) return;
    el.textContent = msg;
    el.style.background = isError ? '#f8d7da' : '#d4edda';
    el.style.color = isError ? '#721c24' : '#155724';
    el.style.border = '1px solid ' + (isError ? '#f5c6cb' : '#c3e6cb');
    el.style.display = 'block';
    setTimeout(function(){ el.style.display='none'; }, 5000);
}

function rhPost(data, cb) {
    data.nonce = rhRegAjax.nonce;
    var body = Object.keys(data).map(function(k){ return encodeURIComponent(k)+'='+encodeURIComponent(data[k]); }).join('&');
    fetch(rhRegAjax.url, {method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:body})
        .then(function(r){ return r.json(); }).then(cb).catch(function(){ rhRegNotice('Network error', true); });
}

function rhOpenNewPatient() {
    ['np-forename','np-surname','np-dob','np-email','np-phone','np-notes'].forEach(function(id){ var el=document.getElementById(id); if(el) el.value=''; });
    document.getElementById('np-sex').value = '';
    document.getElementById('np-wc-user-id').value = '';
    document.getElementById('np-lookup-result').textContent = '';
    document.getElementById('rh-np-feedback').style.display='none';
    document.getElementById('rh-new-patient-modal').style.display='block';
}

function rhLookupCustomer() {
    var email = document.getElementById('np-lookup-email').value.trim();
    if (!email) return;
    var res = document.getElementById('np-lookup-result');
    res.textContent = 'Searching...';
    rhPost({action:'repose_lookup_customer_email', email:email}, function(r) {
        if (!r || !r.success) { res.style.color='#c0392b'; res.textContent='Search failed.'; return; }
        var u = r.data.user;
        var patients = r.data.patients || [];

        if (u) {
            document.getElementById('np-forename').value = u.first_name || '';
            document.getElementById('np-surname').value  = u.last_name  || '';
            document.getElementById('np-email').value    = u.email      || email;
            document.getElementById('np-wc-user-id').value = u.id || '';
        } else {
            document.getElementById('np-email').value = email;
        }

        res.style.color = '#2e7d4f';
        if (patients.length > 0) {
            var p = patients[0];
            document.getElementById('np-forename').value = p.forename || document.getElementById('np-forename').value;
            document.getElementById('np-surname').value  = p.surname  || document.getElementById('np-surname').value;
            document.getElementById('np-dob').value      = p.dob      || '';
            document.getElementById('np-sex').value      = p.sex      || '';
            document.getElementById('np-phone').value    = p.phone    || document.getElementById('np-phone').value;
            res.textContent = '\u2713 Found '+(u&&u.id>0?'registered customer':'guest order')+' + '+ patients.length +' patient record(s) in registry (UID: '+p.patient_uid+'). Fields pre-filled.';
            if (patients.length > 1) {
                res.textContent += ' Note: '+patients.length+' patients share this email — first patient shown.';
            }
        } else if (u) {
            res.textContent = '\u2713 '+(u.id>0?'Registered customer':'Guest order')+' found: ' + (u.first_name||'') + ' ' + (u.last_name||'') + '. No registry patients yet.';
        } else {
            res.style.color = '#856404';
            res.textContent = 'No records found for this email. Fill in manually.';
        }
    });
}

function rhSaveNewPatient() {
    var forename = document.getElementById('np-forename').value.trim();
    var surname  = document.getElementById('np-surname').value.trim();
    var dob      = document.getElementById('np-dob').value.trim();
    var sex      = document.getElementById('np-sex').value;
    if (!forename||!surname||!dob||!sex) { alert('Forename, surname, date of birth and sex are required.'); return; }
    var btn = event.target; btn.disabled=true; btn.textContent='Saving...';
    rhPost({
        action:'repose_create_patient',
        forename: forename, surname: surname, dob: dob, sex: sex,
        email: document.getElementById('np-email').value,
        phone: document.getElementById('np-phone').value,
        notes: document.getElementById('np-notes').value,
        wc_user_id: document.getElementById('np-wc-user-id').value,
    }, function(r) {
        btn.disabled=false; btn.textContent='Register Patient';
        if (r && r.success) {
            var fb = document.getElementById('rh-np-feedback');
            fb.textContent = '\u2713 Patient registered: ' + r.data.uid;
            fb.style.display = 'inline';
            setTimeout(function(){ window.location.href = rhRegAjax.base + '&patient_id=' + r.data.id; }, 1000);
        } else {
            alert((r&&r.data) ? r.data : 'Failed to register patient.');
        }
    });
}

function rhOpenEditPatient(id) { document.getElementById('rh-edit-patient-modal').style.display='block'; }

function rhSavePatient(id) {
    var btn = event.target; btn.disabled=true; btn.textContent='Saving...';
    rhPost({
        action:'repose_update_patient', patient_id:id,
        forename: document.getElementById('ep-forename').value,
        surname:  document.getElementById('ep-surname').value,
        dob:      document.getElementById('ep-dob').value,
        sex:      document.getElementById('ep-sex').value,
        email:    document.getElementById('ep-email').value,
        phone:    document.getElementById('ep-phone').value,
        notes:    document.getElementById('ep-notes').value,
    }, function(r) {
        btn.disabled=false; btn.textContent='Save Changes';
        if (r && r.success) {
            var fb = document.getElementById('rh-ep-feedback');
            fb.textContent = '\u2713 Saved'; fb.style.display='inline';
            setTimeout(function(){ location.reload(); }, 1000);
        } else { alert((r&&r.data)?r.data:'Save failed.'); }
    });
}

function rhAssignTest(patientId) {
    var order = document.getElementById('rh-assign-order').value.trim();
    var test  = document.getElementById('rh-assign-test').value.trim();
    if (!order||!test) { alert('Order ID and test name are required.'); return; }
    var btn = event.target; btn.disabled=true; btn.textContent='Assigning...';
    rhPost({action:'repose_assign_patient_test', patient_id:patientId, order_id:order, test_name:test}, function(r) {
        btn.disabled=false; btn.textContent='Assign Test';
        if (r && r.success) {
            var fb = document.getElementById('rh-assign-feedback');
            fb.textContent = '\u2713 Test assigned'; fb.style.display='inline';
            setTimeout(function(){ location.reload(); }, 1000);
        } else { alert((r&&r.data)?r.data:'Failed.'); }
    });
}

[document.getElementById('rh-new-patient-modal'), document.getElementById('rh-edit-patient-modal')].forEach(function(m){
    if(m) m.addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
});
</script>
