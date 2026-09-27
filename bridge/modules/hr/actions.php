<?php
declare(strict_types=1);

/** HR actions deliberately stay synchronous: each request is bounded to 200 items. */

function mcpHrLeaveAvailable(array $action): bool {
  return class_exists('Module') && Module::isModuleActive('moduleAbsence') && function_exists('isLeavesSystemActiv') && isLeavesSystemActiv();
}

function mcpHrSkillAvailable(array $action): bool {
  return class_exists('Module') && Module::isModuleActive('moduleSkillManagement') && class_exists('Skill') && class_exists('ResourceSkill');
}

function mcpHrCleanError(Throwable $error): array {
  if($error instanceof McpBridgeException){
    return array_merge(array('code'=>$error->errorCode,'message'=>cleanApiMessage($error->getMessage())),$error->details);
  }
  return array('code'=>'hr_action_failed','message'=>cleanApiMessage($error->getMessage()));
}

function mcpHrRunBatch(array $items,string $transactionMode,callable $executor): array {
  if(count($items)<1||count($items)>200)mcpJsonError(400,'invalid_batch','HR action items must contain 1 to 200 entries');
  if(!in_array($transactionMode,array('atomic','best_effort'),true))mcpJsonError(400,'invalid_transaction_mode','transactionMode must be atomic or best_effort');
  $results=array();$effects=array();$previousCapture=$GLOBALS['mcpCaptureErrors']??false;
  if($transactionMode==='atomic')Sql::beginTransaction();
  foreach($items as $index=>$item){
    if($transactionMode==='best_effort')Sql::beginTransaction();
    $GLOBALS['mcpCaptureErrors']=true;
    try{
      if(!is_array($item))mcpJsonError(400,'invalid_item','HR batch entries must be objects',array('index'=>$index));
      $result=$executor($item,$index);
      if(!is_array($result))throw new RuntimeException('HR executor returned an invalid result');
      $result['index']=$index;$results[]=$result;
      foreach($result['effects']??array() as $effect)$effects[]=$effect;
      if($transactionMode==='best_effort')Sql::commitTransaction();
    }catch(Throwable $error){
      if($transactionMode==='best_effort')Sql::rollbackTransaction();
      $results[]=array('index'=>$index,'status'=>'error','error'=>mcpHrCleanError($error),'effects'=>array());
      if($transactionMode==='atomic'){
        Sql::rollbackTransaction();
        foreach($results as &$rolledBack)if(!in_array($rolledBack['status']??'',array('error','invalid'),true))$rolledBack['status']='rolled_back';
        unset($rolledBack);$GLOBALS['mcpCaptureErrors']=$previousCapture;
        return array('ok'=>false,'rolledBack'=>true,'transactionMode'=>$transactionMode,'items'=>$results,'effects'=>array());
      }
    }finally{
      $GLOBALS['mcpCaptureErrors']=$previousCapture;
    }
  }
  if($transactionMode==='atomic')Sql::commitTransaction();
  $ok=!count(array_filter($results,fn($item)=>in_array($item['status']??'',array('error','invalid'),true)));
  return array('ok'=>$ok,'rolledBack'=>false,'transactionMode'=>$transactionMode,'items'=>$results,'effects'=>$effects);
}

function mcpHrRequireExisting(string $class,int $id,string $operation='update'): object {
  mcpRequireClassOperation($class,$operation);$object=new $class($id);
  if(!$object->id)mcpJsonError(404,'not_found',"$class #$id was not found");
  $allowed=Security::checkValidAccessForUser($object,$operation,null,null,false);if(!$allowed&&$operation==='read'&&function_exists('mcpContextualReadAllowed'))$allowed=mcpContextualReadAllowed($object);if(!$allowed)mcpJsonError(403,'forbidden',"$operation access is denied for $class #$id");
  return $object;
}

