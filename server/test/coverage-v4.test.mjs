import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtemp, mkdir, readFile, readdir, rm, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';

const root = new URL('../../', import.meta.url);
const readJson = async (relative) => JSON.parse(await readFile(new URL(relative, root), 'utf8'));

test('Beta 4 coverage manifests are complete and module-owned', async () => {
  const inventory = await readJson('bridge/source-inventory-v4.json');
  const handlers = await readJson('bridge/ui-handler-policy-v4.json');
  const classes = await readJson('bridge/class-policy-v4.json');
  const moduleFiles = (await readdir(new URL('policy/modules/', root))).filter((name) => name.endsWith('.json'));
  const modules = ['core', 'planning', 'ticketing', 'scrum', 'follow_up', 'steering', 'financial', 'products', 'hr', 'environment', 'tools', 'reports', 'configuration'];

  assert.equal(inventory.sourceFileCount, inventory.files.length);
  assert.equal(handlers.entrypointCount, handlers.handlers.length);
  assert.equal(classes.expectedInstalledClassCount, 640);
  assert.equal(handlers.unknownCount, 0);
  assert.equal(handlers.deferredCount, 0);
  assert.equal(classes.unknownCount, 0);
  assert.equal(moduleFiles.length, modules.length);
  assert.deepEqual(Object.keys(handlers.moduleCounts), modules);
  assert.ok(modules.every((module) => handlers.moduleCounts[module] > 0));
  assert.ok(inventory.surfaceCounts.report > 0);
  assert.ok(inventory.surfaceCounts.api > 0);
  assert.ok(inventory.surfaceCounts.sso > 0);
  assert.ok(inventory.surfaceCounts.plugin > 0);
  assert.ok(inventory.files.some((entry) => entry.sourceRole === 'included_library'));
});

test('Beta 4 policy fixes known unsafe and false classifications', async () => {
  const handlers = await readJson('bridge/ui-handler-policy-v4.json');
  const inventory = await readJson('bridge/source-inventory-v4.json');
  const classes = await readJson('bridge/class-policy-v4.json');
  const byPath = new Map(handlers.handlers.map((handler) => [handler.path, handler]));
  const inventoryByPath = new Map(inventory.files.map((entry) => [entry.path, entry]));
  for (const path of ['tool/backupFilter.php', 'tool/backupLayout.php']) {
    assert.equal(byPath.get(path)?.classification, 'read_only', path);
    assert.equal(byPath.get(path)?.mappingSource, 'explicit_session_or_library', path);
  }
  assert.equal(byPath.get('tool/backupReportLayout.php')?.classification, 'registered_action');
  assert.equal(byPath.has('tool/file.php'), false, 'included file helper is not an executable handler');
  assert.equal(inventoryByPath.get('tool/file.php')?.sourceRole, 'included_library');
  for (const path of ['tool/saveWorkTokenClientContract.php', 'tool/saveWorkTokenMarkup.php']) {
    assert.equal(byPath.get(path)?.module, 'financial', path);
    assert.equal(byPath.get(path)?.classification, 'registered_action', path);
  }
  assert.equal(byPath.get('tool/saveObjectMultiplePwd.php')?.exclusionReason, 'secrets_or_credentials');
  assert.equal(byPath.get('tool/installAutoInstall.php')?.exclusionReason, 'plugin_installation');
  assert.equal(byPath.get('tool/sendRequestResetPassword.php')?.mappedActions[0], 'user.trigger_password_reset');
  assert.deepEqual(byPath.get('tool/deleteOAuthClient.php')?.mappedClasses, ['OAuthClient']);
  assert.equal(byPath.get('tool/deleteOAuthClient.php')?.mappingSource, 'explicit_fixed_class_crud');
  assert.equal(byPath.get('tool/commonFilter.php')?.mappedActions[0], 'configuration.view.manage');
  assert.equal(byPath.get('tool/commonFilter.php')?.mappingSource, 'explicit_action_equivalence');
  assert.equal(byPath.get('api/index.php')?.classification, 'generic_crud');
  assert.equal(byPath.get('report/ticketReport.php')?.module, 'reports');
  assert.ok(!byPath.has('report/header.php'), 'included report helpers are not executable handlers');
  assert.ok(handlers.handlers.every((handler) => handler.coverageStatus === 'covered'));

  const expectedClassModules = {
    RaciAssignment: 'steering',
    Phase: 'financial',
    ProjectExpense: 'financial',
    TicketDelayPerProject: 'ticketing',
    Complexity: 'financial',
    ComplexityValues: 'financial'
  };
  for (const [className, module] of Object.entries(expectedClassModules)) {
    assert.equal(classes.classes[className]?.module, module, className);
  }
});

test('runtime loads v4 manifests and recursively inventories every installed PHP surface', async t => {
  const policyPath = new URL('../../bridge/policy.php', import.meta.url);
  const source = await readFile(policyPath, 'utf8');
  assert.match(source, /MCP_POLICY_VERSION = 4/);
  for (const manifest of ['source-inventory-v4.json', 'class-policy-v4.json', 'ui-handler-policy-v4.json']) assert.match(source, new RegExp(manifest.replace('.', '\\.')));
  assert.match(source, /RecursiveDirectoryIterator/);
  for (const surface of ['api', 'plugin', 'report', 'sso', 'tool', 'view']) assert.match(source, new RegExp(`'${surface}'`));
  assert.doesNotMatch(source, /class-policy-v3|ui-handler-policy-v3/);

  const fixture = await mkdtemp(resolve(tmpdir(), 'projeqtor-v4-inventory-'));
  t.after(() => rm(fixture, { recursive: true, force: true }));
  await mkdir(resolve(fixture, 'sso/nested'), { recursive: true });
  await mkdir(resolve(fixture, 'tool'), { recursive: true });
  await mkdir(resolve(fixture, 'untracked'), { recursive: true });
  await writeFile(resolve(fixture, 'sso/nested/entry.php'), '<?php');
  await writeFile(resolve(fixture, 'tool/entry.php'), '<?php');
  await writeFile(resolve(fixture, 'tool/parametersLocation.php'), '<?php');
  await writeFile(resolve(fixture, 'untracked/ignored.php'), '<?php');
  const php = `require ${JSON.stringify(policyPath.pathname)}; echo json_encode(mcpInstalledSourceFiles(${JSON.stringify(fixture)}), JSON_THROW_ON_ERROR);`;
  const files = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
  assert.deepEqual(Object.keys(files), ['sso/nested/entry.php', 'tool/entry.php']);
});
