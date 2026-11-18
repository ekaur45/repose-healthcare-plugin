# Plan: Multi-patient orders sharing one reference

Status: **Implemented; tested locally 2026-11-17** (plugin 1.4.20) — see section 8
Repos affected: `repose-healthcare-plugin` (WordPress) and `thibe-hl7-api_parser` (middleware)

---

## 1. Problem

A customer can order a test for several people in one order (e.g. XYZ orders the same test for herself and her husband). The order gets **one** reference number (e.g. `R26K161234`), but each patient has their own patient ID (`RHP-00010`, `RHP-00011`).

- **Ordering:** `Repose_TDL_CSV::rows_for_order()` builds one row per patient, all with the same `OrderReference`. `Repose_Order_Registration` sends one `register-order` request per row. The lab receives two requests with the same reference and different patient IDs.
- **Results:** the lab sends back two ORU files. File names are `{datetimestamp}_{reference}.hl7`, so they share the reference but are never identical. Each file has a different patient ID inside.

### What goes wrong today

| Layer | Location | Problem |
|---|---|---|
| Middleware storage | `hl7cron.service.ts` (`handleFileProcessing`) | Reference is taken from the file name only. The patient ID is never stored. |
| Middleware fetch | `hl7.service.ts` `getFileContent()` | `findOne(... order: id DESC)` returns only the **latest** ORU for the reference. Re-runs and retries return the last patient's result for every request. |
| Middleware access | `hl7.controller.ts` `file/:fileKey`, `lab-result/:referenceNo` | Any logged-in site can pull any reference's result. The calling site is logged (`result_pull_logs`) but not checked. |
| WP order lookup | `class-repose-json-import.php:314-440` | If the reference isn't found, the plugin guesses: `order_id` in the JSON, RHP ID → patient registry, `placerOrderNumber` as a WC order ID, `patientIdentifierList` as a reference. With several sites (and RHP IDs that can repeat across sites) a guess can attach a result to the wrong patient. |
| WP patient matching | `class-repose-json-import.php:541-590` | The name match reads `patient.firstName/lastName`, which don't exist (the middleware sends `patientName.givenName/familyName`). The patient ID is not checked. The test-name match picks the first patient row, so both results are linked to the **same** patient. |
| WP report | `class-repose-json-import.php:1208` `build_html_report()` | Name, DOB and sex always come from Patient 1's order meta, so the husband's report shows the wife's details. |
| WP order meta | `class-repose-json-import.php:535-538, 588` | `_repose_last_json_filename`, `_repose_result_patient_uid` etc. hold one value per order, so the second result overwrites the first. |
| WP regeneration | `class-repose-json-import.php:699-715, 775-790` | Report regeneration reads the raw JSON from `_repose_last_json_filename`, then falls back to the **latest `hl7_messages` row for the order**. Both can return the other patient's data. |
| WP admin queue | `admin/views/results-queue.php:143` | The patient UID comes from order meta, so both result rows show the same patient. |

### Where the patient ID is in the lab file

Checked against a real TDL result (`20260630124332783_R26F214156.hl7`): the RHP ID is in **PID-3** (`RHP-00401`), and PID-2 is empty. In the parsed JSON it is at **`patient.patientIdentifierList`**, not `patient.patientId`.

---

## 2. Current end-to-end flow (for reference)

```
Checkout ─► Payment ─► Patient registry (RHP-10, RHP-11) ─► Transmission
   └─ 1 ref R26K161234 ─► 2× POST /hl7/register-order (site + ref + patient) + CSV ─► LAB
LAB ─► ORR files ─► middleware cron ─► route by ref ─► WP /orr-confirm (sample received email)
LAB ─► ORU files ─► middleware cron (ref from filename, no patient id stored)
   └─► route by ref: 1 registered site → that site; none → all sites; >1 → conflict, nobody
        └─► WP /results/json-import ─► GET /hl7/file/{ref} (latest by ref, no site check)
             └─► report (Patient 1 header) ─► wp_repose_results (pending_review)
                  └─► patient match (test name only → same patient twice)
                       └─► admin approve ─► email billing contact ─► My Account
```

The middleware calls the WordPress webhook once per processed file, with `{ key: file_name, reference_number: ref }`.

### Middleware multi-site setup (commit `26e48f8`)

