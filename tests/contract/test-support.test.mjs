import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { actionIdsFromListResult } from '../support/action-discovery.mjs';
import { writeSanitizedArtifact } from '../support/artifacts.mjs';
import { FixtureLedger } from '../support/ledger.mjs';
import { withResourceLock } from '../support/locks.mjs';
import { McpTestClient } from '../support/mcp-client.mjs';
import { discoverModules } from '../support/module-discovery.mjs';
import { sanitize } from '../support/redact.mjs';
import { verifyWhoami } from '../support/tool-results.mjs';

test('redaction removes credentials, signatures, email addresses, and object payloads', () => {
  const cleaned = sanitize({
    authorization: 'Bearer super-secret', password: 'unsafe', signature: 'a'.repeat(64),
    owner: 'person@example.test', payload: { name: 'Business object', amount: 42 }, message: `Bearer token-value ${'b'.repeat(64)}`
  });
  assert.equal(cleaned.authorization, '[REDACTED]');
  assert.equal(cleaned.password, '[REDACTED]');
  assert.equal(cleaned.signature, '[REDACTED]');
  assert.equal(cleaned.owner, '[REDACTED_EMAIL]');
  assert.equal(cleaned.payload, '[REDACTED_OBJECT]');
  assert.doesNotMatch(JSON.stringify(cleaned), /super-secret|person@example|Business object|b{48}/);
});

test('sanitized artifacts are atomic and cannot escape their root', async t => {
  const root = await mkdtemp(resolve(tmpdir(), 'projeqtor-artifacts-'));
  t.after(() => rm(root, { recursive: true, force: true }));
  const previous = process.env.PROJEQTOR_TEST_ARTIFACT_DIR;
  process.env.PROJEQTOR_TEST_ARTIFACT_DIR = root;
  t.after(() => { if (previous === undefined) delete process.env.PROJEQTOR_TEST_ARTIFACT_DIR; else process.env.PROJEQTOR_TEST_ARTIFACT_DIR = previous; });
  const path = await writeSanitizedArtifact('nested/result.json', { token: 'secret', status: 'ok' });
  assert.deepEqual(JSON.parse(await readFile(path, 'utf8')), { token: '[REDACTED]', status: 'ok' });
  await assert.rejects(writeSanitizedArtifact('../escape.json', {}), /escapes/);
});

test('fixture ledger records only bounded identity metadata and reverses cleanup order', async t => {
  const root = await mkdtemp(resolve(tmpdir(), 'projeqtor-ledger-'));
  t.after(() => rm(root, { recursive: true, force: true }));
  const ledger = await new FixtureLedger(resolve(root, 'fixtures.jsonl'), 'b4-contract-12345678').initialize();
  await ledger.record({ module: 'planning', kind: 'activity', objectClass: 'Activity', id: 10 });
  await ledger.record({ module: 'planning', kind: 'project', objectClass: 'Project', id: 9, cleanupAction: 'close' });
  assert.deepEqual((await ledger.cleanupPlan()).map(entry => entry.id), [9, 10]);
  await assert.rejects(ledger.record({ module: 'planning', kind: 'activity', objectClass: 'Activity', id: 0 }), /positive/);
});

test('resource locks serialize critical suites and are released after failure', async t => {
  const root = await mkdtemp(resolve(tmpdir(), 'projeqtor-locks-'));
  t.after(() => rm(root, { recursive: true, force: true }));
  const events = [];
  await Promise.all([
    withResourceLock('planning-engine', async () => { events.push('a:start'); await new Promise(resolveDelay => setTimeout(resolveDelay, 40)); events.push('a:end'); }, { root }),
    withResourceLock('planning-engine', async () => { events.push('b:start'); events.push('b:end'); }, { root })
  ]);
  assert.ok(events.join(',') === 'a:start,a:end,b:start,b:end' || events.join(',') === 'b:start,b:end,a:start,a:end');
  await assert.rejects(withResourceLock('cron-control', async () => { throw new Error('expected'); }, { root }), /expected/);
  await withResourceLock('cron-control', async () => {}, { root, timeoutMs: 200 });
});

test('MCP test client never sends tokens in JSON payloads or logger events', async () => {
  const observed = [];
  const logged = [];
  const fakeFetch = async (_url, options) => {
    observed.push(options);
    return new Response(JSON.stringify({ jsonrpc: '2.0', id: 1, result: { tools: [] } }), { status: 200, headers: { 'content-type': 'application/json' } });
  };
  const client = new McpTestClient({ url: 'http://mcp:3000/mcp', token: 'never-log-this', fetchImpl: fakeFetch, logger: event => logged.push(event) });
  await client.tools();
  assert.equal(observed[0].headers.Authorization, 'Bearer never-log-this');
  assert.doesNotMatch(observed[0].body, /never-log-this/);
  assert.doesNotMatch(JSON.stringify(logged), /never-log-this/);
});

test('action discovery reads the canonical action field from MCP list results', () => {
  assert.deepEqual(actionIdsFromListResult({
    structuredContent: {
      returned: 3, hasMore: false, nextCursor: null,
      items: [
        { action: 'configuration.admin.execute' },
        { action: 'configuration.parameter.set' },
        { action: 'cron.start' }
      ]
    }
  }), [
    'configuration.admin.execute',
    'configuration.parameter.set',
    'cron.start'
  ]);
});

test('action discovery fails closed on tool, bridge, malformed, and pagination errors', () => {
  assert.throws(() => actionIdsFromListResult({
    isError: true,
    structuredContent: { ok: false, error: { code: 'api_error', message: 'Bridge unavailable' } }
  }), /api_error/);
  assert.throws(() => actionIdsFromListResult({
    structuredContent: { ok: false, error: { code: 'api_error', message: 'Bridge unavailable' } }
  }), /api_error/);
  assert.throws(() => actionIdsFromListResult({ structuredContent: { items: [] } }), /malformed action page/);
  assert.throws(() => actionIdsFromListResult({
    structuredContent: { returned: 1, hasMore: true, nextCursor: 'signed', items: [{ action: 'cron.start' }] }
  }), /incomplete paginated result/);
});

test('whoami verification fails closed on MCP errors and actor mismatch', () => {
  assert.deepEqual(verifyWhoami({ structuredContent: { username: 'admin' } }, 'admin'), { username: 'admin' });
  assert.throws(() => verifyWhoami({
    isError: true,
    structuredContent: { ok: false, error: { code: 'api_error', message: 'Bridge unavailable' } }
  }, 'admin'), /api_error/);
  assert.throws(() => verifyWhoami({ structuredContent: { username: 'other' } }, 'admin'), /actor mismatch/);
});

test('all twelve module contracts are discoverable and uniquely owned', async () => {
  const modules = await discoverModules();
  assert.equal(modules.length, 12);
  assert.equal(new Set(modules.map(module => module.id)).size, 12);
  assert.deepEqual(modules.map(module => module.id), [...modules.map(module => module.id)].sort());
});
