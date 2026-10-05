# Auth0 production acceptance — 2026-10-05

## Existing-account admission update — 2026-10-05

Code commit `98888dc1ed3dd3ce4298c57be12f5d836ad6635f` supersedes the initial automatic Team Member provisioning described below. MCP admission now requires exactly one existing native account with the normalized verified email. It stores the immutable identity key separately in `mcpoauthidentity`, linked to the native user ID. Unknown/ambiguous email matches, locked/idle users and a second identity trying to claim a linked account are denied. Later email or username changes never remap the identity. Login does not create accounts or alter their native profile/details.

The existing Chris account remains user3 with its explicitly approved Administrator/profile1 access. Its native username is now `ChrisD`; its Auth0 identity is stored in the link table. Existing operation ownership was migrated to the native username. Whoami retains the user-facing email and excludes the identity key.

Matching deployed images:

- App/worker `projeqtor-app:existing-users-20261005`: `sha256:4117d1c745d6f25c76b52951f494240e0dc727e1631a5711331f930a001a979e`.
- MCP `projeqtor-mcp:existing-users-20261005`: `sha256:92210a7f588b3e0e4603393c38487779b9111c09d51fafcb1967939cc45e6589`.

All three services are healthy. Direct signed admission returned existing user3/profileADM with no account creation; an unknown native email returned403. Private static access still discovers36tools; public static access and private OAuth/static crossover return401. Public metadata200 and retired auth404 passed. Recreated upstream addresses briefly caused public502; reloading both Nginx Proxy Manager and the internal gateway restored access. Future MCP recreation must include those reloads.

Actual Claude whoami and Project list calls then succeeded, without a reconnect or another OTP. They returned providerAuth0, the same user3, Administrator/profile1 and a permitted Project read. Sanitized screenshot `claude-existing-user-20261005.jpg` is retained in the operator workspace. No production fixture records were created.

Validation passed:145unit/59contract tests, JavaScript/PHP checks, both images, unchanged policy coverage, disposable OAuth existing-account/concurrency/ambiguity/identity-conflict/locked-idle checks, integration and full acceptance. All code-head CI checks passed. Disposable run: `b4-1791176278212-61e5edb9`.

Rollback checkpoint `/mnt/user/appdata/projeqtor/backups/existing-users-20261005/` contains a consistent DB dump/catalog, application/cache/secrets/gateway archive, prior Compose/environment/images and root-only previous native username. Checksums passed. Restore prior binaries/configuration together with user3's previous native username and operation ownership; retain the new link table dormant. A whole production database restore is not needed merely to undo this authentication change.

The public ProjeQtOr MCP is deployed on vaultserver with Auth0 email OTP. Microsoft 365 is used only to deliver email. The public URL and all 36 tools are preserved.

## Live Claude evidence

Validation used the supplied Claude browser and the dedicated public client `cpmOrJBLNvSPE2HSmYMOrt54RPLj8Qx6`, with no client secret. The individual `chris.diggle@hikoterra.com` account received and completed an email OTP, accepted the two MCP API scopes and offline access, and connected successfully. No Microsoft sign-in was involved.

- Auth0 signup/login: `2026-10-05T03:41:34Z`; authorization-code exchange: `03:41:35.775Z`.
- Claude discovered exactly 36 tools.
- `projeqtor_whoami` returned provider `auth0`, the user-facing email, native user ID 3, profile ID 4, `profileTeamMember`, code `TM`, and `credentialsExposed: false`. No internal subject hash was returned.
- Native Project and Status reads returned 403. The class catalog reported native per-class permissions; a subsequent Ticket list succeeded and returned zero visible items. No production test records or permission changes were made.
- Auth0 recorded a successful rotating refresh-token exchange at `03:45:49.055Z`, log `90020261005034549182998000000000000001223372091785819902`. Claude proactively refreshed before the original token's expiry. Further whoami/class-catalog/Ticket calls after `03:46:40Z` succeeded without another OTP, proving access after the original 300-second lifetime plus tolerance.
- Browser evidence: Claude conversation `a0690a6e-cecc-4e67-8ade-94359853c1fd`. Sanitized screenshots and detailed operational evidence are retained in the operator workspace, outside source control.

## Email sender restriction

