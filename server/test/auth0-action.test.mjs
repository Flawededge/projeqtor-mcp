import test from 'node:test';
import assert from 'node:assert/strict';
import { createRequire } from 'node:module';
const { onExecutePostLogin } = createRequire(import.meta.url)('../../deployment/auth/auth0-post-login.cjs');
const initial = {
  secrets: { MCP_CLIENT_ID: 'claude' }, client: { client_id: 'claude' },
  connection: { name: 'email', strategy: 'email' }, user: { email: 'Person@HIKOTERRA.COM', email_verified: true },
  transaction: { protocol: 'oidc-basic-profile' },
  request: { query: { response_type: 'code', code_challenge_method: 'S256', code_challenge: 'a'.repeat(43) } }
};
async function run(event) {
  const result = { denied: false, claims: {} };
  await onExecutePostLogin(event, { access: { deny: () => { result.denied = true; } }, accessToken: { setCustomClaim: (key, value) => { result.claims[key] = value; } } });
  return result;
}
test('Action admits both domains on login and refresh and ignores unrelated clients', async () => {
  for (const email of ['Person@HIKOTERRA.COM', 'person@pcnzl.com']) for (const refresh of [false, true]) {
    const event = { ...initial, user: { email, email_verified: true } };
    if (refresh) { event.transaction = { protocol: 'oauth2-refresh-token' }; event.request = { query: {} }; }
    const result = await run(event);
    assert.equal(result.denied, false);
    assert.equal(result.claims['https://conceptpower.ddns.net/projeqtor/email'], email.toLowerCase());
    assert.equal(result.claims['https://conceptpower.ddns.net/projeqtor/email_verified'], true);
  }
  assert.deepEqual(await run({ ...initial, client: { client_id: 'unrelated' }, user: {} }), { denied: false, claims: {} });
});
test('Action denies invalid identity or weak PKCE without emitting claims', async () => {
  const events = [
    ...['user@evil.example', 'user@hikoterra.com.evil.example', 'user@@hikoterra.com', 'user @pcnzl.com', ''].map(email => ({ ...initial, user: { email, email_verified: true } })),
    { ...initial, user: { ...initial.user, email_verified: false } },
    { ...initial, connection: { name: 'email', strategy: 'waad' } }, { ...initial, request: { query: {} } },
    { ...initial, request: { query: { ...initial.request.query, code_challenge_method: 'plain' } } },
    { ...initial, request: { query: { ...initial.request.query, response_type: 'token' } } }
  ];
  for (const event of events) assert.deepEqual(await run(event), { denied: true, claims: {} });
  assert.equal((await run({ ...events[0], transaction: { protocol: 'oauth2-refresh-token' } })).denied, true);
  await assert.rejects(run({ ...initial, secrets: {} }), /MCP_CLIENT_ID/);
});
