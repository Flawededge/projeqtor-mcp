import test from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { createServer } from 'node:http';
import { SignJWT, createLocalJWKSet, createRemoteJWKSet, exportJWK, generateKeyPair } from 'jose';
import { CLAIM_NAMESPACE, createOAuthAuthenticator, loadOAuthConfig, oauthChallenge, protectedResourceMetadata, validateOAuthClaims } from '../src/oauth.mjs';

const env = {
  MCP_OAUTH_ENABLED: 'true', MCP_OAUTH_ISSUER: 'https://hikoterra.au.auth0.com/',
  MCP_OAUTH_RESOURCE: 'https://conceptpower.ddns.net/mcp_projeqtor',
  MCP_OAUTH_CLIENT_ID: 'test-claude-client', MCP_OAUTH_ALLOWED_DOMAINS: 'hikoterra.com,pcnzl.com'
};
const config = loadOAuthConfig(env);
const now = Math.floor(Date.now() / 1000);
const baseClaims = {
  sub: 'email|immutable-user', azp: env.MCP_OAUTH_CLIENT_ID, iat: now, exp: now + 300,
  scope: 'openid email offline_access projeqtor:read projeqtor:write',
  [`${CLAIM_NAMESPACE}email`]: 'person@hikoterra.com', [`${CLAIM_NAMESPACE}email_verified`]: true
};
const validate = claims => validateOAuthClaims({ ...baseClaims, ...claims }, { alg: 'RS256' }, config);

