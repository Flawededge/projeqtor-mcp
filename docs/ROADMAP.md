# Roadmap

## v2 import foundation

Implemented in `2.0.0-beta.1`:

- Object schema and required-field discovery.
- Reference-data lookup for calendars, roles, teams, profiles, milestone types, and planning modes.
- Filtered cursor pagination with explicit totals and limits.
- Dependency read/write/delete support, including relationship type and working-day lag.
- Idempotent batch creation/update with migration keys, local references, per-item results, and validation-only mode.
- Structured errors, capability discovery, and documented limits/units.

Next:

- Concurrency protection.
- Team and availability management.
- Allocation/assignment helpers.
- Planning calculation diagnostics and complete project snapshots.
- Reversible import cleanup and baselines.

Destructive cleanup must remain separate from ordinary write tools and require a previewable import-run scope.
