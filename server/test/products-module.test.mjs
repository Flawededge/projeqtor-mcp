import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import products, { PRODUCTS_ACTIONS, PRODUCTS_HANDLERS } from '../src/modules/products/index.mjs';

const phpDescriptor = () => JSON.parse(execFileSync('php', ['-r', `
  function mcpObjectSchema($properties=[],$required=[],$additional=false){return ['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>$additional];}
  function mcpActionSpec($schema,$resultSchema,$risk,$async,$callable,$handlers,$test,$options=[]){return array_merge(['schema'=>$schema,'resultSchema'=>$resultSchema,'risk'=>$risk,'async'=>$async,'mappedHandlers'=>$handlers,'testContract'=>$test,'callable'=>$callable],$options);}
  echo json_encode(require ${JSON.stringify(new URL('../../bridge/modules/products/module.php', import.meta.url).pathname)});
`], { encoding: 'utf8' }));

test('Products exposes independently owned workflows for every requested family', () => {
  assert.equal(products.id, 'products');
  assert.deepEqual(products.dependencies, ['core', 'configuration']);
  assert.deepEqual(products.claims.actions, PRODUCTS_ACTIONS);
  assert.equal(PRODUCTS_ACTIONS.length, 15);
  for (const family of ['hierarchy', 'version', 'composition', 'compatibility', 'context', 'language', 'asset', 'project_links']) {
    assert.ok(PRODUCTS_ACTIONS.some(action => action.includes(family)), family);
  }
});

test('Products maps all 28 real mutating handlers assigned by the v4 inventory', () => {
  const descriptor = phpDescriptor();
  const mapped = new Set(Object.values(descriptor.actions).flatMap(action => action.mappedHandlers));
  assert.deepEqual(mapped, new Set(PRODUCTS_HANDLERS));
  assert.equal(mapped.size, 28);

  const manifest = JSON.parse(readFileSync(new URL('../../bridge/ui-handler-policy-v4.json', import.meta.url)));
  const inventoried = new Set(manifest.handlers
    .filter(handler => handler.module === 'products' && handler.classification === 'registered_action')
    .map(handler => handler.id));
  assert.deepEqual(mapped, inventoried);
});

test('Products contracts are closed, bounded, typed and permission-aware', () => {
  const descriptor = phpDescriptor();
  for (const [id, action] of Object.entries(descriptor.actions)) {
    assert.equal(action.schema.type, 'object', id);
    assert.equal(action.schema.additionalProperties, false, id);
    assert.equal(action.resultSchema.type, 'object', id);
    assert.equal(action.resultSchema.additionalProperties, false, id);
    assert.equal(action.availability, 'mcpProductsActionAvailable', id);
    assert.ok(Array.isArray(action.permissionClasses) && action.permissionClasses.length > 0, id);
    assert.ok(['write', 'destructive', 'administrative'].includes(action.risk), id);
    if (action.batchLimit !== undefined) assert.equal(action.batchLimit, 200, id);
    for (const property of Object.values(action.schema.properties)) {
      if (property?.type === 'array' && property.items?.type === 'object') assert.equal(property.maxItems, 200, id);
    }
    if (action.risk === 'destructive' || action.risk === 'administrative') assert.equal(typeof action.preview, 'string', id);
  }
});

test('Products requires optimistic versions for lifecycle transitions and asset hierarchy moves', () => {
  const actions = phpDescriptor().actions;
  const transition = actions['products.version.transition'].schema.properties.versions.items;
  assert.ok(transition.required.includes('expectedVersion'));
  const asset = actions['products.asset.composition'].schema.properties.assets.items;
  assert.ok(asset.required.includes('expectedVersion'));
  const source = readFileSync(new URL('../../bridge/modules/products/actions.php', import.meta.url), 'utf8');
  assert.match(source, /expected_version_required/);
  assert.match(source, /version_conflict/);
});

test('Products has exactly one asynchronous non-replayable worker action', () => {
  const descriptor = phpDescriptor();
  const asyncActions = Object.entries(descriptor.actions).filter(([, action]) => action.async);
  assert.deepEqual(asyncActions.map(([id]) => id), ['products.version_composition.upgrade']);
  assert.equal(asyncActions[0][1].callable, 'mcpProductsUpgradeWorker');
  assert.equal(asyncActions[0][1].retryPolicy, 'recovery_required');
  assert.equal(asyncActions[0][1].transaction, 'worker');
});

test('Products schemas never accept credential material', () => {
  const text = JSON.stringify(phpDescriptor().actions);
  for (const forbidden of ['password', 'apiKey', 'token', 'oauthSecret', 'smtpPassword', 'credential']) {
    assert.equal(text.includes(`"${forbidden}"`), false, forbidden);
  }
});
