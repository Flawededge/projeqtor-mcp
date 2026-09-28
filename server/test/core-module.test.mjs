import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import core, { CORE_ACTIONS, CORE_HANDLERS } from '../src/modules/core/index.mjs';

const phpDescriptor = () => JSON.parse(execFileSync('php', ['-r', `
  function mcpObjectSchema($properties=[],$required=[],$additional=false){return ['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>$additional];}
  function mcpActionSpec($schema,$resultSchema,$risk,$async,$callable,$handlers,$test,$options=[]){return array_merge(['schema'=>$schema,'resultSchema'=>$resultSchema,'risk'=>$risk,'async'=>$async,'mappedHandlers'=>$handlers,'testContract'=>$test,'callable'=>$callable,'retryPolicy'=>$async?'recovery_required':'never','idempotency'=>['supported'=>true,'scope'=>'actor','sameBodyReturnsOriginal'=>true,'conflictOnDifferentBody'=>true],'transaction'=>$async?'worker':'atomic'],$options);}
  echo json_encode(require ${JSON.stringify(new URL('../../bridge/modules/core/module.php', import.meta.url).pathname)});
`], { encoding: 'utf8' }));

const source = readFileSync(new URL('../../bridge/modules/core/actions.php', import.meta.url), 'utf8');
const policy = JSON.parse(readFileSync(new URL('../../policy/modules/core.json', import.meta.url)));

test('Core owns the requested session, joblist, legal notice, bulk-update and subtask actions', () => {
  assert.equal(core.id, 'core');
  assert.deepEqual(core.dependencies, []);
  assert.deepEqual(core.claims.actions, CORE_ACTIONS);
  assert.equal(CORE_ACTIONS.length, 9);
  for (const action of [
    'user.session.terminate', 'user.session.login', 'core.joblist.update',
    'user.legal_notice.accept', 'core.object.bulk_update', 'core.subtask.manage',
    'user.legal_notice.view'
  ]) assert.ok(CORE_ACTIONS.includes(action), action);
});

test('Core maps both termination paths and all seven requested native workflow families', () => {
  const descriptor = phpDescriptor();
  const mapped = new Set(Object.values(descriptor.actions).flatMap(action => action.mappedHandlers));
  assert.deepEqual(mapped, new Set(CORE_HANDLERS));
  assert.equal(mapped.size, 14);
  assert.deepEqual(descriptor.actions['user.session.terminate'].mappedHandlers, ['tool:disconnectSession', 'tool:hackMessage']);
  assert.deepEqual(descriptor.actions['core.subtask.manage'].mappedHandlers, ['tool:saveSubTask', 'tool:saveSubTaskOrder']);
  for (const handler of policy.ownedHandlers) assert.ok(mapped.has(handler), handler);
});

test('Core action contracts are closed, result-typed, actor-idempotent and transaction-explicit', () => {
  for (const [id, action] of Object.entries(phpDescriptor().actions)) {
    assert.equal(action.schema.type, 'object', id);
    assert.equal(action.schema.additionalProperties, false, id);
    assert.equal(action.resultSchema.type, 'object', id);
    assert.equal(action.resultSchema.additionalProperties, false, id);
    assert.equal(action.availability, 'mcpCoreActionAvailable', id);
    assert.equal(action.idempotency.scope, 'actor', id);
    assert.equal(action.idempotency.sameBodyReturnsOriginal, true, id);
    assert.equal(action.idempotency.conflictOnDifferentBody, true, id);
    assert.ok(['atomic', 'best_effort'].includes(action.transaction), id);
    assert.equal(action.retryPolicy, 'never', id);
    if (['destructive', 'external'].includes(action.risk)) {
      assert.equal(action.confirmationRequired, true, id);
      assert.equal(typeof action.preview, 'string', id);
    } else {
      assert.equal(action.confirmationRequired, false, id);
    }
  }
});

test('Core existing-object schemas require optimistic versions and bound batches', () => {
  const actions = phpDescriptor().actions;
  for (const id of ['object.copy', 'workflow.transition', 'user.session.terminate', 'user.legal_notice.accept']) {
    assert.ok(actions[id].schema.required.includes('expectedVersion'), id);
  }
  assert.deepEqual(actions['core.object.bulk_update'].schema.properties.targets.items.required, ['id', 'expectedVersion']);
  assert.equal(actions['core.object.bulk_update'].schema.properties.targets.maxItems, 200);
  assert.deepEqual(actions['user.legal_notice.view'].schema.properties.followups.items.required, ['followupId', 'expectedVersion']);
  assert.equal(actions['user.legal_notice.view'].schema.properties.followups.maxItems, 200);
  assert.deepEqual(actions['core.subtask.manage'].schema.properties.order.items.required, ['id', 'expectedVersion', 'sortOrder']);
  assert.match(source, /expected_version_required/);
  assert.match(source, /version_conflict/);
  assert.match(source, /subtask_parent_mismatch/);
});

test('Core actor attribution and least-privilege checks are enforced in executors and previews', () => {
  assert.match(source, /actor_mismatch/);
  assert.match(source, /Only the session owner or an Audit administrator/);
  assert.match(source, /Security::checkValidAccessForUser/);
  assert.match(source, /idUser!==\(int\)\$actor\['id'\]/);
  assert.match(source, /\$task->idUser=\$actor\['id'\]/);
  assert.match(source, /mcpCheckOperation\(\$operation,false,true\)/);
});

test('Core atomic rollback normalization never reports rolled-back writes as applied', () => {
  const actionsPath = new URL('../../bridge/modules/core/actions.php', import.meta.url).pathname;
  const result = JSON.parse(execFileSync('php', ['-r', `
    require ${JSON.stringify(actionsPath)};
    function cleanApiMessage($value){return (string)$value;}
    $raw=['ok'=>false,'rolledBack'=>true,'items'=>[
      ['index'=>0,'status'=>'updated','objectClass'=>'Ticket','id'=>7,'requestedFields'=>['idStatus']],
      ['index'=>1,'status'=>'error','objectClass'=>'Ticket','id'=>8,'error'=>['code'=>'version_conflict','message'=>'changed']]
    ]];
    echo json_encode(mcpCoreNormalizeBatch($raw,['id'=>2,'username'=>'actor'],'atomic'));
  `], { encoding: 'utf8' }));
  assert.equal(result.rolledBack, true);
  assert.deepEqual(result.items.map(item => item.status), ['rolled_back', 'error']);
  assert.deepEqual(result.items[0].appliedFields, []);
  assert.deepEqual(result.items[0].rejectedFields, ['idStatus']);
  assert.deepEqual(result.effects, []);
});

test('Core excludes credentials from login and bulk-update contracts', () => {
  const descriptor = phpDescriptor();
  assert.deepEqual(descriptor.actions['user.session.login'].schema.properties, []);
  const text = JSON.stringify(descriptor.actions);
  for (const forbidden of ['password', 'apiKey', 'oauthSecret', 'smtpPassword', 'credential']) assert.equal(text.includes(`"${forbidden}"`), false, forbidden);
  assert.match(source, /mcpSensitiveField\(\$field\)/);
  assert.equal(policy.intentionalExclusions['tool:saveObjectMultiplePwd'], 'secrets_or_credentials');
});
