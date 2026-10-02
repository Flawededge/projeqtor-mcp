import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const compose = await readFile(new URL('../../deployment/compose.yaml', import.meta.url), 'utf8');
const gateway = await readFile(new URL('../../deployment/gateway/nginx.conf', import.meta.url), 'utf8');
const realm = JSON.parse(await readFile(new URL('../../deployment/auth/realm-projeqtor.json', import.meta.url), 'utf8'));
const entrypoint = await readFile(new URL('../../deployment/auth/keycloak-entrypoint.sh', import.meta.url), 'utf8');
const healthcheck = await readFile(new URL('../../deployment/auth/keycloak-healthcheck.sh', import.meta.url), 'utf8');
const userProfile = JSON.parse(await readFile(new URL('../../deployment/auth/user-profile.json', import.meta.url), 'utf8'));
const clientProfiles = JSON.parse(await readFile(new URL('../../deployment/auth/client-profiles.json', import.meta.url), 'utf8'));
const clientPolicies = JSON.parse(await readFile(new URL('../../deployment/auth/client-policies.json', import.meta.url), 'utf8'));
const keycloakProviderDockerfile = await readFile(new URL('../../deployment/auth/keycloak-provider/Dockerfile', import.meta.url), 'utf8');
const claudeCimdExecutor = await readFile(new URL('../../deployment/auth/keycloak-provider/src/main/java/com/flawededge/projeqtor/auth/ClaudeClientIdMetadataDocumentExecutor.java', import.meta.url), 'utf8');
const claudeCimdFactory = await readFile(new URL('../../deployment/auth/keycloak-provider/src/main/java/com/flawededge/projeqtor/auth/ClaudeClientIdMetadataDocumentExecutorFactory.java', import.meta.url), 'utf8');
const bridge = await readFile(new URL('../../bridge/index.php', import.meta.url), 'utf8');
const router = await readFile(new URL('../../bridge/router.php', import.meta.url), 'utf8');
const server = await readFile(new URL('../../server/src/main.mjs', import.meta.url), 'utf8');

test('Keycloak is pinned, profile-gated, non-root, capability-free, and has no host port', () => {
  const keycloak = compose.slice(compose.indexOf('  keycloak:'), compose.indexOf('\n  initialize:'));
  assert.match(keycloak, /profiles: \[keycloak-rollback\]/);
  assert.match(keycloak, /image: \$\{KEYCLOAK_IMAGE:-projeqtor-keycloak:26\.7\.4-claude-cimd-v1\}/);
  assert.match(keycloak, /build:\s+context: \.\/auth\/keycloak-provider/);
  assert.match(keycloakProviderDockerfile, /FROM quay\.io\/keycloak\/keycloak:26\.7\.4@sha256:82a77884f3af238beab1e7afd63b5f530e1b5c0590bd7aa60b40a40463e29b2c/);
  assert.match(keycloakProviderDockerfile, /kc\.sh build --features=cimd/);
  assert.match(keycloak, /user: "1000:1000"/);
  assert.match(keycloak, /cap_drop: \[ALL\]/);
  assert.match(keycloak, /no-new-privileges:true/);
  assert.doesNotMatch(keycloak, /^\s+ports:/m);
});

test('Claude published identity uses a narrow HTTPS-only CIMD policy', () => {
  assert.match(compose, /KC_FEATURES: cimd/);
  assert.match(compose, /client-profiles\.json:\/opt\/keycloak\/conf\/projeqtor-client-profiles\.json:ro/);
  assert.match(compose, /client-policies\.json:\/opt\/keycloak\/conf\/projeqtor-client-policies\.json:ro/);
  assert.match(entrypoint, /update client-policies\/profiles/);
  assert.match(entrypoint, /update client-policies\/policies/);

  assert.equal(clientProfiles.profiles.length, 1);
  const profile = clientProfiles.profiles[0];
  assert.equal(profile.name, 'claude-published-identity-cimd');
  assert.equal(profile.executors.length, 1);
  assert.equal(profile.executors[0].executor, 'claude-client-id-metadata-document');
  assert.deepEqual(profile.executors[0].configuration, {
    'cimd-allow-http-scheme': false,
    'cimd-allow-permitted-domains': ['claude.ai'],
    'cimd-restrict-same-domain': true,
    'cimd-required-properties': [],
    'only-allow-confidential-client': false
  });

  assert.equal(clientPolicies.policies.length, 1);
  const policy = clientPolicies.policies[0];
  assert.equal(policy.name, 'claude-published-identity-cimd');
  assert.equal(policy.enabled, true);
  assert.deepEqual(policy.profiles, ['claude-published-identity-cimd']);
  assert.deepEqual(policy.conditions, [{
    condition: 'client-id-uri',
    configuration: {
      'is-negative-logic': false,
      'client-id-uri-scheme': ['https'],
      'client-id-uri-allow-permitted-domains': ['claude.ai']
    }
  }]);
  assert.doesNotMatch(JSON.stringify(clientProfiles), /\*/);
  assert.doesNotMatch(JSON.stringify(clientPolicies), /\*/);
});

