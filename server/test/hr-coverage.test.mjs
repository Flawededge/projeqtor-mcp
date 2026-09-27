import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { HR_HANDLERS } from '../src/modules/hr/index.mjs';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));

test('HR claims every installed mutating HR handler and leaves no HR unknown or deferred surface', async () => {
  const policy = JSON.parse(await readFile(resolve(repositoryRoot, 'bridge/ui-handler-policy-v4.json'), 'utf8'));
  const hr = policy.handlers.filter(handler => handler.module === 'hr');
  const mutating = hr.filter(handler => handler.mutationTypes.length > 0);
  assert.deepEqual(mutating.map(handler => handler.id).sort(), [...HR_HANDLERS].sort());
  assert.equal(hr.some(handler => ['unknown', 'deferred_beta4'].includes(handler.classification)), false);
  for (const handler of hr) {
    assert.match(handler.sourceHash, /^[a-f0-9]{64}$/);
    if (handler.mutationTypes.length > 0) {
      assert.equal(handler.classification, 'registered_action');
      assert.equal(typeof handler.coverageTestId, 'string');
    } else {
      assert.equal(handler.classification, 'read_only');
    }
  }
});
