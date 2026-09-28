import assert from 'node:assert/strict';
import { mkdtemp, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { McpTestClient, TEST_ACTOR_TOKEN_ENV } from '../../tests/support/mcp-client.mjs';
import { structuredToolError } from '../../tests/support/tool-results.mjs';
import {
  BATCH_SIZES, TASK_COUNT, buildActivities, splitBatches
} from '../../tests/scenarios/import-identity-support.mjs';

test('562 activity operations use unique per-item keys and exact 200/200/162 batches', () => {
  const operations = buildActivities({
    runId: 'b4-20260928-abcdef12', idProject: 42,
    baseData: { idActivityType: 1, idStatus: 1 }
  });
  assert.equal(operations.length, TASK_COUNT);
  assert.equal(new Set(operations.map(item => item.idempotencyKey)).size, TASK_COUNT);
  assert.ok(operations.every(item => item.data.idProject === 42));
  assert.deepEqual(splitBatches(operations).map(batch => batch.length), [...BATCH_SIZES]);
});

test('actor factory creates fresh isolated clients from allowlisted token files', async () => {
  const root = await mkdtemp(join(tmpdir(), 'b4-actor-client-'));
  const previous = process.env.PROJEQTOR_TEST_MANAGER_TOKEN_FILE;
  try {
    const path = join(root, 'manager.token');
    await writeFile(path, 'disposable-test-token\n', { mode: 0o600 });
    process.env.PROJEQTOR_TEST_MANAGER_TOKEN_FILE = path;
    const first = await McpTestClient.forActor('beta4-manager', { url: 'http://mcp.invalid/mcp' });
    const second = await McpTestClient.forActor('beta4-manager', { url: 'http://mcp.invalid/mcp' });
    assert.notEqual(first, second);
    assert.equal(first.sessionId, null);
    assert.equal(second.sessionId, null);
    assert.equal(TEST_ACTOR_TOKEN_ENV['beta4-manager'], 'PROJEQTOR_TEST_MANAGER_TOKEN_FILE');
    await assert.rejects(() => McpTestClient.forActor('not-an-actor'), /Unsupported disposable MCP actor/);
  } finally {
    if (previous === undefined) delete process.env.PROJEQTOR_TEST_MANAGER_TOKEN_FILE;
    else process.env.PROJEQTOR_TEST_MANAGER_TOKEN_FILE = previous;
    await rm(root, { recursive: true, force: true });
  }
});

test('structuredToolError rejects malformed success and accepts exact codes', () => {
  const failure = { isError: true, structuredContent: { ok: false, error: { code: 'forbidden', message: 'denied' } } };
  assert.equal(structuredToolError(failure, 'forbidden').code, 'forbidden');
  assert.throws(() => structuredToolError({ structuredContent: { ok: true } }, 'forbidden'), /unexpectedly succeeded/);
  assert.throws(() => structuredToolError(failure, 'job_not_found'), /expected job_not_found/);
});
