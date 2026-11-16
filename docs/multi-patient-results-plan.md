# Plan: Multi-patient orders sharing one reference

Status: **Planned, not implemented**
Repos affected: `repose-healthcare-plugin` (WordPress) and `thibe-hl7-api_parser` (middleware)

---

## 1. Problem

A customer can order a test for several people in one order (e.g. XYZ orders the same test for herself and her husband). The order gets **one** reference number (e.g. `R26K161234`), but each patient has their own patient ID (`RHP-00010`, `RHP-00011`).

- **Ordering:** `Repose_TDL_CSV::rows_for_order()` builds one row per patient, all with the same `OrderReference`. `Repose_Order_Registration` sends one `register-order` request per row. The lab receives two requests with the same reference and different patient IDs.
- **Results:** the lab sends back two ORU files with the same reference (in the file name) and a different patient ID inside each file.

### What goes wrong today

| Layer | Location | Problem |
|---|---|---|
| Middleware storage | `hl7cron.service.ts` (`handleFileProcessing`) | Reference is taken from the file name only. The patient ID is never stored. |
| Middleware fetch | `hl7.service.ts:45` `getFileContent()` | `findOne(... order: id DESC)` returns only the **latest** ORU for the reference. Re-runs and retries return the last patient's result for every request. |
| WP patient matching | `class-repose-json-import.php:540-590` | The name match reads `patient.firstName/lastName`, which don't exist (the middleware sends `patientName.givenName/familyName`). The patient ID is not checked. The test-name match picks the first patient row, so both results are linked to the **same** patient. |
| WP report | `class-repose-json-import.php:1208` `build_html_report()` | Name, DOB and sex always come from Patient 1's order meta, so the husband's report shows the wife's details. |
| WP order meta | `class-repose-json-import.php:535-538, 588` | `_repose_last_json_filename`, `_repose_result_patient_uid` etc. hold one value per order, so the second result overwrites the first. |
| WP regeneration | `class-repose-json-import.php:699, 775` | Report regeneration reads the raw JSON from `_repose_last_json_filename`, which can point at the other patient's data. |
| WP admin queue | `admin/views/results-queue.php:143` | The patient UID comes from order meta, so both result rows show the same patient. |

### Where the patient ID is in the lab file

Checked against a real TDL result (`20260630124332783_R26F214156.hl7`): the RHP ID is in **PID-3** (`RHP-00401`), and PID-2 is empty. In the parsed JSON it is at **`patient.patientIdentifierList`**, not `patient.patientId`.

---

## 2. Current end-to-end flow (for reference)

```
Checkout ─► Payment ─► Patient registry (RHP-10, RHP-11) ─► Transmission
   └─ 1 ref R26K161234 ─► 2× register-order + CSV (email/Azure) ─► LAB
LAB ─► ORR files ─► middleware cron ─► WP /orr-confirm (sample received email)
LAB ─► ORU files ─► middleware cron (ref from filename, no patient id stored)
   └─► WP /results/json-import ─► GET /hl7/file/{ref} (latest by ref)
        └─► report (Patient 1 header) ─► wp_repose_results (pending_review)
             └─► patient match (test name only → same patient twice)
                  └─► admin approve ─► email billing contact ─► My Account
```

The middleware calls the WordPress webhook once per processed file, with `{ key: file_name, reference_number: ref }`.

---

## 3. Decisions

| # | Rule |
|---|---|
| R1 | **Single-patient order:** today's flow unchanged, still calls `/hl7/file/{ref}`. |
| R2 | **Multi-patient order:** call the new endpoint `/hl7/files/{ref}` and handle each result in the array. |
| R3 | A patient has at most one result per reference. |
| R4 | A patient on this order already has a result: **skip**, and log it. |
| R5 | Match by RHP ID first; if no match, by **name + DOB**; if still no match, **skip and log**. |
| R6 | Deployment is handled manually by the developer (no backward-compatibility layer needed beyond keeping the old endpoint). |
| R7 | Raw JSON per result: option **(a)** below, *pending confirmation*. |

### R7: storing raw JSON per result

Every imported result saves a raw JSON file next to it. If the report file goes missing, it is rebuilt from that JSON. Today the JSON is found through the order-level `_repose_last_json_filename`, which only remembers the **last** result for the order.

- **(a) Name the file after the result ID** (`raw-result-{result_id}.json`). Regeneration looks for that file. No database change. **Recommended.**
- **(b) Add a column** `raw_json_file` to `wp_repose_results` and store the file name there. Clearer, but a schema change.

---

## 4. Part 1: Middleware (`thibe-hl7-api_parser`)

### 4.1 New service method
`src/modules/hl7/hl7.service.ts` → `getFilesContentByRef(ref: string)`

- If `ref` ends in `.hl7`, take the reference from the file name the same way `getFileContent()` does, so both endpoints accept the same input.
- Query:
  ```ts
  hl7MessageRepo.find({
    where: { order_ref_number: ref, type: Hl7MessageType.ORU },
    order: { id: 'ASC' },
  })
  ```
