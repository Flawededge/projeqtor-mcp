# Tool contract

This document describes the `2.0.0` modular full-control interface for ProjeQtOr 13.1.

## Discovery

- `projeqtor_whoami` returns the mapped ProjeQtOr identity and native access context without returning credentials.
- `projeqtor_get_capabilities` returns versions, limits, policy/action inventory, resources, units, and compatibility information.
- `projeqtor_list_object_classes` pages through the installed policy manifest and reports effective per-user operations or a precise denial reason.
- `projeqtor_get_object_schema` returns exact database types, length/precision, nullability, defaults, references, units, sensitivity, field ownership, effective operations, and object concurrency versions.
- `projeqtor_list_ui_handlers` exposes the complete source-hashed handler inventory, coverage classification, mapped action/class, availability, and Beta 4 issue.
- `projeqtor_list_reference_values` exposes permitted reference/type records through the canonical query engine.

All 640 installed `SqlElement` subclasses and 899 HTTP entrypoints from 944 inventoried PHP files are classified at startup, including 337 mutation candidates. Readiness fails if a class or handler is unknown, missing, or source-changed. The policy denies secrets and internal persistence models, then applies the caller's native rights. Schema metadata is user-contextual and version-specific; call it before constructing writes.

## Filtered pagination

`projeqtor_query_items` accepts a validated filter tree (`eq`, `ne`, comparisons, `in`, string matching, null tests, and boolean groups), selected fields, one validated sort, `pageSize` from 1 to 200, optional totals, saved-filter IDs, and an opaque signed keyset cursor. The compatibility `projeqtor_list_items` wrapper maps exact filters into this engine.

```json
{
  "objectClass": "Activity",
  "fields": ["name", "idProject", "externalReference"],
  "filter": { "field": "idProject", "operator": "eq", "value": 2 },
  "pageSize": 100
}
```

A cursor is signed and bound to its class, filter, fields, and sort. Reusing it for another query returns `cursor_query_mismatch`; tampering returns `invalid_cursor`. Class, handler, change-stream, and job lists use the same signed, query-bound behavior.

Filtering, access restrictions, sorting, and keyset pagination execute in PHP/database queries; the MCP never downloads a complete class merely to page it. `projeqtor_get_changes` uses History-aware time windows, fixes `watermarkUntil` on the first page, binds later cursors to that watermark, and includes deletion tombstones without exposing inaccessible classes.

## Dependencies

Dedicated tools list, create, update, and delete dependency links between activities and milestones. Friendly relationships map to ProjeQtOr codes:

| MCP value | ProjeQtOr code |
| --- | --- |
| `finish_to_start` | `E-S` |
| `finish_to_finish` | `E-E` |
| `start_to_start` | `S-S` |

`lagDays` is an integer from -999 to 999 and is measured in working days. Dependency deletion removes only the link; it never deletes either endpoint, and now requires an expiring guarded preview token.

## Validated operation batches

`projeqtor_validate_operations` and `projeqtor_execute_operations` accept up to 200 create, update, or delete operations. Atomic mode is the default; best-effort mode returns an independent result per item. `requestIdempotencyKey` binds one complete canonical batch per actor; a replay returns the original result, while changed arguments return `idempotency_key_conflict`. Per-operation migration keys, local references, and `expectedVersion` concurrency checks remain supported. The compatibility `projeqtor_batch_upsert` wrapper remains available.

Use `{ "$ref": "localKey" }` as a field value to reference the numeric ID returned for an earlier item in the same batch.

```json
{
  "validationOnly": true,
  "items": [
    {
      "localKey": "parent",
      "objectClass": "Activity",
      "migrationKey": "xml:100",
      "data": { "name": "Parent", "idProject": 2 }
    },
    {
      "localKey": "child",
      "objectClass": "Activity",
      "migrationKey": "xml:101",
      "data": {
        "name": "Child",
        "idProject": 2,
        "idActivity": { "$ref": "parent" }
      }
    }
  ]
}
```

Validation performs no writes and reports `missingFields`, `invalidFields`, and `referenceErrors`. Execution reports created, updated, existing, deleted, invalid, or error per item, plus applied, recalculated, rejected, ignored, and saved fields. Atomic failure rolls back the batch; best-effort preserves successful items.

## Write results and errors

Reads include `_version`. Canonical updates should send `expectedVersion`; a mismatch returns `version_conflict` and current metadata without overwriting. Compatibility updates remain accepted but report `concurrencyUnchecked` when no version is supplied. Errors use stable codes and plain text rather than UI HTML.

Deletion, cleanup, security/configuration changes, Cron control, outbound mail, and similar side effects use `projeqtor_prepare_change`/`projeqtor_commit_change` or `projeqtor_prepare_action`/`projeqtor_commit_action`. Tokens expire after five minutes, bind actor/action/arguments/versions, are revalidated at commit, and cannot be replayed.

## Semantic actions

Use `projeqtor_list_actions` and `projeqtor_get_action_schema` before calling an action. Beta 4 registers 212 typed workflows in Core plus twelve module packs and retains generic CRUD for policy-permitted classes. Five typed convenience tools cover project planning, work entry, ticket management, sprint management, and report rendering without creating alternate workflow logic. Secret setting/disclosure, plugin installation, raw SQL, and host/container/database administration remain excluded.

Planning, imports, exports, reports, and large snapshots run as durable jobs under the originating user's identity. Use `projeqtor_list_jobs`, `projeqtor_get_job`, `projeqtor_cancel_job`, and `projeqtor_retry_job`. Leases and heartbeats allow read-only snapshot/export/report jobs and safe Cron operations to retry up to three attempts. Interrupted planning, baseline, and import work becomes `recovery_required` and is never replayed automatically. Explicit retry rechecks ownership, current permissions, safe policy, and attempt limits.

## Resources and retention

Permission-checked bytes are available at:

- `projeqtor://attachments/{id}`
- `projeqtor://document-versions/{id}`
- `projeqtor://jobs/{id}/result`

Uploads use bounded 512 KiB chunks, a one-hour session expiry, the configured attachment limit, filename checks, and ProjeQtOr's evil-file validation. Job artifacts default to seven-day retention; sanitized operation metadata defaults to 30 days. Large snapshots are NDJSON with a History watermark.
