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

## Current tools

- `projeqtor_get_capabilities`
- `projeqtor_get_object_schema`
- `projeqtor_get_item`
- `projeqtor_list_items`
- `projeqtor_list_reference_values`
- `projeqtor_list_resource_choices`
- `projeqtor_list_dependencies`
- `projeqtor_create_dependency`
- `projeqtor_update_dependency`
- `projeqtor_delete_dependency`
- `projeqtor_create_item`
- `projeqtor_update_item`
- `projeqtor_batch_upsert`

Version 2.0.0-beta.1 adds installed-version schema discovery, filtered cursor pagination, reference-data lookup, dependency CRUD, structured errors, and validation-only/idempotent batch upserts. Existing v1 tool names and inputs remain available; write responses now use the structured v2 result format. There is intentionally no generic delete tool.

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

Copy `bridge/index.php`, `bridge/schema.php`, and `bridge/.htaccess` into a dedicated `mcp-api` path inside the ProjeQtOr web root.

| Setting | Required | Purpose |
| --- | --- | --- |
| `PROJEQTOR_MCP_TRUSTED_IP` | Yes | Exact source address assigned to the MCP container on the private Docker network. |
| `PROJEQTOR_MCP_SIGNING_KEY_FILE` | No | Signing-key path; defaults to `/run/secrets/projeqtor-mcp-signing-key`. |

Keep the bridge private: do not publish its path or port outside the application network.

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