- Sites are configured per number `n`: `WP_WEBHOOK_URL_SITE{n}_ORU`, `WP_WEBHOOK_URL_SITE{n}_ORR`, `WP_X_TOKEN_SITE{n}`, `WP_SITE{n}_API_USERNAME`, `WP_SITE{n}_NAME`, `WP_SITE{n}_ENABLED`. The old `WP_WEBHOOK_URL` / `WP_WEBHOOK_URL_ORR` / `WP_X_TOKEN` are still read as site 1 when no `SITE1` variables exist.
- A WordPress site is identified by its login username (`resolveSiteByApiUsername`).
- `register-order` stores site + reference + patient in `order_site_registrations`.

---

## 3. Decisions

| # | Rule |
|---|---|
| R1 | **Single-patient order:** today's flow, still calls `/hl7/file/{ref}`, except the order-lookup guessing is removed (R9). |
| R2 | **Multi-patient order:** call the new endpoint `/hl7/files/{ref}` and handle each result in the array. |
| R3 | A patient has at most one result per reference. |
| R4 | A patient on this order already has a result: **skip**, and log it. |
| R5 | Inside the order: match by RHP ID first; if no match, by **name + DOB**; if still no match, **skip and log**. |
| R6 | Deployment is handled manually by the developer (no backward-compatibility layer needed beyond keeping the old endpoint). |
| R7 | Raw lab data per result: **option (c)**, `hl7_message_id` column on `wp_repose_results`. |
| R8 | **No one may receive someone else's result.** Every lookup after import goes through the result ID, never through the order. If a result's own data can't be found, the system refuses ("re-import") instead of guessing. |
| R9 | **No guessing in WordPress.** An order is found only by an exact `_repose_reference_number` match. If none is found, the import stops with 404 and is logged. |
| R10 | **Middleware checks the calling site** before returning results (`/hl7/file/{ref}` and `/hl7/files/{ref}`): refused only when the reference is registered to a **different** site (rule i). Callers that don't match a configured site are refused. |
| R11 | **Middleware routing unchanged:** results are routed by reference only; unregistered results are still sent to all sites. |
| R12 | **Who sees a result:** the person who placed the order, through their logged-in My Account. Not changed; WordPress already works this way. |

### R7: storing raw lab data per result

Every imported result keeps a copy of the lab data it came from, so its report can be rebuilt if the report file goes missing. Today the order only remembers the copy from its **last** result.

| Option | How | Pros | Cons |
|---|---|---|---|
| **(a)** File named by result ID | `raw-result-{result_id}.json` | No DB change | Link is only a naming convention; file can go missing |
| **(b)** Column with file name | `raw_json_file` on `wp_repose_results` | Explicit link | DB change; still depends on the file |
| **(c)** Column linking to stored lab data | `hl7_message_id` on `wp_repose_results` → `wp_repose_hl7_messages` (already holds the full lab JSON) | No file to lose; replaces the unsafe "latest message by order" fallback | DB change (one column via `dbDelta`) |
| **(d)** (a) + (c) | Both | Two independent sources | Most code |

**Chosen: (c).**

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

- `@Get('files/:ref')` with `JwtAuthGuard`, same as `file/:fileKey`.
- Record the pull with `recordResultPull(ref, site?.code, 'files', ip)`, as the other routes do.
- Run the site check (4.3), then `_Ok(await this.hl7Service.getFilesContentByRef(ref), "Files content fetched.")`.
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

### 4.3 Site check (R10)
Applied to `file/:fileKey` and the new `files/:ref`.

1. Resolve the calling site with `resolveSiteByApiUsername(req.user?.username)`. If no site matches, refuse.
2. Look up `order_site_registrations` for the reference.
3. Allowed if the reference has no registrations with a site code, or if one of them is the calling site. Otherwise refused.
4. Refused: respond **403** with a clear message and log it (reference, calling site, registered sites).

### 4.4 Not changed
- The cron jobs, routing (`OrderMatchingService.routeResultNotification`), the webhook payload and the database schema.

---

## 5. Part 2: Plugin (`repose-healthcare-plugin`)

All in `includes/class-repose-json-import.php` unless noted.

### 5.1 Reorder `handle_import()`

Today the API call (step 3) comes before the order lookup (step 5). New order:

1. Parse the body and run `extract_ref()`. Unchanged.
2. **Find the order by reference first**, with a helper `find_order_by_ref($ref)`: exact `_repose_reference_number` match only.
   - **Order found:** decide the path (5.2).
   - **No order found:** return **404**, audit log `json_result_no_order` with the reference and file key, `error_log` line. Nothing is imported.
   - **More than one order has the reference:** return **409**, audit log `json_result_ambiguous_order`. Nothing is imported.
