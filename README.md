# 360 Platform Core

Local Stage 2 foundation for the shared Global 360 application/data layer. It owns Clinic and Doctor post-type registration and exposes normalized read APIs over existing WordPress posts, meta, relationships, geography, and `360_global_settings`.

```php
$platform = global360_platform();
$clinic   = $platform->clinics()->get( $clinic_id );
$doctor   = $platform->doctors()->get( $doctor_id );
$states   = $platform->states()->all();
```

Stage 2 is compatibility-first: no posts are recreated, no legacy meta is deleted, and the Theme retains a deprecated CPT fallback when Core is inactive.

See `docs/ARCHITECTURE.md`, `docs/DATA-CONTRACT.md`, `docs/FIELD-OWNERSHIP.md`, and `docs/MIGRATION.md`.
