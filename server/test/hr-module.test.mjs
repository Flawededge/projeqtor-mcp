import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import hr, { HR_ACTIONS, HR_CLASSES, HR_HANDLERS } from '../src/modules/hr/index.mjs';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));

function phpJson(source) {
  return JSON.parse(execFileSync('php', ['-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-r', source], {
    cwd: repositoryRoot, encoding: 'utf8'
  }));
}

function bridgeBootstrap(body) {
  const root = JSON.stringify(repositoryRoot);
  return `
    $root=${root};
    require $root.'/bridge/core/module-graph.php';
    require $root.'/bridge/core/action-domains.php';
    require $root.'/bridge/core/module-registry.php';
    ${body}
  `;
}

test('HR server pack owns its semantic actions and exhaustive native handlers without adding a convenience tool', () => {
  assert.equal(hr.id, 'hr');
  assert.deepEqual(hr.dependencies, ['core', 'configuration', 'environment']);
  assert.deepEqual(hr.claims.actions, HR_ACTIONS);
  assert.deepEqual(hr.claims.handlers, HR_HANDLERS);
  assert.deepEqual(hr.claims.jobs, ['hr.leave.calendar.export']);
  assert.deepEqual(hr.claims.classes, HR_CLASSES);
  assert.equal(HR_ACTIONS.length, 16);
  assert.equal(HR_HANDLERS.length, 11);
  assert.deepEqual(hr.claims.tools, []);
  assert.equal(new Set(HR_ACTIONS).size, HR_ACTIONS.length);
  assert.equal(new Set(HR_HANDLERS).size, HR_HANDLERS.length);
});

test('HR bridge descriptors are bounded, typed, and map each mutating handler once', () => {
  const descriptor = phpJson(bridgeBootstrap(`
    $hr=mcpModuleCatalog()['hr'];
    echo json_encode($hr, JSON_UNESCAPED_SLASHES);
  `));
  assert.deepEqual(Object.keys(descriptor.actions), HR_ACTIONS);
  const mapped = Object.values(descriptor.actions).flatMap(action => action.mappedHandlers);
  assert.deepEqual([...mapped].sort(), [...HR_HANDLERS].sort());
  assert.equal(new Set(mapped).size, mapped.length);
  for (const [name, action] of Object.entries(descriptor.actions)) {
    assert.equal(action.async, name === 'hr.leave.calendar.export', name + ' has the expected execution mode');
    assert.equal(action.idempotency.supported, true);
    assert.equal(action.idempotency.scope, 'actor');
    assert.ok(action.schema?.properties);
    assert.ok(action.resultSchema?.properties);
    assert.notDeepEqual(action.resultSchema, { ok: true });
    if (name !== 'hr.leave.calendar.export') {
      const collection = Object.values(action.schema.properties).find(property => property.type === 'array');
      assert.equal(collection?.maxItems, 200, `${name} must cap its batch at 200`);
      assert.equal(action.transaction, 'atomic');
      assert.ok(action.resultSchema.properties.items);
      assert.ok(action.resultSchema.properties.effects);
    }
    if (['destructive', 'administrative'].includes(action.risk)) {
      assert.equal(typeof action.preview, 'string', `${name} must have a guarded preview`);
    }
  }
  assert.equal(descriptor.actions['hr.leave.calendar.export'].risk, 'read');
  assert.equal(descriptor.actions['hr.leave.calendar.export'].transaction, 'worker');
  assert.equal(descriptor.actions['hr.leave.calendar.export'].retryPolicy, 'safe');
});

test('HR schemas reject invalid dates, oversized batches, missing versions, and unexpected fields', () => {
  const cases = phpJson(bridgeBootstrap(`
    $r=mcpActionRegistry();
    $valid=array('requests'=>array(array('operation'=>'create','employeeId'=>1,'leaveTypeId'=>2,'statusId'=>3,'startDate'=>'2026-10-01','startAMPM'=>'AM','endDate'=>'2026-10-01','endAMPM'=>'PM')));
    $invalidDate=$valid;$invalidDate['requests'][0]['startDate']='01/10/2026';
    $oversized=array('assignments'=>array_fill(0,201,array('resourceId'=>1,'skillId'=>2,'skillLevelId'=>3)));
    $missingVersion=array('leaves'=>array(array('leaveId'=>1)));
    $unexpected=$valid;$unexpected['password']='not-accepted';
    echo json_encode(array(
      'valid'=>mcpValidateSchemaValue($valid,$r['hr.leave.submit']['schema']),
      'invalidDate'=>mcpValidateSchemaValue($invalidDate,$r['hr.leave.submit']['schema']),
      'oversized'=>mcpValidateSchemaValue($oversized,$r['hr.skill.assign']['schema']),
      'missingVersion'=>mcpValidateSchemaValue($missingVersion,$r['hr.leave.delete']['schema']),
      'unexpected'=>mcpValidateSchemaValue($unexpected,$r['hr.leave.submit']['schema'])
    ));
  `));
  assert.deepEqual(cases.valid, []);
  assert.ok(cases.invalidDate.some(error => error.code === 'pattern'));
  assert.ok(cases.oversized.some(error => error.code === 'maxItems'));
  assert.ok(cases.missingVersion.some(error => error.code === 'required'));
  assert.ok(cases.unexpected.some(error => error.code === 'additional_property'));
});

test('HR implementation does not accept direct credentials and marks guarded lifecycle operations', async () => {
  const [actions, schema] = await Promise.all([
    readFile(resolve(repositoryRoot, 'bridge/modules/hr/actions.php'), 'utf8'),
    readFile(resolve(repositoryRoot, 'bridge/modules/hr/schema.php'), 'utf8')
  ]);
  assert.doesNotMatch(actions, /password|api[_-]?key|oauth|smtp|credential/i);
  assert.doesNotMatch(schema, /password|api[_-]?key|oauth|smtp|credential/i);
  for (const action of [
    'hr.employee.manager.remove', 'hr.employment.contract.close', 'hr.leave.decide',
    'hr.leave.delete', 'hr.leave.entitlement.adjust', 'hr.skill.remove',
    'hr.leave.permissions.configure'
  ]) assert.ok(HR_ACTIONS.includes(action));
});
