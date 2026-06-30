/**
 * Repose Healthcare — Block Checkout Patient Fields v3.2.0
 *
 * Changes in v3.2.1:
 *  - Server cart sync never removes line items; products stay in cart if unassigned.
 * Changes in v3.2.0:
 *  - New patients default to one assigned test (first cart line); each patient
 *    must keep at least one test (cannot uncheck the last).
 *  - Cart line quantity tracks patients with that product; unassigned lines are not removed.
 *  - Block checkout: refresh cart UI via wc-blocks_added_to_cart after qty sync.
 * Changes in v3.1.0:
 *  - Checkbox click fix: removed conflicting onclick/readOnly pattern.
 *    Native label[for] + onChange handles toggle reliably everywhere.
 *  - Cart quantity sync: when a test is checked for an additional patient,
 *    the WooCommerce cart item quantity is incremented (1 per patient who
 *    has that test assigned). Unchecking decrements it back.
 *
 * Injection strategy — tries three methods in order:
 *  Method A: wc.blocksCheckout.ExperimentalOrderMeta  (WC 7-8)
 *  Method B: wc.blocksCheckout.OrderMeta              (WC 9+)
 *  Method C: Direct DOM injection (universal fallback / classic checkout)
 */
( function () {
    'use strict';

    if ( typeof wp === 'undefined' || ! wp.element || ! wp.plugins ) {
        document.addEventListener( 'DOMContentLoaded', tryDOMInjection );
        return;
    }

    var el             = wp.element.createElement;
    var useState       = wp.element.useState;
    var useEffect      = wp.element.useEffect;
    var useRef         = wp.element.useRef;
    var registerPlugin = wp.plugins.registerPlugin;

    var MAX_PATIENTS = 5;
    var cartItems    = ( typeof reposeCheckout !== 'undefined' && reposeCheckout.cartItems ) ? reposeCheckout.cartItems : [];

    // ── Debounce helper ───────────────────────────────────────────────────
    function debounce( fn, ms ) {
        var t;
        return function() {
            clearTimeout( t );
            var a = arguments, c = this;
            t = setTimeout( function() { fn.apply( c, a ); }, ms );
        };
    }

    // ── Save patient session via AJAX ─────────────────────────────────────
    var saveToSession = debounce( function( fields ) {
        if ( typeof reposeCheckout === 'undefined' ) return;
        var data = new URLSearchParams();
        data.append( 'action', 'repose_save_patient_session' );
        data.append( 'nonce',  reposeCheckout.nonce );
        Object.keys( fields ).forEach( function( k ) {
            var v = fields[k];
            data.append( k, Array.isArray(v) ? JSON.stringify(v) : v );
        });
        fetch( reposeCheckout.ajaxUrl, {
            method      : 'POST',
            credentials : 'same-origin',
            headers     : { 'Content-Type': 'application/x-www-form-urlencoded' },
            body        : data.toString(),
        } ).catch( function() {} );
    }, 600 );

    // ── After cart qty AJAX: refresh checkout UI (Blocks + classic) ──────
    function refreshCheckoutCartAfterQtySync() {
        if ( typeof reposeCheckout === 'undefined' ) return;
        if ( reposeCheckout.isBlockCheckout === '1' && typeof wc !== 'undefined' && wc.blocksCheckout ) {
            try {
                var d = wp.data && wp.data.dispatch;
                if ( d ) {
                    var cartD = d( 'wc/store/cart' );
                    if ( cartD && typeof cartD.invalidateResolutionForStore === 'function' ) {
                        cartD.invalidateResolutionForStore();
                    }
                }
            } catch ( err ) {}
            document.body.dispatchEvent(
                new CustomEvent( 'wc-blocks_added_to_cart', {
                    bubbles: true,
                    detail: { preserveCartData: false },
                } )
            );
        } else if ( typeof jQuery !== 'undefined' ) {
            jQuery( document.body ).trigger( 'wc_update_cart' );
            jQuery( document.body ).trigger( 'update_checkout' );
        }
    }

    // ── Cart quantity sync ────────────────────────────────────────────────
    // Builds { product_id: patient_count } for each cart line; PHP adjusts qty (never removes).
    var syncCartQuantities = debounce( function( patients ) {
        if ( typeof reposeCheckout === 'undefined' ) return;

        var counts = {};
        cartItems.forEach( function(item) { counts[ String( item.product_id ) ] = 0; } );
        patients.forEach( function(p) {
            (p.tests || []).forEach( function(pid) {
                var k = String( pid );
                if ( Object.prototype.hasOwnProperty.call( counts, k ) ) counts[k]++;
            });
        });

        var data = new URLSearchParams();
        data.append( 'action',      'repose_update_cart_qty' );
        data.append( 'nonce',       reposeCheckout.nonce );
        data.append( 'assignments', JSON.stringify(counts) );

        fetch( reposeCheckout.ajaxUrl, {
            method      : 'POST',
            credentials : 'same-origin',
            headers     : { 'Content-Type': 'application/x-www-form-urlencoded' },
            body        : data.toString(),
        } )
        .then( function(r){ return r.json(); } )
        .then( function(resp) {
            if ( resp.success ) {
                refreshCheckoutCartAfterQtySync();
            }
        } )
        .catch( function() {} );
    }, 400 );

    // ── Styles ────────────────────────────────────────────────────────────
    var S = {
        sectionWrap : {
            margin: '32px 0 0',
            fontFamily: "'Nunito', 'Segoe UI', system-ui, sans-serif",
        },
        sectionHeader : {
            display: 'flex', alignItems: 'center',
            gap: '14px', marginBottom: '20px',
            paddingBottom: '16px', borderBottom: '2px solid #e8f0fe',
        },
        sectionIcon : {
            width: '44px', height: '44px',
            background: 'linear-gradient(135deg, #1a6e8c 0%, #0d4a61 100%)',
            borderRadius: '12px',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            flexShrink: 0, fontSize: '22px',
            boxShadow: '0 4px 14px rgba(26,110,140,0.3)',
        },
        sectionTitle : {
            margin: '0', fontSize: '18px', fontWeight: '800',
            color: '#0d1b2a', letterSpacing: '-0.3px',
        },
        sectionSubtitle : {
            margin: '2px 0 0', fontSize: '13px',
            color: '#64748b', fontWeight: '400',
        },
        wrap : {
            margin: '0 0 16px', padding: '22px 24px',
            background: '#ffffff', border: '1.5px solid #e2e8f0',
            borderRadius: '14px',
            boxShadow: '0 2px 16px rgba(26,110,140,0.07)',
            fontFamily: 'inherit',
        },
        deliveryWrap : {
            margin: '0 0 4px', padding: '16px 20px',
            background: 'linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%)',
            border: '1.5px solid #a7f3d0', borderRadius: '14px',
            display: 'flex', alignItems: 'flex-start', gap: '14px',
            fontFamily: 'inherit',
        },
        deliveryIconBox : {
            width: '36px', height: '36px',
            background: 'linear-gradient(135deg, #059669, #047857)',
            borderRadius: '10px',
            display: 'flex', alignItems: 'center', justifyContent: 'center',
            flexShrink: 0, fontSize: '17px',
            boxShadow: '0 3px 10px rgba(5,150,105,0.3)',
        },
        deliveryContent : { flex: 1 },
        deliveryHeading : { margin: '0 0 4px', fontSize: '14px', fontWeight: '700', color: '#047857' },
        deliveryText    : { margin: '3px 0 0', fontSize: '13px', color: '#374151', lineHeight: '1.5' },
        patientBlock : {
            marginBottom: '14px', padding: '18px 20px',
            background: '#fafbff', border: '1.5px solid #e8edf5',
            borderRadius: '12px', boxShadow: '0 1px 6px rgba(0,0,0,0.03)',
        },
        patientBlockHeader : {
            display: 'flex', alignItems: 'center',
            justifyContent: 'space-between',
            marginBottom: '16px', paddingBottom: '12px',
            borderBottom: '1px dashed #e2e8f0',
        },
        patientBadge : {
            display: 'inline-flex', alignItems: 'center', gap: '7px',
            padding: '5px 13px',
            background: 'linear-gradient(135deg, #eff8ff, #dbeafe)',
            borderRadius: '20px', fontSize: '13px', fontWeight: '700',
            color: '#1a6e8c', border: '1px solid #bfdbfe',
        },
        row      : { marginBottom: '14px' },
        gridRow  : {
            display: 'grid', gridTemplateColumns: '1fr 1fr',
            gap: '12px', marginBottom: '14px',
        },
        label : {
            display: 'block', marginBottom: '6px',
            fontWeight: '600', fontSize: '13px',
            color: '#374151', letterSpacing: '0.01em',
        },
        star     : { color:'#ef4444', marginLeft:'3px', fontSize:'10px', verticalAlign:'super' },
        input    : {
            display: 'block', width: '100%',
            padding: '10px 13px', fontSize: '14px',
            border: '1.5px solid #e2e8f0', borderRadius: '10px',
            boxSizing: 'border-box', backgroundColor: '#f8fafc',
            color: '#1e293b', fontFamily: 'inherit', outline: 'none',
        },
        inputErr : {
            display: 'block', width: '100%',
            padding: '10px 13px', fontSize: '14px',
            border: '1.5px solid #f87171', borderRadius: '10px',
            boxSizing: 'border-box', backgroundColor: '#fff7f7',
            color: '#1e293b', fontFamily: 'inherit', outline: 'none',
        },
        textarea : {
            display: 'block', width: '100%',
            padding: '10px 13px', fontSize: '13px',
            border: '1.5px solid #e2e8f0', borderRadius: '10px',
            boxSizing: 'border-box', backgroundColor: '#f8fafc',
            color: '#1e293b', minHeight: '78px', resize: 'vertical',
            fontFamily: 'inherit',
        },
        err  : {
            display: 'flex', alignItems: 'center', gap: '4px',
            marginTop: '5px', fontSize: '12px',
            color: '#ef4444', fontWeight: '500',
        },
        hint : {
            display: 'block', marginTop: '5px',
            fontSize: '12px', color: '#94a3b8', lineHeight: '1.4',
        },
        addBtn : {
            display: 'inline-flex', alignItems: 'center', gap: '8px',
            marginTop: '6px', padding: '11px 22px',
            background: 'linear-gradient(135deg, #1a6e8c 0%, #0d4a61 100%)',
            color: '#fff', border: 'none', borderRadius: '10px',
            cursor: 'pointer', fontSize: '14px', fontWeight: '700',
            fontFamily: 'inherit', boxShadow: '0 4px 14px rgba(26,110,140,0.3)',
        },
        removeBtn : {
            display: 'inline-flex', alignItems: 'center', gap: '5px',
            padding: '6px 13px', background: '#fff5f5',
            color: '#ef4444', border: '1px solid #fecaca',
            borderRadius: '8px', cursor: 'pointer',
            fontSize: '12px', fontWeight: '600', fontFamily: 'inherit',
        },
        // ── Checkbox row styles (used as wrapper label) ──────────────────
        // IMPORTANT: pointer-events on the label element itself are set to 'none'
        // so only the real <input> checkbox handles click events — this prevents
        // the double-toggle that occurred when both the label's native for= linkage
        // AND an onclick handler tried to toggle the checkbox simultaneously.
        checkboxRow : {
            display: 'flex', alignItems: 'flex-start', gap: '10px',
            padding: '10px 13px', borderRadius: '10px',
            background: '#f8fafc', border: '1.5px solid #e2e8f0',
            marginBottom: '8px', cursor: 'pointer',
            userSelect: 'none',
            // Let the <input> inside be the sole click target for toggling.
            // The label's native for= still works because we did NOT set
            // pointerEvents:'none' on the label — the input's pointer-events are auto.
        },
        checkboxRowActive : {
            display: 'flex', alignItems: 'flex-start', gap: '10px',
            padding: '10px 13px', borderRadius: '10px',
            background: '#eff8ff', border: '1.5px solid #1a6e8c',
            marginBottom: '8px', cursor: 'pointer',
            userSelect: 'none',
        },
        checkboxLabel : {
            fontSize: '13px', color: '#334155',
            lineHeight: '1.45', flex: '1',
            fontWeight: '500',
            // Explicitly allow pointer-events on the text span so the label's
            // for= linkage fires when the user clicks the text.
            cursor: 'pointer',
            pointerEvents: 'none', // text is inside the label, click bubbles to label→checkbox
        },
        counterPill : {
            marginLeft: '6px', background: 'rgba(255,255,255,0.25)',
            padding: '2px 10px', borderRadius: '12px',
            fontSize: '12px', fontWeight: '400',
        },
        // qty badge shown on each test row when count > 1
        qtyBadge : {
            display: 'inline-block',
            marginLeft: '8px',
            padding: '1px 8px',
            background: '#1a6e8c',
            color: '#fff',
            borderRadius: '10px',
            fontSize: '11px',
            fontWeight: '700',
            verticalAlign: 'middle',
        },
        dupWarning : {
            padding: '10px 14px', marginBottom: '14px',
            background: '#fff3cd', border: '1px solid #ffc107',
            borderRadius: '8px', fontSize: '13px', color: '#856404',
            display: 'flex', alignItems: 'flex-start', gap: '8px',
        },
    };

    function emptyPatient( assignAll ) {
        var tests = assignAll ? cartItems.map(function(t){ return t.product_id; }) : [];
        return { forename:'', surname:'', dob:'', sex:'', notes:'', tests: tests };
    }

    /** First cart product only — satisfies “at least one test” for new patients. */
    function defaultTestsMinimumOne() {
        if ( ! cartItems || cartItems.length === 0 ) return [];
        return [ cartItems[0].product_id ];
    }

    function emptyAdditionalPatient() {
        return {
            forename: '', surname: '', dob: '', sex: '', notes: '',
            tests: defaultTestsMinimumOne(),
        };
    }

    function withPatientKey( patient, pk ) {
        return Object.assign( {}, patient, { _pk: pk } );
    }

    function testsIncludes( tests, productId ) {
        var want = String( productId );
        return (tests || []).some( function(t) { return String(t) === want; } );
    }

    // ── Compute per-product patient counts across all patients ─────────────
    // Returns { product_id: count } (keys match localized cartItems product_id)
    function computeTestCounts( patients ) {
        var counts = {};
        var canon = {};
        cartItems.forEach( function(item) {
            counts[ item.product_id ] = 0;
            canon[ String( item.product_id ) ] = item.product_id;
        } );
        patients.forEach( function(p) {
            (p.tests || []).forEach( function(pid) {
                var k = canon[ String( pid ) ];
                if ( k !== undefined ) counts[k]++;
            });
        });
        return counts;
    }

    // ── Test Assignment checkboxes ─────────────────────────────────────────
    // Props: patient, onChange, patientIdx, testCounts (global across all patients)
    function TestAssignment( props ) {
        var patient    = props.patient;
        var onChange   = props.onChange;
        var patientIdx = props.patientIdx;
        var testCounts = props.testCounts || {};

        if ( ! cartItems || cartItems.length === 0 ) return null;

        function toggleTest( productId ) {
            var current = patient.tests || [];
            var had = testsIncludes( current, productId );
            if ( had ) {
                if ( current.length <= 1 ) return;
                onChange( 'tests', current.filter( function(id) {
                    return String(id) !== String(productId);
                } ) );
            } else {
                onChange( 'tests', current.concat( [ productId ] ) );
            }
        }

        return el( 'div', { style: S.row },
            el( 'label', { style: S.label },
                'Assign Test(s) ',
                el( 'span', { style: { fontWeight:'400', color:'#6b7280', fontSize:'13px' } },
                    '— select which tests apply to this patient'
                )
            ),
            cartItems.map( function(item) {
                var checked = testsIncludes( patient.tests, item.product_id );
                // Unique id per patient-per-product so label[for] is unambiguous
                var inputId = 'rh-test-p' + patientIdx + '-' + item.product_id;
                var totalForThisTest = testCounts[ item.product_id ] || 0;

                return el( 'label', {
                    key    : item.product_id,
                    htmlFor: inputId,
                    // ── CHECKBOX FIX ──────────────────────────────────────
                    // We use native label[for] to drive the checkbox.
                    // No onClick on the label — that caused double-toggle.
                    // The onChange on the <input> is the single source of truth.
                    style  : checked ? S.checkboxRowActive : S.checkboxRow,
                },
                    el( 'input', {
                        id      : inputId,
                        type    : 'checkbox',
                        checked : checked,
                        style   : {
                            marginTop: '2px',
                            accentColor: '#1a6e8c',
                            cursor: 'pointer',
                            flexShrink: '0',
                            width: '18px',
                            height: '18px',
                            // Ensure the checkbox itself is always clickable
                            pointerEvents: 'auto',
                        },
                        // onChange is the only toggle handler — no onClick, no readOnly
                        onChange: function() { toggleTest( item.product_id ); },
                    }),
                    el( 'span', { style: S.checkboxLabel },
                        item.name,
                        // Show global cart qty badge when multiple patients share this test
                        totalForThisTest > 1
                            ? el( 'span', { style: S.qtyBadge }, 'Qty: ' + totalForThisTest )
                            : null
                    )
                );
            })
        );
    }

    // ── Single Patient Form ────────────────────────────────────────────────
    function PatientForm( props ) {
        var idx        = props.idx;
        var patient    = props.patient;
        var onChange   = props.onChange;
        var onRemove   = props.onRemove;
        var touched    = props.touched;
        var onTouch    = props.onTouch;
        var testCounts = props.testCounts;
        var isFirst    = idx === 0;
        var label      = isFirst ? 'Patient 1' : 'Patient ' + ( idx + 1 );

        function iStyle( err ) { return err ? S.inputErr : S.input; }

        function fieldRow( key, labelText, required, renderInput ) {
            var val     = patient[key] || '';
            var showErr = required && touched[key] && ! val.trim();
            return el( 'div', { style: S.row },
                el( 'label', { style: S.label },
                    labelText,
                    required ? el( 'span', { style: S.star }, ' *' ) : null
                ),
                renderInput( showErr, val ),
                showErr ? el( 'span', { style: S.err }, labelText + ' is required.' ) : null
            );
        }

        return el( 'div', { style: S.patientBlock },

            el( 'div', { style: S.patientBlockHeader },
                el( 'div', { style: S.patientBadge },
                    el( 'span', null, '👤' ),
                    el( 'span', null, label )
                ),
                ! isFirst
                    ? el( 'button', { type:'button', style: S.removeBtn, onClick: onRemove },
                        el('span', null, '✕'), ' Remove'
                      )
                    : null
            ),

            fieldRow( 'forename', 'First Name', true, function( err, val ) {
                return el( 'input', {
                    type:'text', value:val, placeholder:'First name',
                    style: iStyle(err), autoComplete:'given-name',
                    onChange : function(e){ onChange('forename', e.target.value); },
                    onBlur   : function(){ onTouch('forename'); },
                });
            }),

            fieldRow( 'surname', 'Last Name', true, function( err, val ) {
                return el( 'input', {
                    type:'text', value:val, placeholder:'Last name',
                    style: iStyle(err), autoComplete:'family-name',
                    onChange : function(e){ onChange('surname', e.target.value); },
                    onBlur   : function(){ onTouch('surname'); },
                });
            }),

            fieldRow( 'dob', 'Date of Birth', true, function( err, val ) {
                return el( 'input', {
                    type         : 'text',
                    defaultValue : val,
                    placeholder  : 'DD/MM/YYYY  e.g. 15/06/1985',
                    readOnly     : true,
                    style        : Object.assign( {}, iStyle(err), { letterSpacing:'1.5px', cursor:'pointer' } ),
                    ref          : function(node) {
                        if ( ! node ) return;
                        if ( node._fp ) {
                            if ( val && node._fp.input.value !== val ) {
                                node._fp.setDate( val, false, 'd/m/Y' );
                            }
                            return;
                        }
                        if ( typeof flatpickr === 'undefined' ) return;
                        node._fp = flatpickr( node, {
                            dateFormat   : 'd/m/Y',
                            allowInput   : true,
                            disableMobile: true,
                            defaultDate  : val || null,
                            onChange     : function( selectedDates, dateStr ) {
                                onChange( 'dob', dateStr );
                                onTouch( 'dob' );
                            },
                        });
                    },
                });
            }),

            fieldRow( 'sex', 'Sex at Birth', true, function( err, val ) {
                return el( 'select', {
                    value: val, style: iStyle(err),
                    onChange : function(e){ onChange('sex', e.target.value); onTouch('sex'); },
                    onBlur   : function(){ onTouch('sex'); },
                },
                    el( 'option', { value:'' },       'Please select…' ),
                    el( 'option', { value:'male' },   'Male' ),
                    el( 'option', { value:'female' }, 'Female' )
                );
            }),

            el( TestAssignment, {
                patient    : patient,
                onChange   : onChange,
                patientIdx : idx,
                testCounts : testCounts,
            }),

            el( 'div', { style: S.row },
                el( 'label', { style: S.label },
                    'Additional Notes ',
                    el('span', { style: { fontWeight:'400', color:'#6b7280', fontSize:'13px' } }, '(optional)')
                ),
                el( 'textarea', {
                    value      : patient.notes || '',
                    placeholder: 'Optional — e.g. symptoms or relevant medical information',
                    style      : S.textarea,
                    onChange   : function(e){ onChange('notes', e.target.value); },
                }),
                el( 'span', { style: S.hint }, 'Include any symptoms or relevant information for accurate test processing.' )
            )
        );
    }

    // ── Delivery & Tracking ────────────────────────────────────────────────
    function DeliveryInfo() {
        return el( 'div', { style: S.deliveryWrap },
            el( 'div', { style: S.deliveryIconBox }, '📦' ),
            el( 'div', { style: S.deliveryContent },
                el( 'h4', { style: S.deliveryHeading }, 'Delivery & Tracking' ),
                el( 'p', { style: { margin:'0', fontSize:'13px', color:'#374151', lineHeight:'1.5' } },
                    'Your test kit will be dispatched to the address provided above.'
                ),
                el( 'p', { style: S.deliveryText }, '📬 Tracking details will be sent once your kit is dispatched.' )
            )
        );
    }

    // ── Main PatientFields component ───────────────────────────────────────
    function PatientFields() {
        var nextPkRef   = useRef( 1 );
        var pS          = useState( [ withPatientKey( emptyPatient(true), 0 ) ] );
        var patients    = pS[0];
        var setPatients = pS[1];

        var tS            = useState( [ {} ] );
        var touchedArr    = tS[0];
        var setTouchedArr = tS[1];

        // Compute live test counts across all patients (drives qty badges + cart sync)
        var testCounts = computeTestCounts( patients );

        // Sync patient session + cart quantities whenever patients state changes
        useEffect( function() {
            // 1. Save session fields
            var fields = { repose_patient_count: patients.length };
            patients.forEach( function( p, i ) {
                var sfx = i === 0 ? '' : '_' + (i+1);
                fields[ 'repose_patient_forename' + sfx ] = p.forename;
                fields[ 'repose_patient_surname'  + sfx ] = p.surname;
                fields[ 'repose_sex_at_birth'     + sfx ] = p.sex;
                fields[ 'repose_date_of_birth'    + sfx ] = p.dob;
                fields[ 'repose_additional_notes' + sfx ] = p.notes;
                fields[ 'repose_patient_tests'    + sfx ] = p.tests || [];
            });
            saveToSession( fields );

            // 2. Sync cart quantities to match patient test assignments
            syncCartQuantities( patients );
        }, [ patients ] );

        function updatePatient( idx, key, val ) {
            setPatients( function(prev) {
                return prev.map( function(p,i) {
                    if ( i !== idx ) return p;
                    var u = Object.assign({}, p);
                    u[key] = val;
                    return u;
                });
            });
        }

        function touchField( idx, key ) {
            setTouchedArr( function(prev) {
                return prev.map( function(t,i) {
                    if ( i !== idx ) return t;
                    var u = Object.assign({}, t);
                    u[key] = true;
                    return u;
                });
            });
        }

        function addPatient() {
            if ( patients.length >= MAX_PATIENTS ) return;
            var pk = nextPkRef.current++;
            setPatients(   function(p){
                return p.concat( [ withPatientKey( emptyAdditionalPatient(), pk ) ] );
            });
            setTouchedArr( function(t){ return t.concat([ {} ]); });
        }

        function removePatient( idx ) {
            setPatients(   function(p){ return p.filter(function(_,i){ return i!==idx; }); });
            setTouchedArr( function(t){ return t.filter(function(_,i){ return i!==idx; }); });
        }

        // Duplicate test warning: any product assigned to 2+ patients
        var dupNames = cartItems
            .filter( function(item){ return (testCounts[ item.product_id ] || 0) > 1; } )
            .map( function(item){ return item.name; } );

        var canAdd = patients.length < MAX_PATIENTS;

        return el( 'div', { style: S.sectionWrap },

            el( 'div', { style: S.sectionHeader },
                el( 'div', { style: S.sectionIcon }, '🧑‍⚕️' ),
                el( 'div', null,
                    el( 'h3', { style: S.sectionTitle }, 'Patient Information' ),
                    el( 'p',  { style: S.sectionSubtitle }, 'Please provide details for each patient being tested' )
                )
            ),

            el( 'div', { style: S.wrap },

                // Duplicate-test info banner (informational — not a blocker)
                dupNames.length > 0
                    ? el( 'div', { style: S.dupWarning },
                          el( 'span', { style:{ fontSize:'17px', flexShrink:'0' } }, '⚠️' ),
                          el( 'span', null,
                              el( 'strong', null, 'Same test assigned to multiple patients: ' ),
                              '"' + dupNames.join('", "') + '" — ' +
                              'the cart quantity has been updated to reflect all patients. ' +
                              'Each patient\'s results will be reported separately.'
                          )
                      )
                    : null,

                patients.map( function(patient, idx) {
                    return el( PatientForm, {
                        key        : patient._pk,
                        idx        : idx,
                        patient    : patient,
                        touched    : touchedArr[idx] || {},
                        testCounts : testCounts,
                        onChange   : function(key,val){ updatePatient(idx,key,val); },
                        onTouch    : function(key){ touchField(idx,key); },
                        onRemove   : idx > 0 ? function(){ removePatient(idx); } : null,
                    });
                }),

                canAdd
                    ? el( 'button', {
                          type:'button', style: S.addBtn,
                          onClick: addPatient,
                          title: 'Add another patient (up to ' + MAX_PATIENTS + ')',
                      },
                          '＋ Add Another Patient',
                          el( 'span', { style: S.counterPill }, patients.length + ' / ' + MAX_PATIENTS )
                      )
                    : el( 'p', {
                          style:{ margin:'6px 0 0', fontSize:'13px', color:'#94a3b8', fontStyle:'italic' }
                      }, 'Maximum of ' + MAX_PATIENTS + ' patients per order.' )
            ),

            el( DeliveryInfo )
        );
    }

    // ── Method A & B: WC Blocks plugin API ───────────────────────────────
    function tryBlocksAPI() {
        if ( typeof wc === 'undefined' || ! wc.blocksCheckout ) return false;
        var Slot = wc.blocksCheckout.ExperimentalOrderMeta
                || wc.blocksCheckout.OrderMeta
                || null;
        if ( ! Slot || ! registerPlugin ) return false;
        try {
            registerPlugin( 'repose-patient-fields', {
                render: function() {
                    return el( Slot, { currentPostId: 0 }, el( PatientFields ) );
                },
                scope: 'woocommerce-checkout',
            });
            return true;
        } catch(e) { return false; }
    }

    // ── Method C: Direct DOM injection ───────────────────────────────────
    function tryDOMInjection() {
        var attempts = 0;
        function inject() {
            if ( document.getElementById('rh-patient-fields-dom') ) return;
            var placeholder = document.getElementById('rh-patient-fields-dom-target');
            if ( placeholder ) {
                var container = document.createElement('div');
                container.id  = 'rh-patient-fields-dom';
                container.innerHTML = buildDOMForm();
                placeholder.appendChild( container );
                bindDOMEvents( container );
                return;
            }
            attempts++;
            if ( attempts < 60 ) setTimeout( inject, 500 );
        }
        inject();
    }

    function escHTML(str) {
        return String(str)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    // ── DOM path: compute counts from rendered checkboxes ─────────────────
    function getDOMTestCounts() {
        var counts = {};
        cartItems.forEach( function(item) { counts[ String( item.product_id ) ] = 0; } );
        document.querySelectorAll('.rh-pb').forEach( function(block) {
            block.querySelectorAll('input[type=checkbox]:checked').forEach( function(cb) {
                var pid = String( cb.value );
                if ( Object.prototype.hasOwnProperty.call( counts, pid ) ) counts[pid]++;
            });
        });
        return counts;
    }

    // ── DOM path: build test checkbox HTML ────────────────────────────────
    // FIX: uses plain <label for="…"> with NO onclick — the browser's native
    // label-for-input linkage handles the toggle cleanly without any JS.
    // A change listener on the input updates visuals and syncs the cart.
    function buildTestCheckboxes(n) {
        if ( ! cartItems || cartItems.length === 0 ) return '';
        var html = '<div class="rh-tests-wrap" style="margin-bottom:14px;">' +
            '<label style="display:block;margin-bottom:8px;font-weight:600;font-size:14px;color:#23282d;">' +
            'Assign Test(s) <span style="font-weight:400;color:#6b7280;font-size:13px;">— select which tests apply to this patient</span>' +
            '</label>';
        var firstPid = cartItems[0].product_id;
        cartItems.forEach( function(item) {
            var checked = n === 1 || String( item.product_id ) === String( firstPid );
            var defaultChecked = checked ? ' checked' : '';
            var activeBg     = '#eff8ff', activeBc = '#1a6e8c';
            var inactiveBg   = '#f8fafc', inactiveBc = '#e2e8f0';
            var bg           = checked ? activeBg : inactiveBg;
            var bc           = checked ? activeBc : inactiveBc;
            var cbId         = 'rh-cb-p' + n + '-' + item.product_id;

            // ── CHECKBOX FIX ─────────────────────────────────────────────
            // <label for="cbId"> drives the checkbox via native linkage.
            // No onclick on label or input. onchange listener is added in
            // bindDOMEvents via event delegation — updates visuals + syncs cart.
            html +=
                '<label for="' + cbId + '" id="row-' + cbId + '"' +
                ' class="rh-test-row"' +
                ' style="display:flex;align-items:flex-start;gap:10px;' +
                'padding:10px 13px;border-radius:10px;' +
                'background:' + bg + ';border:1.5px solid ' + bc + ';' +
                'margin-bottom:8px;cursor:pointer;user-select:none;">' +
                '<input type="checkbox"' +
                ' id="' + cbId + '"' +
                ' name="rp_test_' + n + '[]"' +
                ' value="' + item.product_id + '"' +
                defaultChecked +
                ' style="margin-top:2px;accent-color:#1a6e8c;cursor:pointer;flex-shrink:0;width:18px;height:18px;">' +
                '<span style="font-size:13px;color:#334155;line-height:1.45;flex:1;font-weight:500;">' +
                escHTML(item.name) +
                '</span>' +
                '</label>';
        });
        html += '</div>';
        return html;
    }

    // ── DOM path: update row visual after checkbox change ─────────────────
    function rhUpdateTestRow( cb ) {
        var row = document.getElementById( 'row-' + cb.id );
        if ( ! row ) return;
        row.style.background = cb.checked ? '#eff8ff' : '#f8fafc';
        row.style.border     = '1.5px solid ' + (cb.checked ? '#1a6e8c' : '#e2e8f0');
    }

    // ── DOM path: sync cart quantities ────────────────────────────────────
    var domSyncCart = debounce( function() {
        if ( typeof reposeCheckout === 'undefined' ) return;
        var counts = getDOMTestCounts();
        var data   = new URLSearchParams();
        data.append( 'action',      'repose_update_cart_qty' );
        data.append( 'nonce',       reposeCheckout.nonce );
        data.append( 'assignments', JSON.stringify(counts) );
        fetch( reposeCheckout.ajaxUrl, {
            method      : 'POST',
            credentials : 'same-origin',
            headers     : { 'Content-Type': 'application/x-www-form-urlencoded' },
            body        : data.toString(),
        }).then( function(r){ return r.json(); }).then( function(resp) {
            if ( resp.success ) {
                refreshCheckoutCartAfterQtySync();
            }
        }).catch( function(){} );
    }, 400 );

    function buildDOMForm() {
        return '<div style="margin:32px 0 0;font-family:\'Nunito\',\'Segoe UI\',system-ui,sans-serif;">' +
            '<div style="display:flex;align-items:center;gap:14px;margin-bottom:20px;padding-bottom:16px;border-bottom:2px solid #e8f0fe;">' +
                '<div style="width:44px;height:44px;background:linear-gradient(135deg,#1a6e8c,#0d4a61);border-radius:12px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:22px;box-shadow:0 4px 14px rgba(26,110,140,0.3);">🧑‍⚕️</div>' +
                '<div>' +
                    '<h3 style="margin:0;font-size:18px;font-weight:800;color:#0d1b2a;letter-spacing:-0.3px;">Patient Information</h3>' +
                    '<p style="margin:2px 0 0;font-size:13px;color:#64748b;font-weight:400;">Please provide details for each patient being tested</p>' +
                '</div>' +
            '</div>' +
            '<div style="margin:0 0 16px;padding:22px 24px;background:#ffffff;border:1.5px solid #e2e8f0;border-radius:14px;box-shadow:0 2px 16px rgba(26,110,140,0.07);">' +
                '<div id="rh-patients-container">' + buildPatientBlock(1) + '</div>' +
                '<button type="button" id="rh-add-patient" onclick="rhAddPatientDOM()" style="display:inline-flex;align-items:center;gap:8px;margin-top:6px;padding:11px 22px;background:linear-gradient(135deg,#1a6e8c,#0d4a61);color:#fff;border:none;border-radius:10px;cursor:pointer;font-size:14px;font-weight:700;font-family:inherit;box-shadow:0 4px 14px rgba(26,110,140,0.3);">＋ Add Another Patient <span id="rh-patient-counter" style="background:rgba(255,255,255,0.25);padding:2px 10px;border-radius:12px;font-size:12px;font-weight:400;">1 / 5</span></button>' +
            '</div>' +
            '<div style="margin:0 0 4px;padding:16px 20px;background:linear-gradient(135deg,#f0fdf4,#ecfdf5);border:1.5px solid #a7f3d0;border-radius:14px;display:flex;align-items:flex-start;gap:14px;font-family:inherit;">' +
                '<div style="width:36px;height:36px;background:linear-gradient(135deg,#059669,#047857);border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px;box-shadow:0 3px 10px rgba(5,150,105,0.3);">📦</div>' +
                '<div>' +
                    '<h4 style="margin:0 0 4px;font-size:14px;font-weight:700;color:#047857;">Delivery &amp; Tracking</h4>' +
                    '<p style="margin:0;font-size:13px;color:#374151;line-height:1.5;">Your test kit will be dispatched to the address provided above.</p>' +
                    '<p style="margin:3px 0 0;font-size:13px;color:#374151;">📬 Tracking details will be sent once your kit is dispatched.</p>' +
                '</div>' +
            '</div>' +
        '</div>';
    }

    function buildPatientBlock(n) {
        var label     = n === 1 ? 'Patient 1' : 'Patient ' + n;
        var removeBtn = n > 1
            ? '<button type="button" onclick="rhRemovePatientDOM(this)" style="display:inline-flex;align-items:center;gap:5px;padding:6px 13px;background:#fff5f5;color:#ef4444;border:1px solid #fecaca;border-radius:8px;cursor:pointer;font-size:12px;font-weight:600;">✕ Remove</button>'
            : '';
        return '<div class="rh-pb" data-pnum="' + n + '" style="margin-bottom:14px;padding:18px 20px;background:#fafbff;border:1.5px solid #e8edf5;border-radius:12px;box-shadow:0 1px 6px rgba(0,0,0,0.03);">' +
            '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:12px;border-bottom:1px dashed #e2e8f0;">' +
                '<div style="display:inline-flex;align-items:center;gap:7px;padding:5px 13px;background:linear-gradient(135deg,#eff8ff,#dbeafe);border-radius:20px;font-size:13px;font-weight:700;color:#1a6e8c;border:1px solid #bfdbfe;"><span>👤</span><span>' + label + '</span></div>' +
                removeBtn +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">' +
                rhDOMField('First Name',  'rp_forename_'+n, 'text', 'First name',  'given-name',  true) +
                rhDOMField('Last Name',   'rp_surname_'+n,  'text', 'Last name',   'family-name', true) +
            '</div>' +
            '<div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">' +
                '<div><label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151;">Date of Birth <span style="color:#ef4444;font-size:10px;vertical-align:super;">*</span></label>' +
                '<input type="text" id="rp_dob_'+n+'" name="rp_dob_'+n+'" placeholder="DD/MM/YYYY" readonly style="display:block;width:100%;padding:10px 13px;font-size:14px;border:1.5px solid #e2e8f0;border-radius:10px;box-sizing:border-box;letter-spacing:1.5px;font-family:inherit;cursor:pointer;background:#f8fafc;color:#1e293b;"></div>' +
                '<div><label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151;">Sex at Birth <span style="color:#ef4444;font-size:10px;vertical-align:super;">*</span></label>' +
                '<select id="rp_sex_'+n+'" name="rp_sex_'+n+'" style="display:block;width:100%;padding:10px 13px;font-size:14px;border:1.5px solid #e2e8f0;border-radius:10px;box-sizing:border-box;background:#f8fafc;color:#1e293b;font-family:inherit;"><option value="">Please select…</option><option value="male">Male</option><option value="female">Female</option></select></div>' +
            '</div>' +
            buildTestCheckboxes(n) +
            '<div><label style="display:block;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151;">Additional Notes <span style="font-weight:400;color:#94a3b8;font-size:12px;">(optional)</span></label>' +
            '<textarea id="rp_notes_'+n+'" name="rp_notes_'+n+'" rows="2" placeholder="Optional — e.g. symptoms or relevant medical information" style="display:block;width:100%;padding:10px 13px;font-size:13px;border:1.5px solid #e2e8f0;border-radius:10px;box-sizing:border-box;resize:vertical;font-family:inherit;background:#f8fafc;color:#1e293b;"></textarea>' +
            '<span style="display:block;margin-top:5px;font-size:12px;color:#94a3b8;line-height:1.4;">Include any symptoms or relevant information for accurate test processing.</span></div>' +
            '</div>';
    }

    function rhDOMField(lbl, id, type, ph, ac, req) {
        return '<div><label for="'+id+'" style="display:block;margin-bottom:6px;font-weight:600;font-size:13px;color:#374151;">' +
            lbl + (req ? ' <span style="color:#ef4444;font-size:10px;vertical-align:super;">*</span>' : '') + '</label>' +
            '<input type="'+type+'" id="'+id+'" name="'+id+'" placeholder="'+ph+'" autocomplete="'+ac+'" ' +
            'style="display:block;width:100%;padding:10px 13px;font-size:14px;border:1.5px solid #e2e8f0;border-radius:10px;box-sizing:border-box;background:#f8fafc;color:#1e293b;font-family:inherit;"></div>';
    }

    var domPatientCount = 1;

    window.rhAddPatientDOM = function() {
        if ( domPatientCount >= MAX_PATIENTS ) return;
        domPatientCount++;
        var c = document.getElementById('rh-patients-container');
        if ( ! c ) return;
        var div       = document.createElement('div');
        div.innerHTML = buildPatientBlock( domPatientCount );
        c.appendChild( div.firstElementChild );
        rhUpdateDOMCounter();
        setTimeout( function(){ initFlatpickrDOB( c.lastElementChild ); }, 100 );
        // New patient: at least first cart line checked — sync quantities
        domSyncCart();
    };

    window.rhRemovePatientDOM = function(btn) {
        var block = btn.closest('.rh-pb');
        if ( block ) block.remove();
        rhUpdateDOMCounter();
        rhSaveDOM();
        domSyncCart();
    };

    window.rhUpdateDOMCounter = function() {
        var blocks = document.querySelectorAll('.rh-pb');
        domPatientCount = blocks.length;
        var ctr = document.getElementById('rh-patient-counter');
        if ( ctr ) ctr.textContent = domPatientCount + ' / ' + MAX_PATIENTS;
        var btn = document.getElementById('rh-add-patient');
        if ( btn ) btn.style.display = domPatientCount >= MAX_PATIENTS ? 'none' : '';
    };

    function initFlatpickrDOB(scope) {
        var inputs = (scope || document).querySelectorAll('input[id^="rp_dob_"]');
        inputs.forEach( function(inp) {
            if ( inp._flatpickr ) return;
            if ( typeof flatpickr === 'undefined' ) return;
            inp._flatpickr = flatpickr( inp, {
                dateFormat   : 'd/m/Y',
                allowInput   : true,
                disableMobile: true,
                onChange     : function(){ rhSaveDOM(); },
            });
        });
    }

    function bindDOMEvents(container) {
        // Delegate all input/change events
        container.addEventListener( 'input',  function(){ rhSaveDOM(); } );
        container.addEventListener( 'change', function(e) {
            // ── CHECKBOX FIX + CART SYNC ──────────────────────────────────
            // When a test checkbox changes:
            //  1. Update the row's visual (background + border) to reflect state.
            //  2. Save session fields.
            //  3. Sync cart quantities.
            if ( e.target && e.target.type === 'checkbox' &&
                 e.target.name && e.target.name.indexOf('rp_test_') === 0 ) {
                var pb = e.target.closest('.rh-pb');
                if ( pb && ! e.target.checked &&
                     pb.querySelectorAll('input[type=checkbox]:checked').length === 0 ) {
                    e.target.checked = true;
                }
                rhUpdateTestRow( e.target );
                domSyncCart();
            }
            rhSaveDOM();
        });
        setTimeout( function(){ initFlatpickrDOB( container ); }, 100 );
    }

    var rhSaveDOMDebounced = debounce( function() {
        if ( typeof reposeCheckout === 'undefined' ) return;
        var blocks = document.querySelectorAll('.rh-pb');
        var fields = { repose_patient_count: blocks.length };
        blocks.forEach( function(block, i) {
            var sfx = i === 0 ? '' : '_'+(i+1);
            var n   = i + 1;
            fields['repose_patient_forename'+sfx] = (document.getElementById('rp_forename_'+n)||{}).value||'';
            fields['repose_patient_surname' +sfx] = (document.getElementById('rp_surname_' +n)||{}).value||'';
            fields['repose_date_of_birth'   +sfx] = (document.getElementById('rp_dob_'    +n)||{}).value||'';
            fields['repose_sex_at_birth'    +sfx] = (document.getElementById('rp_sex_'    +n)||{}).value||'';
            fields['repose_additional_notes'+sfx] = (document.getElementById('rp_notes_'  +n)||{}).value||'';
            var checkedTests = [];
            block.querySelectorAll('input[type=checkbox]:checked').forEach( function(cb) {
                checkedTests.push( parseInt(cb.value, 10) );
            });
            fields['repose_patient_tests'+sfx] = checkedTests;
        });
        saveToSession(fields);
    }, 600 );

    window.rhSaveDOM = function() { rhSaveDOMDebounced(); };

    var isBlockCheckout = ( typeof reposeCheckout !== 'undefined' && reposeCheckout.isBlockCheckout === '1' );

    function boot() {
        if ( isBlockCheckout ) {
            var success = tryBlocksAPI();
            if ( ! success ) {
                setTimeout( function() { tryBlocksAPI(); }, 2000 );
            }
        } else {
            tryDOMInjection();
        }
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', boot );
    } else {
        boot();
    }

}() );
