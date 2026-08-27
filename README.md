# 360 Platform Core

Local Stage 2 foundation for the shared Global 360 application/data layer. It owns Clinic and Doctor post-type registration and exposes normalized read APIs over existing WordPress posts, meta, relationships, geography, and `360_global_settings`.

```php
$platform = global360_platform();
$clinic   = $platform->clinics()->get( $clinic_id );
$doctor   = $platform->doctors()->get( $doctor_id );
$states   = $platform->states()->all();        // 50 states plus DC for normalization/data.
$directory_states = $platform->states()->states_only(); // 50-state public directories.
```

Stage 2 is compatibility-first: no posts are recreated, no legacy meta is deleted, and the Theme retains a deprecated CPT fallback when Core is inactive.

## Releases and updates

The current release is `1.0.2`. WordPress reads update metadata from `plugin-manifest.json` on the GitHub `main` branch of `KazimirAlvis/360-platform-core`. The manifest points to the `main` branch ZIP, and the updater normalizes GitHub's extracted folder name back to `360-platform-core` so the installed path remains `/wp-content/plugins/360-platform-core/`.

Release workflow: update the authoritative `GLOBAL360_PLATFORM_VERSION` constant and synchronize the plugin header and manifest metadata, validate the `main` package, then publish `main`. Production installations never track a feature branch.

See `docs/ARCHITECTURE.md`, `docs/DATA-CONTRACT.md`, `docs/FIELD-OWNERSHIP.md`, and `docs/MIGRATION.md`.
