import test from 'node:test';
import assert from 'node:assert/strict';
import { SignJWT, createLocalJWKSet, exportJWK, generateKeyPair } from 'jose';
import {
  createOAuthAuthenticator,
  loadOAuthConfig,
  oauthChallenge,
  protectedResourceMetadata,
  validateOAuthClaims
} from '../src/oauth.mjs';

const tenant = '11111111-1111-4111-8111-111111111111';
const objectId = '22222222-2222-4222-8222-222222222222';
const env = {
  MCP_OAUTH_ENABLED: 'true',
  MCP_OAUTH_ISSUER: 'https://conceptpower.ddns.net/projeqtor-auth/realms/projeqtor',
  MCP_OAUTH_RESOURCE: 'https://conceptpower.ddns.net/mcp_projeqtor',
  MCP_OAUTH_ENTRA_TENANT_ID: tenant,
  MCP_OAUTH_ALLOWED_DOMAINS: 'hikoterra.com,pcnzl.com',
  MCP_OAUTH_REQUIRED_SCOPES: 'projeqtor:read projeqtor:write'
};
const config = loadOAuthConfig(env);
const baseClaims = {
  entra_oid: objectId,
  entra_tid: tenant,
  name: 'Example User',
  scope: 'openid projeqtor:read projeqtor:write'
};

test('OAuth is opt-in and enabled configuration is HTTPS-only', () => {
  assert.deepEqual(loadOAuthConfig({}), { enabled: false });
  assert.throws(() => loadOAuthConfig({ ...env, MCP_OAUTH_ISSUER: 'http://keycloak/realms/projeqtor' }), /must use https/);
  assert.equal(config.jwksUrl, `${env.MCP_OAUTH_ISSUER}/protocol/openid-connect/certs`);
  assert.equal(config.metadataUrl, 'https://conceptpower.ddns.net/.well-known/oauth-protected-resource/mcp_projeqtor');
});

test('either approved Microsoft domain independently qualifies', () => {
  for (const email of ['chris@hikoterra.com', 'peet@pcnzl.com']) {
    const principal = validateOAuthClaims({ ...baseClaims, upn: email }, { alg: 'RS256' }, config);
    assert.equal(principal.email, email);
    assert.equal(principal.username, `entra-${objectId}`);
    assert.equal(principal.authenticationProvider, 'microsoft');
  }
});

test('wrong tenant, guests, other domains, missing scopes, and weak algorithms fail closed', () => {
  assert.throws(() => validateOAuthClaims({ ...baseClaims, upn: 'user@other.example' }, { alg: 'RS256' }, config), /domain/);
  assert.throws(() => validateOAuthClaims({ ...baseClaims, entra_tid: '33333333-3333-4333-8333-333333333333', upn: 'user@hikoterra.com' }, { alg: 'RS256' }, config), /wrong Microsoft tenant/);
  assert.throws(() => validateOAuthClaims({ ...baseClaims, upn: 'guest_other.example#EXT#@hikoterra.com' }, { alg: 'RS256' }, config), /Guest/);
  assert.throws(() => validateOAuthClaims({ ...baseClaims, scope: 'projeqtor:read', upn: 'user@pcnzl.com' }, { alg: 'RS256' }, config), /projeqtor:write/);
  assert.throws(() => validateOAuthClaims({ ...baseClaims, upn: 'user@pcnzl.com' }, { alg: 'HS256' }, config), /RS256/);
});

test('protected-resource metadata and bearer challenge use the RFC 9728 path', () => {
  assert.deepEqual(protectedResourceMetadata(config), {
    resource: env.MCP_OAUTH_RESOURCE,
    authorization_servers: [env.MCP_OAUTH_ISSUER],
    bearer_methods_supported: ['header'],
    scopes_supported: ['projeqtor:read', 'projeqtor:write'],
    resource_name: 'ProjeQtOr MCP'
  });
  const challenge = oauthChallenge(config, 'invalid_token', 'Login required');
  assert.match(challenge, /^Bearer resource_metadata=/);
  assert.match(challenge, /resource_metadata="https:\/\/conceptpower\.ddns\.net\/\.well-known\/oauth-protected-resource\/mcp_projeqtor"/);
  assert.match(challenge, /scope="projeqtor:read projeqtor:write"/);
});

test('authenticator binds issuer, audience, algorithm and the validated principal', async () => {
  let options;
  const authenticator = createOAuthAuthenticator(config, {
    jwks: {},
    jwtVerify: async (token, jwks, received) => {
      assert.equal(token, 'signed-token');
      assert.deepEqual(jwks, {});
      options = received;
      return {
        payload: { ...baseClaims, upn: 'person@hikoterra.com', exp: 10, iat: 1 },
        protectedHeader: { alg: 'RS256' }
      };
    }
  });
  assert.equal(await authenticator(undefined), null);
  const principal = await authenticator('Bearer signed-token');
  assert.equal(principal.email, 'person@hikoterra.com');
  assert.equal(options.issuer, env.MCP_OAUTH_ISSUER);
  assert.equal(options.audience, env.MCP_OAUTH_RESOURCE);
  assert.deepEqual(options.algorithms, ['RS256']);
  assert.deepEqual(options.requiredClaims, ['exp', 'iat', 'entra_oid', 'entra_tid']);
});

test('real JWT verification rejects wrong audience, expiry, future nbf, and tampering', async () => {
  const { publicKey, privateKey } = await generateKeyPair('RS256');
  const publicJwk = await exportJWK(publicKey);
  publicJwk.kid = 'oauth-test-key';
  publicJwk.alg = 'RS256';
  publicJwk.use = 'sig';
  const authenticator = createOAuthAuthenticator(config, {
    jwks: createLocalJWKSet({ keys: [publicJwk] })
  });
  const now = Math.floor(Date.now() / 1000);
  const sign = ({
    audience = env.MCP_OAUTH_RESOURCE,
    expiration = now + 300,
    claims = {}
  } = {}) => new SignJWT({
    ...baseClaims,
    upn: 'signed@pcnzl.com',
    ...claims
  })
    .setProtectedHeader({ alg: 'RS256', kid: publicJwk.kid })
    .setIssuer(env.MCP_OAUTH_ISSUER)
    .setAudience(audience)
    .setIssuedAt(now)
    .setExpirationTime(expiration)
    .sign(privateKey);

  const valid = await sign();
  assert.equal((await authenticator(`Bearer ${valid}`)).email, 'signed@pcnzl.com');
  await assert.rejects(async () => authenticator(`Bearer ${await sign({ audience: 'https://wrong.example/mcp' })}`));
  await assert.rejects(async () => authenticator(`Bearer ${await sign({ expiration: now - 30 })}`));
  await assert.rejects(async () => authenticator(`Bearer ${await sign({ claims: { nbf: now + 60 } })}`));
  const segments = valid.split('.');
  segments[2] = `${segments[2][0] === 'a' ? 'b' : 'a'}${segments[2].slice(1)}`;
  await assert.rejects(async () => authenticator(`Bearer ${segments.join('.')}`));
});
