import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import followUp, { FOLLOW_UP_ACTIONS, FOLLOW_UP_HANDLERS } from '../src/modules/follow_up/index.mjs';

const phpDescriptor = () => JSON.parse(execFileSync('php', ['-r', `
  function mcpObjectSchema($properties=[],$required=[],$additional=false){return ['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>$additional];}
  function mcpActionSpec($schema,$resultSchema,$risk,$async,$callable,$handlers,$test,$options=[]){return array_merge(['schema'=>$schema,'resultSchema'=>$resultSchema,'risk'=>$risk,'async'=>$async,'mappedHandlers'=>$handlers,'testContract'=>$test,'callable'=>$callable],$options);}
  echo json_encode(require ${JSON.stringify(new URL('../../bridge/modules/follow_up/module.php', import.meta.url).pathname)});
`], { encoding: 'utf8' }));

const actionsSource = readFileSync(new URL('../../bridge/modules/follow_up/actions.php', import.meta.url), 'utf8');
const workerSource = readFileSync(new URL('../../bridge/modules/follow_up/worker.php', import.meta.url), 'utf8');
const policy = JSON.parse(readFileSync(new URL('../../policy/modules/follow_up.json', import.meta.url)));

test('Follow-up owns every requested semantic workflow family', () => {
  assert.equal(followUp.id, 'follow_up');
  assert.deepEqual(followUp.dependencies, ['core', 'planning', 'environment']);
  assert.deepEqual(followUp.claims.actions, FOLLOW_UP_ACTIONS);
  assert.equal(FOLLOW_UP_ACTIONS.length, 10);
  for (const family of ['work', 'dispatch_work', 'timer', 'remaining_work', 'period', 'imputation_alert']) {
    assert.ok(FOLLOW_UP_ACTIONS.some(action => action.includes(family)), family);
  }
});

test('Follow-up maps its 11 native mutation handlers including dispatch handoff', () => {
  const descriptor = phpDescriptor();
  const mapped = new Set(Object.values(descriptor.actions).flatMap(action => action.mappedHandlers));
  assert.deepEqual(mapped, new Set(FOLLOW_UP_HANDLERS));
  assert.equal(mapped.size, 11);
  assert.deepEqual(new Set(policy.ownedHandlers), mapped);
});

test('Follow-up contracts are exact, bounded, result-typed, and permission-aware', () => {
  const descriptor = phpDescriptor();
  for (const [id, action] of Object.entries(descriptor.actions)) {
    assert.equal(action.schema.type, 'object', id);
    assert.equal(action.schema.additionalProperties, false, id);
    assert.equal(action.resultSchema.type, 'object', id);
    assert.equal(action.resultSchema.additionalProperties, false, id);
    assert.equal(action.availability, 'mcpFollowUpActionAvailable', id);
    if (action.batchLimit !== undefined) assert.equal(action.batchLimit, 200, id);
    for (const property of Object.values(action.schema.properties)) {
      if (property?.type === 'array' && property.items?.type === 'object') assert.equal(property.maxItems, 200, id);
    }
    const resultItem = action.resultSchema.properties.items?.items;
    if (resultItem?.properties?.saved && resultItem?.properties?.error) {
      assert.equal(resultItem.properties.saved.additionalProperties, false, id);
      assert.equal(resultItem.properties.error.additionalProperties, false, id);
    }
  }
});

test('Follow-up existing mutations fail closed on versions and native permissions', () => {
  const actions = phpDescriptor().actions;
  const deletion = actions['follow_up.work.delete'].schema.properties.entries.items;
  assert.ok(deletion.required.includes('expectedVersion'));
  const dispatch = actions['follow_up.dispatch_work.apply'].schema;
  assert.ok(dispatch.required.includes('expectedVersion'));
  const timer = actions['follow_up.timer.control'].schema;
  assert.ok(timer.required.includes('expectedVersion'));
  const remaining = actions['follow_up.remaining_work.update'].schema.properties.assignments.items;
  assert.ok(remaining.required.includes('expectedVersion'));
  assert.match(actionsSource, /expected_version_required/);
  assert.match(actionsSource, /version_conflict/);
  assert.match(actionsSource, /ImputationLine::getValidationRight/);
  assert.match(actionsSource, /mcpFollowUpTarget\(\(string\)\$assignment->refType/);
  assert.doesNotMatch(actionsSource, /\?\?\s*mcpObjectVersion/);
  assert.doesNotMatch(actionsSource, /mcpObjectArray/);
  assert.match(actionsSource, /mcpRequireClassOperation\('Assignment','update'\)/);
  assert.match(actionsSource, /'rolled_back'/);
  assert.match(actionsSource, /\$GLOBALS\['mcpCaptureErrors'\]=\$previousCapture/);
});

test('Follow-up destructive and external actions always have permission-safe previews', () => {
  const descriptor = phpDescriptor();
  for (const [id, action] of Object.entries(descriptor.actions)) {
    if (['destructive', 'external', 'administrative'].includes(action.risk)) {
      assert.equal(typeof action.preview, 'string', id);
    }
  }
  assert.equal(descriptor.actions['follow_up.imputation_alert.generate'].preview, 'mcpFollowUpAlertPreview');
});

test('Follow-up has one non-replayable, cancellable external worker', () => {
  const descriptor = phpDescriptor();
  const asyncActions = Object.entries(descriptor.actions).filter(([, action]) => action.async);
  assert.deepEqual(asyncActions.map(([id]) => id), ['follow_up.imputation_alert.generate']);
  assert.equal(asyncActions[0][1].retryPolicy, 'recovery_required');
  assert.match(workerSource, /workerCancelled\(\$jobId\)/);
  assert.match(workerSource, /generateImputationAlert/);
});

test('Follow-up schemas never accept credential material', () => {
  const text = JSON.stringify(phpDescriptor().actions);
  for (const forbidden of ['password', 'apiKey', 'oauthSecret', 'smtpPassword', 'credential']) {
    assert.equal(text.includes(`"${forbidden}"`), false, forbidden);
  }
});
