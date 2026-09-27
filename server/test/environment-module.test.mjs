import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import environment, { ENVIRONMENT_ACTIONS, ENVIRONMENT_HANDLERS } from '../src/modules/environment/index.mjs';

const phpDescriptor = () => JSON.parse(execFileSync('php', ['-r', `
  function mcpObjectSchema($properties=[],$required=[],$additional=false){return ['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>$additional];}
  function mcpActionSpec($schema,$resultSchema,$risk,$async,$callable,$handlers,$test,$options=[]){return array_merge(['schema'=>$schema,'resultSchema'=>$resultSchema,'risk'=>$risk,'async'=>$async,'mappedHandlers'=>$handlers,'testContract'=>$test],$options);}
  echo json_encode(require ${JSON.stringify(new URL('../../bridge/modules/environment/module.php', import.meta.url).pathname)});
`], { encoding: 'utf8' }));

test('Environment owns typed semantic actions for every requested workflow family', () => {
  assert.equal(environment.id, 'environment');
  assert.deepEqual(environment.dependencies, ['core', 'configuration']);
  assert.deepEqual(environment.claims.actions, ENVIRONMENT_ACTIONS);
  assert.equal(ENVIRONMENT_ACTIONS.length, 14);
  for (const family of ['calendar', 'resource', 'team', 'contact', 'capacity', 'cost', 'surbooking', 'incompatibility', 'support']) {
    assert.ok(ENVIRONMENT_ACTIONS.some(action => action.includes(family)), family);
  }
});

test('Environment maps every true Environment mutation handler after UI-layout reclassification', () => {
  const descriptor = phpDescriptor();
  const mapped = new Set(Object.values(descriptor.actions).flatMap(action => action.mappedHandlers));
  assert.deepEqual(mapped, new Set(ENVIRONMENT_HANDLERS));
  assert.equal(mapped.size, 15);
});

test('Environment action contracts are closed, bounded, permission-aware, and result-typed', () => {
  const descriptor = phpDescriptor();
  for (const [id, action] of Object.entries(descriptor.actions)) {
    assert.equal(action.schema.type, 'object', id);
    assert.equal(action.schema.additionalProperties, false, id);
    assert.equal(action.resultSchema.type, 'object', id);
    assert.equal(action.resultSchema.additionalProperties, false, id);
    assert.equal(action.availability, 'mcpEnvironmentActionAvailable', id);
    assert.equal(action.batchLimit, 200, id);
    assert.equal(action.transaction, 'atomic', id);
    assert.equal(action.async, false, id);
    assert.ok(['write', 'administrative'].includes(action.risk), id);
    const batch = Object.values(action.schema.properties).find(property => property?.type === 'array');
    if (batch) assert.equal(batch.maxItems, 200, id);
  }
});

test('Environment schemas reject credentials and direct user provisioning fields', () => {
  const descriptor = phpDescriptor();
  const text = JSON.stringify(descriptor.actions);
  for (const forbidden of ['password', 'apiKey', 'token', 'oauthSecret', 'smtpPassword', 'isUser']) {
    assert.equal(text.includes(`"${forbidden}"`), false, forbidden);
  }
});

test('Environment mutations fail closed on concurrency and parent permissions', () => {
  const root = new URL('../../bridge/modules/environment/', import.meta.url);
  const actions = execFileSync('php', ['-r', `echo file_get_contents(${JSON.stringify(new URL('actions.php', root).pathname)});`], { encoding: 'utf8' });
  const costs = execFileSync('php', ['-r', `echo file_get_contents(${JSON.stringify(new URL('cost-actions.php', root).pathname)});`], { encoding: 'utf8' });
  const internal = execFileSync('php', ['-r', `echo file_get_contents(${JSON.stringify(new URL('internal-batch.php', root).pathname)});`], { encoding: 'utf8' });
  assert.match(actions, /expected_version_required/);
  assert.match(costs, /ResourceCost update requires expectedVersion/);
  assert.doesNotMatch(costs, /\?\?mcpObjectVersion\(\$current\)/);
  assert.match(internal, /Existing intervention target is unavailable/);
  assert.match(internal, /\$resource,'update'/);
});