test('OAuth configuration preserves exact issuer and binds a registered client', () => {
  assert.deepEqual(loadOAuthConfig({}), { enabled: false });
  assert.equal(config.issuer, env.MCP_OAUTH_ISSUER);
  assert.equal(config.jwksUrl, 'https://hikoterra.au.auth0.com/.well-known/jwks.json');
  assert.equal(config.metadataUrl, 'https://conceptpower.ddns.net/.well-known/oauth-protected-resource/mcp_projeqtor');
  assert.throws(() => loadOAuthConfig({ ...env, MCP_OAUTH_ISSUER: 'http://issuer.example/' }), /https/);
  assert.throws(() => loadOAuthConfig({ ...env, MCP_OAUTH_CLIENT_ID: '' }), /client ID/);
  assert.throws(() => loadOAuthConfig({ ...env, MCP_OAUTH_CLOCK_TOLERANCE_SECONDS: 'NaN' }), /integer/);
});
test('verified domains and stable subjects do not link users by email', () => {
  const expected = `auth0-${createHash('sha256').update(`${config.issuer}\0${baseClaims.sub}`).digest('hex')}`;
  for (const email of ['Person@HIKOTERRA.COM', 'person@pcnzl.com']) {
    const principal = validate({ [`${CLAIM_NAMESPACE}email`]: email });
    assert.equal(principal.email, email.toLowerCase());
    assert.equal(principal.username, expected);
    assert.equal(principal.authenticationProvider, 'auth0');
  }
  assert.notEqual(validate({ sub: 'email|another-user' }).username, expected);
  assert.notEqual(validateOAuthClaims(baseClaims, { alg: 'RS256' }, { ...config, issuer: 'https://other.auth0.com/' }).username, expected);
});
test('invalid identity, verification, domain, client, timestamps and scopes fail closed', () => {
  for (const email of ['person@other.example', 'person@hikoterra.com.evil.example', 'person@sub.hikoterra.com', 'person@@hikoterra.com', 'person @hikoterra.com', '']) assert.throws(() => validate({ [`${CLAIM_NAMESPACE}email`]: email }));
  for (const verified of [false, 'true', undefined]) assert.throws(() => validate({ [`${CLAIM_NAMESPACE}email_verified`]: verified }), /verified/);
  assert.throws(() => validate({ [`${CLAIM_NAMESPACE}email`]: undefined, email: 'person@hikoterra.com', email_verified: true }), /verified/);
  for (const sub of ['', undefined, 'email|white space', 'x'.repeat(256)]) assert.throws(() => validate({ sub }), /subject/);
  assert.throws(() => validate({ azp: 'another-client' }), /client/);
  assert.throws(() => validate({ iat: now + 60 }), /timestamps/);
  assert.throws(() => validate({ exp: now }), /timestamps/);
  assert.throws(() => validate({ scope: 'projeqtor:read' }), /projeqtor:write/);
  assert.throws(() => validateOAuthClaims(baseClaims, { alg: 'HS256' }, config), /RS256/);
});
test('metadata and challenge request API and email refresh scopes', () => {
  assert.deepEqual(protectedResourceMetadata(config).authorization_servers, [env.MCP_OAUTH_ISSUER]);
  assert.deepEqual(protectedResourceMetadata(config).scopes_supported, ['projeqtor:read', 'projeqtor:write', 'openid', 'email', 'offline_access']);
  assert.match(oauthChallenge(config), /resource_metadata="https:\/\/conceptpower\.ddns\.net\/\.well-known\/oauth-protected-resource\/mcp_projeqtor"/);
  assert.match(oauthChallenge(config), /offline_access/);
});
test('real JWT verification rejects issuer changes, audience, expiry, nbf, missing claims and tampering', async () => {
  const { publicKey, privateKey } = await generateKeyPair('RS256');
  const jwk = { ...await exportJWK(publicKey), kid: 'test-key', alg: 'RS256', use: 'sig' };
  const authenticator = createOAuthAuthenticator(config, { jwks: createLocalJWKSet({ keys: [jwk] }) });
  const sign = (claims = {}, issuer = config.issuer, audience = config.resource) => new SignJWT({ ...baseClaims, ...claims }).setProtectedHeader({ alg: 'RS256', kid: jwk.kid }).setIssuer(issuer).setAudience(audience).sign(privateKey);
  const token = await sign();
  assert.equal((await authenticator(`Bearer ${token}`)).email, 'person@hikoterra.com');
  assert.equal(await authenticator(undefined), null);
  for (const tokenPromise of [sign({}, config.issuer.slice(0, -1)), sign({}, config.issuer, 'https://wrong.example'), sign({ exp: now - 30, iat: now - 300 }), sign({ nbf: now + 60 }), sign({ sub: undefined }), sign({ azp: undefined })]) await assert.rejects(authenticator(`Bearer ${await tokenPromise}`));
  const parts = token.split('.'); parts[2] = `${parts[2][0] === 'a' ? 'b' : 'a'}${parts[2].slice(1)}`;
  await assert.rejects(authenticator(`Bearer ${parts.join('.')}`));
});
test('remote JWKS discovers a newly rotated signing key', async () => {
  const pairs = await Promise.all([generateKeyPair('RS256'), generateKeyPair('RS256')]);
  const keys = await Promise.all(pairs.map(async (pair, i) => ({ ...await exportJWK(pair.publicKey), kid: `rotation-${i}`, alg: 'RS256', use: 'sig' })));
  let currentKeys = [keys[0]];
  const server = createServer((req, res) => { res.setHeader('Content-Type', 'application/json'); res.end(JSON.stringify({ keys: currentKeys })); });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  try {
    const rotatingConfig = { ...config, jwksUrl: `http://127.0.0.1:${server.address().port}/jwks` };
    const authenticate = createOAuthAuthenticator(rotatingConfig, { jwks: createRemoteJWKSet(new URL(rotatingConfig.jwksUrl), { cooldownDuration: 0 }) });
    const sign = i => new SignJWT(baseClaims).setProtectedHeader({ alg: 'RS256', kid: keys[i].kid }).setIssuer(config.issuer).setAudience(config.resource).sign(pairs[i].privateKey);
    assert.ok(await authenticate(`Bearer ${await sign(0)}`));
    currentKeys = keys;
    assert.ok(await authenticate(`Bearer ${await sign(1)}`));
  } finally { await new Promise(resolve => server.close(resolve)); }
});
