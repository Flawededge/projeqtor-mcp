# Auth0 email OTP for Claude

The public MCP accepts Auth0 access tokens only. Private `/mcp` retains static per-user tokens. Native ProjeQtOr rights remain authoritative; the two OAuth API scopes do not grant project rights.

## Auth0 configuration

Use the existing `hikoterra` tenant (`https://hikoterra.au.auth0.com/`, including the trailing slash).

1. Create an RS256 API named **ProjeQtOr MCP**, identifier `https://conceptpower.ddns.net/mcp_projeqtor`, scopes `projeqtor:read` and `projeqtor:write`, 300-second access tokens, and offline access enabled. Use the standard Auth0 access-token profile (with `azp`).
2. Register **ProjeQtOr MCP — Claude** as a First-party public Native application: token authentication `none`, authorization-code and refresh-token grants only, exact callback `https://claude.ai/api/mcp/auth_callback`. Ownership is immutable: choose First-party when creating this user-owned registration so email can be enabled per application without promoting the connection tenant-wide. No wildcards, implicit/password/device-code/client-credentials grants, open DCR, or client secret. Authorize only the two MCP scopes through the API's per-app user-delegated policy; keep machine access disabled and skipping user consent disabled.
3. Enable only the Passwordless connection named `email` (strategy `email`) for this client, using hosted Universal Login and codes with self-registration. In Authentication > Authentication Profile, select **Identifier First**; Auth0 otherwise rejects this email-only client with `no connections enabled for the client`. No database, SMS, enterprise or social login. Keep native rate limits and attack protection.
4. In tenant Settings > Advanced, enable **Resource Parameter Compatibility Profile** and **Include Issuer in Authorization Responses**. Do not set a tenant default audience.
5. Use rotating, expiring refresh tokens: idle 3,600 seconds, maximum 28,800 seconds, reuse interval 3 seconds. Request `openid email offline_access projeqtor:read projeqtor:write`.
6. Create a Post Login Action from `deployment/auth/auth0-post-login.cjs`, set its `MCP_CLIENT_ID` secret to the registered client ID, deploy, and bind it to Post Login. This is a configuration value, not a credential. The Action affects this client only, requires email verification and an exact approved domain, enforces S256 authorization, and reapplies admission on refresh.

Microsoft 365 is email delivery only. Set both the provider and Passwordless email connection From fields to the bare address `catchall@hikoterra.com`. The native Microsoft 365 provider treats a display-name form such as `ProjeQtOr MCP <catchall@hikoterra.com>` as a mailbox identifier and rejects it as an invalid user. Verify an Auth0 test email, delivery logs and mailbox receipt before cutover. Preserve catchall-only sending permissions and never grant tenant-wide mail access as a fallback. Confirm features are available on Free rather than relying on the enterprise trial; do not purchase a subscription.

## Deployment

Set these non-secret values in the authoritative environment:

```dotenv
MCP_OAUTH_ENABLED=true
MCP_OAUTH_ISSUER=https://hikoterra.au.auth0.com/
MCP_OAUTH_RESOURCE=https://conceptpower.ddns.net/mcp_projeqtor
MCP_OAUTH_JWKS_URL=https://hikoterra.au.auth0.com/.well-known/jwks.json
MCP_OAUTH_CLIENT_ID=REPLACE_WITH_REGISTERED_CLIENT_ID
MCP_OAUTH_ALLOWED_DOMAINS=hikoterra.com,pcnzl.com
```

No OAuth Compose profile is required. `keycloak-rollback` retains dormant historical services/assets; it is not a working alternative login path for the Auth0 binaries. Recover the previous images and gateway/configuration together to roll back. Retain the historical database and secret files; do not delete persistent state.

The MCP verifies signature, exact issuer, resource audience, `azp`, timestamps, scopes and namespaced verified-email claims. It derives an internal identity key from `(issuer, sub)` and stores it in `mcpoauthidentity`, linked to the native user ID�not the username. First use requires exactly one existing native user with the normalized verified email; no account is created. Missing/duplicate emails, inactive/locked accounts and another subject trying to claim an already-linked user are denied. Subsequent requests resolve the immutable link, so email or username changes never move the identity to another account. Native email, name and profile are not changed by login. The global transaction lock serializes initial links for this small deployment. Locked/idle users stay denied, and native project/action rights remain in force.

`projeqtor_whoami` exposes the email, display name and provider `auth0`, never the subject digest or credentials. The protected-resource metadata remains at `/.well-known/oauth-protected-resource/mcp_projeqtor`; the public MCP remains `/mcp_projeqtor` and proxies exclusively to `/mcp/oauth`. Retired `/projeqtor-auth` routes return 404. No additional host ports are needed.