function mcpHrRequireExpected(object $object,?string $expectedVersion): void {
  if(!$expectedVersion)mcpJsonError(409,'expected_version_required','expectedVersion is required when changing an existing HR record');
  $actual=mcpObjectVersion($object);
  if(!hash_equals($actual,$expectedVersion))mcpJsonError(409,'version_conflict',get_class($object).' has changed',array('expectedVersion'=>$expectedVersion,'actualVersion'=>$actual));
}

function mcpHrSave(object $object,string $failureCode='hr_save_failed'): array {
  $raw=$object->save();$status=getLastOperationStatus($raw);
  if(!in_array($status,array('OK','NO_CHANGE'),true))mcpJsonError(400,$failureCode,cleanApiMessage($raw));
  return array($status==='NO_CHANGE'?'unchanged':($object->id?'saved':'created'),$raw);
}

function mcpHrMayManageEmployee(int $employeeId): bool {
  $userId=(int)getSessionUser()->id;
  if($employeeId===$userId)return true;
  if(function_exists('isManagerOfEmployee')&&isManagerOfEmployee($userId,$employeeId))return true;
  return function_exists('isLeavesAdmin')&&isLeavesAdmin($userId);
}

function mcpHrRequireLeavesAdmin(): void {
  if(!(function_exists('isLeavesAdmin')&&isLeavesAdmin((int)getSessionUser()->id)))mcpJsonError(403,'forbidden','Leave-system administrator access is required');
}

function mcpHrGenericResult(array $operation,string $class,array $extra=array()): array {
  $result=mcpApplyOperation($operation,true);
  if(in_array($result['status']??'',array('invalid','error'),true))mcpJsonError(400,$result['error']['code']??'hr_operation_failed',$result['error']['message']??'HR operation failed');
  $id=(int)($result['id']??0);
  $effect=array('action'=>$operation['action'],'objectClass'=>$class,'id'=>$id);
  return array_merge($result,array('effects'=>array($effect)),$extra);
}

function mcpHrEmployeeManage(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['employees']??array(),(string)($arguments['transactionMode']??'atomic'),function(array $item): array {
    $operation=(string)($item['operation']??'');$id=(int)($item['employeeId']??0);$data=is_array($item['fields']??null)?$item['fields']:array();
    if($operation==='create'){
      $data['isEmployee']=1;$data['isResource']=1;
      return mcpHrGenericResult(array('action'=>'create','objectClass'=>'Employee','data'=>$data),'Employee');
    }
    if(!in_array($operation,array('update','deactivate','reactivate'),true))mcpJsonError(400,'invalid_employee_operation','Employee operation must be create, update, deactivate, or reactivate');
    if($id<1)mcpJsonError(400,'employee_id_required','employeeId is required');
    if(empty($item['expectedVersion']))mcpJsonError(409,'expected_version_required','expectedVersion is required when changing an employee');
    if($operation==='deactivate'){$data['idle']=1;if(!empty($item['endDate']))$data['endDate']=$item['endDate'];}
    if($operation==='reactivate'){$data['idle']=0;$data['endDate']=null;}
    return mcpHrGenericResult(array('action'=>'update','objectClass'=>'Employee','id'=>$id,'expectedVersion'=>$item['expectedVersion']??null,'data'=>$data),'Employee');
  });
}

