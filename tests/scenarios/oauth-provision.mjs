// Executed only inside the disposable harness's trusted MCP container.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createHash, createHmac } from 'node:crypto';
const key = readFileSync(process.env.PROJEQTOR_SIGNING_KEY_FILE, 'utf8').trim();
assert.ok(process.env.OAUTH_TEST_ID, 'A disposable fixture ID is required');
const users = ['one', 'two'].map(subject => `auth0-${createHash('sha256').update(`https://test.auth0.com/\0email|${process.env.OAUTH_TEST_ID}-${subject}`).digest('hex')}`);
async function request(username, uri, input) {
  const method = input === undefined ? 'GET' : 'POST';
  const body = input === undefined ? '' : JSON.stringify(input);
  const timestamp = String(Math.floor(Date.now() / 1000));
  const signature = createHmac('sha256', key).update(`${timestamp}\n${username}\n${method}\n${uri}\n${createHash('sha256').update(body).digest('hex')}`).digest('hex');
  const response = await fetch(`${process.env.PROJEQTOR_API_URL}/${uri}`, {
    method, headers: { 'Content-Type': 'application/json', 'X-Projeqtor-User': username, 'X-Projeqtor-Timestamp': timestamp, 'X-Projeqtor-Signature': signature },
    ...(method === 'POST' ? { body } : {}), signal: AbortSignal.timeout(30000)
  });
  return { status: response.status, data: await response.json() };
}
const provision = (username, email) => request(username, '__mcp/v2/oauth/provision', { username, displayName: email, email, provider: 'auth0' });
if (process.env.OAUTH_TEST_DENIED === '1') {
  for (const username of users) {
    const result = await provision(username, 'fixture@hikoterra.com');
    assert.equal(result.status, 403);
    assert.equal(result.data.error.code, 'oauth_user_unavailable');
  }
  process.stdout.write(JSON.stringify({ ok: true }));
} else {
  const concurrent = await Promise.all(Array.from({ length: 8 }, () => provision(users[0], 'one@hikoterra.com')));
  for (const result of concurrent) assert.equal(result.status, 200, 'Concurrent native provisioning must succeed');
  assert.equal(new Set(concurrent.map(result => result.data.id)).size, 1);
  assert.equal(concurrent.filter(result => result.data.created).length, 1);
  const second = await provision(users[1], 'one@hikoterra.com');
  assert.equal(second.status, 200);
  assert.notEqual(second.data.id, concurrent[0].data.id);
  const renamed = await provision(users[0], 'renamed@pcnzl.com');
  assert.equal(renamed.data.id, concurrent[0].data.id);
  const whoami = await request(users[0], '__mcp/v2/whoami');
  assert.equal(whoami.status, 200);
  assert.equal(whoami.data.username, 'renamed@pcnzl.com');
  assert.equal(whoami.data.authenticationProvider, 'auth0');
  assert.equal(whoami.data.profileCode, 'TM');
  assert.equal(whoami.data.credentialsExposed, false);
  assert.ok(!JSON.stringify(whoami.data).includes(users[0]));
  process.stdout.write(JSON.stringify({ ok: true, ids: [renamed.data.id, second.data.id], concurrency: 8, nativeTeamMember: true, stableEmailChange: true }));
}