- Return one item per row:
  ```json
  { "id": 101, "createdAt": "...", "raw": "", "json": { "...parsed ORU..." } }
  ```
  `id` and `createdAt` are for logging and debugging only. The plugin doesn't depend on them.
- No rows: return `[]` (not an error), so the plugin can tell "nothing yet" apart from a failure.

### 4.2 New controller route
`src/modules/hl7/hl7.controller.ts`

- `@Get('files/:ref')` → `_Ok(await this.hl7Service.getFilesContentByRef(ref), "Files content fetched.")`
- Same auth and guards as `file/:fileKey`.
- Response:
  ```json
  {
    "success": true,
    "message": "Files content fetched.",
    "data": [
      { "id": 101, "createdAt": "...", "raw": "", "json": { } },
      { "id": 102, "createdAt": "...", "raw": "", "json": { } }
    ]
  }
  ```

### 4.3 Not changed
- `GET /hl7/file/:fileKey`, the cron jobs, the webhook payload and the database schema.

---

## 5. Part 2: Plugin (`repose-healthcare-plugin`)

All in `includes/class-repose-json-import.php` unless noted.

### 5.1 Reorder `handle_import()`

Today the API call (step 3) comes before the order lookup (step 5). New order:

1. Parse the body and run `extract_ref()`. Unchanged.
2. **Find the order by reference first.** Move the `_repose_reference_number` lookup ahead of the API call, into a helper `find_order_by_ref($ref)`.
   - **Order found:** decide the path (5.2).
   - **No order found:** keep today's flow exactly. Call `/hl7/file/{ref}` and run the existing fallback lookups (`order_id` in the JSON, RHP registry, `placerOrderNumber`, `patientIdentifierList`), because some of those need the API data.
3. **Single-patient path:** call `/hl7/file/{ref}` and run today's flow from step 4 onwards, unchanged.
4. **Multi-patient path:** section 5.3.

### 5.2 Counting patients on an order

New helper `get_order_patients(WC_Order $order): array` returns one entry per patient:

```
[
  [ 'index' => 1, 'uid' => _repose_patient_1_uid,
    'forename', 'surname', 'dob', 'sex' ],   // patient 1: from _repose_patient_* meta
  [ 'index' => n, 'uid' => _repose_patient_{n}_uid,
    'forename', 'surname', 'dob', 'sex' ],   // from _repose_additional_patients[n-2]
]
```

- Use the same sources as `Repose_TDL_CSV::rows_for_order()`, so the patients match what was sent to the lab.
- Skip additional-patient entries that aren't arrays (the same guard `rows_for_order()` uses).
- More than one entry means the multi-patient path.

### 5.3 Multi-patient path

1. `callApiMulti($ref)`: same login as `callAPi()`, then `GET {API_URL}/hl7/files/{ref}`.
   - Refactor the login into a shared private `get_api_token()` used by both.
   - Read `data` as a list. If it's empty or the call fails, return a 502 with debug information, the same way today's flow does.
2. For **each item** in the list, run `import_single_result($order, $patients, $item['json'], $data, $ref)` (5.4), and collect each outcome: `imported`, `skipped_exists` or `skipped_no_match`.
3. Return **200** with a summary:
   ```json
   {
     "success": true,
     "order_id": 123,
     "reference": "R26K161234",
     "results": [
       { "uid": "RHP-00010", "outcome": "skipped_exists", "result_id": 55 },
       { "uid": "RHP-00011", "outcome": "imported",       "result_id": 56 }
     ]
   }
   ```
   It's 200 even when everything was skipped, because skipping is normal here (webhook 2 always re-sends the first patient's result).

### 5.4 `import_single_result()`, for one result in the array

#### Step A: identify the patient (R5)
1. Read the lab's patient ID from `json.patient.patientIdentifierList` (PID-3). Keep only what comes before the first `^` (PID-3 can have components), then trim.
   - If that's empty, fall back to `json.patient.patientId` (PID-2).
2. **Match by ID:** the order patient whose `uid` equals it, ignoring case.
3. **If no ID match, try name + DOB:**
   - Lab side: `patient.patientName.givenName`, `patient.patientName.familyName`, `patient.dateOfBirth` (YYYYMMDD).
   - Order side: the patient's `forename`, `surname`, `dob`.
   - Clean both sides the same way:
     - Names: `Repose_TDL_CSV::latin_only()` (the lab received names cleaned like this), lowercase, trim.
     - DOB: reduce to digits in `YYYYMMDD` order (order-side DOB can be `YYYY-MM-DD` or `DD/MM/YYYY`).
   - It's a match only if forename, surname and DOB **all** agree, and exactly **one** patient matches. If two patients match (e.g. twins with the same name), treat it as no match.
4. **If there's still no match:** skip.
   - Audit log `json_result_skipped_no_match` with the reference, the lab's patient ID, name and DOB.
   - `error_log` line with the same information.
   - Outcome: `skipped_no_match`.

#### Step B: already imported? (R4)
- Look up the patient's registry ID with `Repose_Patient_Registry::get_by_uid($uid)`.
- Check `wp_repose_patient_tests` for that patient and this `order_id` with `result_id IS NOT NULL`.
- Also make sure that `result_id` exists in `wp_repose_results` and isn't soft-deleted (`deleted_at IS NULL`), so an admin's deletion doesn't block a re-import.
- **If a valid result exists:** skip.
  - Audit log `json_result_skipped_exists`.
  - Outcome: `skipped_exists`.

