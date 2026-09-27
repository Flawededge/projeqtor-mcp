import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import financial, { FINANCIAL_ACTIONS, FINANCIAL_HANDLERS } from '../src/modules/financial/index.mjs';

const phpDescriptor = () => JSON.parse(execFileSync('php', ['-r', `
  function mcpObjectSchema($properties=[],$required=[],$additional=false){return ['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>$additional];}
  function mcpActionSpec($schema,$resultSchema,$risk,$async,$callable,$handlers,$test,$options=[]){return array_merge(['schema'=>$schema,'resultSchema'=>$resultSchema,'risk'=>$risk,'async'=>$async,'mappedHandlers'=>$handlers,'testContract'=>$test,'callable'=>$callable],$options);}
  echo json_encode(require ${JSON.stringify(new URL('../../bridge/modules/financial/module.php', import.meta.url).pathname)});
`], { encoding: 'utf8' }));

test('Financial exposes every requested workflow family as an independent canonical action', () => {
  assert.equal(financial.id, 'financial');
  assert.deepEqual(financial.dependencies, ['core', 'configuration', 'environment', 'products', 'follow_up', 'tools']);
  assert.deepEqual(financial.claims.actions, FINANCIAL_ACTIONS);
  assert.equal(FINANCIAL_ACTIONS.length, 20);
  for (const family of ['expense', 'billing', 'provider_term', 'tender', 'budget', 'work_command', 'work_unit', 'work_token', 'abacus', 'facturx']) {
    assert.ok(FINANCIAL_ACTIONS.some(action => action.includes(family)), family);
  }
});

test('Financial maps its 36 real native handlers, including scanner misses and Work Token writes', () => {
  const descriptor = phpDescriptor();
  const mapped = new Set(Object.values(descriptor.actions).flatMap(action => action.mappedHandlers));
  assert.deepEqual(mapped, new Set(FINANCIAL_HANDLERS));
  assert.equal(mapped.size, 36);
  for (const scannerMiss of [
    'tool:saveOrganizationBudgetElement', 'tool:closeUncloseOrganizationBudgetElement',
    'tool:saveAbacusAffectations', 'tool:saveAbacusAssignments'
  ]) assert.ok(mapped.has(scannerMiss), scannerMiss);
  assert.ok(mapped.has('tool:saveWorkTokenClientContract'));
  assert.ok(mapped.has('tool:saveWorkTokenMarkup'));
});

test('Financial does not claim HR employment contracts or Planning activity work units', () => {
  const claims = new Set(financial.claims.classes);
  for (const foreign of ['EmploymentContract', 'EmploymentContractType', 'ActivityWorkUnit']) assert.equal(claims.has(foreign), false, foreign);
  const policy = JSON.parse(readFileSync(new URL('../../policy/modules/financial.json', import.meta.url)));
  assert.equal(policy.excludedOwnership.EmploymentContract, 'hr');
  assert.equal(policy.excludedOwnership.ActivityWorkUnit, 'planning');
  assert.doesNotMatch(JSON.stringify(policy.ownedHandlers), /EmpContract|ActivityWorkUnit/);
});

test('Financial contracts are closed, bounded, typed, permission-aware and guarded', () => {
  const descriptor = phpDescriptor();
  for (const [id, action] of Object.entries(descriptor.actions)) {
    assert.equal(action.schema.type, 'object', id);
    assert.equal(action.schema.additionalProperties, false, id);
    assert.equal(action.resultSchema.type, 'object', id);
    assert.equal(action.resultSchema.additionalProperties, false, id);
    assert.equal(action.availability, 'mcpFinancialActionAvailable', id);
    assert.ok(Array.isArray(action.permissionClasses) && action.permissionClasses.length > 0, id);
    assert.ok(['destructive', 'external'].includes(action.risk), id);
    assert.equal(typeof action.preview, 'string', id);
    if (action.batchLimit !== undefined) assert.equal(action.batchLimit, 200, id);
    for (const property of Object.values(action.schema.properties)) {
      if (property?.type === 'array' && property.items?.type === 'object') assert.equal(property.maxItems, 200, id);
    }
    const resultItem = action.resultSchema.properties.items?.items;
    if (resultItem) {
      assert.equal(resultItem.properties.saved.additionalProperties, false, id);
      assert.equal(resultItem.properties.error.additionalProperties, false, id);
    }
  }
});

