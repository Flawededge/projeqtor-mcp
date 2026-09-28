import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';

const root = new URL('../', import.meta.url);
const [compose, harness, runner] = await Promise.all([
  readFile(new URL('compose.yaml', root), 'utf8'),
  readFile(new URL('support/harness.mjs', root), 'utf8'),
  readFile(new URL('run-suite.mjs', root), 'utf8')
]);

test('disposable runner mounts separate token files for all four actors', () => {
  for (const role of ['ADMIN', 'MANAGER', 'MEMBER', 'DENIED']) {
    assert.match(harness, new RegExp(`BETA4_${role}_TOKEN_FILE`));
    assert.match(compose, new RegExp(`BETA4_${role}_TOKEN_FILE`));
    assert.match(compose, new RegExp(`PROJEQTOR_TEST_${role}_TOKEN_FILE`));
  }
  assert.doesNotMatch(compose, /secrets:\/users\.json.*test-runner/s);
});

test('acceptance mode executes the import and identity scenario after coverage checks', () => {
  assert.match(runner, /runImportIdentityScenario/);
  assert.match(runner, /summary\.importIdentity = await runImportIdentityScenario\(\{ runId \}\)/);
  assert.match(runner, /if \(mode === 'acceptance'\)/);
});