3. **Remove the guessing lookups (R9):** `order_id` in the JSON, RHP ID → patient registry tests, `placerOrderNumber` as a WC order ID, `patientIdentifierList` as a reference (lines 319-354 and 379-430).
4. **Single-patient path:** call `/hl7/file/{ref}` and run today's flow from step 6 onwards (5.8 lists what changes there).
5. **Multi-patient path:** section 5.3.

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
   - Read `data` as a list. If it's empty or the call fails (including 403 from the site check), return a 502 with debug information, the same way today's flow does.
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
2. **Match by ID:** the order patient whose `uid` equals it, ignoring case. Only patients on **this** order are considered.
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

- **Patient has no registry record or no test rows on this order:** skip (`skipped_not_registered`), audit log + `error_log`. Without that link the result can't be tied to the patient and a re-run would import it again.

#### Step C: import (same steps as today, per patient)
1. **Test name:** `reports[0].universalServiceId.text`, as today.
2. **Report:** `build_html_report($json, $order, $patient)`, with the patient passed in (5.5).
   - File name: `json-result-{order_id}-{uid}-{time}.html`, with the UID added so the two files can be told apart.
3. **Insert the `wp_repose_results` row**, same columns as today (`status = pending_review`).
4. **`process_lab_data($json, $order_id, $ref, $result_id)`**, unchanged, plus back-filling `wc_result_id` on `hl7_reports` as today.
5. **Raw lab data (R7):** with option (c), save the returned `hl7_message_id` on the result row. (Option (a): save `raw-result-{result_id}.json` instead.)
6. **Update this patient's rows** in `wp_repose_patient_tests` for this order: `status = complete`, `result_id = new id`.
   - If the patient has several test rows on the order, update the row whose test name matches; if none match, update the first row **for that patient** (never another patient's row).
7. **Order meta:** keep writing `_repose_last_json_result_id`, `_repose_hl7_message_id` and `_repose_hl7_patient_id` for compatibility, but **nothing in the multi-patient path reads them**. Don't write `_repose_result_patient_uid` (see 5.6).
8. **Logging:**
   - Audit log `json_result_imported` with ref, UID, test and result ID.
   - Order note: `Repose: JSON result imported for {uid} ({name}). Test: {test}, Result ID: {id}. Pending admin review.`

### 5.5 `build_html_report()` with the right patient

- New signature: `build_html_report(array $data, WC_Order $order, ?array $patient = null)`.
- If `$patient` is given, take forename, surname, DOB and sex from it. Only if a value is empty, fall back to the HL7 PID data.
- If `$patient` is `null`, keep today's behaviour (Patient 1 meta first), so single-patient orders don't change.

### 5.6 Places that read order-level data and could mix patients (R8)

| Location | Today | Change |
|---|---|---|
| `ajax_generate_hl7_pdf()` (~line 699) and `build_report_from_db()` (~line 775): finding the lab data | `_repose_last_json_filename`, then latest `hl7_messages` row **by order** | Look up by **result**: the result's `hl7_message_id` (option c). For results imported before this change (no link): use the old lookups **only if the order has one patient**; otherwise refuse with "cannot regenerate, please re-import". |
| Same two functions: rebuilding the report | `build_html_report($data, $order)` | Find this result's patient via `wp_repose_patient_tests.result_id = result_id` and pass it in. If the order has several patients and none is linked, refuse. |
| `admin/views/results-queue.php:143` | Patient UID from order meta `_repose_result_patient_uid` | First look up the UID via `wp_repose_patient_tests.result_id = $result->id`; fall back to the meta only for single-patient orders. |

### 5.7 Who sees the result (R12)
Not changed. Approved results go to the person who placed the order and are visible in their logged-in My Account.

### 5.8 Not changed
- Single-patient orders: same endpoint, same matching, same report (only the order-lookup guessing is removed, R9).
- Ordering, ORR, review, approval, rejection, notifications and the customer dashboard.
- Database schema, unless R7 option (b), (c) or (d) is chosen.

---

## 6. Test cases

| # | Scenario | Expected |
|---|---|---|
| T1 | Single-patient order, one result | Same as today. |
| T2 | Two patients; wife's file, then husband's | Webhook 1 imports the wife's. Webhook 2 skips the wife's (`skipped_exists`) and imports the husband's. Each report shows its own patient's details. |
| T3 | Two patients; husband's file arrives first | Husband's imported with his own details. Then the wife's is imported. |
| T4 | Webhook re-run after both are imported | Both skipped, nothing duplicated. |
| T5 | RHP ID missing or wrong, but name and DOB match a patient on the order | Imported to the right patient via the name + DOB fallback. |
| T6 | No ID, name or DOB match | Skipped, with an audit log entry and an `error_log` line. |
| T7 | Admin soft-deleted a result, then a re-run | Re-imported (deleted results don't block it). |
| T8 | Report file deleted, then viewed | Rebuilt from that result's own lab data with the right patient. |
| T9 | Admin results queue | Each row shows the right RHP ID. |
| T10 | `/hl7/files/{ref}` with no results | `[]`, and WordPress returns a 502 with debug information. |
| T11 | Webhook for a reference with no matching order on this site | 404, logged, nothing imported (no guessing). |
| T12 | Site B pulls a reference registered to site A | Middleware refuses (403) and logs it. |
| T13 | Old multi-patient result (no per-result link), report deleted, viewed | Refused with "please re-import"; never shows the other patient's data. |

---

## 7. Open items

1. **Middleware `.env`:** the local `.env` still uses the old `WP_WEBHOOK_URL` variables (read as site 1), pointing at `old-wp.local`, which isn't a local Apache site (`local-wp`, `wp.local`). Move to `WP_WEBHOOK_URL_SITE{n}_*` and set `WP_SITE{n}_API_USERNAME` per site.
2. **`POST /hl7/re-run-webhook`** has no login check. Outside this plan's scope; noted for a separate fix.

### Closed
- Site check rule: (i), refuse only references registered to a different site.
- R7: option (c).
- Same file names: files are `{datetimestamp}_{reference}.hl7`, so two results never share a name.
- `register-order` route: exists in the middleware (`hl7.controller.ts:90`).
- Middleware routing by patient: not wanted; routing stays by reference only (R11).

---

## 8. Local test run (2026-11-17)

Setup: middleware on `localhost:8010` (Azure disabled), SITE1 = `local-wp`, SITE2 = `local-wp2`. Lab files were synthetic ORU messages parsed by the middleware's own `LabResultModel`, saved to `hl7_messages` and routed like the cron does.

| # | Result |
|---|---|
| T1 | Imported. **But** linked to a blank "ghost" registry patient (see finding 1). |
| T2 | Pass. Wife imported; husband imported on webhook 2, wife `skipped_exists`. Each report shows its own patient and values. |
| T3 | Pass. |
| T4 | Pass. Re-run skipped both, no duplicates. |
| T5 | Pass. Wrong RHP ID, matched by name + DOB ("CHLOE BROWN" ↔ "Chloé Brown"). |
| T6 | Pass. Skipped and logged. |
| T7 | Pass. Re-imported after soft delete. |
| T8 | Pass via `build_report_from_db()` rebuild with the right patient. (Report files are owned by `www-data`, so a file was not physically deleted; the admin-ajax view was not driven through a browser.) |
| T9 | Pass, except T1's result shows the ghost patient. |
| T10 | Pass. `[]` → 502, nothing imported. |
| T11 | Pass. 404 + `json_result_no_order`. |
| T12 | Pass. SITE2's ref routed to SITE2 only; SITE1 refused (403) on `/hl7/file` and `/hl7/files`; SITE2 allowed. |
| T13 | Pass. Rebuild refused without a link; works with it. |
| extra | Middleware refusal reaching the single-patient path → 502, nothing imported. |

### Findings during testing
1. **Ghost registry patient (pre-existing):** `Repose_Patient_Registry::sync_from_order()` casts `(array) ''` on `_repose_additional_patients`, creating a blank patient with all the order's tests for every single-patient order. The single-patient matcher (unchanged) sorts by surname, so the blank patient wins. Not fixed yet.
2. **Middleware returns HTTP 200 for every error** (`global-exception.filter.ts:105`). WordPress logged `order_registered` for registrations that failed. The plugin now treats `{ success: false }` as a failed fetch. Filter not changed.
3. **New middleware tables need manual creation** (`synchronize: false`): run `src/database/tables/order_site_registrations.sql`, `result_pull_logs.sql`, `order_match_conflicts.sql` on every environment.
4. **RHP IDs repeat across sites** (both local sites have RHP-00001 for different people) — confirms why order lookup must not guess by RHP ID.
5. Security, outside scope: `GET /auth/create` and `POST /auth/create-site-user` have no auth; failed webhook calls log the site token.

