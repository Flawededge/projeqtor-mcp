import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import steering, { STEERING_ACTIONS, STEERING_HANDLERS } from '../src/modules/steering/index.mjs';

const phpDescriptor = () => JSON.parse(execFileSync('php', ['-r', `
  function mcpObjectSchema($properties=[],$required=[],$additional=false){return ['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>$additional];}
  function mcpActionSpec($schema,$resultSchema,$risk,$async,$callable,$handlers,$test,$options=[]){return array_merge(['schema'=>$schema,'resultSchema'=>$resultSchema,'risk'=>$risk,'async'=>$async,'mappedHandlers'=>$handlers,'testContract'=>$test,'callable'=>$callable],$options);}
  echo json_encode(require ${JSON.stringify(new URL('../../bridge/modules/steering/module.php', import.meta.url).pathname)});
`], { encoding: 'utf8' }));

test('Steering owns all requested workflow families as independent canonical actions', () => {
  assert.equal(steering.id, 'steering');
  assert.deepEqual(steering.dependencies, ['core', 'configuration', 'planning', 'tools']);
  assert.deepEqual(steering.claims.actions, STEERING_ACTIONS);
  assert.equal(STEERING_ACTIONS.length, 16);
  for (const family of ['approval', 'checklist', 'raci', 'meeting', 'kpi', 'situation', 'test_run', 'voting', 'consolidation']) {
    assert.ok(STEERING_ACTIONS.some(action => action.includes(family)), family);
  }
});

test('Steering maps all real native handlers, including exact Core and Planning corrections', () => {
  const descriptor = phpDescriptor();
  const mapped = new Set(Object.values(descriptor.actions).flatMap(action => action.mappedHandlers));
  assert.deepEqual(mapped, new Set(STEERING_HANDLERS));
  assert.equal(mapped.size, 23);
  const fragment = JSON.parse(readFileSync(new URL('../../policy/modules/steering.json', import.meta.url)));
  assert.deepEqual(new Set(fragment.ownedHandlers), mapped);
  for (const id of ['tool:approveItem', 'tool:saveTcrData', 'tool:saveRaciAssignment', 'tool:removeRaciAssignment']) assert.ok(mapped.has(id), id);
});

test('Steering contracts are closed, bounded, typed, and permission-aware', () => {
  const descriptor = phpDescriptor();
  for (const [id, action] of Object.entries(descriptor.actions)) {
    assert.equal(action.schema.type, 'object', id);
    assert.equal(action.schema.additionalProperties, false, id);
    assert.equal(action.resultSchema.type, 'object', id);
    assert.equal(action.resultSchema.additionalProperties, false, id);
    assert.equal(action.availability, 'mcpSteeringActionAvailable', id);
    assert.ok(Array.isArray(action.permissionClasses) && action.permissionClasses.length > 0, id);
    assert.ok(['read', 'write', 'destructive', 'administrative', 'external'].includes(action.risk), id);
    const arrays = Object.values(action.schema.properties).filter(property => property?.type === 'array');
    for (const array of arrays) assert.equal(array.maxItems, 200, id);
    if (['destructive', 'administrative', 'external'].includes(action.risk)) assert.equal(action.preview, 'mcpSteeringPreview', id);
  }
});

test('existing Steering writes require optimistic concurrency at schema and executor layers', () => {
  const descriptor = phpDescriptor();
  assert.ok(descriptor.actions['steering.approval.decide'].schema.properties.approvals.items.required.includes('expectedVersion'));
  assert.ok(descriptor.actions['steering.meeting.assign_team'].schema.properties.meetings.items.required.includes('expectedVersion'));
  assert.ok(descriptor.actions['steering.situation.manage'].schema.properties.situations.items.required.includes('parentExpectedVersion'));
  assert.ok(descriptor.actions['steering.consolidation.validate'].schema.properties.projects.items.required.includes('expectedVersion'));
  const source = readFileSync(new URL('../../bridge/modules/steering/actions.php', import.meta.url), 'utf8');
  assert.match(source, /expected_version_required/);
  assert.match(source, /version_conflict/);
  assert.match(source, /mcpSteeringTarget\(\(string\)\$approver->refType,\(int\)\$approver->refId,'update'\)/);
  assert.match(source, /HabilitationOther/);
});

test('long and external Steering work is cancellable and never silently replayed', () => {
  const descriptor = phpDescriptor();
  const asyncActions = Object.entries(descriptor.actions).filter(([, action]) => action.async);
  assert.deepEqual(asyncActions.map(([id]) => id), ['steering.test_run.execute', 'steering.voting.cast']);
  for (const [, action] of asyncActions) {
    assert.equal(action.transaction, 'worker');
    assert.equal(action.retryPolicy, 'recovery_required');
  }
  const worker = readFileSync(new URL('../../bridge/modules/steering/worker.php', import.meta.url), 'utf8');
  assert.ok((worker.match(/workerCancelled\(\$jobId\)/g) ?? []).length >= 2);
  assert.match(worker, /workerUpdate\(\$jobId,'running'/);
});

test('state discovery is parent-permission scoped and redacts secret-like fields', () => {
  const descriptor = phpDescriptor();
  const state = descriptor.actions['steering.state.query'];
  assert.equal(state.risk, 'read');
  assert.equal(state.transaction, 'none');
  const source = readFileSync(new URL('../../bridge/modules/steering/actions.php', import.meta.url), 'utf8');
  assert.match(source, /mcpSteeringTarget\(\$parentClass,\$parentId,'read'\)/);
  assert.match(source, /password\|token\|secret\|credential/);
});

test('Steering schemas never accept credential material', () => {
  const text = JSON.stringify(phpDescriptor().actions);
  for (const forbidden of ['password', 'apiKey', 'oauthSecret', 'smtpPassword', 'credential']) assert.equal(text.includes(`"${forbidden}"`), false, forbidden);
});
