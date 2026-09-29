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
  const semantic = mutating.filter(handler => handler.classification === 'registered_action');
  const generic = mutating.filter(handler => handler.classification === 'generic_crud');
  assert.deepEqual(semantic.map(handler => handler.id).sort(), [...HR_HANDLERS].sort());
  assert.deepEqual(generic.map(handler => handler.id).sort(), ['tool:removeTranslatorLanguage', 'tool:saveTranslatorLanguage']);
  assert.equal(hr.some(handler => ['unknown', 'deferred_beta4'].includes(handler.classification)), false);
  for (const handler of hr) {
    assert.match(handler.sourceHash, /^[a-f0-9]{64}$/);
    if (handler.mutationTypes.length > 0) {
      assert.ok(['registered_action', 'generic_crud'].includes(handler.classification));
      if (handler.classification === 'registered_action') assert.equal(typeof handler.coverageTestId, 'string');
      else assert.deepEqual(handler.mappedClasses, ['LocalizationTranslatorLanguage']);
    } else {
      assert.equal(handler.classification, 'read_only');
    }
  }
});
