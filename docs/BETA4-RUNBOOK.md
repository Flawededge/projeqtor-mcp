# Beta 4 acceptance and rollout runbook

Last verified: 2026-09-28

## Accepted source

- Branch: `feature/v2-beta4-integration`
- Accepted implementation commit: `94f9071`
- Application: ProjeQtOr `13.1.0`
- MCP: `2.0.0-beta.4`
- Scope: disposable fresh-data stack only. The live Beta 3 stack was not changed.

## Verified evidence

The fresh disposable run `b4-1790638145356-c829c0a9` completed full acceptance and was then sanitized and destroyed. Generated credentials and volumes were removed.

- 137 unit tests and 36 contract tests passed.
- JavaScript checks and every bridge, worker, and compiler PHP lint check passed.
- Coverage verified 944 source files, 899 HTTP entrypoints, 337 mutation candidates, and 640 classes with zero unknown and zero deferred surfaces.
- The full suite passed all 12 modules, the 562-task idempotent import and forced cleanup, identities, least privilege, dependencies, assignments, planning/resource leveling, overload diagnostics, snapshots, attachments, reports, cancellation, recovery, artifacts, and private-mail delivery.
- PDF, PNG/JPEG, and structured report artifacts passed signature and bounded-download checks.
- All disposable services were healthy, published no host ports, and the worker remained backend-only.
- The private mail sink was empty after cleanup.
- No incomplete `.tmp-*`, `.render-*`, or `.capture-*` artifacts remained.
- Generated bearer tokens, signing keys, cursor keys, and the database password were absent from container logs; acceptance fixture identifiers were absent from application, MCP, worker, and gateway logs.
- Final MCP image build succeeded as local image `projeqtor-mcp:beta4-final`.

Sanitized evidence is kept only under the ignored `tests/.runtime/` tree. Never commit raw harness output, database contents, mail bodies, credentials, or live backups.

## Live test-instance approval gate

Do not deploy, merge the final PR, or create `v2.0.0-beta.4` without explicit approval. At approval time:

1. Confirm the Beta 3 worker is idle and quiesce test writes.
2. Capture an application-consistent PostgreSQL dump, data/cache archive, Compose/source backups, and checksums.
3. Restore the dump into a disposable stack and complete the restore drill with mail redirected to its private sink.
4. Record the exact running Beta 3 app, MCP, and worker image IDs and create immutable rollback tags.
5. Build the Beta 4 app and MCP images from the reviewed PR commit.
6. Recreate app, MCP, and worker together. Preserve the existing gateway, Headscale hostname `projeqtor`, network isolation, and absence of host ports.
7. Run safe live smoke tests for ChrisD and Peet identity, 36 tools, 13 packs including Core, zero unknown/deferred coverage, actor-attributed reversible writes, HMAC/path binding, worker health, and log redaction.
8. Keep risky HR, configuration, Cron, and outbound-delivery execution in the disposable clone.

## Rollback

Rollback app, MCP, and worker together to the recorded immutable Beta 3 images. Revalidate application and MCP authentication, bridge isolation, worker health, Headscale reachability, port isolation, and redacted logs.

The operation schema additions must remain dormant under Beta 3. Restore the matching database, data, and Compose checkpoint only if image rollback does not restore service. Do not partially mix Beta 3 and Beta 4 images.
