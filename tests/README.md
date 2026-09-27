# Beta 4 disposable test harness

This directory contains an isolated ProjeQtOr 13.1 test stack and the support
code used by unit, contract, module, live-smoke, and full-acceptance suites.
It is deliberately separate from the production deployment definition.

## Safety properties

- Compose publishes no host ports and creates no Tailscale service.
- PostgreSQL, the application, worker, and mail sink are on an internal-only
  backend network. Only the gateway, MCP server, and test runner can join the
  internal frontend network.
- Every invocation gets a random project name, database password, bridge
  signing key, bearer tokens, and run ID. Credentials are held in a mode-0700
  system temporary directory outside the repository and Docker build context.
- Sanitized artifacts under `tests/.runtime/` are ignored by Git. The artifact
  writer removes credentials, authorization headers, signatures, cookies,
  mail addresses, and payload-shaped object data.
- The harness refuses live mode unless `PROJEQTOR_LIVE_TESTS=1` is explicitly
  set. Live mode never starts or modifies Compose services.

## Commands

Run these from `server/`:

```text
npm run test:unit
npm run test:contract
npm run test:integration
npm run test:module -- --module planning
npm run test:live
npm run test:acceptance
```

`npm run harness:prepare` creates a disposable environment and prints only its
non-secret run ID and paths. `npm run harness:config` renders and validates the
Compose model. `npm run harness:up` starts a fresh-data stack; `harness:down`
removes its containers, volumes, and generated credential directory.

Fresh-data mode (`BETA4_SEED_MODE=fresh`, the default) uses an empty PostgreSQL
volume and lets the pinned application perform its normal first-run database
initialization. Restore-seed mode is for a separately approved restore drill:

```text
BETA4_SEED_MODE=restore \
BETA4_SEED_DUMP=/absolute/path/to/application-consistent.dump \
npm run harness:up
```

The dump path must be absolute, readable, outside the repository, and contain
no connection string. It is mounted read-only into a one-shot restore service.
The harness does not copy the dump, database contents, or credentials into the
repository or artifacts. Restore mode must be used only with a sanitized test
backup and the stack's internal mail sink.

Module suites are discovered from `tests/modules/*.module.mjs`. A module file
declares its availability probe, exclusive resource locks, fixtures, cleanup,
and test cases. Missing actions are reported as `pending`, so the shared matrix
can land before module implementations while still failing on malformed module
contracts.