function mcpHrManagerAssign(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['assignments']??array(),(string)($arguments['transactionMode']??'atomic'),function(array $item): array {
    $id=(int)($item['id']??0);$managerId=(int)($item['managerId']??0);$employeeId=(int)($item['employeeId']??0);
    $manager=mcpHrRequireExisting('EmployeeManager',$managerId,'update');
    $employee=mcpHrRequireExisting('Employee',$employeeId,'read');
    if($id){$relation=new EmployeesManaged($id);if(!$relation->id)mcpJsonError(404,'manager_assignment_not_found','Manager assignment was not found');mcpHrRequireExpected($relation,$item['expectedVersion']??null);}
    if($id)mcpHrRequireExisting('EmployeeManager',(int)$relation->idEmployeeManager,'update');
    else{$relation=new EmployeesManaged();}
    $relation->idEmployeeManager=$manager->id;$relation->idEmployee=$employee->id;$relation->startDate=$item['startDate']??null;$relation->endDate=$item['endDate']??null;$relation->idle=!empty($item['idle'])?1:0;
    [$status]=mcpHrSave($relation,'manager_assignment_failed');$saved=new EmployeesManaged($relation->id);
    return array('status'=>$id?($status==='unchanged'?'unchanged':'updated'):'created','objectClass'=>'EmployeesManaged','id'=>(int)$saved->id,'saved'=>mcpObjectArray($saved),'appliedFields'=>array('idEmployeeManager','idEmployee','startDate','endDate','idle'),'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>array(),'effects'=>array(array('action'=>$id?'update':'create','objectClass'=>'EmployeesManaged','id'=>(int)$saved->id)));
  });
}

function mcpHrManagerRemovePreview(array $arguments,string $username,string $action): array {
  $items=array();foreach($arguments['assignments']??array() as $entry){
    $object=new EmployeesManaged((int)($entry['id']??0));if(!$object->id)mcpJsonError(404,'manager_assignment_not_found','Manager assignment was not found');
    mcpHrRequireExisting('EmployeeManager',(int)$object->idEmployeeManager,'update');
    mcpHrRequireExpected($object,$entry['expectedVersion']??null);
    $items[]=array('id'=>(int)$object->id,'exists'=>true,'employeeId'=>(int)$object->idEmployee,'managerId'=>(int)$object->idEmployeeManager,'version'=>mcpObjectVersion($object));
  }
  return array('count'=>count($items),'items'=>$items);
}

function mcpHrManagerRemove(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['assignments']??array(),'atomic',function(array $item): array {
    $relation=new EmployeesManaged((int)($item['id']??0));if(!$relation->id)mcpJsonError(404,'manager_assignment_not_found','Manager assignment was not found');
    mcpHrRequireExisting('EmployeeManager',(int)$relation->idEmployeeManager,'update');mcpHrRequireExpected($relation,$item['expectedVersion']??null);
    SqlElement::setDeleteConfirmed();$raw=$relation->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'manager_assignment_delete_failed',cleanApiMessage($raw));
    return array('status'=>'deleted','objectClass'=>'EmployeesManaged','id'=>(int)$item['id'],'effects'=>array(array('action'=>'delete','objectClass'=>'EmployeesManaged','id'=>(int)$item['id'])));
  });
}

function mcpHrContractManage(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['contracts']??array(),(string)($arguments['transactionMode']??'atomic'),function(array $item): array {
    $operation=(string)($item['operation']??'');$id=(int)($item['contractId']??0);$data=is_array($item['fields']??null)?$item['fields']:array();
    if($operation==='create')return mcpHrGenericResult(array('action'=>'create','objectClass'=>'EmploymentContract','data'=>$data),'EmploymentContract');
    if($operation!=='update'||$id<1)mcpJsonError(400,'invalid_contract_operation','Contract operation must be create or update with contractId');
    if(empty($item['expectedVersion']))mcpJsonError(409,'expected_version_required','expectedVersion is required when changing a contract');
    unset($data['idle'],$data['endDate'],$data['idEmploymentContractEndReason']);
    return mcpHrGenericResult(array('action'=>'update','objectClass'=>'EmploymentContract','id'=>$id,'expectedVersion'=>$item['expectedVersion']??null,'data'=>$data),'EmploymentContract');
  });
}

function mcpHrContractClosePreview(array $arguments,string $username,string $action): array {
  $items=array();foreach($arguments['contracts']??array() as $entry){
    $contract=mcpHrRequireExisting('EmploymentContract',(int)($entry['contractId']??0),'update');
    mcpHrRequireExpected($contract,$entry['expectedVersion']??null);
    $items[]=array('id'=>(int)$contract->id,'exists'=>true,'employeeId'=>(int)$contract->idEmployee,'version'=>mcpObjectVersion($contract),'willCloseLeaveRights'=>true);
  }
  return array('count'=>count($items),'items'=>$items);
}

