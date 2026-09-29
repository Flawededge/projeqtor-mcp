<?php
declare(strict_types=1);

function mcpEnvironmentExecuteInternalBatch(array $entries,string $mode,callable $executor): array {
  if (!in_array($mode,array('atomic','best_effort'),true)) mcpJsonError(400,'invalid_transaction_mode','transactionMode must be atomic or best_effort');
  $items=array();
  if ($mode==='atomic') Sql::beginTransaction();
  foreach ($entries as $index=>$entry) {
    if ($mode==='best_effort') Sql::beginTransaction();
    $GLOBALS['mcpCaptureErrors']=true;
    try {
      $item=$executor($entry);$item['index']=$index;$items[]=$item;
      if ($mode==='best_effort') Sql::commitTransaction();
    } catch (Throwable $error) {
      if ($mode==='best_effort') Sql::rollbackTransaction();
      $details=$error instanceof McpBridgeException
        ? array_merge(array('code'=>$error->errorCode,'message'=>$error->getMessage()),$error->details)
        : array('code'=>'environment_operation_failed','message'=>cleanApiMessage($error->getMessage()));
      $items[]=array('index'=>$index,'status'=>'error','objectClass'=>'EnvironmentInternal','id'=>null,'error'=>$details);
      if ($mode==='atomic') {
        Sql::rollbackTransaction();$result=array('ok'=>false,'rolledBack'=>true,'transactionMode'=>$mode,'items'=>$items);
        $result['effects']=array();$GLOBALS['mcpCaptureErrors']=false;return $result;
      }
    } finally {
      $GLOBALS['mcpCaptureErrors']=false;
    }
  }
  if ($mode==='atomic') Sql::commitTransaction();
  $result=array('ok'=>!count(array_filter($items,fn($item)=>($item['status']??'')==='error')),'rolledBack'=>false,'transactionMode'=>$mode,'items'=>$items);
  $result['effects']=mcpEnvironmentEffects($result);foreach($result['items'] as &$item)unset($item['effects']);unset($item);return $result;
}

function mcpEnvironmentInterventionCapacityBatchAction(array $arguments,string $username,string $action): array {
  return mcpEnvironmentExecuteInternalBatch($arguments['entries'],(string)($arguments['transactionMode']??'atomic'),function(array $entry): array {
    $class=(string)$entry['refType'];Security::checkValidClass($class);$ref=new $class((int)$entry['refId']);
    if (!$ref->id||!Security::checkValidAccessForUser($ref,'update',null,null,false)) mcpJsonError(403,'forbidden','Intervention capacity target is unavailable');
    $object=SqlElement::getSingleSqlElementFromCriteria('InterventionCapacity',array('refType'=>$class,'refId'=>(int)$entry['refId'],'month'=>$entry['month']));
    $verb=(string)($entry['operation']??'upsert');
    if ($verb==='delete'&&!$object->id) mcpJsonError(404,'environment_target_not_found','Intervention capacity was not found');
    if ($verb!=='delete') {$object->refType=$class;$object->refId=(int)$entry['refId'];$object->month=(string)$entry['month'];$object->fte=$entry['fte'];}
    return mcpEnvironmentInternalSave($object,$verb,$entry['expectedVersion']??null);
  });
}

function mcpEnvironmentInterventionScheduleBatchAction(array $arguments,string $username,string $action): array {
  return mcpEnvironmentExecuteInternalBatch($arguments['entries'],(string)($arguments['transactionMode']??'atomic'),function(array $entry): array {
    $resource=new Resource((int)$entry['idResource']);
    if (!$resource->id||!Security::checkValidAccessForUser($resource,'update',null,null,false)) mcpJsonError(403,'forbidden','Resource is unavailable');
    $object=SqlElement::getSingleSqlElementFromCriteria('PlannedWorkManual',array('workDate'=>$entry['workDate'],'idResource'=>(int)$entry['idResource'],'period'=>$entry['period']));
    if ($entry['operation']==='clear' && !$object->id) mcpJsonError(404,'environment_target_not_found','Intervention schedule entry was not found');
    if ($object->id&&!empty($object->refType)&&!empty($object->refId)) {
      $currentClass=(string)$object->refType;Security::checkValidClass($currentClass);$currentRef=new $currentClass((int)$object->refId);
      if (!$currentRef->id||!Security::checkValidAccessForUser($currentRef,'update',null,null,false)) mcpJsonError(403,'forbidden','Existing intervention target is unavailable');
    }
    if (!$object->id) {$object=new PlannedWorkManual();$object->setDates((string)$entry['workDate']);$object->idResource=(int)$entry['idResource'];$object->period=(string)$entry['period'];}
    if ($entry['operation']==='clear') {$object->refType=null;$object->refId=null;$object->idInterventionMode=null;$object->work=null;}
    else {
      foreach (array('refType','refId','idInterventionMode') as $field) if (empty($entry[$field])) mcpJsonError(400,'missing_field',"$field is required when setting an intervention",array('field'=>$field));
      $class=(string)$entry['refType'];Security::checkValidClass($class);$ref=new $class((int)$entry['refId']);
      if (!$ref->id||!Security::checkValidAccessForUser($ref,'update',null,null,false)) mcpJsonError(403,'forbidden','Intervention target is unavailable');
      mcpEnvironmentAssertInterventionCompatible((int)$entry['idResource'],(string)$entry['workDate'],(string)$entry['period'],(int)($object->id??0));
      $object->refType=$class;$object->refId=(int)$entry['refId'];$object->idInterventionMode=(int)$entry['idInterventionMode'];
      $object->work=$entry['work']??($resource->getCapacityPeriod($entry['workDate'])/2);
    }
    return mcpEnvironmentInternalSave($object,'upsert',$entry['expectedVersion']??null);
  });
}
