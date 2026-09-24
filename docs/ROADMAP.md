# Roadmap

## v2 full-control beta

Implemented in `2.0.0-beta.2`:

- Complete installed-class classification with native permission enforcement and exact schema discovery.
- Efficient filter-AST queries, signed keyset pagination, and History-aware deltas.
- Validated generic CRUD, 200-operation atomic/best-effort batches, idempotency, and optimistic concurrency.
- Guarded destructive/administrative changes with expiring replay-proof tokens.
- Dependency, planning, baseline, snapshot, import/export/report, attachment, cleanup, user-reset, and Cron actions.
- Durable per-user worker jobs, cancellation, NDJSON artifacts, and retention maintenance.
- Permission-checked attachment, document-version, and job-result resources.
- Compatibility preservation for all beta.1 tool names.

## Remaining before stable

The generic engine makes policy-permitted business, relationship, reference, and administrative classes controllable now. Stable v2 still requires additional dedicated semantic adapters where ProjeQtOr's UI handlers perform logic beyond `SqlElement::save()`:

- Complete the machine-generated mutating UI-handler inventory and add a registered action or precise exclusion for every handler.
- Add dedicated helpers for work-period submission/validation and module-specific approvals.
- Expand semantic coverage for financial/procurement, HR, agile/voting, asset/localization, notification/mail, and automation workflows enabled on an instance.
- Add disposable-database contract fixtures for every action category and least-privilege policy path.
- Add multi-worker contention and restart/recovery stress tests.
- Validate future ProjeQtOr versions by regenerating the class/handler manifests before declaring compatibility.

Raw SQL, password/API-key or secret disclosure/direct setting, plugin/subscription installation, and host/container/database/backup administration remain intentional exclusions.