function mcpHrContractClose(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['contracts']??array(),'atomic',function(array $item): array {
    $id=(int)($item['contractId']??0);$contract=mcpHrRequireExisting('EmploymentContract',$id,'update');mcpHrRequireExpected($contract,$item['expectedVersion']??null);
    $contract->endDate=$item['endDate'];$contract->idEmploymentContractEndReason=(int)$item['endReasonId'];$contract->idle=1;
    [$status]=mcpHrSave($contract,'contract_close_failed');$saved=new EmploymentContract($id);
    return array('status'=>$status==='unchanged'?'unchanged':'closed','objectClass'=>'EmploymentContract','id'=>$id,'saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>'close','objectClass'=>'EmploymentContract','id'=>$id)));
  });
}

function mcpHrAbsenceRecord(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['entries']??array(),(string)($arguments['transactionMode']??'atomic'),function(array $entry): array {
    $resourceId=(int)$entry['resourceId'];if(!mcpHrMayManageEmployee($resourceId))mcpJsonError(403,'forbidden','Absence entry is outside the actor employee scope');
    $assignment=mcpHrRequireExisting('Assignment',(int)$entry['assignmentId'],'read');
    if((int)$assignment->idResource!==$resourceId||(int)$assignment->idProject!==(int)$entry['projectId']||$assignment->refType!=='Activity'||(int)$assignment->refId!==(int)$entry['activityId'])mcpJsonError(409,'assignment_mismatch','Assignment does not match the absence entry');
    $id=(int)($entry['workId']??0);if($id){$work=mcpHrRequireExisting('Work',$id,'update');mcpHrRequireExpected($work,$entry['expectedVersion']??null);}else{$work=new Work();if(!Security::checkValidAccessForUser($work,'create',null,null,false))mcpJsonError(403,'forbidden','Work creation is denied');}
    if($id&&($work->refType!=='Activity'||!mcpHrMayManageEmployee((int)$work->idResource)||!Project::isTheLeaveProject((int)$work->idProject)))mcpJsonError(409,'not_absence_work','Existing work is not a leave-project absence in the actor employee scope');
    $work->refType='Activity';$work->refId=(int)$entry['activityId'];$work->idProject=(int)$entry['projectId'];$work->idResource=$resourceId;$work->idAssignment=(int)$entry['assignmentId'];$work->work=(float)$entry['work'];$work->setDates((string)$entry['workDate']);
    $raw=$work->saveWork();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'absence_save_failed',cleanApiMessage($raw));$saved=new Work($work->id);
    return array('status'=>$id?'updated':'created','objectClass'=>'Work','id'=>(int)$saved->id,'saved'=>mcpObjectArray($saved),'appliedFields'=>array('workDate','work','idResource','idAssignment'),'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>array(),'effects'=>array(array('action'=>$id?'update':'create','objectClass'=>'Work','id'=>(int)$saved->id)));
  });
}

