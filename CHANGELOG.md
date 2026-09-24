# Changelog

All notable changes are documented here. Versions follow Semantic Versioning.

## [2.0.0-beta.2] - 2026-09-24

### Added

- Canonical discovery, query, change-stream, operation, guarded-change, action, job, and resource interfaces while retaining all 13 compatibility tools.
- A versioned policy for all 640 installed `SqlElement` subclasses with startup failure on unknown classes and native per-user authorization layered above policy.
- Database-side filter AST, selected fields, saved filters, totals, validated sorting, and signed keyset cursors with 200-row pages.
- History-aware change windows with deletion tombstones.
- Validated atomic or best-effort create/update/delete batches of up to 200 operations, local references, generalized idempotency, and migration keys.
- `_version` reads and `expectedVersion` conflict protection.
- Actor-bound, expiring, replay-proof previews for destructive and administrative changes.
- Twenty registered semantic actions for copy/transition, planning, baselines, snapshots, import/export/report, attachments, reset mail, cleanup, and Cron.
- A private non-root worker with durable per-user jobs, progress, cancellation, recovery, result artifacts, and automatic retention maintenance.
- Chunked attachment uploads and permission-checked MCP resources for attachments, document versions, and job results.
- Import-run journals with unchanged-object cleanup and a separate forced preview when records were subsequently edited.
- Structured per-item results and errors with secret-field redaction.

### Changed

- Query pagination no longer downloads a complete object class.
- Atomic is the default transaction mode; compatibility writes without a version report `concurrencyUnchecked`.
- Dependency deletion and comparable side effects now require a preview/commit cycle.
- Large snapshots stream NDJSON at a History watermark instead of returning oversized tool responses.
- HMAC validation binds timestamp, actor, method, path, and body digest.

## [2.0.0-beta.1] - 2026-09-24

### Added

- Installed-version object schema and required-field discovery, including nested planning fields.
- Reference lookup for calendars, functions, teams, profiles, planning modes, statuses, and object types.
- Exact filters, modified-since selection, opaque cursors, totals, and pages up to 200 items.
- Dedicated dependency list/create/update/delete tools with working-day lag.
- Idempotent batch upsert with migration keys, explicit matches, local parent references, and validation-only mode.
- Capability discovery, documented units/limits, structured errors, and explicit write results.
- Unit and mocked MCP-level integration tests.

### Changed

- Portable bridge network settings are required rather than embedding deployment-specific addresses.
- Internal HMAC signatures are bound to the API path as well as user, method, timestamp, and body.
- The bridge accepts authenticated DELETE transport only for MCP tools; no generic delete tool is exposed.

## [1.2.0] - 2026-09-24

### Added

- Per-user bearer-token authentication with constant-time digest comparison.
- HMAC-authenticated internal bridge to the ProjeQtOr API.
- Read, list, create, and update tools for allow-listed ProjeQtOr classes.
- Resource calendar and main-function lookup.
- Health endpoint and bounded request sizes.
- Portable environment-driven host, origin, trusted-address, and secret-path configuration.
