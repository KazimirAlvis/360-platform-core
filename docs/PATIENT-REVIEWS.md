# Patient review storage and moderation

The core plugin owns patient submissions in `{wp_prefix}360_patient_reviews`. This is not a post type, has no REST registration, and is not included in clinic repository output, search, feeds, or automatic frontend rendering. Approved reviews are exposed only through the explicit public projection and the standalone Patient Reviews page. Existing `clinic_reviews` and `_360_reviews` metadata remain exclusively on their existing manual/API path.

## Configuration and access

- Forms are detected from actual CF7 tags containing all seven required field names: `clinic-id`, `review-doctor`, `review-rating`, `review-display-name`, `review-email`, `review-message`, and `review-consent`. IDs, titles, and page slugs are not used for detection. The normal WordPress page `/leave-a-review/` contains the existing CF7 shortcode. The core plugin supplies the form choices and dependent-dropdown behavior. Other forms are not handled by the storage integration.
- The schema installs once on `init`, including for an already-active plugin. The `global360_patient_reviews_schema` option tracks its version.
- Administrators receive `moderate_patient_reviews` on installation. Grant that capability explicitly to other trusted staff roles/users if needed; ordinary editors and subscribers do not receive access by default.
- Open **Patient Reviews** in WordPress admin. The paginated list links to a private detail screen containing the sanitized original review, private email, consent wording, and notification status. Text and rating have no editing controls or write handler.
- Approve, Reject, and Hide use POST, a review-specific WordPress nonce, and the capability check. Only status, last moderation UTC timestamp, and moderator ID are changed. Moderation history beyond the most recent decision is not recorded.

## Submission lifecycle

CF7's normal validation, consent, and spam checks still run. Core independently validates the original field shapes and required values, then validates again immediately before storage. Only published, non-password-protected clinics and related published, non-password-protected doctors are accepted. Doctor value `0` means Clinic overall. Ratings accept integers 1–5 and the existing CF7 labels (`1 - Poor` through `5 - Excellent`). Consent must be `1`.

Text is sanitized as plain text. Empty or oversized fields are rejected (display name: 200 bytes, email: 254 bytes, review: 20,000 bytes). Email must already be a valid address, not merely become valid after sanitization. Consent wording is saved along with consent and the submission UTC timestamp.

A valid, non-spam submission is inserted as Pending in `wpcf7_before_send_mail`. Storage failure aborts mail. Notification success/failure updates only `notification_status`; mail failure never deletes or rolls back the review. CF7 retains its usual notification result message.

A unique database index on an HMAC fingerprint of the form ID and normalized submission fields makes retries atomic and prevents concurrent duplicate rows. An identical submission to the same form is treated as the same review indefinitely, including after moderation. A retry may retry mail but never resets the moderation status or modifies the original review. This is content-based deduplication, not a browser token. Changing WordPress authentication salts changes future fingerprints.

There is no public review or email REST API. The standalone `/patient-reviews/` page uses the approved-only projection below. Do not add private table columns to generic REST export or clinic sync code.

## Local verification

Run from `wp-content` with the local database socket:

```sh
php -d mysqli.default_socket=/Applications/MAMP/tmp/mysql/mysql.sock plugins/360-platform-core/tests/patient-reviews.php
php -d mysqli.default_socket=/Applications/MAMP/tmp/mysql/mysql.sock plugins/360-platform-core/tests/shared-review-form.php
node plugins/360-platform-core/tests/review-form-js.cjs
php plugins/360-platform-core/tests/run.php
```

The submission suite exercises the actual CF7 pipeline. It intercepts `wp_mail`, creates temporary clinic/doctor/user fixtures, and cleans up its own rows and users in `finally`. It tests invalid clinic/doctor selections, consent, ratings and required fields, spam, saved-before-mail ordering, failed mail and retries, storage failure, form scoping, metadata isolation, permissions, nonces, moderation immutability, and public REST isolation. No test email is transmitted.

## Shared review page

Use one ordinary published WordPress page at `/leave-a-review/`, with `[contact-form-7 id="YOUR_FORM_ID" title="Patient Review Submission."]`. It uses the normal theme page template. There are no clinic-specific review routes, templates, styles, or URL-derived defaults.