function mcpHrLeaveSubmit(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['requests']??array(),(string)($arguments['transactionMode']??'atomic'),function(array $item): array {
    $operation=(string)$item['operation'];$id=(int)($item['leaveId']??0);$employeeId=(int)$item['employeeId'];
    if(!mcpHrMayManageEmployee($employeeId))mcpJsonError(403,'forbidden','Leave request is outside the actor employee scope');
    if($operation==='update'){$leave=mcpHrRequireExisting('Leave',$id,'update');mcpHrRequireExpected($leave,$item['expectedVersion']??null);}
    elseif($operation==='create'){$leave=new Leave();if(!Security::checkValidAccessForUser($leave,'create',null,null,false))mcpJsonError(403,'forbidden','Leave creation is denied');}
    else mcpJsonError(400,'invalid_leave_operation','Leave request operation must be create or update');
    $leave->idEmployee=$employeeId;$leave->idLeaveType=(int)$item['leaveTypeId'];$leave->idStatus=(int)$item['statusId'];$leave->startDate=$item['startDate'];$leave->startAMPM=$item['startAMPM'];$leave->endDate=$item['endDate'];$leave->endAMPM=$item['endAMPM'];$leave->comment=$item['comment']??'';
    $raw=$leave->save();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'leave_save_failed',cleanApiMessage($raw));$saved=new Leave($leave->id);
    return array('status'=>$operation==='create'?'created':'updated','objectClass'=>'Leave','id'=>(int)$saved->id,'saved'=>mcpObjectArray($saved),'appliedFields'=>array('idEmployee','idLeaveType','idStatus','startDate','startAMPM','endDate','endAMPM','comment'),'recalculatedFields'=>array('nbDays','submitted','accepted','rejected','idResource','processingDateTime'),'ignoredFields'=>array(),'rejectedFields'=>array(),'effects'=>array(array('action'=>$operation,'objectClass'=>'Leave','id'=>(int)$saved->id)));
  });
}

function mcpHrLeaveDecisionPreview(array $arguments,string $username,string $action): array {
  $items=array();foreach($arguments['decisions']??array() as $entry){
    $leave=mcpHrRequireExisting('Leave',(int)($entry['leaveId']??0),'update');mcpHrRequireExpected($leave,$entry['expectedVersion']??null);
    if(!(function_exists('isLeavesAdmin')&&isLeavesAdmin((int)getSessionUser()->id))&&!(function_exists('isManagerOfEmployee')&&isManagerOfEmployee((int)getSessionUser()->id,(int)$leave->idEmployee)))mcpJsonError(403,'forbidden','Only the employee manager or leave administrator may preview a leave decision');
    $items[]=array('id'=>(int)$leave->id,'exists'=>true,'employeeId'=>(int)$leave->idEmployee,'currentStatusId'=>(int)$leave->idStatus,'targetStatusId'=>(int)($entry['statusId']??0),'version'=>mcpObjectVersion($leave));
  }
  return array('count'=>count($items),'items'=>$items);
}

function mcpHrLeaveDecide(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['decisions']??array(),'atomic',function(array $item): array {
    $id=(int)$item['leaveId'];$leave=mcpHrRequireExisting('Leave',$id,'update');mcpHrRequireExpected($leave,$item['expectedVersion']??null);
    if(!(function_exists('isLeavesAdmin')&&isLeavesAdmin((int)getSessionUser()->id))&&!(function_exists('isManagerOfEmployee')&&isManagerOfEmployee((int)getSessionUser()->id,(int)$leave->idEmployee)))mcpJsonError(403,'forbidden','Only the employee manager or leave administrator may decide a leave request');
    $leave->idStatus=(int)$item['statusId'];$leave->comment=$item['comment']??$leave->comment;
    $raw=$leave->save();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'leave_decision_failed',cleanApiMessage($raw));$saved=new Leave($id);
    return array('status'=>'decided','objectClass'=>'Leave','id'=>$id,'saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>'status_change','objectClass'=>'Leave','id'=>$id,'idStatus'=>(int)$saved->idStatus)));
  });
}

function mcpHrLeaveDeletePreview(array $arguments,string $username,string $action): array {
  $items=array();foreach($arguments['leaves']??array() as $entry){
    $leave=mcpHrRequireExisting('Leave',(int)($entry['leaveId']??0),'delete');mcpHrRequireExpected($leave,$entry['expectedVersion']??null);
    if(!mcpHrMayManageEmployee((int)$leave->idEmployee))mcpJsonError(403,'forbidden','Leave deletion preview is outside the actor employee scope');
    $items[]=array('id'=>(int)$leave->id,'exists'=>true,'employeeId'=>(int)$leave->idEmployee,'nbDays'=>(float)$leave->nbDays,'statusId'=>(int)$leave->idStatus,'version'=>mcpObjectVersion($leave));
  }
  return array('count'=>count($items),'items'=>$items);
}