#### Step C: import (same steps as today, per patient)
1. **Test name:** `reports[0].universalServiceId.text`, as today.
2. **Report:** `build_html_report($json, $order, $patient)`, with the patient passed in (5.5).
   - File name: `json-result-{order_id}-{uid}-{time}.html`, with the UID added so the two files can be told apart.
3. **Insert the `wp_repose_results` row**, same columns as today (`status = pending_review`).
4. **Raw JSON (R7, option a):** *after* the insert, save `raw-result-{result_id}.json` with the same content as today (`trigger`, `hl7_result`, `debug`).
5. **`process_lab_data($json, $order_id, $ref, $result_id)`**, unchanged, plus back-filling `wc_result_id` on `hl7_reports` as today.
6. **Update this patient's rows** in `wp_repose_patient_tests` for this order: `status = complete`, `result_id = new id`.
   - If the patient has several test rows on the order, update the row whose test name matches; if none match, update the first row for that patient.
7. **Order meta:** keep writing `_repose_last_json_result_id`, `_repose_hl7_message_id` and `_repose_hl7_patient_id` for compatibility, but **nothing in the multi-patient path reads them**. Don't write `_repose_result_patient_uid` (see 5.6).
8. **Logging:**
   - Audit log `json_result_imported` with ref, UID, test and result ID.
   - Order note: `Repose: JSON result imported for {uid} ({name}). Test: {test}, Result ID: {id}. Pending admin review.`

### 5.5 `build_html_report()` with the right patient

- New signature: `build_html_report(array $data, WC_Order $order, ?array $patient = null)`.
- If `$patient` is given, take forename, surname, DOB and sex from it. Only if a value is empty, fall back to the HL7 PID data.
- If `$patient` is `null`, keep today's behaviour (Patient 1 meta first), so single-patient orders don't change.

### 5.6 Places that read order-level meta and would mix patients

| Location | Today | Change |
|---|---|---|
| `class-repose-json-import.php:699` `ajax_generate_hl7_pdf()` | Raw JSON from `_repose_last_json_filename` | First try `raw-result-{result_id}.json`; if missing, fall back to today's lookup. |
| `class-repose-json-import.php:775` `build_report_from_db()` | Same | Same change. |
| Both of the above, second fallback | Latest `hl7_messages` row **by order** | *Open item:* find the message through `hl7_reports.wc_result_id = result_id`, or leave as is (it only runs when both files are missing). |
| Both of the above, rebuilding the report | `build_html_report($data, $order)` | Find this result's patient via `wp_repose_patient_tests.result_id = result_id` and pass it in. |
| `admin/views/results-queue.php:143` | Patient UID from order meta `_repose_result_patient_uid` | First look up the UID via `wp_repose_patient_tests.result_id = $result->id`, then fall back to today's meta. |

### 5.7 Not changed
- Single-patient orders: same endpoint, same matching, same report.
- Ordering, ORR, review, approval, rejection, notifications and the customer dashboard.
- Database schema (with option a).

---

## 6. Test cases

| # | Scenario | Expected |
|---|---|---|
| T1 | Single-patient order, one result | Same as today. |
| T2 | Two patients; wife's file, then husband's | Webhook 1 imports the wife's. Webhook 2 skips the wife's (`skipped_exists`) and imports the husband's. Each report shows its own patient's details. |
| T3 | Two patients; husband's file arrives first | Husband's imported with his own details. Then the wife's is imported. |
| T4 | Webhook re-run after both are imported | Both skipped, nothing duplicated. |
| T5 | RHP ID missing or wrong, but name and DOB match | Imported to the right patient via the name + DOB fallback. |
| T6 | No ID, name or DOB match | Skipped, with an audit log entry and an `error_log` line. |
| T7 | Admin soft-deleted a result, then a re-run | Re-imported (deleted results don't block it). |
| T8 | Report file deleted, then viewed | Rebuilt from `raw-result-{id}.json` with the right patient. |
| T9 | Admin results queue | Each row shows the right RHP ID. |
| T10 | `/hl7/files/{ref}` with no results | `[]`, and WordPress returns a 502 with debug information. |

---

## 7. Open items

1. **R7:** confirm option (a) (file named by result ID), or choose (b) (new column).
2. **Second fallback in 5.6:** fix the "latest message by order" fallback, or leave it?
3. **Same file names:** unconfirmed whether the lab sends both files with identical names. If it does, the middleware's `file_scans` upsert on `file_name` and the local copy in `hl7_files/OUT` would lose one result before any of this runs. That needs its own small middleware fix. Check a real multi-patient pair.
4. **Middleware `.env`:** confirm `WP_WEBHOOK_URL` points to `/wp-json/repose/v1/results/json-import`.
5. **`register-order` endpoint:** the local middleware code has no `/hl7/register-order` route, but `Repose_Order_Registration` calls it. Confirm the deployed middleware has it, or check the audit log for `order_registration_failed`.