test('Claude CIMD compatibility is exact-match and preserves stock validation', () => {
  assert.match(claudeCimdFactory, /PROVIDER_ID = "claude-client-id-metadata-document"/);
  assert.match(claudeCimdExecutor, /CLAUDE_CLIENT_ID = "https:\/\/claude\.ai\/oauth\/mcp-oauth-client-metadata"/);
  assert.match(claudeCimdExecutor, /CLAUDE_REDIRECT_URI = "https:\/\/claude\.ai\/api\/mcp\/auth_callback"/);
  assert.match(claudeCimdExecutor, /JWT_AUTHORIZATION_GRANT = "urn:ietf:params:oauth:grant-type:jwt-bearer"/);
  assert.match(claudeCimdExecutor, /grants\.remove\(JWT_AUTHORIZATION_GRANT\)/);
  assert.match(claudeCimdExecutor, /CLAUDE_CLIENT_ID\.equals\(clientIdUri\.toString\(\)\)/);
  assert.match(claudeCimdExecutor, /CLAUDE_REDIRECT_URI\.equals\(redirectUri\.toString\(\)\)/);
  assert.match(claudeCimdExecutor, /"none"\.equals\(authenticationMethod\)/);
  assert.match(claudeCimdExecutor, /super\.validateClientMetadata\(clientIdUri, redirectUri, client\)/);
  assert.doesNotMatch(claudeCimdExecutor, /setTokenEndpointAuthMethod/);
});

test('Keycloak gets a separate database and role without exposing either password', () => {
  const init = compose.slice(compose.indexOf('  auth-db-init:'), compose.indexOf('\n  keycloak:'));
  assert.match(init, /networks: \[backend\]/);
  assert.match(init, /keycloak-db-password:\/run\/secrets\/keycloak_db_password:ro/);
  assert.match(compose, /KC_DB_URL: jdbc:postgresql:\/\/db:5432\/keycloak/);
  assert.match(entrypoint, /read_secret KC_DB_PASSWORD \/run\/secrets\/keycloak_db_password/);
  assert.doesNotMatch(compose, /KC_DB_PASSWORD:/);
});