function mcpHrLeaveDelete(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['leaves']??array(),'atomic',function(array $item): array {
    $id=(int)$item['leaveId'];$leave=mcpHrRequireExisting('Leave',$id,'delete');mcpHrRequireExpected($leave,$item['expectedVersion']??null);
    if(!mcpHrMayManageEmployee((int)$leave->idEmployee))mcpJsonError(403,'forbidden','Leave deletion is outside the actor employee scope');
    SqlElement::setDeleteConfirmed();$raw=$leave->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'leave_delete_failed',cleanApiMessage($raw));
    return array('status'=>'deleted','objectClass'=>'Leave','id'=>$id,'effects'=>array(array('action'=>'delete','objectClass'=>'Leave','id'=>$id)));
  });
}

function mcpHrEntitlementPreview(array $arguments,string $username,string $action): array {
  mcpHrRequireLeavesAdmin();$items=array();foreach($arguments['entitlements']??array() as $entry){
    $id=(int)($entry['entitlementId']??0);$operation=(string)($entry['operation']??'');
    if($operation==='create'){
      $object=new EmployeeLeaveEarned();if(!Security::checkValidAccessForUser($object,'create',null,null,false))mcpJsonError(403,'forbidden','Entitlement creation is denied');
    }else{
      $object=mcpHrRequireExisting('EmployeeLeaveEarned',$id,'update');mcpHrRequireExpected($object,$entry['expectedVersion']??null);
    }
    $items[]=array('operation'=>$operation,'id'=>$id?:null,'exists'=>(bool)$object->id,'employeeId'=>(int)($entry['employeeId']??($object->idEmployee??0)),'leaveTypeId'=>(int)($entry['leaveTypeId']??($object->idLeaveType??0)),'quantity'=>$entry['quantity']??null,'leftQuantity'=>$entry['leftQuantity']??null,'version'=>$object->id?mcpObjectVersion($object):null);
  }
  return array('count'=>count($items),'items'=>$items);
}

function mcpHrEntitlementAdjust(array $arguments,string $username,string $action): array {
  mcpHrRequireLeavesAdmin();
  return mcpHrRunBatch($arguments['entitlements']??array(),'atomic',function(array $item): array {
    $operation=(string)$item['operation'];$id=(int)($item['entitlementId']??0);
    if($operation==='create'&&(empty($item['employeeId'])||empty($item['leaveTypeId'])))mcpJsonError(400,'entitlement_reference_required','employeeId and leaveTypeId are required when creating an entitlement');
    if($operation==='create'){$object=new EmployeeLeaveEarned();if(!Security::checkValidAccessForUser($object,'create',null,null,false))mcpJsonError(403,'forbidden','Entitlement creation is denied');}
    elseif(in_array($operation,array('adjust','close'),true)){$object=mcpHrRequireExisting('EmployeeLeaveEarned',$id,'update');mcpHrRequireExpected($object,$item['expectedVersion']??null);}
    else mcpJsonError(400,'invalid_entitlement_operation','Entitlement operation must be create, adjust, or close');
    if($operation==='create'){$object->idEmployee=(int)$item['employeeId'];$object->idLeaveType=(int)$item['leaveTypeId'];$object->idUser=(int)getSessionUser()->id;}
    foreach(array('startDate','endDate','quantity','leftQuantity') as $field)if(array_key_exists($field,$item))$object->$field=$item[$field];
    if($operation==='close')$object->idle=1;elseif(array_key_exists('idle',$item))$object->idle=$item['idle']?1:0;
    $raw=$object->save(true);if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'entitlement_save_failed',cleanApiMessage($raw));$saved=new EmployeeLeaveEarned($object->id);
    return array('status'=>$operation==='create'?'created':($operation==='close'?'closed':'updated'),'objectClass'=>'EmployeeLeaveEarned','id'=>(int)$saved->id,'saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>$operation,'objectClass'=>'EmployeeLeaveEarned','id'=>(int)$saved->id)));
  });
}

