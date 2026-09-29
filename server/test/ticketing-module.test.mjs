import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import ticketing, { TICKETING_ACTIONS, TICKETING_CLASSES, TICKETING_HANDLERS } from '../src/modules/ticketing/index.mjs';

const serverRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const repositoryRoot = path.dirname(serverRoot);
const modulePath = path.join(repositoryRoot, 'bridge/modules/ticketing/module.php');
const registryPath = path.join(repositoryRoot, 'bridge/core/module-registry.php');

function php(expression) {
  return execFileSync('php', ['-r', expression], { encoding: 'utf8' });
}

function descriptor() {
  return JSON.parse(php([
    `require ${JSON.stringify(registryPath)};`,
    `$module=require ${JSON.stringify(modulePath)};`,
    'echo json_encode($module,JSON_THROW_ON_ERROR);'
  ].join('')));
}

test('Ticketing presentation pack owns its semantic surface and one convenience tool', () => {
  assert.equal(ticketing.id, 'ticketing');
  assert.equal(ticketing.version, '2.0.1');
  assert.deepEqual(ticketing.dependencies, ['core']);
  assert.deepEqual(ticketing.claims.actions, TICKETING_ACTIONS);
  assert.deepEqual(ticketing.claims.handlers, TICKETING_HANDLERS);
  assert.deepEqual(ticketing.claims.classes, TICKETING_CLASSES);
  assert.deepEqual(ticketing.claims.tools, ['projeqtor_manage_ticket']);
});

test('Ticketing bridge covers dispatch, lifecycle, synchronization, SLA, and escalation', () => {
  const module = descriptor();
  assert.equal(module.id, 'ticketing');
  assert.deepEqual(module.dependencies, ['core', 'configuration', 'environment', 'products']);
  assert.deepEqual(Object.keys(module.actions), TICKETING_ACTIONS);
  for (const action of ['ticketing.dispatch', 'ticketing.transition', 'ticketing.synchronize', 'ticketing.sla.evaluate', 'ticketing.escalate']) {
    assert.ok(module.actions[action], action);
  }
});

test('Ticketing action contracts are closed, bounded, typed, and actor-idempotent', () => {
  const actions = descriptor().actions;
  for (const [id, action] of Object.entries(actions)) {
    assert.equal(action.schema.type, 'object', id);
    assert.equal(action.schema.additionalProperties, false, id);
    assert.equal(action.resultSchema.type, 'object', id);
    assert.equal(action.resultSchema.additionalProperties, false, id);
    assert.equal(action.availability, 'mcpTicketingActionAvailable', id);
    assert.equal(action.idempotency.scope, 'actor', id);
    assert.equal(action.async, false, id);
    assert.match(action.testContract, /^ticketing\./, id);
    const batch = Object.values(action.schema.properties).find(property => property?.type === 'array');
    if (batch) assert.equal(batch.maxItems, 200, id);
  }
  assert.equal(actions['ticketing.synchronization.configure'].risk, 'administrative');
  assert.equal(actions['ticketing.synchronization.configure'].preview, 'mcpTicketingSynchronizationConfigurePreview');
  assert.equal(actions['ticketing.synchronization.disable'].risk, 'destructive');
  assert.equal(actions['ticketing.synchronization.disable'].preview, 'mcpTicketingSynchronizationDisablePreview');
  const inspect = actions['ticketing.synchronization.inspect'].resultSchema.properties.items.items;
  assert.ok(inspect.required.includes('linkId'));
  assert.ok(inspect.required.includes('linkVersion'));
  assert.deepEqual(inspect.properties.linkVersion.type, ['string', 'null']);
  const ticketItem = actions['ticketing.transition'].resultSchema.properties.items.items;
  assert.equal(ticketItem.properties.saved.additionalProperties, false);
  assert.equal(ticketItem.properties.error.additionalProperties, false);
  assert.ok(ticketItem.properties.saved.required.includes('actualDueDateTime'));
  const definition = actions['ticketing.synchronization.configure'].resultSchema.properties.definition;
  assert.equal(definition.additionalProperties, false);
});

test('Ticketing maps only genuine Ticket synchronization mutations and records handoffs', () => {
  const actions = descriptor().actions;
  const mapped = new Set(Object.values(actions).flatMap(action => action.mappedHandlers));
  assert.deepEqual(mapped, new Set(TICKETING_HANDLERS));
  const policy = JSON.parse(php(`echo file_get_contents(${JSON.stringify(path.join(repositoryRoot, 'policy/modules/ticketing.json'))});`));
  assert.equal(policy.handlerMappings['tool/saveSynchronizationDefinition.php'].action, 'ticketing.synchronization.configure');
  assert.equal(policy.handlerMappings['tool/saveDisableSynchronizationDefinition.php'].action, 'ticketing.synchronization.disable');
  assert.equal(policy.handlerHandoffs['tool/saveDispatchWork.php'], 'follow_up');
  assert.equal(policy.handlerHandoffs['tool/dynamicDialogDispatchWork.php'], 'follow_up');
  assert.equal(policy.handlerHandoffs['view/dashboardTicketMain.php'], 'configuration');
});

