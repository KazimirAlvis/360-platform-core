# Field Ownership

`FieldOwnership` classifies the normalized contracts as `api_managed`, `editorial`, `derived`, or `ai_owned`.

Upstream identifiers, names, API bios, contact details, addresses, source images, clinic/doctor relationships, and source timestamps are API-managed. Normalized state/city/coordinates and doctor locations are derived. `editor_notes` documents the reserved editorial boundary. `ai_extensions` reserves an AI-owned namespace; Stage 2 creates no AI content.

The Theme uses this contract to present API-sourced identity and operational fields as read-only in wp-admin. This does not prevent API Sync from writing them. Manually created records remain editable, and no override fields are introduced in Stage 2.