function mcpHrSkillAssign(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['assignments']??array(),(string)($arguments['transactionMode']??'atomic'),function(array $item): array {
    $id=(int)($item['id']??0);if($id){$skill=mcpHrRequireExisting('ResourceSkill',$id,'update');mcpHrRequireExpected($skill,$item['expectedVersion']??null);}
    else{$skill=new ResourceSkill();if(!Security::checkValidAccessForUser($skill,'create',null,null,false))mcpJsonError(403,'forbidden','Resource skill creation is denied');}
    $skill->idResource=(int)$item['resourceId'];$skill->idSkill=(int)$item['skillId'];$skill->idSkillLevel=(int)$item['skillLevelId'];$skill->useSince=$item['useSince']??null;$skill->useUntil=$item['useUntil']??null;$skill->comment=$item['comment']??'';$skill->idle=!empty($item['idle'])?1:0;
    $raw=$skill->save();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'resource_skill_save_failed',cleanApiMessage($raw));$saved=new ResourceSkill($skill->id);
    return array('status'=>$id?'updated':'created','objectClass'=>'ResourceSkill','id'=>(int)$saved->id,'saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>$id?'update':'create','objectClass'=>'ResourceSkill','id'=>(int)$saved->id)));
  });
}

function mcpHrSkillRemovePreview(array $arguments,string $username,string $action): array {
  $items=array();foreach($arguments['assignments']??array() as $entry){
    $object=mcpHrRequireExisting('ResourceSkill',(int)($entry['id']??0),'delete');mcpHrRequireExpected($object,$entry['expectedVersion']??null);
    $items[]=array('id'=>(int)$object->id,'exists'=>true,'resourceId'=>(int)$object->idResource,'skillId'=>(int)$object->idSkill,'version'=>mcpObjectVersion($object));
  }
  return array('count'=>count($items),'items'=>$items);
}

function mcpHrSkillRemove(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['assignments']??array(),'atomic',function(array $item): array {
    $id=(int)$item['id'];$object=mcpHrRequireExisting('ResourceSkill',$id,'delete');mcpHrRequireExpected($object,$item['expectedVersion']??null);SqlElement::setDeleteConfirmed();$raw=$object->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'resource_skill_delete_failed',cleanApiMessage($raw));
    return array('status'=>'deleted','objectClass'=>'ResourceSkill','id'=>$id,'effects'=>array(array('action'=>'delete','objectClass'=>'ResourceSkill','id'=>$id)));
  });
}

function mcpHrSkillMove(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['moves']??array(),(string)($arguments['transactionMode']??'atomic'),function(array $item): array {
    $source=mcpHrRequireExisting('Skill',(int)$item['skillId'],'update');$target=mcpHrRequireExisting('Skill',(int)$item['targetSkillId'],'read');mcpHrRequireExpected($source,$item['expectedVersion']??null);
    if((int)$source->id===(int)$target->id)mcpJsonError(400,'invalid_skill_move','A skill cannot be moved relative to itself');
    $raw=$source->moveTo((int)$target->id,(string)$item['position']);$status=getLastOperationStatus($raw);if(!in_array($status,array('OK','NO_CHANGE'),true)&&!str_contains((string)$raw,'OK'))mcpJsonError(400,'skill_move_failed',cleanApiMessage((string)$raw));$saved=new Skill($source->id);
    return array('status'=>$status==='NO_CHANGE'?'unchanged':'moved','objectClass'=>'Skill','id'=>(int)$saved->id,'saved'=>mcpObjectArray($saved),'effects'=>array(array('action'=>'move','objectClass'=>'Skill','id'=>(int)$saved->id,'targetId'=>(int)$target->id,'position'=>$item['position'])));
  });
}

