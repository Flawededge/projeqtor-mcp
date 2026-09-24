# Changelog

All notable changes are documented here. Versions follow Semantic Versioning.

## [2.0.0-beta.1] - Unreleased

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