## Claude and acceptance

Record the existing connector settings before recreating it. Claude currently requires removal/re-addition to change configuration. Use **Sign in now** and **Use your own OAuth client**, entering only the public client ID and the existing MCP URL. Use an individual verified work address, not a shared static credential.

Required gates:

- Existing unit/contract suites, syntax/PHP lint, policy coverage, app/MCP image builds and disposable integration/acceptance pass. Run mutating scenarios only against the disposable harness.
- Auth0 test-email delivery and actual OTP receipt succeed; no Microsoft sign-in appears.
- Authenticate through the supplied Claude browser, call `projeqtor_whoami`, and perform a permitted read. The pre-existing user retains its native profile; unknown users are denied before any MCP tools execute.
- After the original token's 300-second expiry plus clock tolerance, another Claude tool call succeeds without another OTP. Correlate successful refresh issuance and client identity in Auth0 logs without recording tokens.
- Invalid domains, unverified identities, wrong clients, non-S256 requests and locked/idle users are denied. Native permission restrictions hold. Exactly 36 tools remain available.
- Public static tokens and private OAuth/static crossover fail; metadata succeeds and retired auth routes return 404.

Take a consistent database/data/config backup and preserve immutable images before deployment. Deploy app and worker together with the bridge, then MCP and gateway. Keep Keycloak until live acceptance; stop it afterward without deleting state. Roll back images/configuration on failure; restore data only if necessary. Update the host runbook with sanitized evidence. Do not publish a release before CI and live Claude acceptance pass.

The inspected vaultserver ingress uses Nginx Proxy Manager host 2 and routes `/projeqtor-auth/` directly to `projeqtor-keycloak-ingress`, bypassing this repository's gateway. At cutover, back up that proxy host's persisted settings and generated configuration, replace its Keycloak proxy location with exact/descendant 404 locations, and persist the change in Nginx Proxy Manager as well as its generated file. Preserve its existing MCP and protected-resource metadata locations. Validate Nginx before reload and verify the public URL returns 404; changing only the internal gateway is insufficient.

Create native users through normal ProjeQtOr administration before allowing MCP access. Keep email unique and accurate. To revoke or deliberately rebind an OAuth identity, an administrator must remove its `mcpoauthidentity` row and review both the native account and Auth0 identity; deleting/recreating an Auth0 user does not silently reclaim a linked account. Do not expose this table through generic MCP mutations. Existing legacy Auth0-named accounts must be linked before renaming them to a human username; preserve user IDs, profiles, history and assignments.

For revocation, block/revoke the Auth0 user/session and mark the native ProjeQtOr account locked or idle. The native check prevents further tool use immediately, including already-issued tokens. Refresh admission reevaluates verified-domain eligibility.

## Production acceptance — 2026-10-05

Auth0 email OTP is live and verified through Claude, including a permitted Ticket read and token refresh without another OTP. Keycloak is stopped and its public routes return 404. See [the sanitized acceptance record](AUTH0-ACCEPTANCE.md) for image IDs, mail-scope denial, native permissions, backup and test evidence. The team remains on Free; no paid subscription was enabled.

## Historical implementation checkpoint — 2026-10-02

The final public client ID is `cpmOrJBLNvSPE2HSmYMOrt54RPLj8Qx6`; the API ID is `6abef70d34ee3c51cd38cec2`. The deployed Post Login Action is `dee18f14-3e66-4a39-ba21-69c74427e1da`. An abandoned Third-party registration (`tpc_g9s6gZefq2dqayHFf9ZZDN`) has no MCP API grant and must not be used.

Disposable run `b4-1790900582829-a082d57d` passed integration, OAuth provisioning and full acceptance across all 12 modules. Eight concurrent provisioning requests created one Team Member; separate subjects with matching email remained separate; email changes preserved identity; locked/idle accounts were denied. JavaScript checks, 145 unit tests, 59 contract tests, both image builds, PHP lint and policy/module checks passed. Coverage remained 944 source files, 899 entrypoints, 337 mutation candidates and 640 classes with zero unknown/deferred surfaces. The OAuth fixture ledger records the native user IDs; harness destruction removes its disposable volumes and credentials.

At this earlier checkpoint, production cutover and Claude login/read/refresh acceptance were pending email delivery. The subsequent bounded Exchange retry and October 5 live acceptance supersede that state. Do not treat an Auth0 `sapi` test-email operation as delivery success: require the notification result and mailbox receipt. No release was approved by this historical checkpoint.