test('Ticket mutations require expectedVersion and reject unbounded batches', () => {
  const script = [
    `require ${JSON.stringify(registryPath)};`,
    `$module=require ${JSON.stringify(modulePath)};`,
    '$manage=$module["actions"]["ticketing.ticket.manage"]["schema"];',
    '$transition=$module["actions"]["ticketing.transition"]["schema"];',
    '$out=array(',
    '"missingVersion"=>mcpValidateSchemaValue(array("operations"=>array(array("ticketId"=>1,"operation"=>"transition","statusId"=>2))),$manage),',
    '"tooMany"=>mcpValidateSchemaValue(array("items"=>array_fill(0,201,array("ticketId"=>1,"statusId"=>2,"expectedVersion"=>"v"))),$transition),',
    '"unknown"=>mcpValidateSchemaValue(array("items"=>array(array("ticketId"=>1,"statusId"=>2,"expectedVersion"=>"v","password"=>"secret"))),$transition)',
    ');echo json_encode($out,JSON_THROW_ON_ERROR);'
  ].join('');
  const result = JSON.parse(php(script));
  assert.ok(result.missingVersion.some(error => error.code === 'required' && error.path.endsWith('.expectedVersion')));
  assert.ok(result.tooMany.some(error => error.code === 'maxItems'));
  assert.ok(result.unknown.some(error => error.code === 'additional_property'));
});

test('Ticketing executors fail closed on permissions, concurrency, and guarded synchronization', () => {
  const actions = php(`echo file_get_contents(${JSON.stringify(path.join(repositoryRoot, 'bridge/modules/ticketing/actions.php'))});`);
  assert.doesNotMatch(actions, /\$_(?:REQUEST|POST|GET)/);
  assert.match(actions, /expected_version_required/);
  assert.match(actions, /Security::checkValidAccessForUser/);
  assert.match(actions, /mcpRequireClassOperation\('Ticket',\$operation\)/);
  assert.match(actions, /mcpRequireClassOperation\('TicketDelay','read'\)/);
  assert.match(actions, /mcpTicketingRequireTicket\(\(int\)\$item->ref2Id,'update'\)/);
  assert.match(actions, /Sql::beginTransaction/);
  assert.match(actions, /synchronization_batch_too_large/);
  assert.match(actions, /ticket_synchronization_failed/);
  assert.match(actions, /mcpTicketingTicketData\(\$saved\)/);
  assert.match(actions, /mcpTicketingDefinitionData\(\$saved\)/);
  assert.doesNotMatch(actions, /mcpObjectArray\(\$saved\)/);
});

test('Ticketing batch rolls back atomically and isolates best-effort failures', () => {
  const actionsPath = path.join(repositoryRoot, 'bridge/modules/ticketing/actions.php');
  const script = [
    'class McpBridgeException extends RuntimeException { public string $errorCode="test"; public array $details=array(); }',
    'function cleanApiMessage($value){return (string)$value;}',
    'class Sql { public static array $events=array(); public static function beginTransaction(){self::$events[]="begin";} public static function commitTransaction(){self::$events[]="commit";} public static function rollbackTransaction(){self::$events[]="rollback";} }',
    `require ${JSON.stringify(actionsPath)};`,
    '$item=function($id){return array("ticketId"=>$id,"expectedVersion"=>"v");};',
    '$atomic=mcpTicketingBatch(array($item(1),$item(2)),"atomic",function($entry,$index){if($index===1)throw new RuntimeException("stop");return array("status"=>"transitioned","objectClass"=>"Ticket","id"=>$entry["ticketId"],"appliedFields"=>array("statusId"),"recalculatedFields"=>array(),"rejectedFields"=>array(),"ignoredFields"=>array(),"effects"=>array(array("action"=>"update","objectClass"=>"Ticket","id"=>$entry["ticketId"])));});',
    '$atomicEvents=Sql::$events;Sql::$events=array();',
    '$best=mcpTicketingBatch(array($item(1),$item(2),$item(3)),"best_effort",function($entry,$index){if($index===1)throw new RuntimeException("skip");return array("status"=>"dispatched","objectClass"=>"Ticket","id"=>$entry["ticketId"],"appliedFields"=>array("resourceId"),"recalculatedFields"=>array(),"rejectedFields"=>array(),"ignoredFields"=>array(),"effects"=>array(array("action"=>"update","objectClass"=>"Ticket","id"=>$entry["ticketId"])));});',
    'echo json_encode(array("atomic"=>$atomic,"atomicEvents"=>$atomicEvents,"best"=>$best,"bestEvents"=>Sql::$events),JSON_THROW_ON_ERROR);'
  ].join('');
  const result = JSON.parse(php(script));
  assert.equal(result.atomic.ok, false);
  assert.equal(result.atomic.rolledBack, true);
  assert.deepEqual(result.atomic.items.map(item => item.status), ['rolled_back', 'error']);
  assert.deepEqual(result.atomicEvents, ['begin', 'rollback']);
  assert.equal(result.best.ok, false);
  assert.equal(result.best.rolledBack, false);
  assert.deepEqual(result.best.items.map(item => item.status), ['dispatched', 'error', 'dispatched']);
  assert.deepEqual(result.bestEvents, ['begin', 'commit', 'begin', 'rollback', 'begin', 'commit']);
});
