import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import planningModule from '../src/modules/planning/index.mjs';

const serverRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const repositoryRoot = path.dirname(serverRoot);
const modulePath = path.join(repositoryRoot, 'bridge/modules/planning/module.php');
const registryPath = path.join(repositoryRoot, 'bridge/core/module-registry.php');

function php(expression) {
  return execFileSync('php', ['-r', expression], { encoding: 'utf8' });
}

function descriptor() {
  const source = [
    `require ${JSON.stringify(registryPath)};`,
    `$module=require ${JSON.stringify(modulePath)};`,
    'echo json_encode($module,JSON_THROW_ON_ERROR);'
  ].join('');
  return JSON.parse(php(source));
}

test('Planning presentation module owns only its high-frequency convenience tool', () => {
  assert.equal(planningModule.id, 'planning');
  assert.equal(planningModule.version, '2.0.0-beta.4');
  assert.deepEqual(planningModule.claims.tools, ['projeqtor_plan_projects']);
  assert.deepEqual(planningModule.claims.actions, ['planning.calculate']);
  assert.deepEqual(planningModule.claims.jobs, ['planning.calculate']);
});

test('Planning bridge declares every requested semantic workflow family', () => {
  const module = descriptor();
  assert.equal(module.id, 'planning');
  assert.equal(module.version, '4.0.0');
  assert.deepEqual(module.dependencies, ['core', 'configuration', 'environment']);
  assert.deepEqual(Object.keys(module.actions), [
    'project.snapshot',
    'planning.assignment.upsert',
    'planning.assignment.remove',
    'planning.assignment.automatic',
    'planning.allocation.upsert',
    'planning.allocation.remove',
    'planning.dependency.upsert',
    'planning.dependency.remove',
    'planning.element.resize',
    'planning.element.phase',
    'planning.activity.split',
    'planning.scenario.configure',
    'planning.critical_resources.evaluate',
    'planning.calculate',
    'planning.diagnostics',
    'planning.baseline.create',
    'planning.baseline.delete'
  ]);
});

test('Planning actions have exact contracts, unique native handlers, and guarded destructive previews', () => {
  const actions = descriptor().actions;
  const handlerOwners = new Map();
  for (const [id, action] of Object.entries(actions)) {
    assert.equal(action.module, undefined);
    assert.equal(action.schema.type, 'object', id);
    assert.equal(action.schema.additionalProperties, false, id);
    assert.equal(action.resultSchema.type, 'object', id);
    assert.ok(Array.isArray(action.resultSchema.required), id);
    assert.ok(action.resultSchema.required.length > 0, id);
    assert.match(action.testContract, /^planning\./, id);
    assert.equal(action.idempotency.scope, 'actor', id);
    for (const handler of action.mappedHandlers) {
      assert.equal(handlerOwners.has(handler), false, `${handler} is duplicated`);
      handlerOwners.set(handler, id);
    }
    if (action.risk === 'destructive') assert.equal(typeof action.preview, 'string', id);
  }
  assert.equal(handlerOwners.get('tool:saveAssignment'), 'planning.assignment.upsert');
  assert.equal(handlerOwners.get('tool:saveAffectation'), 'planning.allocation.upsert');
  assert.equal(handlerOwners.get('tool:saveDependency'), 'planning.dependency.upsert');
  assert.equal(handlerOwners.get('tool:savePlanningElementAfterResize'), 'planning.element.resize');
  assert.equal(handlerOwners.get('tool:splitActivity'), 'planning.activity.split');
  assert.equal(handlerOwners.get('tool:refreshCriticalResources'), 'planning.critical_resources.evaluate');
  assert.equal(handlerOwners.get('tool:savePlanningBaseline'), 'planning.baseline.create');
});

test('Planning batch and date schemas reject unbounded or ambiguous input', () => {
  const script = [
    `require ${JSON.stringify(registryPath)};`,
    `$module=require ${JSON.stringify(modulePath)};`,
    '$assignment=$module["actions"]["planning.assignment.upsert"]["schema"];',
    '$resize=$module["actions"]["planning.element.resize"]["schema"];',
    '$out=array(',
    '"empty"=>mcpValidateSchemaValue(array("items"=>array()),$assignment),',
    '"tooMany"=>mcpValidateSchemaValue(array("items"=>array_fill(0,201,array())),$assignment),',
    '"unknown"=>mcpValidateSchemaValue(array("items"=>array(array("refType"=>"Activity","refId"=>1,"resourceId"=>2,"rate"=>100,"assignedWork"=>1,"unexpected"=>true))),$assignment),',
    '"badDate"=>mcpValidateSchemaValue(array("refType"=>"Activity","refId"=>1,"startDate"=>"09/27/2026","endDate"=>"2026-09-28"),$resize)',
    ');echo json_encode($out,JSON_THROW_ON_ERROR);'
  ].join('');
  const result = JSON.parse(php(script));
  assert.ok(result.empty.some(error => error.code === 'minItems'));
  assert.ok(result.tooMany.some(error => error.code === 'maxItems'));
  assert.ok(result.unknown.some(error => error.code === 'additional_property'));
  assert.ok(result.badDate.some(error => error.code === 'pattern'));
});

