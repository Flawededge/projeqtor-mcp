# ProjeQtOr MCP

A self-hosted, per-user Model Context Protocol server for ProjeQtOr. It exposes a deliberately allow-listed set of read and write operations while preserving each user's ProjeQtOr permissions and audit identity.

## Architecture

```text
MCP client
  |  Bearer token identifies one configured user
  v
Node MCP server
  |  HMAC-signed internal request carrying that username
  v
ProjeQtOr bridge
  |  ProjeQtOr API call under the mapped user's identity
  v
ProjeQtOr
```

The bearer token is stored only as a SHA-256 digest. The MCP server and bridge share a separate signing key through mounted secret files. The bridge also accepts requests from one explicitly configured MCP container address.

## Current interface

Version `2.0.1` splits full-control coverage into Core plus twelve independently owned module packs for ProjeQtOr 13.1.

- Discovery: identity, capabilities, module/action ownership, installed class policy, exact schemas, reference values, and native-handler coverage.
- Query: database-filtered keyset pagination and History-aware changes with tombstones.
- Mutation: validation and atomic or best-effort operation batches of up to 200 records.
- Guarded changes: actor-bound, expiring previews for deletion, security, configuration, Cron, mail, and comparable side effects.
- Actions: 212 typed workflows across Planning, Ticketing, Scrum, Follow-up, Steering, Financial, Products, HR, Environment, Tools, Reports, Configuration, and Core.
- Jobs: durable per-user queue, progress, cooperative cancellation, and expiring result artifacts.
- Resources: permission-checked attachments, document versions, and typed job results including PDF, image, CSV, XLSX, ZIP, JSON, and NDJSON artifacts.

All 31 Beta 3 tools remain available and five typed convenience tools bring the public surface to 36. The pinned inventory contains 944 PHP files, 899 HTTP entrypoints, 337 mutation candidates, and 640 installed `SqlElement` subclasses with zero unknown and zero deferred surfaces. Unknown or source-changed classes/handlers fail readiness, and the caller's native ProjeQtOr rights are applied above repository policy.

See [docs/TOOLS.md](docs/TOOLS.md) for inputs, limits, units, examples, and pagination behavior.

## Container hosting

The stable release publishes two public Linux/AMD64 images:

- `ghcr.io/flawededge/projeqtor-mcp-app:2.0.1` — ProjeQtOr 13.1, bridge, initializer, and worker runtime.
- `ghcr.io/flawededge/projeqtor-mcp:2.0.1` — non-root MCP HTTP server.

The worker reuses the application image. PostgreSQL and the gateway use their official upstream images. For a fresh local deployment:

```bash
git clone https://github.com/Flawededge/projeqtor-mcp.git
cd projeqtor-mcp
scripts/setup-host.sh deployment
docker compose --env-file deployment/.env -f deployment/compose.yaml up -d
```

The setup command creates new credentials in a host-root-only directory without printing them. The initial ProjeQtOr administrator password is stored at `deployment/secrets/admin-password`; that administrator's MCP bearer token is stored separately at `deployment/secrets/admin-mcp-token`.

The default endpoints are:

```text
http://127.0.0.1:8080/       ProjeQtOr
http://127.0.0.1:3000/mcp    MCP Streamable HTTP
```

The Compose bundle binds to loopback by default. Put it behind an authenticated TLS reverse proxy or a private tailnet before changing the bind address. App, MCP, and worker image versions must be upgraded together.

## Server configuration

| Setting | Required | Purpose |
| --- | --- | --- |
| `PROJEQTOR_API_URL` | No | Internal bridge URL; defaults to `http://app/mcp-api`. |
| `PROJEQTOR_SIGNING_KEY_FILE` | Yes | File containing the shared HMAC signing key. |
| `MCP_USERS_FILE` | Yes | JSON file containing usernames and bearer-token hashes. |
| `MCP_ALLOWED_HOSTS` | No | Comma-separated accepted HTTP Host values. |
| `MCP_ALLOWED_ORIGINS` | No | Comma-separated accepted origins/hosts. |
| `PORT` | No | Listener port; defaults to `3000`. |

The principal file format is:

```json
{
  "version": 1,
  "users": [
    {
      "username": "example-user",
      "tokenSha256": "64-lowercase-hex-characters"
    }
  ]
}
```

Generate a strong bearer token with a cryptographically secure password manager, then store only its SHA-256 digest in this file. Never commit the token, digest file, signing key, or a credential-bearing URL.

## Bridge configuration

Copy the complete `bridge/` directory into a dedicated `mcp-api` path inside the ProjeQtOr web root. Install `worker/` in the application image when queued actions are enabled.

| Setting | Required | Purpose |
| --- | --- | --- |
| `PROJEQTOR_MCP_TRUSTED_IP` | Yes | Exact source address assigned to the MCP container on the private Docker network. |
| `PROJEQTOR_MCP_SIGNING_KEY_FILE` | No | Signing-key path; defaults to `/run/secrets/projeqtor-mcp-signing-key`. |

Keep the bridge private: do not publish its path or port outside the application network.

The worker must have only the private database network, no published port, the same application/data view as ProjeQtOr, and a non-root runtime identity. It stores sanitized operation metadata in the additive `McpOperation` table; leases and heartbeats recover safe jobs without replaying mutating jobs, result artifacts default to seven-day retention, and audit rows to 30 days.

## Development

```bash
cd server
npm ci
npm run check
npm test
```

Releases are cut from `main` using semantic-version tags. New capabilities are developed on focused branches and merged only after validation. See [CONTRIBUTING.md](CONTRIBUTING.md), [docs/TOOLS.md](docs/TOOLS.md), and [docs/ROADMAP.md](docs/ROADMAP.md).

Beta 4 acceptance evidence, the live approval gate, and coordinated rollback steps are recorded in [docs/BETA4-RUNBOOK.md](docs/BETA4-RUNBOOK.md).

## License and upstream attribution

ProjeQtOr MCP is licensed under the [GNU Affero General Public License, version 3 or later](LICENSE) (`AGPL-3.0-or-later`). If you modify this software and let users interact with the modified version over a network, AGPL section 13 requires offering those users its corresponding source.

This is an independently developed integration, not an official ProjeQtOr product. ProjeQtOr is the original upstream product, copyright 2009-2026 Pascal BERNARD / PROJEQTOR, and is also distributed under AGPL v3 or later. See [NOTICE](NOTICE) and the [official ProjeQtOr license](https://www.projeqtor.com/en/copyright_en/) for attribution and details.

The corresponding source for this integration, including its container build and deployment scripts, is available at [github.com/Flawededge/projeqtor-mcp](https://github.com/Flawededge/projeqtor-mcp).

## Security

See [SECURITY.md](SECURITY.md). This repository intentionally contains no deployment secrets or host-specific addresses.
