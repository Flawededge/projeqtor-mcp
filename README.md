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

Version `2.0.0-beta.3` hardens the policy-controlled ProjeQtOr 13.1 object engine with reproducible coverage inventories, consistent cursors, durable idempotency, and recoverable worker jobs.

- Discovery: identity, capabilities, installed class policy, exact schemas, and reference values.
- Query: database-filtered keyset pagination and History-aware changes with tombstones.
- Mutation: validation and atomic or best-effort operation batches of up to 200 records.
- Guarded changes: actor-bound, expiring previews for deletion, security, configuration, Cron, mail, and comparable side effects.
- Actions: 20 registered workflows covering copy, transitions, snapshots, planning, baselines, import/export/report, attachments, reset mail, cleanup, and Cron.
- Jobs: durable per-user queue, progress, cooperative cancellation, and expiring result artifacts.
- Resources: permission-checked attachments, document versions, and job results.

All 13 beta.1 tools remain as compatibility wrappers, for a total of 31 tools. Every one of the 640 installed `SqlElement` subclasses and all 797 installed PHP entrypoints are classified; unknown or source-changed classes/handlers fail readiness, and the caller's native ProjeQtOr rights are applied above repository policy. The 310 module handlers deferred to Beta 4 are visible through `projeqtor_list_ui_handlers` and linked to milestone issues.

See [docs/TOOLS.md](docs/TOOLS.md) for inputs, limits, units, examples, and pagination behavior.

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

## Security

See [SECURITY.md](SECURITY.md). This repository intentionally contains no deployment secrets or host-specific addresses.
