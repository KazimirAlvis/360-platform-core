# Data Contract

Clinic views contain `wp_id`, `permalink`, `status`, `external_organization_id`, `name`, `bio`, `phone`, `website`, `assessment_id`, normalized `addresses`, `city`, `state_codes`, `coordinates`, `logo_attachment_id`, `clinic_info`, `reviews`, `doctor_ids`, `source_timestamp`, `source`, and `is_temporary`.

Doctor views contain `wp_id`, `permalink`, `status`, `external_doctor_id`, `external_slug`, `name`, `title`, `bio`, `photo_attachment_id`, `clinic_ids`, derived `locations`, `source_timestamp`, `source`, and `is_temporary`.

The public views intentionally contain no `_360_*`, `clinic_*`, or `_cpt360_*` compatibility aliases. `LegacyMetaAdapter` resolves those internally. Doctor locations are derived from linked Clinic records, not copied doctor meta.

## Compatibility aliases read during Stage 2

- Clinic identity: `_360_organization_id`, `organization_id`, `clinic_organization_id`.
- Clinic contact/content: `_360_phone`, `clinic_phone`, `_cpt360_clinic_phone`, `_360_website_url`, `clinic_website`, `_clinic_website_url`, `clinic_bio`, `_cpt360_clinic_bio`.
- Clinic geography: `clinic_addresses`, `_360_addresses`, `clinic_states`, `_360_states`, `_cpt360_clinic_state`, and the historical scalar city/state/zip/lat/lng variants.
- Clinic media/content: `_clinic_logo_id`, `clinic_logo`, `_360_clinic_logo_id`, `clinic_info`, `_360_clinic_info`, `clinic_reviews`, `_360_reviews`.
- Doctor identity/content: `_360_doctor_id`, `doctor_id`, `_360_doctor_slug`, `doctor_slug`, `_360_title`, `doctor_title`, `doctor_name`, `doctor_bio`.
- Doctor media/relationships: `_doctor_photo_id`, `doctor_photo`, `_360_doctor_photo_id`, `clinic_id`, `_360_clinic_post_id`, `clinic_post_id`.

These remain storage compatibility details and are not returned as public keys.

State normalization accepts postal codes, full names, and state slugs. The full `all()` registry includes the 50 states and District of Columbia (`DC`), including `district-of-columbia` and `washington-dc` slugs. Public 50-state directory consumers use `states_only()`, which excludes DC without changing normalization or stored Clinic data.
