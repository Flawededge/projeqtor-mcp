# Roadmap

## v2 import foundation

The next release series targets reliable migration and schedule verification:

1. Object schema and required-field discovery.
2. Reference-data lookup for calendars, roles, teams, profiles, milestone types, and planning modes.
3. Filtered cursor pagination with explicit totals and limits.
4. Dependency read/write support, including relationship type and lag.
5. Idempotent batch creation with external migration keys and validation-only mode.

Follow-on capabilities include structured validation errors, concurrency protection, team and availability management, allocation/assignment helpers, planning calculation diagnostics, complete project snapshots, reversible import cleanup, and baselines.

Destructive cleanup must remain separate from ordinary write tools and require a previewable import-run scope.