test('public and private MCP authentication routes are distinct', () => {
  assert.match(server, /app\.all\('\/mcp'/);
  assert.match(server, /app\.all\('\/mcp\/oauth'/);
  assert.match(gateway, /location = \/mcp_projeqtor[\s\S]*proxy_pass http:\/\/mcp:3000\/mcp\/oauth;/);
  assert.match(gateway, /location = \/\.well-known\/oauth-protected-resource\/mcp_projeqtor/);
  assert.doesNotMatch(gateway.slice(gateway.indexOf('location = /mcp_projeqtor')), /proxy_pass http:\/\/mcp:3000\/mcp;/);
});

test('retired Keycloak routes return 404 on both gateway listeners', () => {
  assert.equal((gateway.match(/location = \/projeqtor-auth \{ return 404; \}/g) ?? []).length, 2);
  assert.equal((gateway.match(/location \^~ \/projeqtor-auth\/ \{ return 404; \}/g) ?? []).length, 2);
  assert.doesNotMatch(gateway, /proxy_pass http:\/\/keycloak/);
});

test('realm uses short tokens, rotating refresh tokens, PKCE, and no implicit or password grants', () => {
  assert.equal(realm.accessTokenLifespan, 300);
  assert.equal(realm.ssoSessionIdleTimeout, 3600);
  assert.equal(realm.revokeRefreshToken, true);
  assert.equal(realm.refreshTokenMaxReuse, 0);
  assert.equal(realm.registrationAllowed, false);
  for (const client of realm.clients) {
    assert.equal(client.standardFlowEnabled, true);
    assert.equal(client.implicitFlowEnabled, false);
    assert.equal(client.directAccessGrantsEnabled, false);
    assert.equal(client.serviceAccountsEnabled, false);
    assert.equal(client.attributes['pkce.code.challenge.method'], 'S256');
  }
});

test('cloud clients support standard OpenID profile and email scopes', () => {
  const scopes = new Map(realm.clientScopes.map(scope => [scope.name, scope]));
  for (const name of ['profile', 'email']) {
    assert.equal(scopes.get(name)?.protocol, 'openid-connect');
    assert.ok(scopes.get(name).protocolMappers.length > 0);
    assert.ok(realm.defaultDefaultClientScopes.includes(name));
  }

  const profileClaims = new Set(scopes.get('profile').protocolMappers.map(mapper => mapper.config['claim.name']).filter(Boolean));
  const emailClaims = new Set(scopes.get('email').protocolMappers.map(mapper => mapper.config['claim.name']).filter(Boolean));
  assert.ok(profileClaims.has('preferred_username'));
  assert.ok(emailClaims.has('email'));
  assert.ok(emailClaims.has('email_verified'));

  for (const client of realm.clients) {
    assert.ok(client.defaultClientScopes.includes('profile'));
    assert.ok(client.defaultClientScopes.includes('email'));
  }
});

test('Microsoft federation is single-tenant and maps immutable identity claims', () => {
  const microsoft = realm.identityProviders.find(provider => provider.alias === 'microsoft');
  assert.match(microsoft.config.authorizationUrl, /login\.microsoftonline\.com\/\$\{ENTRA_TENANT_ID\}/);
  assert.equal(microsoft.config.clientId, '${ENTRA_CLIENT_ID}');
  assert.match(microsoft.config.defaultScope, /\bUser\.Read\b/);
  const mappings = new Map(realm.identityProviderMappers.map(mapper => [mapper.config.claim, mapper.config['user.attribute']]));
  assert.equal(mappings.get('oid'), 'entra_oid');
  assert.equal(mappings.get('tid'), 'entra_tid');
  assert.equal(mappings.get('preferred_username'), 'email');
});

test('Claude is dedicated and ChatGPT is a restricted pre-registered PKCE client', () => {
  const claude = realm.clients.find(client => client.clientId === 'claude-projeqtor');
  assert.equal(claude.publicClient, false);
  assert.equal(claude.secret, '${CLAUDE_OAUTH_CLIENT_SECRET}');
  assert.deepEqual(claude.redirectUris, ['${CLAUDE_OAUTH_REDIRECT_URI}']);

  const chatgpt = realm.clients.find(client => client.clientId === 'chatgpt-projeqtor');
  assert.equal(chatgpt.publicClient, true);
  assert.deepEqual(chatgpt.redirectUris, ['https://chatgpt.com/connector_platform_oauth_redirect']);
  assert.deepEqual(chatgpt.webOrigins, ['https://chatgpt.com']);
});

test('first login provisions exactly one Team Member under an advisory lock', () => {
  assert.match(bridge, /pg_advisory_xact_lock/);
  assert.match(bridge, /profileCode' => 'ADM'/);
  assert.match(bridge, /setSessionUser\(\$provisioner\)/);
  assert.match(bridge, /profileCode' => 'TM'/);
  assert.match(bridge, /\$oauthUser->isResource = 1/);
  assert.match(bridge, /\$oauthUser->isEmployee = 1/);
  assert.match(bridge, /\$oauthUser->save\(\)/);
  assert.match(bridge, /oauth_user_unavailable/);
  assert.doesNotMatch(bridge, /\$oauthUser->isUser\s*=/);
  assert.doesNotMatch(bridge, /INSERT INTO .*fullname/);
});

test('identity output is useful but excludes subject digests and tokens', () => {
  assert.match(router, /'displayName'/);
  assert.match(router, /'authenticationProvider'/);
  assert.match(router, /'auth0'/);
  assert.match(router, /\$publicUsername=\$isAuth0 \? \(\$user->email/);
  assert.match(router, /\$user->resourceName/);
  assert.doesNotMatch(router, /entra_oid|entra_tid|accessToken|refreshToken/);
});

test('Keycloak installs a managed Microsoft attribute profile before becoming healthy', () => {
  const attributes = new Map(userProfile.attributes.map(attribute => [attribute.name, attribute]));
  for (const name of ['entra_oid', 'entra_tid', 'entra_upn', 'entra_display_name']) {
    assert.ok(attributes.has(name));
    assert.deepEqual(attributes.get(name).permissions.edit, ['admin']);
  }
  assert.equal(userProfile.unmanagedAttributePolicy, undefined);
  assert.match(compose, /user-profile\.json:\/opt\/keycloak\/conf\/projeqtor-user-profile\.json:ro/);
  assert.doesNotMatch(compose, /user-profile\.json:\/opt\/keycloak\/data\/import\//);
  assert.match(entrypoint, /update users\/profile/);
  assert.match(entrypoint, /oauth-profile-ready/);
  assert.match(entrypoint, /wait "\$keycloak_pid"/);
  assert.doesNotMatch(entrypoint, /update users\/profile[^\n]*\|\| true/);
  assert.match(healthcheck, /oauth-profile-ready/);
});

test('Microsoft UPN populates both the managed UPN and email attributes', () => {
  const pairs = realm.identityProviderMappers.map(mapper => [mapper.config.claim, mapper.config['user.attribute']]);
  assert.ok(pairs.some(([claim, attribute]) => claim === 'preferred_username' && attribute === 'entra_upn'));
  assert.ok(pairs.some(([claim, attribute]) => claim === 'preferred_username' && attribute === 'email'));
});

test('active MCP configuration uses Auth0 with no Entra prerequisite', () => {
  const mcp = compose.slice(compose.indexOf('  mcp:'), compose.indexOf('  gateway:'));
  assert.match(mcp, /https:\/\/hikoterra\.au\.auth0\.com\//);
  assert.match(mcp, /MCP_OAUTH_CLIENT_ID/);
  assert.doesNotMatch(mcp, /ENTRA|keycloak/);
  assert.match(bridge, /auth0-\[0-9a-f\]\{64\}/);
  assert.match(server, /provider: principal\.authenticationProvider/);
});
