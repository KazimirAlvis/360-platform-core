# Architecture

Platform Core is the stable application/data boundary. The Theme owns presentation and transitional editing UI. API Sync owns upstream transport and canonical writes. Global Blocks remains presentation tooling. Future AI extensions consume Core contracts and store their own namespaced fields.

For API-sourced Clinic and Doctor records, API Sync is the source of truth. Core owns the normalized contract, not source authority. Theme admin controls for API-managed identity and operational fields are informational/read-only; future overrides require separate namespaced fields and an explicit precedence policy.

## Public API

`global360_platform()` returns the service container with `clinics()`, `doctors()`, `locations()`, `states()`, `relationships()`, `site_context()`, `ownership()`, and the internal compatibility accessor `legacy_meta()`.

Core registers `clinic` and `doctor` at `init` priority 5. Meta registration follows at priority 6. Theme fallback registration runs later only when Core is unavailable.

REST visibility intentionally remains `false` in Stage 2. Enabling it would change the current editor/API surface and needs a separately versioned REST schema rather than exposing legacy meta accidentally.

## Extension contract

- `global360_entity_updated` action: entity type, WordPress post ID, change context.
- `global360_sync_completed` action: final sync result.
- `global360_clinic_view` and `global360_doctor_view` filters: normalized entity views.
- `global360_internal_link_candidates` filter: invoked through `internal_link_candidates()` for link-candidate providers.
- `global360_schema_graph` filter: invoked through `schema_graph()` for schema extensions.
