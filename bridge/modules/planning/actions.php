<?php
declare(strict_types=1);

function mcpPlanningDiagnosticsAction(array $arguments,string $username,string $action): array {
  return mcpPlanningDiagnostics((int)($arguments['idProject']??0));
}

function mcpPlanningRequireVersion(object $object,?string $expected): void {
  if($expected===null||$expected==='')mcpJsonError(409,'expected_version_required',get_class($object).' #'.(int)$object->id.' requires expectedVersion');
  $actual=mcpObjectVersion($object);
  if(!hash_equals($actual,$expected))mcpJsonError(409,'version_conflict',get_class($object).' #'.(int)$object->id.' has changed',array('expectedVersion'=>$expected,'actualVersion'=>$actual));
}

function mcpPlanningRequireAccess(object $object,string $operation,string $message): void {
  if(!(int)($object->id??0)||!Security::checkValidAccessForUser($object,$operation,null,null,false))mcpJsonError(403,'forbidden',$message);
}

function mcpPlanningSave(object $object,string $code='planning_save_failed'): object {
  $raw=$object->save();
  if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,$code,cleanApiMessage($raw));
  $class=get_class($object);return new $class((int)$object->id);
}

function mcpPlanningTarget(string $class,int $id,string $operation='update'): array {
  if(!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D',$class)||!SqlElement::class_exists($class))mcpJsonError(400,'invalid_target_class','Target type is not an installed ProjeQtOr class');
  mcpRequireClassOperation($class,$operation);
  $target=new $class($id);
  mcpPlanningRequireAccess($target,$operation,"$operation access is denied for $class #$id");
  $planningClass=$class==='PeriodicMeeting'?'MeetingPlanningElement':$class.'PlanningElement';
  if(!property_exists($target,$planningClass)||!is_object($target->$planningClass)||!(int)($target->$planningClass->id??0))mcpJsonError(400,'not_plannable',"$class #$id has no planning element");
  return array($target,$target->$planningClass);
}

function mcpPlanningErrorResult(Throwable $error,int $index,array $item=array()): array {
  $code=$error instanceof McpBridgeException?$error->errorCode:'planning_operation_failed';
  $details=$error instanceof McpBridgeException?$error->details:array();
  return array('index'=>$index,'status'=>'error','appliedFields'=>array(),'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>array_values(array_filter(array_keys(isset($item['value'])&&is_array($item['value'])?$item['value']:$item),fn($field)=>!in_array($field,array('id','expectedVersion'),true))),'error'=>array_merge(array('code'=>$code,'message'=>cleanApiMessage($error->getMessage())),$details));
}

function mcpPlanningBatch(array $arguments,callable $executor): array {
  $items=$arguments['items']??array();
  if(!is_array($items)||count($items)<1||count($items)>200)mcpJsonError(400,'invalid_batch','items must contain 1 to 200 entries');
  $mode=(string)($arguments['transactionMode']??'atomic');
  if(!in_array($mode,array('atomic','best_effort'),true))mcpJsonError(400,'invalid_transaction_mode','transactionMode must be atomic or best_effort');
  $results=array();$effects=array();$rolledBack=false;
  if($mode==='atomic')Sql::beginTransaction();
  foreach($items as $index=>$item){
    if($mode==='best_effort')Sql::beginTransaction();
    try{
      if(!is_array($item))mcpJsonError(400,'invalid_item',"Planning item $index must be an object");
      $result=$executor($item,$index);
      $result['index']=$index;
      $fieldSource=isset($item['value'])&&is_array($item['value'])?$item['value']:$item;
      $requestedFields=array_values(array_filter(array_keys($fieldSource),fn($field)=>!in_array($field,array('id','expectedVersion'),true)));
      $result['appliedFields']=$result['appliedFields']??(in_array($result['status'],array('created','updated','applied'),true)?$requestedFields:array());
      $result['recalculatedFields']=$result['recalculatedFields']??array();$result['rejectedFields']=$result['rejectedFields']??array();$result['ignoredFields']=$result['ignoredFields']??array();
      foreach($result['effects']??array() as $effect)$effects[]=$effect;
      unset($result['effects']);
      if($mode==='best_effort')Sql::commitTransaction();
      $results[]=$result;
    }catch(Throwable $error){
      if($mode==='best_effort')Sql::rollbackTransaction();
      $results[]=mcpPlanningErrorResult($error,$index,is_array($item)?$item:array());
      if($mode==='atomic'){
        Sql::rollbackTransaction();$rolledBack=true;$effects=array();
        foreach($results as &$entry)if(!in_array($entry['status'],array('error'),true)){$entry['status']='rolled_back';$entry['rejectedFields']=array_values(array_unique(array_merge($entry['rejectedFields']??array(),$entry['appliedFields']??array())));$entry['appliedFields']=array();$entry['recalculatedFields']=array();}
        unset($entry);break;
      }
    }
  }
  if($mode==='atomic'&&!$rolledBack)Sql::commitTransaction();
  return array('ok'=>!$rolledBack&&!count(array_filter($results,fn($item)=>$item['status']==='error')),'rolledBack'=>$rolledBack,'transactionMode'=>$mode,'items'=>$results,'effects'=>$effects);
}

function mcpPlanningDeletePreview(string $class,array $arguments): array {
  mcpRequireClassOperation($class,'delete');
  $items=array();
  foreach($arguments['items']??array() as $entry){
    $id=(int)($entry['id']??0);$object=new $class($id);
    mcpPlanningRequireAccess($object,'delete',"Delete access is denied for $class #$id");
    $items[]=array('objectClass'=>$class,'id'=>$id,'exists'=>(bool)$object->id,'name'=>$object->name??null,'version'=>$object->id?mcpObjectVersion($object):null);
  }
  return array('count'=>count($items),'items'=>$items);
}

function mcpPlanningAssignmentUpsert(array $arguments,string $username,string $action): array {
  return mcpPlanningBatch($arguments,function(array $item): array {
    mcpRequireClassOperation('Assignment',isset($item['id'])?'update':'create');
    $assignment=isset($item['id'])?new Assignment((int)$item['id']):new Assignment();
    if(isset($item['id'])){
      mcpPlanningRequireAccess($assignment,'update','Assignment is unavailable');
      mcpPlanningRequireVersion($assignment,isset($item['expectedVersion'])?(string)$item['expectedVersion']:null);
      $refType=(string)$assignment->refType;$refId=(int)$assignment->refId;
    }else{
      foreach(array('refType','refId','resourceId','rate','assignedWork') as $field)if(!array_key_exists($field,$item))mcpJsonError(400,'missing_field',"Assignment create requires '$field'",array('field'=>$field));
      $refType=(string)$item['refType'];$refId=(int)$item['refId'];
    }
    [$target, $planningTarget]=mcpPlanningTarget($refType,$refId);
    if(isset($item['resourceId'])){
      $resource=new Affectable((int)$item['resourceId']);
      if(!$resource->id)mcpJsonError(400,'invalid_reference','Assignment resource is unavailable',array('field'=>'resourceId'));
      $assignment->idResource=(int)$resource->id;
    }
    if(isset($item['roleId'])){
      $role=new Role((int)$item['roleId']);if(!$role->id)mcpJsonError(400,'invalid_reference','Assignment role is unavailable',array('field'=>'roleId'));
      $assignment->idRole=(int)$role->id;
    }elseif(!$assignment->idRole&&$assignment->idResource){
      $resource=new ResourceAll((int)$assignment->idResource);$assignment->idRole=(int)$resource->idRole;
    }
    $assignment->refType=$refType;$assignment->refId=$refId;$assignment->idProject=(int)$target->idProject;
    foreach(array('rate','capacity','dailyCost') as $field)if(array_key_exists($field,$item))$assignment->$field=(float)$item[$field];
    foreach(array('proportional','optional','idle') as $field)if(array_key_exists($field,$item))$assignment->$field=$item[$field]?1:0;
    foreach(array('assignedWork','leftWork') as $field)if(array_key_exists($field,$item))$assignment->$field=(float)$item[$field];
    if(array_key_exists('comment',$item))$assignment->comment=(string)$item['comment'];
    if(!$assignment->id&&!Security::checkValidAccessForUser($assignment,'create',null,null,false))mcpJsonError(403,'forbidden','Assignment create access is denied');
    $created=!$assignment->id;$saved=mcpPlanningSave($assignment,'assignment_save_failed');
    $effect=array('action'=>$created?'create':'update','objectClass'=>'Assignment','id'=>(int)$saved->id);
    return array('status'=>$created?'created':'updated','objectClass'=>'Assignment','id'=>(int)$saved->id,'saved'=>mcpObjectArray($saved),'effects'=>array($effect));
  });
}

function mcpPlanningAssignmentRemovePreview(array $arguments,string $username,string $action): array {
  return mcpPlanningDeletePreview('Assignment',$arguments);
}

function mcpPlanningAssignmentRemove(array $arguments,string $username,string $action): array {
  return mcpPlanningBatch($arguments,function(array $item): array {
    mcpRequireClassOperation('Assignment','delete');$assignment=new Assignment((int)$item['id']);
    mcpPlanningRequireAccess($assignment,'delete','Assignment is unavailable');
    mcpPlanningRequireVersion($assignment,isset($item['expectedVersion'])?(string)$item['expectedVersion']:null);
    $id=(int)$assignment->id;$raw=$assignment->delete();
    if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'assignment_delete_failed',cleanApiMessage($raw));
    return array('status'=>'deleted','objectClass'=>'Assignment','id'=>$id,'effects'=>array(array('action'=>'delete','objectClass'=>'Assignment','id'=>$id)));
  });
}

function mcpPlanningAutomaticAssignment(array $arguments,string $username,string $action): array {
  [$target,$planning]=mcpPlanningTarget((string)$arguments['refType'],(int)$arguments['refId']);
  mcpPlanningRequireVersion($target,isset($arguments['expectedVersion'])?(string)$arguments['expectedVersion']:null);
  Sql::beginTransaction();
  try{
    $planning->automaticAssignment=!empty($arguments['enabled'])?1:0;
    mcpPlanningSave($planning,'automatic_assignment_failed');
    if(!empty($arguments['enabled'])){
      $existing=array();$probe=new Assignment();
      foreach($probe->getSqlElementsFromCriteria(array('idProject'=>(int)$target->idProject,'refType'=>get_class($target),'refId'=>(int)$target->id)) as $assignment)$existing[(int)$assignment->idResource]=$assignment;
      $affectation=new Affectation();
      foreach($affectation->getSqlElementsFromCriteria(array('idProject'=>(int)$target->idProject,'idle'=>'0')) as $allocation){
        $resourceId=(int)$allocation->idResource;
        if(isset($existing[$resourceId])){if($existing[$resourceId]->idle){$existing[$resourceId]->idle=0;mcpPlanningSave($existing[$resourceId]);}continue;}
        $resource=new ResourceAll($resourceId);if(!$resource->id||(!$resource->isResource&&!$resource->isResourceTeam))continue;
        $assignment=new Assignment();$assignment->idProject=(int)$target->idProject;$assignment->refType=get_class($target);$assignment->refId=(int)$target->id;
        $assignment->idResource=$resourceId;$assignment->idRole=(int)$resource->idRole;$assignment->rate=100;if($resource->isResourceTeam)$assignment->capacity=1;
        mcpPlanningSave($assignment,'automatic_assignment_failed');
      }
    }
    $targetClass=get_class($target);$saved=new $targetClass((int)$target->id);Sql::commitTransaction();
  }catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  return array('ok'=>true,'status'=>'updated','saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>'update','objectClass'=>get_class($saved),'id'=>(int)$saved->id)));
}

function mcpPlanningAllocationUpsert(array $arguments,string $username,string $action): array {
  return mcpPlanningBatch($arguments,function(array $item): array {
    mcpRequireClassOperation('Affectation',isset($item['id'])?'update':'create');
    $allocation=isset($item['id'])?new Affectation((int)$item['id']):new Affectation();
    if(isset($item['id'])){
      mcpPlanningRequireAccess($allocation,'update','Allocation is unavailable');
      mcpPlanningRequireVersion($allocation,isset($item['expectedVersion'])?(string)$item['expectedVersion']:null);
    }else foreach(array('projectId','resourceId','profileId','rate') as $field)if(!array_key_exists($field,$item))mcpJsonError(400,'missing_field',"Allocation create requires '$field'",array('field'=>$field));
    $projectId=(int)($item['projectId']??$allocation->idProject);$project=new Project($projectId);
    mcpPlanningRequireAccess($project,'update',"Allocation access is denied for project #$projectId");
    if(isset($item['resourceId'])){$resource=new Affectable((int)$item['resourceId']);if(!$resource->id)mcpJsonError(400,'invalid_reference','Allocation resource is unavailable');$allocation->idResource=(int)$resource->id;}
    if(isset($item['profileId'])){$profile=new Profile((int)$item['profileId']);if(!$profile->id)mcpJsonError(400,'invalid_reference','Allocation profile is unavailable');$allocation->idProfile=(int)$profile->id;}
    $allocation->idProject=$projectId;
    if(array_key_exists('rate',$item))$allocation->rate=(float)$item['rate'];
    foreach(array('startDate','endDate','description') as $field)if(array_key_exists($field,$item))$allocation->$field=$item[$field]??null;
    if(array_key_exists('idle',$item))$allocation->idle=$item['idle']?1:0;
    if(!$allocation->id&&!Security::checkValidAccessForUser($allocation,'create',null,null,false))mcpJsonError(403,'forbidden','Allocation create access is denied');
    $created=!$allocation->id;$saved=mcpPlanningSave($allocation,'allocation_save_failed');
    return array('status'=>$created?'created':'updated','objectClass'=>'Affectation','id'=>(int)$saved->id,'saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>$created?'create':'update','objectClass'=>'Affectation','id'=>(int)$saved->id)));
  });
}

function mcpPlanningAllocationRemovePreview(array $arguments,string $username,string $action): array {
  return mcpPlanningDeletePreview('Affectation',$arguments);
}

function mcpPlanningAllocationRemove(array $arguments,string $username,string $action): array {
  return mcpPlanningBatch($arguments,function(array $item): array {
    mcpRequireClassOperation('Affectation','delete');$allocation=new Affectation((int)$item['id']);
    mcpPlanningRequireAccess($allocation,'delete','Allocation is unavailable');
    mcpPlanningRequireVersion($allocation,isset($item['expectedVersion'])?(string)$item['expectedVersion']:null);
    $id=(int)$allocation->id;$raw=$allocation->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'allocation_delete_failed',cleanApiMessage($raw));
    return array('status'=>'deleted','objectClass'=>'Affectation','id'=>$id,'effects'=>array(array('action'=>'delete','objectClass'=>'Affectation','id'=>$id)));
  });
}

function mcpPlanningDependencyUpsert(array $arguments,string $username,string $action): array {
  return mcpPlanningBatch($arguments,function(array $item): array {
    mcpRequireClassOperation('Dependency',isset($item['id'])?'update':'create');
    $dependency=isset($item['id'])?new Dependency((int)$item['id']):new Dependency();
    if(isset($item['id'])){
      mcpPlanningRequireAccess($dependency,'update','Dependency is unavailable');
      mcpPlanningRequireVersion($dependency,isset($item['expectedVersion'])?(string)$item['expectedVersion']:null);
    }else{
      foreach(array('predecessorType','predecessorId','successorType','successorId') as $field)if(!array_key_exists($field,$item))mcpJsonError(400,'missing_field',"Dependency create requires '$field'",array('field'=>$field));
      [, $predecessor]=mcpPlanningTarget((string)$item['predecessorType'],(int)$item['predecessorId'],'read');
      [, $successor]=mcpPlanningTarget((string)$item['successorType'],(int)$item['successorId'],'update');
      $dependency->predecessorId=(int)$predecessor->id;$dependency->predecessorRefType=(string)$predecessor->refType;$dependency->predecessorRefId=(int)$predecessor->refId;
      $dependency->successorId=(int)$successor->id;$dependency->successorRefType=(string)$successor->refType;$dependency->successorRefId=(int)$successor->refId;
    }
    if(array_key_exists('relationshipType',$item))$dependency->dependencyType=(string)$item['relationshipType'];
    elseif(!$dependency->dependencyType)$dependency->dependencyType='E-S';
    if(array_key_exists('lag',$item))$dependency->dependencyDelay=(float)$item['lag'];
    if(array_key_exists('comment',$item))$dependency->comment=(string)$item['comment'];
    if(!$dependency->id&&!Security::checkValidAccessForUser($dependency,'create',null,null,false))mcpJsonError(403,'forbidden','Dependency create access is denied');
    $created=!$dependency->id;$saved=mcpPlanningSave($dependency,'dependency_save_failed');
    return array('status'=>$created?'created':'updated','objectClass'=>'Dependency','id'=>(int)$saved->id,'saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>$created?'create':'update','objectClass'=>'Dependency','id'=>(int)$saved->id)));
  });
}

function mcpPlanningDependencyRemovePreview(array $arguments,string $username,string $action): array {
  return mcpPlanningDeletePreview('Dependency',$arguments);
}

function mcpPlanningDependencyRemove(array $arguments,string $username,string $action): array {
  return mcpPlanningBatch($arguments,function(array $item): array {
    mcpRequireClassOperation('Dependency','delete');$dependency=new Dependency((int)$item['id']);
    mcpPlanningRequireAccess($dependency,'delete','Dependency is unavailable');
    mcpPlanningRequireVersion($dependency,isset($item['expectedVersion'])?(string)$item['expectedVersion']:null);
    $id=(int)$dependency->id;$raw=$dependency->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'dependency_delete_failed',cleanApiMessage($raw));
    return array('status'=>'deleted','objectClass'=>'Dependency','id'=>$id,'effects'=>array(array('action'=>'delete','objectClass'=>'Dependency','id'=>$id)));
  });
}

function mcpPlanningElementResize(array $arguments,string $username,string $action): array {
  [$target,$planning]=mcpPlanningTarget((string)$arguments['refType'],(int)$arguments['refId']);
  mcpPlanningRequireVersion($planning,isset($arguments['expectedVersion'])?(string)$arguments['expectedVersion']:null);
  $start=(string)$arguments['startDate'];$end=(string)$arguments['endDate'];if($end<$start)mcpJsonError(400,'invalid_date_range','endDate must not be before startDate');
  $mode=new PlanningMode((int)$planning->idPlanningMode);$code=(string)$mode->code;$resizer=(string)($arguments['resizer']??'both');
  if(in_array($code,array('START','STARR'),true)){$planning->validatedStartDate=$start;if($planning->validatedEndDate)$planning->validatedEndDate=$end;}
  if($code==='ALAP'){$planning->validatedEndDate=$end;if($planning->validatedStartDate)$planning->validatedStartDate=$start;}
  if($code==='FDUR'){$planning->validatedDuration=workDayDiffDates($start,$end,(int)$planning->idProject);if($planning->validatedStartDate)$planning->validatedStartDate=$start;if($planning->validatedEndDate)$planning->validatedEndDate=$end;}
  if(in_array($code,array('DDUR','CDUR'),true)){if($resizer!=='start')$planning->validatedDuration=workDayDiffDates($start,$end,(int)$planning->idProject);if($resizer==='start'||$planning->validatedStartDate)$planning->validatedStartDate=$start;if($planning->validatedEndDate)$planning->validatedEndDate=$end;}
  if(in_array($code,array('REGUL','QUART','HALF','FULL'),true)){$planning->validatedStartDate=$start;$planning->validatedEndDate=$end;}
  $planning->plannedStartDate=$start;$planning->plannedEndDate=$end;$planning->plannedDuration=workDayDiffDates($start,$end,(int)$planning->idProject);
  $saved=mcpPlanningSave($planning,'planning_resize_failed');
  return array('ok'=>true,'status'=>'updated','saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>'update','objectClass'=>'PlanningElement','id'=>(int)$saved->id)));
}

function mcpPlanningElementPhase(array $arguments,string $username,string $action): array {
  [$target,$planning]=mcpPlanningTarget((string)$arguments['refType'],(int)$arguments['refId']);
  mcpPlanningRequireVersion($planning,isset($arguments['expectedVersion'])?(string)$arguments['expectedVersion']:null);
  $mode=new PlanningMode((int)$arguments['planningModeId']);if(!$mode->id||$mode->idle)mcpJsonError(400,'invalid_reference','Planning mode is unavailable',array('field'=>'planningModeId'));
  $planning->idPlanningMode=(int)$mode->id;
  foreach(array('validatedStartDate','validatedEndDate','validatedDuration','validatedWork','priority') as $field)if(array_key_exists($field,$arguments))$planning->$field=$arguments[$field];
  if(isset($arguments['parentRefType'])||isset($arguments['parentRefId'])){
    if(!isset($arguments['parentRefType'],$arguments['parentRefId']))mcpJsonError(400,'missing_field','Both parentRefType and parentRefId are required');
    [, $parent]=mcpPlanningTarget((string)$arguments['parentRefType'],(int)$arguments['parentRefId'],'read');
    if((int)$parent->id===(int)$planning->id)mcpJsonError(400,'invalid_parent','A planning element cannot be its own parent');
    $planning->topId=(int)$parent->id;$planning->topRefType=(string)$parent->refType;$planning->topRefId=(int)$parent->refId;
  }
  $saved=mcpPlanningSave($planning,'planning_phase_failed');
  return array('ok'=>true,'status'=>'updated','saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>'update','objectClass'=>'PlanningElement','id'=>(int)$saved->id)));
}

function mcpPlanningActivitySplitPreview(array $arguments,string $username,string $action): array {
  [$activity,$planning]=mcpPlanningTarget('Activity',(int)$arguments['refId']);
  $workUnit=new ActivityWorkUnit();$count=$workUnit->countSqlElementsFromCriteria(array('refType'=>'Activity','refId'=>(int)$activity->id));
  return array('objectClass'=>'Activity','id'=>(int)$activity->id,'name'=>$activity->name,'version'=>mcpObjectVersion($activity),'planningVersion'=>mcpObjectVersion($planning),'activityWorkUnitCount'=>$count,'allowed'=>$count===0);
}

function mcpPlanningActivitySplit(array $arguments,string $username,string $action): array {
  [$activity,$planning]=mcpPlanningTarget('Activity',(int)$arguments['refId']);
  mcpPlanningRequireVersion($activity,isset($arguments['expectedVersion'])?(string)$arguments['expectedVersion']:null);
  $ratio=(float)($arguments['ratio']??0.5);if($ratio<=0||$ratio>=1)mcpJsonError(400,'invalid_ratio','ratio must be greater than zero and less than one');
  $workUnit=new ActivityWorkUnit();if($workUnit->countSqlElementsFromCriteria(array('refType'=>'Activity','refId'=>(int)$activity->id))>0)mcpJsonError(409,'activity_has_work_units','Activities with work units cannot be split');
  $deletedDependencies=array();
  Sql::beginTransaction();
  try{
    $newName=(string)($arguments['newName']??$activity->name);
    $new=$activity->copyTo('Activity',(int)$activity->idActivityType,$newName,(int)$activity->idProject,false,true,true,true,true,false,null,null,true,false,true,true,(int)$planning->id,true,false,true);
    if(!(int)($new->id??0)||getLastOperationStatus($new->_copyResult??'')!=='OK')mcpJsonError(400,'activity_split_failed',cleanApiMessage($new->_copyResult??'Unable to copy activity'));
    unset($new->_copyResult);
    $structure=PlanningElement::copyStructure($activity,$new,false,true,true,true,true,null,(int)$activity->idProject,false,true,false,true);
    if($structure!=='OK')mcpJsonError(400,'activity_split_failed',cleanApiMessage($structure));
    PlanningElement::copyStructureFinalize();
    $dependency=new Dependency();$where='predecessorRefType='.Sql::str('Activity').' and predecessorRefId='.Sql::fmtId((int)$activity->id).' and successorRefId<>'.Sql::fmtId((int)$new->id);
    foreach($dependency->getSqlElementsFromCriteria(null,false,$where) as $outgoing){
      $dependencyId=(int)$outgoing->id;$raw=$outgoing->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'activity_split_failed',cleanApiMessage($raw));
      $deletedDependencies[]=$dependencyId;
    }
    $newPlanning=$new->ActivityPlanningElement;
    foreach(array('validatedDuration','validatedWork') as $field){
      $total=(float)$planning->$field;$planning->$field=$total*$ratio;$newPlanning->$field=$total-$planning->$field;
    }
    mcpPlanningSave($planning,'activity_split_failed');mcpPlanningSave($newPlanning,'activity_split_failed');
    $assignment=new Assignment();
    foreach(array((int)$activity->id=>$ratio,(int)$new->id=>1-$ratio) as $refId=>$share)foreach($assignment->getSqlElementsFromCriteria(array('refType'=>'Activity','refId'=>$refId)) as $entry){
      foreach(array('assignedWork','leftWork') as $field)$entry->$field=(float)$entry->$field*$share;
      mcpPlanningSave($entry,'activity_split_failed');
    }
    Sql::commitTransaction();
  }catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  $original=new Activity((int)$activity->id);$created=new Activity((int)$new->id);
  $effects=array(array('action'=>'update','objectClass'=>'Activity','id'=>(int)$original->id),array('action'=>'create','objectClass'=>'Activity','id'=>(int)$created->id));
  foreach($deletedDependencies as $id)$effects[]=array('action'=>'delete','objectClass'=>'Dependency','id'=>$id);
  return array('ok'=>true,'status'=>'split','original'=>mcpObjectArray($original),'created'=>mcpObjectArray($created),'effects'=>$effects);
}

function mcpPlanningScenarioConfigure(array $arguments,string $username,string $action): array {
  $pools=$arguments['poolAdjustments']??array();$projects=$arguments['projectAdjustments']??array();
  if(!is_array($pools)||!is_array($projects)||count($pools)+count($projects)<1||count($pools)+count($projects)>200)mcpJsonError(400,'invalid_batch','Scenario adjustments must contain 1 to 200 entries');
  $scenarioId=isset($arguments['scenarioId'])?(int)$arguments['scenarioId']:null;
  if($scenarioId){$scenario=new CriticalResourceScenario($scenarioId);if(!$scenario->id||(int)$scenario->idUser!==(int)getSessionUser()->id)mcpJsonError(403,'forbidden','Critical-resource scenario is unavailable');}
  $items=array();foreach($pools as $pool)$items[]=array('kind'=>'pool','value'=>$pool);foreach($projects as $project)$items[]=array('kind'=>'project','value'=>$project);
  return mcpPlanningBatch(array('items'=>$items,'transactionMode'=>$arguments['transactionMode']??'atomic'),function(array $entry) use($scenarioId): array {
    $value=$entry['value'];$userId=(int)getSessionUser()->id;
    if($entry['kind']==='pool'){
      $resource=new ResourceTeam((int)$value['resourceId']);if(!$resource->id)mcpJsonError(400,'invalid_reference','Resource pool is unavailable');
      $criteria=array('idResource'=>(int)$resource->id,'idUser'=>$userId,'idScenario'=>$scenarioId);
      $adjustment=SqlElement::getSingleSqlElementFromCriteria('CriticalResourceScenarioPool',$criteria);
      $created=!$adjustment->id;$adjustment->idResource=(int)$resource->id;$adjustment->idUser=$userId;$adjustment->idScenario=$scenarioId;
      if(array_key_exists('extraCapacity',$value))$adjustment->extracapacity=$value['extraCapacity'];
      if(array_key_exists('givenDate',$value))$adjustment->givenDate=$value['givenDate'];
      if($adjustment->extracapacity===null&&$adjustment->givenDate===null&&!$adjustment->id)return array('status'=>'applied','objectClass'=>'CriticalResourceScenarioPool','id'=>null);
      if($adjustment->extracapacity===null&&$adjustment->givenDate===null&&$adjustment->id){$id=(int)$adjustment->id;$raw=$adjustment->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'scenario_save_failed',cleanApiMessage($raw));return array('status'=>'deleted','objectClass'=>'CriticalResourceScenarioPool','id'=>$id,'effects'=>array(array('action'=>'delete','objectClass'=>'CriticalResourceScenarioPool','id'=>$id)));}
      $saved=mcpPlanningSave($adjustment,'scenario_save_failed');return array('status'=>$created?'created':'updated','objectClass'=>'CriticalResourceScenarioPool','id'=>(int)$saved->id,'saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>$created?'create':'update','objectClass'=>'CriticalResourceScenarioPool','id'=>(int)$saved->id)));
    }
    $project=new Project((int)$value['projectId']);mcpPlanningRequireAccess($project,'read','Scenario project is unavailable');
    $criteria=array('idProject'=>(int)$project->id,'idUser'=>$userId,'idScenario'=>$scenarioId);
    $adjustment=SqlElement::getSingleSqlElementFromCriteria('CriticalResourceScenarioProject',$criteria);
    $created=!$adjustment->id;$adjustment->idProject=(int)$project->id;$adjustment->idUser=$userId;$adjustment->idScenario=$scenarioId;
    if(array_key_exists('proposal',$value))$adjustment->proposale=match($value['proposal']){'include'=>1,'exclude'=>2,default=>null};
    if(array_key_exists('monthDelay',$value))$adjustment->monthDelay=$value['monthDelay'];
    if($adjustment->proposale===null&&$adjustment->monthDelay===null&&!$adjustment->id)return array('status'=>'applied','objectClass'=>'CriticalResourceScenarioProject','id'=>null);
    if($adjustment->proposale===null&&$adjustment->monthDelay===null&&$adjustment->id){$id=(int)$adjustment->id;$raw=$adjustment->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'scenario_save_failed',cleanApiMessage($raw));return array('status'=>'deleted','objectClass'=>'CriticalResourceScenarioProject','id'=>$id,'effects'=>array(array('action'=>'delete','objectClass'=>'CriticalResourceScenarioProject','id'=>$id)));}
    $saved=mcpPlanningSave($adjustment,'scenario_save_failed');return array('status'=>$created?'created':'updated','objectClass'=>'CriticalResourceScenarioProject','id'=>(int)$saved->id,'saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>$created?'create':'update','objectClass'=>'CriticalResourceScenarioProject','id'=>(int)$saved->id)));
  });
}

function mcpPlanningBaselineDeletePreview(array $arguments,string $username,string $action): array {
  $baseline=new Baseline((int)($arguments['id']??0));
  mcpRequireClassOperation('Baseline','delete');
  if(!$baseline->id||(int)$baseline->idUser!==(int)getSessionUser()->id)mcpJsonError(403,'forbidden','Only the baseline owner may preview its deletion');
  mcpPlanningRequireAccess($baseline,'delete','Baseline delete access is denied');
  return array('objectClass'=>'Baseline','id'=>(int)$baseline->id,'exists'=>true,'name'=>$baseline->name??null,'version'=>mcpObjectVersion($baseline),'ownedByActor'=>true);
}

function mcpPlanningBaselineDelete(array $arguments,string $username,string $action): array {
  $baseline=new Baseline((int)($arguments['id']??0));
  if(!$baseline->id||(int)$baseline->idUser!==(int)getSessionUser()->id)mcpJsonError(403,'forbidden','Only the baseline owner may delete it');
  mcpPlanningRequireVersion($baseline,isset($arguments['expectedVersion'])?(string)$arguments['expectedVersion']:null);
  Sql::beginTransaction();$raw=$baseline->deleteWithPlanning();
  if(getLastOperationStatus($raw)!=='OK'){Sql::rollbackTransaction();mcpJsonError(400,'baseline_delete_failed',cleanApiMessage($raw));}
  Sql::commitTransaction();return array('ok'=>true,'id'=>(int)$arguments['id'],'status'=>'deleted','effects'=>array(array('action'=>'delete','objectClass'=>'Baseline','id'=>(int)$arguments['id'])));
}