test('Financial existing writes enforce optimistic concurrency in the executor', () => {
  const descriptor = phpDescriptor();
  assert.ok(descriptor.actions['financial.budget.move'].schema.properties.moves.items.required.includes('expectedVersion'));
  assert.ok(descriptor.actions['financial.abacus.apply_to_project'].schema.required.includes('expectedVersion'));
  const source = readFileSync(new URL('../../bridge/modules/financial/actions.php', import.meta.url), 'utf8');
  assert.match(source, /expected_version_required/);
  assert.match(source, /version_conflict/);
  assert.match(source, /Security::checkValidAccessForUser/);
});

test('Financial atomic batches report rollback accurately and preserve nested capture state', () => {
  const actionsPath = new URL('../../bridge/modules/financial/actions.php', import.meta.url).pathname;
  const result = JSON.parse(execFileSync('php', ['-r', `
    class McpBridgeException extends RuntimeException { public string $errorCode; public array $details; function __construct($code,$message,$details=[]){parent::__construct($message);$this->errorCode=$code;$this->details=$details;} }
    function cleanApiMessage($value){return (string)$value;}
    function mcpJsonError($status,$code,$message,$details=[]){throw new McpBridgeException($code,$message,$details);}
    class Sql { public static array $events=[]; public static function beginTransaction(){self::$events[]='begin';} public static function commitTransaction(){self::$events[]='commit';} public static function rollbackTransaction(){self::$events[]='rollback';} }
    require ${JSON.stringify(actionsPath)};
    $GLOBALS['mcpCaptureErrors']=true;
    $result=mcpFinancialBatch([['id'=>1],['id'=>2]],'atomic',function($entry,$index){
      if($index===1)throw new RuntimeException('stop');
      return ['status'=>'updated','objectClass'=>'Bill','id'=>1,'relatedIds'=>[],'requestedFields'=>['amount'],'appliedFields'=>['amount'],'recalculatedFields'=>['total'],'ignoredFields'=>[],'rejectedFields'=>[],'saved'=>['id'=>1,'_version'=>'v'],'concurrencyUnchecked'=>false];
    });
    $mismatch=null;try{mcpFinancialRequireChildContext((object)['id'=>7,'idExpense'=>9],['idExpense'=>8]);}catch(McpBridgeException $error){$mismatch=$error->errorCode;}
    echo json_encode(['result'=>$result,'events'=>Sql::$events,'capture'=>$GLOBALS['mcpCaptureErrors'],'mismatch'=>$mismatch],JSON_THROW_ON_ERROR);
  `], { encoding: 'utf8' }));
  assert.equal(result.result.rolledBack, true);
  assert.deepEqual(result.result.items.map(item => item.status), ['rolled_back', 'error']);
  assert.equal(result.result.items[0].saved, null);
  assert.deepEqual(result.result.items[0].appliedFields, []);
  assert.deepEqual(result.result.items[0].rejectedFields, ['amount']);
  assert.deepEqual(result.events, ['begin', 'rollback']);
  assert.equal(result.capture, true);
  assert.equal(result.mismatch, 'financial_child_context_mismatch');
});

test('Financial semantic child executors bind existing rows to authorized parents', () => {
  const source = readFileSync(new URL('../../bridge/modules/financial/actions.php', import.meta.url), 'utf8');
  assert.ok((source.match(/mcpFinancialRequireChildContext\(/g) ?? []).length >= 11);
});

test('Financial async actions have one worker each and are never silently replayed', () => {
  const asyncActions = Object.entries(phpDescriptor().actions).filter(([, action]) => action.async);
  assert.deepEqual(asyncActions.map(([id]) => id), ['financial.abacus.apply_to_project', 'financial.facturx.import']);
  for (const [, action] of asyncActions) {
    assert.match(action.callable, /^mcpFinancial/);
    assert.equal(action.retryPolicy, 'recovery_required');
    assert.equal(action.transaction, 'worker');
  }
});

test('Factur-X consumes an actor-bound upload and bounds file and line processing', () => {
  const action = phpDescriptor().actions['financial.facturx.import'];
  assert.deepEqual(action.schema.required, ['uploadId', 'idProject']);
  assert.equal(action.schema.additionalProperties, false);
  const source = readFileSync(new URL('../../bridge/modules/financial/worker.php', import.meta.url), 'utf8');
  assert.match(source, /mcpReadUpload/);
  assert.match(source, /Security::checkEvilFile/);
  assert.match(source, /PDF signature/);
  assert.match(source, /1 to 200 invoice lines/);
  assert.match(source, /workerCancelled/);
});

test('Financial schemas never accept credential material', () => {
  const text = JSON.stringify(phpDescriptor().actions);
  for (const forbidden of ['password', 'apiKey', 'oauthSecret', 'smtpPassword', 'credential']) assert.equal(text.includes(`"${forbidden}"`), false, forbidden);
});