test('Planning executors are isolated from HTTP request globals and expose cancellation-aware workers', () => {
  const actions = php(`echo file_get_contents(${JSON.stringify(path.join(repositoryRoot, 'bridge/modules/planning/actions.php'))});`);
  const worker = php(`echo file_get_contents(${JSON.stringify(path.join(repositoryRoot, 'bridge/modules/planning/worker.php'))});`);
  assert.doesNotMatch(actions, /\$_(?:REQUEST|POST|GET)/);
  assert.match(actions, /mcpPlanningRequireVersion/);
  assert.match(actions, /expected_version_required/);
  assert.match(actions, /mcpRequireClassOperation\(\$class,\$operation\)/);
  assert.match(actions, /mcpRequireClassOperation\(\$class,'delete'\)/);
  assert.match(actions, /Security::checkValidAccessForUser/);
  assert.match(actions, /Sql::beginTransaction/);
  assert.match(worker, /workerCancelled/);
  assert.match(worker, /mcpPlanningCriticalResourcesWorker/);
  assert.match(worker, /named_scenario_requires_activation/);
});
test('Planning batches roll back atomically and isolate best-effort failures', () => {
  const actionsPath = path.join(repositoryRoot, 'bridge/modules/planning/actions.php');
  const script = [
    'class McpBridgeException extends RuntimeException { public string $errorCode="test"; public array $details=array(); }',
    'function cleanApiMessage($value){return (string)$value;}',
    'class Sql { public static array $events=array();',
    'public static function beginTransaction(){self::$events[]="begin";}',
    'public static function commitTransaction(){self::$events[]="commit";}',
    'public static function rollbackTransaction(){self::$events[]="rollback";}}',
    `require ${JSON.stringify(actionsPath)};`,
    '$atomic=mcpPlanningBatch(array("items"=>array(array("name"=>"one"),array("name"=>"two")),"transactionMode"=>"atomic"),',
    'function($item,$index){if($index===1)throw new RuntimeException("stop");return array("status"=>"created","objectClass"=>"Activity","id"=>1,"effects"=>array(array("action"=>"create","objectClass"=>"Activity","id"=>1)));});',
    '$atomicEvents=Sql::$events;Sql::$events=array();',
    '$best=mcpPlanningBatch(array("items"=>array(array("name"=>"one"),array("name"=>"two"),array("name"=>"three")),"transactionMode"=>"best_effort"),',
    'function($item,$index){if($index===1)throw new RuntimeException("skip");return array("status"=>"updated","objectClass"=>"Activity","id"=>$index+1,"effects"=>array(array("action"=>"update","objectClass"=>"Activity","id"=>$index+1)));});',
    'echo json_encode(array("atomic"=>$atomic,"atomicEvents"=>$atomicEvents,"best"=>$best,"bestEvents"=>Sql::$events),JSON_THROW_ON_ERROR);'
  ].join('');
  const result = JSON.parse(php(script));
  assert.equal(result.atomic.ok, false);
  assert.equal(result.atomic.rolledBack, true);
  assert.deepEqual(result.atomic.items.map(item => item.status), ['rolled_back', 'error']);
  assert.deepEqual(result.atomic.items[0].appliedFields, []);
  assert.deepEqual(result.atomic.items[0].rejectedFields, ['name']);
  assert.deepEqual(result.atomic.events, undefined);
  assert.deepEqual(result.atomicEvents, ['begin', 'rollback']);
  assert.equal(result.best.ok, false);
  assert.equal(result.best.rolledBack, false);
  assert.deepEqual(result.best.items.map(item => item.status), ['updated', 'error', 'updated']);
  assert.deepEqual(result.bestEvents, ['begin', 'commit', 'begin', 'rollback', 'begin', 'commit']);
  for (const item of result.best.items) {
    assert.ok(Array.isArray(item.appliedFields));
    assert.ok(Array.isArray(item.recalculatedFields));
    assert.ok(Array.isArray(item.rejectedFields));
    assert.ok(Array.isArray(item.ignoredFields));
  }
});