The CF7 clinic field is `[select* clinic-id "Select a clinic"]`, replacing the former hidden tag. The doctor field is `[select* review-doctor "Select a clinic first"]`. Leave the remaining rating, name, email, text, consent, and notification settings intact. The core plugin filters only the detected review form, populating clinics from this site's published, non-password-protected clinic posts. Clinic and doctor values are WordPress post IDs; `0` means Clinic overall.

`GET /wp-json/global360/v1/review-clinics/{id}/doctors` returns only the selected clinic ID and published doctor IDs/names. Invalid/unpublished clinics return 404. It never queries the patient review table. A clinic without doctors has an empty doctor list and can still be reviewed overall. No cross-site queries occur.

The doctor control is disabled in initial HTML. Its script loads only when the detected review form renders, clears the old selection on every clinic change, and ignores late responses from earlier requests. Loading, empty results, and failed lookup states are announced; failures offer a retry. Reset clears the doctor selection. JavaScript is required for dependent selection, with a noscript explanation.

CF7's browser schema retains required fields but omits its context-free doctor enum; the server generates doctor choices using the submitted clinic ID and independently validates the actual relationship before saving. Changing a clinic while retaining an old doctor ID is rejected. Storage, duplicate handling, and moderation have no dependency on the page URL.

When replacing the old implementation on another local site, update the CF7 tags, create the normal page, delete `global360_review_form_route_version`, and flush rewrite rules once (soft flush is sufficient). Do not add a second route implementation. Local database edits are separate from source files and do not travel with Git. The obsolete theme integration, template, stylesheet, and clinic-specific tests were removed.

## Public Patient Reviews page

The ordinary WordPress page `/patient-reviews/` uses the theme's `page-patient-reviews.php` and its contextual stylesheet. It is separate from `/leave-a-review/` and does not require a hardcoded page ID, block, or clinic-page integration.

`global360_platform()->patient_reviews()->query($args)` returns `items`, `total`, `pages`, `page`, and `per_page`. Optional arguments are `page`, `per_page` (1–50), `clinic_id`, and `doctor_id` (0 for clinic overall). Future components should reuse this query. Only approved records associated with a published, non-password-protected clinic are selected. Items contain only review ID, clinic/doctor IDs, public names and public WordPress permalinks, display name, rating, review text, and submission timestamp. Private email, consent, moderation, notification, and duplicate-key columns are never selected. Missing/unpublished doctor names are empty; the theme labels them without exposing private post titles.

Results are ordered by submission timestamp descending and ID descending for ties. The page shows nine cards per page using `?review_page=N`, accessible numbered links, and an empty state. An out-of-range page is clamped to the last available page.

The repository deliberately does not cache query results. The public page sends WordPress no-cache headers and calls the installed WP Fastest Cache exclusion API; `DONOTCACHEPAGE` is also set for compatible caches. Successful moderation emits `global360_patient_review_moderated`, which purges the page via the installed WP Fastest Cache post-cache hook (desktop/mobile descendants included). This removes pre-existing cache copies as well as keeping subsequent requests fresh. Other future cache providers can subscribe to that moderation event.

Additional verification:

```sh
php -d mysqli.default_socket=/Applications/MAMP/tmp/mysql/mysql.sock plugins/360-platform-core/tests/public-patient-reviews.php
```

Use `--preview` in a local terminal to pause with temporary synthetic cards for browser viewport checks; press Enter to clean up. The suite checks privacy, association names, ordering, pagination, escaping, empty state, and anonymous HTTP visibility after moderation, including an existing WP Fastest Cache descendant. It never alters real patient review rows.

## Editor content and review presentation

Both pages render editor content through the theme's standard `content-page` template part and `the_content()` pipeline. Existing Global 360 hero and content blocks work normally. The public page places its approved listing after editor content and has no hardcoded title, intro, or CTA. Add those in the editor as desired. Keep the existing CF7 shortcode in `/leave-a-review/`; the template does not render a second form.

