# Migration

Stage 2 moves registration ownership, not stored data. Activation registers existing `clinic` and `doctor` post types with their exact previous slugs and arguments, records the Core version, and flushes rewrites once. Deactivation flushes once so the Theme fallback can take over.

No post IDs, slugs, meta, relationships, or settings are migrated. API Sync continues compatibility writes. The Theme keeps deprecated fallback registration and meta registration while Core is absent.

Stage 2B should convert more Theme/Blocks reads to repositories, define a stable REST representation, eliminate copied doctor location meta, optimize relationship indexing, and plan legacy-alias retirement only after all consumers are measured.
