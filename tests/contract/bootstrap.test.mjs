import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const compose = await readFile(new URL('../compose.yaml', import.meta.url), 'utf8');
const bootstrap = await readFile(new URL('../support/bootstrap.php', import.meta.url), 'utf8');

test('fresh database bootstrap gates application startup', () => {
  const service = compose.slice(compose.indexOf('  db-bootstrap:'), compose.indexOf('\n  app:'));
  const app = compose.slice(compose.indexOf('  app:'), compose.indexOf('\n  worker:'));
  assert.match(service, /php \/bootstrap\.php/);
  assert.match(service, /support\/bootstrap\.php:\/bootstrap\.php:ro/);
  assert.match(service, /no-new-privileges:true/);
  assert.match(app, /db-bootstrap: \{condition: service_completed_successfully\}/);
});

test('harness waits for PostgreSQL health before starting bootstrap', async () => {
  const harness = await readFile(new URL('../support/harness.mjs', import.meta.url), 'utf8');
  assert.equal((harness.match(/\['up', '-d', '--wait', 'db'\]/g) ?? []).length, 2);
});

test('fresh bootstrap uses official migrations and never assigns credentials', () => {
  assert.match(bootstrap, /require '\/var\/www\/html\/db\/maintenance\.php'/);
  assert.match(bootstrap, /DROP TABLE parameter/);
  assert.match(bootstrap, /V13\.1\.0/);
  assert.match(bootstrap, /'beta4-admin' => 'ADM'.*'beta4-manager' => 'PL'.*'beta4-member' => 'TM'.*'beta4-denied' => 'G'/s);
  assert.doesNotMatch(bootstrap, /->(?:password|apiKey)\s*=/);
});

test('harness principals are isolated tokens for all acceptance actors', async () => {
  const harness = await readFile(new URL('../support/harness.mjs', import.meta.url), 'utf8');
  for (const actor of ['beta4-admin', 'beta4-manager', 'beta4-member', 'beta4-denied']) assert.match(harness, new RegExp(`'${actor}'`));
  assert.match(harness, /tokenSha256: sha256\(token\)/);
});

test('disposable bootstrap captures state and enables every optional acceptance family', () => {
  assert.match(bootstrap, /mcp-harness-module-state\.json/);
  assert.match(bootstrap, /if\(!is_file\(\$moduleSnapshotPath\)\)/);
  for (const moduleName of [
    'moduleAbsence', 'moduleNotification', 'moduleDataCloning', 'moduleAssets',
    'moduleLocalization', 'modulePoker', 'moduleChecklist', 'moduleMail',
    'moduleTokenManagement', 'moduleHumanResource', 'moduleSkillManagement',
    'moduleVoting', 'moduleCrmProspect', 'moduleAbacus'
  ]) assert.match(bootstrap, new RegExp(`'${moduleName}'`));
  assert.match(bootstrap, /foreach\(array_keys\(\$parentModuleIds\) as \$parentModuleId\)/);
});