function mcpHrLeaveCalendarExport(array $arguments,string $username,string $action): array {
  $year=(int)$arguments['year'];$month=(int)$arguments['month'];$start=sprintf('%04d-%02d-01',$year,$month);$end=(new DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
  $clauses=array('endDate>='.Sql::str($start),'startDate<='.Sql::str($end));
  foreach(array('idStatus'=>'statusId','idLeaveType'=>'leaveTypeId','idEmployee'=>'employeeId') as $field=>$argument)if(!empty($arguments[$argument]))$clauses[]=$field.'='.Sql::fmtId((int)$arguments[$argument]);
  $leave=new Leave();$rows=$leave->getSqlElementsFromCriteria(null,false,implode(' and ',$clauses),'startDate asc,id asc',false,true,201);$truncated=count($rows)>200;if($truncated)array_pop($rows);$items=array();
  foreach($rows as $row){if(!mcpHrMayManageEmployee((int)$row->idEmployee))continue;if(!Security::checkValidAccessForUser($row,'read',null,null,false))continue;$items[]=mcpObjectArray($row);}
  return array('ok'=>true,'period'=>array('startDate'=>$start,'endDate'=>$end),'returned'=>count($items),'truncated'=>$truncated,'items'=>$items,'effects'=>array());
}

function mcpHrLeavePermissionsPreview(array $arguments,string $username,string $action): array {
  mcpHrRequireLeavesAdmin();if(securityGetAccessRightYesNo("menuLeavesSystemHabilitation","update")!=="YES")mcpJsonError(403,"forbidden","Leave-system habilitation update access is required");
  $items=array();foreach($arguments["rules"]??array() as $entry){
    $rule=SqlElement::getSingleSqlElementFromCriteria("LeavesSystemHabilitation",array("menuName"=>(string)($entry["menuName"]??"")));if(!$rule->id)mcpJsonError(404,"leave_permission_not_found","Leave-system habilitation record was not found");mcpHrRequireExpected($rule,$entry["expectedVersion"]??null);
    $items[]=array("menuName"=>$entry["menuName"]??null,"exists"=>true,"id"=>(int)$rule->id,"version"=>mcpObjectVersion($rule),"requested"=>$entry);
  }
  return array("count"=>count($items),"items"=>$items);
}

function mcpHrLeavePermissionsConfigure(array $arguments,string $username,string $action): array {
  mcpHrRequireLeavesAdmin();if(securityGetAccessRightYesNo("menuLeavesSystemHabilitation","update")!=="YES")mcpJsonError(403,"forbidden","Leave-system habilitation update access is required");
  return mcpHrRunBatch($arguments["rules"]??array(),"atomic",function(array $item): array {
    $menuName=(string)$item["menuName"];$menuValid=false;foreach(getLeavesSystemMenu() as $menu)if($menu->name===$menuName&&$menu->type!=="menu"){$menuValid=true;break;}if(!$menuValid)mcpJsonError(400,"invalid_leave_menu","menuName is not an installed leave-system object menu");
    $rule=SqlElement::getSingleSqlElementFromCriteria("LeavesSystemHabilitation",array("menuName"=>$menuName));if(!$rule->id)mcpJsonError(404,"leave_permission_not_found","Leave-system habilitation record was not found");
    mcpHrRequireExpected($rule,$item["expectedVersion"]??null);
    foreach(array("viewAccess","readAccess","createAccess","updateAccess","deleteAccess","seeAllAccess") as $field)if(array_key_exists($field,$item))$rule->$field=implode("",array_values(array_unique($item[$field])));
    $raw=$rule->save();if(!in_array(getLastOperationStatus($raw),array("OK","NO_CHANGE"),true))mcpJsonError(400,"leave_permission_save_failed",cleanApiMessage($raw));unsetSessionValue("leavesSystemHabilitation");
    return array("status"=>getLastOperationStatus($raw)==="NO_CHANGE"?"unchanged":"updated","objectClass"=>"LeavesSystemHabilitation","id"=>(int)$rule->id,"effects"=>array(array("action"=>"update","objectClass"=>"LeavesSystemHabilitation","id"=>(int)$rule->id)));
  });
}
