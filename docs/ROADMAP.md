# Roadmap

## Implemented in 2.0.0-beta.3

- Source-hashed policy manifests classify all 640 installed classes and 797 UI entrypoints, including all 331 mutation candidates.
- CI regenerates both manifests from the checksum-pinned ProjeQtOr 13.1 source and fails on drift.
- Every `deferred_beta4` handler links to one of the five Beta 4 milestone issues.
- Query-bound signed cursors cover class, handler, object, change-stream, and job lists; change streams retain a fixed watermark.
- Canonical batches and actions enforce actor-scoped, body-bound idempotency.
- Durable jobs use leases, heartbeats, attempt limits, safe retry, `recovery_required`, and atomic artifact publication.
- The worker remains non-root, capability-free, backend-only, and without published ports.
- All 13 compatibility tools remain available alongside the 18 canonical tools.

## Implemented in 2.0.0-beta.4

- Core and twelve deterministic module packs own 212 typed actions without expanding beyond the planned 36-tool surface.
- Token-aware coverage now inventories 944 PHP files, 899 HTTP entrypoints, 337 mutation candidates, and all 640 installed classes.
- Every installed surface is classified with zero unknown and zero deferred handlers.
- Native report rendering, guarded administration, canonical job artifacts, and the internal-only disposable acceptance harness are included.

## Completed module milestone

Beta 4 closes the former 310 visible deferrals grouped in the [2.0.0-beta.4 milestone](https://github.com/Flawededge/projeqtor-mcp/milestone/1):

1. [Planning, Follow-up and Environment](https://github.com/Flawededge/projeqtor-mcp/issues/2) — work submission/validation, calendars, leave, capacity, and resource actions.
2. [Ticketing and Scrum](https://github.com/Flawededge/projeqtor-mcp/issues/3) — SLA/escalation, backlog ordering, sprint lifecycle, points, and agile calculations.
3. [Steering and Reports](https://github.com/Flawededge/projeqtor-mcp/issues/4) — KPI calculation, dashboards, approvals, rendered reports, PDF/chart output, and scheduled delivery.
4. [Financial and Products](https://github.com/Flawededge/projeqtor-mcp/issues/5) — expenses, billing, procurement, budgets, product/version lifecycle, composition, and compatibility.
5. [HR, Tools and Configuration](https://github.com/Flawededge/projeqtor-mcp/issues/6) — HR/absence/skills, notifications, mail, automation, cloning, profiles, access rules, workflows, modules, and parameters.

The listed key modules now have zero deferred handlers. Risky HR, configuration, and outbound-delivery acceptance remains confined to the disposable environment unless separately approved for the live test instance.

## Remaining before stable

- Run representative disposable-database contracts for every registered action category and least-privilege path.
- Add multi-worker contention and restart/recovery stress coverage.
- Re-run the 562-task migration, dependency, snapshot, planning, identity, and isolation fixtures for each release candidate.
- Regenerate and review both policy manifests before supporting any later ProjeQtOr version.

Raw SQL, password/API-key or secret disclosure/direct setting, plugin/subscription installation, and host/container/database/backup administration remain intentional exclusions.
