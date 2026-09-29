import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import planningModule, { PLANNING_ACTIONS, PLANNING_JOBS } from '../src/modules/planning/index.mjs';

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

test('Planning presentation module claims every canonical action and only one convenience tool', () => {
  assert.equal(planningModule.id, 'planning');
  assert.equal(planningModule.version, '2.0.1');
  assert.deepEqual(planningModule.claims.tools, ['projeqtor_plan_projects']);
  assert.deepEqual(planningModule.claims.actions, PLANNING_ACTIONS);
  assert.equal(PLANNING_ACTIONS.length, 21);
  assert.deepEqual(planningModule.claims.jobs, PLANNING_JOBS);
  assert.equal(PLANNING_JOBS.length, 5);
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
    'planning.selection.delete',
    'planning.integrity.repair',
    'planning.grid.inline_edit',
    'planning.element.resize',
    'planning.element.phase',
    'planning.activity.split',
    'planning.scenario.configure',
    'planning.critical_resources.evaluate',
    'planning.calculate',
    'planning.wbs.renumber',
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
  assert.equal(handlerOwners.get('tool:deletePlanningSelection'), 'planning.selection.delete');
  assert.equal(handlerOwners.get('tool:jsonPlanning'), 'planning.integrity.repair');
  assert.equal(handlerOwners.get('tool:saveEditRowObject'), 'planning.grid.inline_edit');
  assert.equal(handlerOwners.get('tool:recalculatePlanningSaveDates'), 'planning.grid.inline_edit');
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
    '"badDate"=>mcpValidateSchemaValue(array("refType"=>"Activity","refId"=>1,"startDate"=>"09/27/2026","endDate"=>"2026-09-28"),$resize),',
    '"quickPlan"=>mcpValidateSchemaValue(array("items"=>array(array("planningElementId"=>4,"expectedPlanningVersion"=>"v2:1:test","planningFields"=>array("quickplanStartDate"=>"2026-09-27","quickplanEndDate"=>"2026-09-28","quickplanUpdated"=>true)))),$module["actions"]["planning.grid.inline_edit"]["schema"])',
    ');echo json_encode($out,JSON_THROW_ON_ERROR);'
  ].join('');
  const result = JSON.parse(php(script));
  assert.ok(result.empty.some(error => error.code === 'minItems'));
  assert.ok(result.tooMany.some(error => error.code === 'maxItems'));
  assert.ok(result.unknown.some(error => error.code === 'additional_property'));
  assert.ok(result.badDate.some(error => error.code === 'pattern'));
  assert.deepEqual(result.quickPlan, []);
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
  assert.match(actions, /saveForced/);
  assert.match(actions, /planning_element_not_orphan/);
  assert.match(actions, /dependency_target_mismatch/);
  assert.match(actions, /PlanningElement::moveTaskFinalize/);
  assert.match(actions, /PlanningElement::updateSynthesisNoDispatch/);
  assert.match(actions, /realStartDate/);
  assert.match(actions, /realEndDate/);
  assert.match(worker, /workerCancelled/);
  assert.match(worker, /mcpPlanningCriticalResourcesWorker/);
  assert.match(worker, /named_scenario_requires_activation/);
});
test('Planning destructive repairs require confirmation and policy maps exact source handlers', () => {
  const module = descriptor();
  for (const id of ['planning.selection.delete', 'planning.integrity.repair']) {
    assert.equal(module.actions[id].confirmationRequired, true, id);
    assert.match(module.actions[id].preview, /Preview$/, id);
  }
  const policy = JSON.parse(php(`echo file_get_contents(${JSON.stringify(path.join(repositoryRoot, 'policy/modules/planning.json'))});`));
  assert.equal(policy.handlerMappings['tool/deletePlanningSelection.php'].action, 'planning.selection.delete');
  assert.equal(policy.handlerMappings['tool/jsonPlanning.php'].action, 'planning.integrity.repair');
  assert.equal(policy.handlerMappings['tool/saveEditRowObject.php'].action, 'planning.grid.inline_edit');
  assert.equal(policy.handlerMappings['tool/recalculatePlanningSaveDates.php'].action, 'planning.grid.inline_edit');
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
test('Planning async worker result schemas accept executor output without filesystem paths', () => {
  const script = [
    `require ${JSON.stringify(registryPath)};`,
    `$module=require ${JSON.stringify(modulePath)};`,
    '$actions=$module["actions"];$cases=array(',
    '"project.snapshot"=>array("ok"=>true,"idProject"=>1,"historyWatermark"=>"2026-09-28T00:00:00+00:00","counts"=>array("Project"=>1),"resource"=>"projeqtor://jobs/7/result"),',
    '"planning.calculate"=>array("ok"=>true,"status"=>"complete","message"=>"OK","projects"=>array(1),"diagnostics"=>array(array("ok"=>true,"idProject"=>1,"needsReplan"=>false,"elements"=>array(),"assignments"=>array(),"overloads"=>array(),"counts"=>array()))),',
    '"planning.critical_resources.evaluate"=>array("ok"=>true,"status"=>"complete","message"=>"OK","projects"=>array(1),"startDate"=>null,"endDate"=>null,"resources"=>array(array("idResource"=>2,"name"=>"Resource","plannedWork"=>2.0,"surbookedWork"=>1.0,"overloadedDays"=>1,"firstDate"=>"2026-09-28","lastDate"=>"2026-09-28")),"counts"=>array("resources"=>1,"overloaded"=>1),"effects"=>array()),',
    '"planning.wbs.renumber"=>array("ok"=>true,"status"=>"renumbered","priorityChanges"=>1,"structureChanges"=>2,"effects"=>array(array("action"=>"update","objectClass"=>"PlanningElement","count"=>3))),',
    '"planning.baseline.create"=>array("ok"=>true,"baseline"=>array("id"=>9,"_version"=>"v2:9:test"))',
    ');$errors=array();foreach($cases as $id=>$result)$errors[$id]=mcpValidateSchemaValue($result,$actions[$id]["resultSchema"]);',
    'echo json_encode(array("errors"=>$errors,"snapshotHasPath"=>array_key_exists("path",$actions["project.snapshot"]["resultSchema"]["properties"])),JSON_THROW_ON_ERROR);'
  ].join('');
  const result = JSON.parse(php(script));
  for (const [action, errors] of Object.entries(result.errors)) {
    assert.deepEqual(errors, [], action);
  }
  assert.equal(result.snapshotHasPath, false);
});