The theme's review stylesheet leaves hero blocks full width, constrains the public listing independently, and gives the detected review form a 760px content width plus 20px side padding. Colors and typography inherit the site's theme variables. Core adds the `global360-patient-review-form` class only to the detected review form, and its live doctor feedback is positioned after the doctor field. CF7 errors and submission feedback stay in that scoped form. Storage, selection, privacy, and moderation logic are unchanged.

The local CF7 `mail_sent_ok` message is: “Thank you. Your review has been submitted and will be reviewed before publication.” This is a local database setting, not a source-code override.

`tests/review-editor-pages.php --preview` temporarily prepends the existing Page Title Hero block and a paragraph to both pages for visual checks. Press Enter to restore the original editor content; newer concurrent editor changes are preserved. No permanent hero blocks are inserted by the implementation.

## Staging setup (database configuration is separate from Git)

1. Install these core plugin and theme branches together, with Contact Form 7 active and Global 360 Theme active. Activate the existing Global 360 blocks plugin if using its hero blocks. No build step or new package dependencies are required.
2. Create or selectively transfer the review form in CF7. Use its own generated shortcode on the page. No form-ID option is required: core detects the complete field signature. Any obsolete form-hash option is ignored and may be removed; no migration is needed.
3. Configure the form using the markup below. Do not retain the old hidden `clinic-id` field or add hardcoded clinic/doctor options. Configure CF7 Mail recipients, sender, reply-to and SMTP for staging separately. Do not copy local recipient settings blindly. The integration stores pending submissions before normal CF7 notification; leave `skip_mail`, `demo_mode`, and `do_not_store` overrides off (local Additional Settings is empty).
4. Set CF7 Messages → successful mail message (`mail_sent_ok`) to: **Thank you. Your review has been submitted and will be reviewed before publication.**
5. Create/publish normal pages with exact slugs `leave-a-review` and `patient-reviews`. Select **Default template** for both. WordPress automatically selects `page-patient-reviews.php` by slug; do not assign a custom page template. The submission page uses normal `page.php`. No page IDs are configured.
6. On `leave-a-review`, place the existing Page Title Hero block above one Shortcode block containing `[contact-form-7 id="YOUR_FORM_ID" title="Patient Review Submission."]`. On `patient-reviews`, add any hero/intro/CTA blocks desired; the listing renders after editor content automatically. Both local pages currently contain `<!-- wp:global360blocks/page-title-hero /-->`; only the submission page also contains the form shortcode. Transfer these page bodies selectively or recreate them in the editor.
7. On the first WordPress request, core's `init` installer creates `{prefix}360_patient_reviews`, sets `global360_patient_reviews_schema` to `2`, and grants administrators `moderate_patient_reviews`. Reactivation is unnecessary for an already-active plugin. Do not preseed the schema-version option before the table exists. Verify the table and Patient Reviews menu; grant `moderate_patient_reviews` explicitly to any other authorized staff roles.
8. If staging previously ran the obsolete clinic-specific implementation, remove its old code, run `wp option delete global360_review_form_route_version` if present, and `wp rewrite flush` once. Otherwise there are no new rewrite rules. Clear existing page caches after installing. Verify `/clinics/{slug}/review-form/` returns 404. WP Fastest Cache is handled by the integration; exclude `patient-reviews` from any additional reverse-proxy/CDN full-page cache as well.
9. Use published clinics/doctors and the existing core relationship records. Verify one submission, pending/private storage, approval visibility, Hide removal, and notification configuration on staging.

CF7 Form tab:

```text
<label> Clinic
[select* clinic-id "Select a clinic"]
</label>
<label> Doctor
[select* review-doctor "Select a clinic first"]
</label>
<fieldset><legend>Your rating</legend>
[radio review-rating use_label_element "1 - Poor" "2 - Fair" "3 - Good" "4 - Very good" "5 - Excellent"]
</fieldset>
<label> Name shown publicly
[text* review-display-name placeholder "First name and last initial"]
</label>
<label> Your email
[email* review-email autocomplete:email]
</label>
<p>Your email is for verification only and will never be displayed publicly.</p>
<label> Your review
[textarea* review-message placeholder "Tell us about your experience with the clinic or doctor."]
</label>
<p>Please avoid including diagnoses, treatment details, or other private medical information.</p>
[acceptance review-consent]
I agree that my review, rating, and display name may be published on this website. My email address will remain private.
[/acceptance]
[submit "Submit Review"]
```