Exchange has one `Application Mail.Send` assignment, `Auth0-Catchall-MailSend`, scoped to `Auth0-Catchall-Only`. The scope selects exactly `catchall@hikoterra.com`; authorization tests report catchall in scope and Chris's mailbox out of scope. Tenant-wide Graph application/delegated Mail.Send grants and unnecessary requested permissions were removed before deployment.

With the user's specific approval, a single Auth0 configuration test temporarily selected Chris's mailbox as the sender. Actual sending failed with `Access is denied` at `03:23:08.235Z`, log `90020261005032308355044000000000000001223372091784725405`. Catchall was restored immediately and saved. A fresh test from catchall arrived in the approved mailbox at `03:24:43Z`.

The initial OTP delivery exposed a distinct formatting issue: the Microsoft 365 provider interprets `ProjeQtOr MCP <catchall@hikoterra.com>` as an invalid mailbox identifier. Both provider and Passwordless connection From fields now use the bare `catchall@hikoterra.com`; the OTP arrived at `03:40:17Z` and completed login.

The hosted login also requires **Authentication > Authentication Profile > Identifier First**. Email was already enabled for this client, but the previous identifier-and-password profile produced `no connections enabled for the client`. Identifier First resolved it without enabling another connection.

## Deployment and boundaries

The deployed implementation is built from commit `83bb4bfd26b8cecd4cb3d4865b1a4618926b57f4`:

- MCP `projeqtor-mcp:auth0-83bb4bf`: `sha256:383a366a62f1eda7ba08544dc6b0bad7f63ef3c83a73ad6b4da77276d0d67c4c`.
- App/worker `projeqtor-app:auth0-83bb4bf`: `sha256:aa72f2af76904e51ded1bf26ba7571493e055fa0a8630115359042ada88855bf`.
- Database, app, worker, MCP, gateway and Tailscale health checks pass. No project host ports are published; the worker remains backend-only.
- Protected-resource metadata advertises exact Auth0 issuer `https://hikoterra.au.auth0.com/` and the required API/OIDC scopes.
- Public unauthenticated/static-token requests return 401; private static-token access and whoami succeed. Private OAuth paths reject static tokens.
- Exact and descendant `/projeqtor-auth` URLs return 404 through the persisted Nginx Proxy Manager host-2 configuration. The private gateway was validated and reloaded too.
- Keycloak is stopped after acceptance. Its persistent database, credentials and dormant `keycloak-rollback` profile remain available.
- Bounded app/worker/MCP/gateway logs contain no exact matches for the existing administrator/API/signing/cursor secrets.

## Recovery and test gates

Fresh consistent rollback checkpoint: `/mnt/user/appdata/projeqtor/backups/auth0-predeploy-20261005T032457Z`. It contains immutable prior images, both database dumps, roles, attachments/data/cache/configuration/secrets, authoritative Compose/environment, NPM snapshot/generated configuration, and source archives. Checksums, archive listings and dump catalogs verified. The same backup procedure's October 2 database dumps were successfully restored in an isolated disposable container; its evidence is retained in that checkpoint.

Rollback restores previous images and matching Compose/environment/gateway, plus only the saved NPM host-2 fields/generated configuration. Do not restore the production database merely to undo this authentication rollout: retain the newly provisioned user unless data recovery is needed.

The implementation passed 145 unit tests, 59 contracts, PHP lint, both image builds, all 12 module contracts, policy coverage and disposable integration/OAuth provisioning/full acceptance. Policy coverage remains 944 source files, 899 entrypoints, 337 mutation candidates and 640 classes, with zero unknown/deferred surfaces. Concurrent provisioning, immutable subject identity, email changes, both allowed domains, admission failures, JWKS rotation, locked/idle users and native restrictions were exercised in the existing harness. Mutating acceptance ran only in the disposable deployment.

The Auth0 team subscription is **Free ($0)**. Its entitlement table lists Custom Email Provider, Customizable Login Experience and Actions Library as included; the official pricing page includes Passwordless authentication in Free. This deployment uses one Action and standard public-client OAuth/rotating refresh, not the trial-only enterprise connections, Email Workflow, Organizations, long-lived sessions or session-management API. No paid subscription/add-on was enabled. Free log retention is short; keep sanitized acceptance evidence separately.

Release publication requires passing CI for the final reviewed commit in addition to this live evidence. No release tag is created by this acceptance record.
