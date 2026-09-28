import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile, readdir } from 'node:fs/promises';

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