No database export is included. CF7 form markup/messages/mail settings, page content/heroes, and staff capability assignments must be recreated or selectively transferred. Do not transfer local test reviews or replace the staging database. The new table remains separate from existing manual/API review metadata; no migration of those reviews occurs.

Public card links use `get_permalink()` on the stored IDs only for published, non-password-protected, publicly viewable records. Missing permalinks retain plain labels. Clinic overall has no doctor link. Existing visibility rules still exclude reviews with unavailable clinics and replace unavailable doctor names with a plain fallback. The admin pagination deprecation fix normalizes `paginate_links()`'s optional null result to an empty string before `wp_kses_post()`.

Detection uses one shared `ContactFormIntegration::matches()` method for tags, form classes, scripts, browser schema, validation, storage, and notifications. It scans actual registered CF7 tags (escaped pseudo-tags and plain text do not count), requires all seven names, and caches by markup within the request. The scanner is isolated to avoid recursive tag-filter side effects. The theme listens to `global360_review_form_rendering` for scoped form styles on any page; public-listing styles still load on `patient-reviews`. Other forms receive no review classes, scripts, validation, or storage behavior. Run `tests/review-form-signature.php` to verify two distinct form IDs plus ordinary and incomplete forms.

## Trash and permanent deletion (Core 1.2.0)

Reviews use a custom private table, so WordPress post-trash functions and automatic trash cleanup do not apply. Schema version 2 adds `previous_status` on `init` without changing existing review content. No reactivation or manual migration is required.

Authorized staff with `moderate_patient_reviews` can Move to Trash from review details or list rows. The default list excludes Trash; its Trash view offers Restore and Delete Permanently. Restore reinstates the previous pending/approved/rejected/hidden status, including public visibility for approved reviews. Content, rating, consent, and submission date remain read-only. Trash and restore record the acting moderator and timestamp. Trashed reviews cannot be approved directly; restore first.

All mutations require POST, capability checks, and a review nonce; trash/restore/delete use action-specific nonces. Permanent deletion is available only from Trash and requires a separate confirmation screen with an explicitly checked confirmation box verified server-side. It deletes the whole private row, including its duplicate fingerprint; a later identical submission can therefore create a new pending review. There is no bulk or scheduled permanent deletion. Successful lifecycle changes fire the existing moderation cache-invalidation event, and approved-only public selection excludes Trash immediately.

Run `tests/review-trash.php` locally for fixture-only checks of all restored states, approved visibility, cache invalidation, immutable fields, permanent confirmation, nonce/action isolation, and unauthorized requests. Real reviews must never be used as deletion test fixtures.

## Human-readable notification mail tags (Core 1.2.1)

In CF7's **Mail** tab (and Mail (2), if used), replace `[clinic-id]` and `[review-doctor]` where you want names with `[review-clinic-name]` and `[review-doctor-name]`. These work in both Subject and Message body. Example:

```text
Subject: Patient review: [review-clinic-name] — [review-doctor-name]

Clinic: [review-clinic-name]
Doctor: [review-doctor-name]
Rating: [review-rating]
Display name: [review-display-name]
Review: [review-message]
```

Do not add hidden name fields to the Form tab. Keep `clinic-id` and `review-doctor` unchanged as the actual form fields; their ID values continue to drive validation and storage. The new tags resolve through the existing core clinic/doctor repositories from the saved, server-validated IDs. Clinic-wide reviews render exactly `Clinic overall / No specific doctor`. Names supplied by the browser are never used; HTML mail names are escaped. Replacement runs only for forms matching the complete patient-review field signature, without any configured form ID. Missing records yield empty name values.

Installing the plugin adds tag support but deliberately does not rewrite existing CF7 mail templates or notification recipients in the database. Make the Mail-tab replacements separately on each site. Existing ID mail tags remain available if desired for internal reference. Run `tests/review-mail-names.php` locally to verify plain/HTML subjects and bodies, doctor/overall cases, forged names, invalid IDs, and unrelated-form isolation; all test mail is intercepted.
