# Microsoft OAuth for Claude Cloud and ChatGPT

This guide applies to ProjeQtOr MCP v2.1.0. It authenticates only the remote MCP endpoint. It does not add Microsoft login to the ProjeQtOr web interface or change the private Headscale routes.

## Trust boundaries

- Private MCP: `/mcp` accepts only the existing locally generated bearer tokens.
- OAuth MCP: `/mcp/oauth` accepts only Keycloak access tokens.
- Public MCP: `https://conceptpower.ddns.net/mcp_projeqtor` must proxy only to `/mcp/oauth`.
- Protected-resource metadata: `https://conceptpower.ddns.net/.well-known/oauth-protected-resource/mcp_projeqtor`.
- Public authorization server: `https://conceptpower.ddns.net/projeqtor-auth/realms/projeqtor`.
- Keycloak administration, the master realm, and client-registration endpoints must not be exposed by the public proxy.

An eligible user has one Microsoft account whose normalized UPN ends in either `@hikoterra.com` or `@pcnzl.com`. The user does not need an identity in both domains. The MCP repeats this check for every access token even after Entra and Keycloak have authenticated the user.

## Microsoft Entra registration

Create a single-tenant Web registration:

- Name: `ProjeQtOr MCP Microsoft Login`
- Supported account type: accounts in this organizational directory only
- Redirect URI: `https://conceptpower.ddns.net/projeqtor-auth/realms/projeqtor/broker/microsoft/endpoint`
- Authorization-code flow enabled
- Implicit grants disabled
- OIDC scopes `openid`, `profile`, and `email`
- Delegated `User.Read`

Copy the non-secret tenant and application client IDs into `deployment/.env`. Store the client secret without putting it in a command argument:

```bash
scripts/configure-oauth-secret.sh deployment
```

The helper prompts without echo and writes only `deployment/secrets/entra-client-secret`. Do not paste the secret into Compose, Git, chat, or logs.

In the Enterprise Application, enable assignment required. Prefer one `ProjeQtOr Users` security group containing the approved users from either domain. If group assignment is unavailable, assign users individually. Do not assign guests, personal Microsoft accounts, users from other tenants, or users outside both allowed domains.

## Enable the OAuth profile

Set these non-secret values in `deployment/.env`:

```dotenv
COMPOSE_PROFILES=oauth
MCP_OAUTH_ENABLED=true
ENTRA_TENANT_ID=00000000-0000-0000-0000-000000000000
ENTRA_CLIENT_ID=00000000-0000-0000-0000-000000000000
CLAUDE_OAUTH_REDIRECT_URI=https://callback-shown-by-claude.example/
```

Replace all example values. The Claude redirect must exactly equal the callback shown by Claude's connector setup.

Start the stack with the OAuth profile. The one-shot `auth-db-init` service creates a separate `keycloak` database and role inside PostgreSQL. Keycloak has no published port and reads database, administrator, Entra, and Claude credentials from mounted files.

## Client configuration

### Claude Cloud

Use Claude's published identity in the connector setup:

- MCP URL: `https://conceptpower.ddns.net/mcp_projeqtor`
- Authentication: `Sign in now`
- OAuth client: `Use Claude's published identity`
- Transport: `Streamable HTTP`
- Authorization server: discovered from the MCP protected-resource metadata
- Scopes: `projeqtor:read projeqtor:write`

Keycloak's experimental CIMD support is enabled and constrained to HTTPS metadata and callbacks on `claude.ai`. Dynamic client registration remains blocked publicly. Claude currently publishes the confidential-only `urn:ietf:params:oauth:grant-type:jwt-bearer` grant in otherwise-public PKCE metadata. The pinned Keycloak image therefore includes a narrow compatibility provider that removes only that grant, and only when both Claude's exact metadata URL and exact callback match, before delegating all remaining validation to Keycloak. Revalidate or remove this provider when upgrading Keycloak.

The pre-registered confidential `claude-projeqtor` client remains available as a private fallback, using the exact Claude callback and the root-only client secret; do not expose that secret to ordinary users.

### ChatGPT

Use the pre-registered public PKCE client:

- MCP URL: `https://conceptpower.ddns.net/mcp_projeqtor`
- OAuth client ID: `chatgpt-projeqtor`
- Client authentication: none
- Redirect URI: `https://chatgpt.com/connector_platform_oauth_redirect`
- Scopes: `projeqtor:read projeqtor:write`

Stock Keycloak 26.7.4 cannot parse ChatGPT's CIMD document because it rejects the plural `token_endpoint_auth_methods_supported` property. OpenAI also supports predefined OAuth clients, so v2.1.0 uses that path instead of enabling dynamic registration or shipping a custom Keycloak extension. Re-evaluate CIMD after upgrading Keycloak and passing the same acceptance tests.

## First-login provisioning

A valid OAuth request is mapped to the stable login `entra-<Microsoft object ID>`. The object ID and tenant are never returned by MCP tools.

Under a PostgreSQL advisory lock, the bridge creates one ProjeQtOr `User` through the native save path with:

- Team Member profile code `TM`
- Resource and employee flags enabled
- Display name and email from validated Microsoft claims
- No credential is accepted, returned, or made usable by the connector; any native ProjeQtOr credential fields generated internally remain secret

Existing disabled, locked, idle, or non-user records are rejected. ProjeQtOr's native object permissions remain authoritative for all 36 tools.

## Reverse-proxy requirements

The public TLS proxy must expose only:

- Exact `/mcp_projeqtor` to MCP `/mcp/oauth`
- Exact `/.well-known/oauth-protected-resource/mcp_projeqtor` to the MCP metadata route
- `/projeqtor-auth/realms/projeqtor/` and required Keycloak login resources

Explicitly reject public requests to:

- `/projeqtor-auth/admin/`
- `/projeqtor-auth/realms/master/`
- `/projeqtor-auth/realms/projeqtor/clients-registrations/`

Preserve `Host`, `X-Forwarded-Host`, `X-Forwarded-Proto`, and client address headers. Do not proxy the public MCP path to private `/mcp`.

## Acceptance and revocation

Before enabling users, verify:

1. OAuth discovery, PKCE, issuer identification, audience, and both required scopes.
2. One assigned `@hikoterra.com` user succeeds independently.
3. One assigned `@pcnzl.com` user succeeds independently.
4. Wrong tenant, guest, unassigned, disallowed domain, missing scope, wrong audience, expired, and tampered tokens fail.
5. Concurrent first requests create one Team Member and audit actions as that user.
6. A static administrator token fails on the public URL and still works on private `/mcp`.
7. Keycloak admin, master realm, and registration endpoints are unavailable publicly.
8. No host ports or secret-bearing log entries were added.

To remove access, remove the Entra assignment and revoke the user's Keycloak session. Five-minute access-token expiry bounds any already issued token.
