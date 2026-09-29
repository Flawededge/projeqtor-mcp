# Changelog

All notable changes are documented here. Versions follow Semantic Versioning.

## [2.0.0] - 2026-09-28

### Added

- Stable Core plus twelve-module full-control MCP surface with 36 tools and 212 typed actions.
- Public GHCR application and MCP images with OCI source, version, license, SBOM, and provenance metadata.
- A production Docker Compose bundle, hardened gateway, and non-printing first-run secret generator.
- Automatic official ProjeQtOr V13.1.0 database initialization and secure fresh administrator provisioning.

### Changed

- Promoted the accepted `2.0.0-beta.4` runtime and worker protocol to stable `2.0.0`.
- Database, HMAC, cursor, API, administrator, and MCP credentials are supplied as mounted files.

### Validation

- Stable release retains the accepted 137 unit tests, 43 contract tests, complete PHP/JavaScript lint, 562-task import, identity, permission, artifact, resource-leveling, and zero-unknown/deferred coverage evidence.

## [2.0.0-beta.4] - 2026-09-27

### Added

- Core plus twelve independently owned module packs with exact schemas, permissions, transaction policy, retry policy, and native-handler contracts.
- 212 typed canonical actions covering the requested Planning, Ticketing, Scrum, Follow-up, Steering, Financial, Products, HR, Environment, Tools, Reports, and Configuration workflows.
- Five high-frequency convenience tools for planning, work entry, ticket management, sprint management, and native report rendering, bringing the public surface to 36 tools.
- An internal-only disposable PostgreSQL/app/MCP/worker/gateway/mail test harness with isolated temporary credentials and sanitized artifacts.

### Changed

- Coverage now inventories `tool/`, `view/`, `report/`, API, SSO, and bundled-plugin surfaces with token-aware mutation detection and fail-closed runtime-catalog validation.
- Reports render native PDF, image, CSV, and structured artifacts instead of exporting `Report` database rows.
- Administrative reference rebuilding and WBS renumbering run as guarded, recovery-required worker jobs.

### Security

- Session termination requires Administration access or explicit Audit update permission.
- Guarded failures persist structured failed outcomes, and one-shot confirmation actions no longer advertise unsupported idempotency guarantees.
- Job resources enforce canonical actor-owned paths and exact MIME types for JSON, NDJSON, CSV, PDF, PNG, JPEG, ZIP, and XLSX.

### Validation

- The pinned inventory contains 944 PHP files, 899 HTTP entrypoints, 337 mutation candidates, and 640 classes with zero unknown and zero deferred surfaces.
- All 127 unit tests, 10 contract tests, JavaScript checks, PHP lint, and reproducible coverage verification pass.

## [2.0.0-beta.3] - 2026-09-24

### Added

- Token-aware coverage scanning for all 797 pinned ProjeQtOr 13.1 `tool/` and `view/` PHP entrypoints, including 331 mutation candidates.
- Explicit generated manifests for all 640 installed `SqlElement` subclasses and all UI handlers, with source hashes and fail-closed readiness checks.
- `projeqtor_list_ui_handlers` with module, classification, mutation, and text filters plus signed cursor pagination.
- Beta 4 issue ownership for every deferred module handler across planning, ticketing/Scrum, steering/reports, financial/products, and HR/tools/configuration.
- Top-level operation-batch idempotency and actor-scoped action idempotency with body-conflict detection.
- Worker leases, heartbeats, attempt limits, recovery states, safe-job retry, atomic artifact publication, and structured error codes.
- `projeqtor_retry_job` with ownership, current-permission, retry-policy, and attempt-limit enforcement.

### Changed

- Class, UI-handler, query, change-stream, and job cursors are signed and bound to their originating query.
- Change streams retain one fixed upper watermark across every page.
- Action discovery now publishes module ownership, mapped handlers, result schema, side-effect classification, retry policy, and idempotency behavior.
- Worker health checks validate a fresh heartbeat file instead of only checking the process command line.
- Safe read/export/report/Cron jobs may retry up to three times after an expired lease; interrupted planning, baseline, and import jobs become `recovery_required`.
- Operation logs contain only operation ID, actor, action, duration, and outcome.

### Security

- Readiness fails on unknown, missing, or source-changed installed classes and handlers.
- Idempotency keys are isolated per actor and never permit the same key to bind a different request body.
- Worker artifacts are written to temporary files and atomically renamed; failed and cancelled partial artifacts are removed.

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
