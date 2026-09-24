# Tool contract

This document describes the `2.0.0-beta.1` import-foundation interface.

## Discovery

- `projeqtor_get_capabilities` returns supported classes, operations, limits, relationship codes, and units.
- `projeqtor_get_object_schema` reads the installed ProjeQtOr model metadata. It reports root and nested planning fields, types, formats, native required flags, defaults, writability, references, and the source model.
- `projeqtor_list_reference_values` exposes calendars, main functions, teams, profiles, milestone/activity/project/ticket types, statuses, and planning modes.

Schema metadata is user-contextual and version-specific. Call it before constructing migration payloads; do not copy required-field assumptions between ProjeQtOr versions.

## Filtered pagination

`projeqtor_list_items` accepts exact-match `filters`, an ISO-8601 `modifiedSince`, `pageSize` from 1 to 200, and an opaque `cursor`. It returns `total`, `returned`, `hasMore`, and `nextCursor`.

```json
{
  "objectClass": "Activity",
  "fields": ["name", "idProject", "externalReference"],
  "filters": { "idProject": 2 },
  "pageSize": 100
}
```

A cursor is bound to its class, filters, and modified-since value. Reusing it with a different query returns `invalid_cursor`.

ProjeQtOr 13.1 does not provide native limit/offset parameters in its REST list API. The MCP therefore retrieves the authenticated user's accessible list, filters and sorts by numeric ID, then emits stable pages. This removes the old 200-object MCP ceiling, but it is application-level pagination rather than database-level pagination.

## Dependencies

Dedicated tools list, create, update, and delete dependency links between activities and milestones. Friendly relationships map to ProjeQtOr codes:

| MCP value | ProjeQtOr code |
| --- | --- |
| `finish_to_start` | `E-S` |
| `finish_to_finish` | `E-E` |
| `start_to_start` | `S-S` |

`lagDays` is an integer from -999 to 999 and is measured in working days. Dependency deletion removes only the link; it never deletes either endpoint.

## Idempotent batch upsert

`projeqtor_batch_upsert` accepts up to 50 items. Every item needs a unique `localKey` and either a `migrationKey` or explicit `match` fields. A migration key is written to `externalReference`; when `idProject` is present, matching is automatically scoped to that project.

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

Validation-only mode performs no create or update calls. It reports `missingFields`, `invalidFields`, `referenceErrors`, and references that cannot be independently verified. A non-validation batch returns `created`, `updated`, `existing`, `invalid`, or `error` for every item; successful earlier items are not rolled back when a later item fails.

## Write results and errors

Write results include the object ID, requested/applied fields, and the refreshed saved object. Errors use a stable `code` and plain-text `message`; ProjeQtOr's UI-oriented HTML is removed. Schema validation separately reports missing, invalid, and inaccessible reference fields.

There is no generic delete tool. The only destructive operation in this beta is the explicitly annotated dependency-link deletion tool.
