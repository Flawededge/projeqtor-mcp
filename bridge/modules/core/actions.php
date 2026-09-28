<?php
declare(strict_types=1);

function mcpCoreActionAvailable(array $action): bool {
  foreach($action['permissionClasses']??array() as $class)if(!SqlElement::class_exists((string)$class))return false;
  return true;
}

function mcpCoreActor(string $username): array {
  $user=getSessionUser();
  if(!$user||!$user->id||!hash_equals((string)$user->name,$username))mcpJsonError(403,'actor_mismatch','The mapped ProjeQtOr user does not match the authenticated actor');
  return array('id'=>(int)$user->id,'username'=>(string)$user->name);
}

function mcpCoreRequireVersion(object $object,string $expected,string $label): void {
  if($expected==='')mcpJsonError(409,'expected_version_required',"$label requires expectedVersion");
  $actual=mcpObjectVersion($object);if(!hash_equals($actual,$expected))mcpJsonError(409,'version_conflict',"$label has changed",array('expectedVersion'=>$expected,'actualVersion'=>$actual));
}

function mcpCoreTarget(string $class,int $id,string $operation,string $expectedVersion): object {
  mcpRequireClassOperation($class,$operation);$object=new $class($id);
  if(!$object->id)mcpJsonError(404,'not_found',"$class #$id was not found");
  if(!Security::checkValidAccessForUser($object,$operation,null,null,false))mcpJsonError(403,'forbidden',"$operation access is denied for $class #$id");
  mcpCoreRequireVersion($object,$expectedVersion,"$class #$id");return $object;
}

function mcpCoreIdentity(string $class,object $object): array {return array('objectClass'=>$class,'id'=>(int)$object->id,'version'=>mcpObjectVersion($object));}
function mcpCoreEffect(string $action,string $class,int $id): array {return array('action'=>$action,'objectClass'=>$class,'id'=>$id);}
function mcpCoreSaved(object $object): array {return array('id'=>(int)$object->id,'_version'=>mcpObjectVersion($object));}


function mcpCoreItem(int $index,string $status,string $class,?int $id,array $fields=array(),?object $saved=null,?array $error=null): array {
  return array('index'=>$index,'status'=>$status,'objectClass'=>$class,'id'=>$id,'requestedFields'=>array_values($fields),'appliedFields'=>in_array($status,array('created','updated'),true)?array_values($fields):array(),
    'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>$status==='rolled_back'?array_values($fields):array(),'saved'=>$saved?mcpCoreSaved($saved):null,'error'=>$error,'concurrencyUnchecked'=>false);
}

function mcpCoreSave(object $object,string $class,string $verb): string {
  $raw=$object->save();$status=getLastOperationStatus($raw);if(!in_array($status,array('OK','NO_CHANGE'),true))mcpJsonError(400,'save_failed',cleanApiMessage($raw));
  return $status==='NO_CHANGE'?'unchanged':($verb==='create'?'created':'updated');
}

function mcpCoreCopy(array $arguments,string $username,string $action): array {
  $actor=mcpCoreActor($username);$class=(string)$arguments['objectClass'];mcpRequireClassOperation($class,'create');$source=new $class((int)$arguments['id']);
  if(!$source->id)mcpJsonError(404,'not_found',"$class #{$arguments['id']} was not found");mcpCoreRequireVersion($source,(string)$arguments['expectedVersion'],"$class #{$arguments['id']}");
  if(!Security::checkValidAccessForUser($source,'read',null,null,false)||!Security::checkValidAccessForUser(new $class(),'create',null,null,false))mcpJsonError(403,'forbidden','Copy access is denied');
  Sql::beginTransaction();try{$copy=$source->copy();if(!$copy||!$copy->id)mcpJsonError(400,'copy_failed','Object copy failed');Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  return array('ok'=>true,'actor'=>$actor,'source'=>mcpCoreIdentity($class,$source),'copy'=>mcpCoreIdentity($class,$copy),'effects'=>array(mcpCoreEffect('create',$class,(int)$copy->id)));
}

function mcpCoreTransition(array $arguments,string $username,string $action): array {
  $actor=mcpCoreActor($username);$class=(string)$arguments['objectClass'];$id=(int)$arguments['id'];
  Sql::beginTransaction();try{$result=mcpApplyOperation(array('action'=>'update','objectClass'=>$class,'id'=>$id,'expectedVersion'=>(string)$arguments['expectedVersion'],'data'=>array('idStatus'=>(int)$arguments['idStatus'])),false);if(($result['status']??'')!=='updated')mcpJsonError(400,'transition_failed',cleanApiMessage($result['error']['message']??'Workflow transition failed'));Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  $saved=new $class($id);return array('ok'=>true,'actor'=>$actor,'object'=>mcpCoreIdentity($class,$saved),'idStatus'=>(int)$saved->idStatus,'effects'=>array(mcpCoreEffect('update',$class,$id)));
}

function mcpCoreSessionAudit(array $arguments,array $actor): Audit {
  $audit=new Audit((int)$arguments['auditId']);if(!$audit->id)mcpJsonError(404,'audit_not_found','The requested session audit was not found');
  $self=(int)$audit->idUser===(int)$actor['id'];$admin=false;try{$admin=securityGetAccessRightYesNo('menuAudit','read',$audit)==='YES';}catch(Throwable $error){}
  if(!$self&&!$admin)mcpJsonError(403,'forbidden','Only the session owner or an Audit administrator may terminate this session');
  mcpCoreRequireVersion($audit,(string)$arguments['expectedVersion'],'Audit #'.$audit->id);return $audit;
}

function mcpCoreSessionTerminatePreview(array $arguments,string $username,string $action): array {
  $actor=mcpCoreActor($username);$audit=mcpCoreSessionAudit($arguments,$actor);
  return array('action'=>$action,'actor'=>$actor,'auditId'=>(int)$audit->id,'targetUserId'=>(int)$audit->idUser,'requestDisconnection'=>true,'stopActiveWork'=>true,'invalidateBrowserCookies'=>true);
}

function mcpCoreSessionTerminate(array $arguments,string $username,string $action): array {
  $actor=mcpCoreActor($username);$audit=mcpCoreSessionAudit($arguments,$actor);$target=new User((int)$audit->idUser);if(!$target->id)mcpJsonError(404,'user_not_found','The audited user no longer exists');
  Sql::beginTransaction();try{$audit->requestDisconnection=1;$raw=$audit->save();if(getLastOperationStatus($raw)==='ERROR')mcpJsonError(400,'session_termination_failed',cleanApiMessage($raw));$target->cleanCookieHash();$target->stopAllWork();Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  $saved=new Audit((int)$audit->id);return array('ok'=>true,'status'=>'termination_requested','actor'=>$actor,'auditId'=>(int)$saved->id,'targetUserId'=>(int)$saved->idUser,'version'=>mcpObjectVersion($saved),'effects'=>array(mcpCoreEffect('update','Audit',(int)$saved->id),mcpCoreEffect('update','User',(int)$target->id)));
}

function mcpCoreSessionLogin(array $arguments,string $username,string $action): array {
  $actor=mcpCoreActor($username);Sql::beginTransaction();try{$before=SqlElement::getSingleSqlElementFromCriteria('Audit',array('sessionId'=>session_id()));Audit::updateAudit(true);MessageLegalFollowup::updateMessageLegalFollowup();$audit=SqlElement::getSingleSqlElementFromCriteria('Audit',array('sessionId'=>session_id()));if(!$audit->id)mcpJsonError(500,'session_audit_failed','The authenticated session could not be audited');Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  return array('ok'=>true,'status'=>'active','actor'=>$actor,'auditId'=>(int)$audit->id,'targetUserId'=>$actor['id'],'version'=>mcpObjectVersion($audit),'effects'=>array(mcpCoreEffect($before->id?'update':'create','Audit',(int)$audit->id)));
}

function mcpCoreJoblistUpdate(array $arguments,string $username,string $action): array {
  $actor=mcpCoreActor($username);$class=(string)$arguments['objectClass'];$parent=mcpCoreTarget($class,(int)$arguments['objectId'],'update',(string)$arguments['expectedVersion']);
  $definition=new JoblistDefinition((int)$arguments['joblistDefinitionId']);if(!$definition->id)mcpJsonError(404,'joblist_definition_not_found','Joblist definition was not found');
  if(!Security::checkValidAccessForUser($definition,'read',null,null,false))mcpJsonError(403,'forbidden','Joblist definition read access is denied');
  $defined=array();foreach($definition->_JobDefinition??array() as $line)$defined[(int)$line->id]=true;if(!$defined||count($defined)>200)mcpJsonError(400,'invalid_joblist_definition','Joblist definition must contain 1 to 200 lines');
  $requests=array();foreach($arguments['jobs'] as $entry){$lineId=(int)$entry['jobDefinitionId'];if(isset($requests[$lineId]))mcpJsonError(400,'duplicate_job_definition','Each job definition may appear only once');$requests[$lineId]=$entry;}
  $expected=array_keys($defined);$actual=array_keys($requests);sort($expected);sort($actual);if($expected!==$actual)mcpJsonError(400,'incomplete_joblist','jobs must contain exactly one entry for every line in the definition');
  $stored=(new Job())->getSqlElementsFromCriteria(array('refType'=>$class,'refId'=>(int)$parent->id,'idJoblistDefinition'=>(int)$definition->id));$byDefinition=array();foreach($stored as $job)$byDefinition[(int)$job->idJobDefinition]=$job;
  foreach($requests as $lineId=>$entry)if(isset($byDefinition[$lineId]))mcpCoreRequireVersion($byDefinition[$lineId],(string)($entry['expectedVersion']??''),'Job #'.$byDefinition[$lineId]->id);elseif(isset($entry['expectedVersion']))mcpJsonError(409,'version_conflict',"Job definition #$lineId has no existing Job for the supplied expectedVersion");
  $items=array();$effects=array();Sql::beginTransaction();try{foreach($requests as $lineId=>$entry){$job=$byDefinition[$lineId]??new Job();$new=!$job->id;$fields=array('value');$previous=(bool)($job->value??false);$job->refType=$class;$job->refId=(int)$parent->id;$job->idJoblistDefinition=(int)$definition->id;$job->idJobDefinition=$lineId;$job->value=$entry['checked']?1:0;if(!$previous&&$entry['checked'])$job->checkTime=date('Y-m-d H:i:s');if(array_key_exists('idUser',$entry)){$job->idUser=$entry['idUser'];$fields[]='idUser';}elseif(!$job->idUser){$job->idUser=$actor['id'];$fields[]='idUser';}if(array_key_exists('creationDate',$entry)){$job->creationDate=$entry['creationDate'];$fields[]='creationDate';}if(array_key_exists('comment',$entry)){$job->comment=$entry['comment'];$fields[]='comment';}$status=mcpCoreSave($job,'Job',$new?'create':'update');$saved=new Job((int)$job->id);$index=count($items);$items[]=mcpCoreItem($index,$status,'Job',(int)$saved->id,$fields,$saved);if($status!=='unchanged')$effects[]=mcpCoreEffect($new?'create':'update','Job',(int)$saved->id);}Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  return array('ok'=>true,'rolledBack'=>false,'transactionMode'=>'atomic','actor'=>$actor,'items'=>$items,'effects'=>$effects);
}

function mcpCoreLegalFollowup(int $id,string $expectedVersion,array $actor): array {
  $followup=new MessageLegalFollowup($id);if(!$followup->id)mcpJsonError(404,'legal_followup_not_found','Legal notice follow-up was not found');if((int)$followup->idUser!==(int)$actor['id'])mcpJsonError(403,'forbidden','Legal notice follow-up does not belong to the actor');mcpCoreRequireVersion($followup,$expectedVersion,'MessageLegalFollowup #'.$id);
  $notice=new MessageLegal((int)$followup->idMessageLegal);if(!$notice->id||$notice->idle)mcpJsonError(404,'legal_notice_unavailable','Legal notice is unavailable');$now=date('Y-m-d H:i:s');if(($notice->startDate&&$now<(string)$notice->startDate)||($notice->endDate&&$now>(string)$notice->endDate))mcpJsonError(409,'legal_notice_inactive','Legal notice is outside its active period');
  $user=getSessionUser();if($notice->idAffectable&&(int)$notice->idAffectable!==(int)$user->id)mcpJsonError(403,'forbidden','Legal notice is not addressed to the actor');if($notice->idProfile&&(int)$notice->idProfile!==(int)$user->idProfile)mcpJsonError(403,'forbidden','Legal notice is not addressed to the actor profile');if($notice->idTeam&&(int)$notice->idTeam!==(int)$user->idTeam)mcpJsonError(403,'forbidden','Legal notice is not addressed to the actor team');if($notice->idOrganization&&(int)$notice->idOrganization!==(int)$user->idOrganization)mcpJsonError(403,'forbidden','Legal notice is not addressed to the actor organization');
  if($notice->idProject){$projects=(array)$user->getAffectedProjects();$projectIds=array_unique(array_merge(array_map('intval',array_keys($projects)),array_map('intval',array_values($projects))));if(!in_array((int)$notice->idProject,$projectIds,true))mcpJsonError(403,'forbidden','Legal notice project is outside the actor scope');}
  return array($followup,$notice);
}

function mcpCoreLegalResult(string $status,array $actor,MessageLegalFollowup $followup,MessageLegal $notice,bool $preferencesUpdated): array {
  return array('ok'=>true,'status'=>$status,'actor'=>$actor,'followupId'=>(int)$followup->id,'messageLegalId'=>(int)$notice->id,'acceptedAt'=>$followup->acceptedDate?:null,'firstViewedAt'=>$followup->firstViewDate?:null,'lastViewedAt'=>$followup->lastViewDate?:null,'preferencesUpdated'=>$preferencesUpdated,'version'=>mcpObjectVersion($followup),'effects'=>array(mcpCoreEffect('update','MessageLegalFollowup',(int)$followup->id)));
}

function mcpCoreLegalNoticeAcceptPreview(array $arguments,string $username,string $action): array {$actor=mcpCoreActor($username);list($followup,$notice)=mcpCoreLegalFollowup((int)$arguments['followupId'],(string)$arguments['expectedVersion'],$actor);return array('action'=>$action,'actor'=>$actor,'followupId'=>(int)$followup->id,'messageLegalId'=>(int)$notice->id,'noticeName'=>(string)$notice->name,'legalAcceptance'=>true,'preferencesUpdated'=>$notice->name==='newGui');}

function mcpCoreLegalNoticeAccept(array $arguments,string $username,string $action): array {
  $actor=mcpCoreActor($username);list($followup,$notice)=mcpCoreLegalFollowup((int)$arguments['followupId'],(string)$arguments['expectedVersion'],$actor);$preferences=false;
  Sql::beginTransaction();try{$followup->accepted=1;$followup->acceptedDate=date('Y-m-d H:i:s');$raw=$followup->save();if(getLastOperationStatus($raw)==='ERROR')mcpJsonError(400,'legal_acceptance_failed',cleanApiMessage($raw));if($notice->name==='newGui'){if(!array_key_exists('newGuiEnabled',$arguments))mcpJsonError(400,'new_gui_choice_required','The newGui notice requires newGuiEnabled');$value=$arguments['newGuiEnabled']?'1':'0';if($actor['id']===1){Parameter::storeGlobalParameter('newGui',$value);Parameter::storeGlobalParameter('newGuiThemeColor','545381');Parameter::storeGlobalParameter('newGuiThemeColorBis','e97b2c');}Parameter::storeUserParameter('newGui',$value);Parameter::storeUserParameter('newGuiThemeColor','545381');Parameter::storeUserParameter('newGuiThemeColorBis','e97b2c');Parameter::storeUserParameter('paramScreen','left');Parameter::storeUserParameter('paramRightDiv','bottom');Parameter::storeUserParameter('paramLayoutObjectDetail','tab');Parameter::storeUserParameter('menuBarTopMode','ICONTXT');Parameter::clearGlobalParameters();$preferences=true;}Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  $saved=new MessageLegalFollowup((int)$followup->id);return mcpCoreLegalResult('accepted',$actor,$saved,$notice,$preferences);
}

function mcpCoreLegalNoticeView(array $arguments,string $username,string $action): array {
  $actor=mcpCoreActor($username);$validated=array();$seen=array();foreach($arguments['followups'] as $entry){$id=(int)$entry['followupId'];if(isset($seen[$id]))mcpJsonError(400,'duplicate_legal_followup','Each follow-up may appear only once');$seen[$id]=true;$validated[]=mcpCoreLegalFollowup($id,(string)$entry['expectedVersion'],$actor);}
  $items=array();$effects=array();Sql::beginTransaction();try{foreach($validated as [$followup,$notice]){$now=date('Y-m-d H:i:s');if(!$followup->firstViewDate)$followup->firstViewDate=$now;$followup->lastViewDate=$now;$raw=$followup->save();if(getLastOperationStatus($raw)==='ERROR')mcpJsonError(400,'legal_view_failed',cleanApiMessage($raw));$saved=new MessageLegalFollowup((int)$followup->id);$items[]=mcpCoreLegalResult('viewed',$actor,$saved,$notice,false);$effects[]=mcpCoreEffect('update','MessageLegalFollowup',(int)$saved->id);}Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  return array('ok'=>true,'actor'=>$actor,'items'=>$items,'effects'=>$effects);
}

function mcpCoreRejectCredentialValue(mixed $value): void {if(!is_array($value))return;foreach($value as $key=>$entry){if(is_string($key)&&mcpSensitiveField($key))mcpJsonError(400,'credential_field_forbidden','Credential-valued fields are excluded from bulk update');mcpCoreRejectCredentialValue($entry);}}
function mcpCoreBulkOperations(array $arguments): array {$class=(string)$arguments['objectClass'];$field=(string)$arguments['field'];if(mcpSensitiveField($field))mcpJsonError(400,'credential_field_forbidden','Credential-valued fields are excluded from bulk update');mcpCoreRejectCredentialValue($arguments['value']);$seen=array();$operations=array();foreach($arguments['targets'] as $target){$id=(int)$target['id'];if(isset($seen[$id]))mcpJsonError(400,'duplicate_bulk_target','Each bulk-update target may appear only once');$seen[$id]=true;$operations[]=array('action'=>'update','objectClass'=>$class,'id'=>$id,'expectedVersion'=>(string)$target['expectedVersion'],'data'=>array($field=>$arguments['value']));}return $operations;}
function mcpCoreObjectBulkUpdatePreview(array $arguments,string $username,string $action): array {$actor=mcpCoreActor($username);$operations=mcpCoreBulkOperations($arguments);foreach($operations as $operation)mcpCheckOperation($operation,false,true);return array('action'=>$action,'actor'=>$actor,'objectClass'=>$arguments['objectClass'],'field'=>$arguments['field'],'targetCount'=>count($operations),'transactionMode'=>$arguments['transactionMode']??'atomic','credentialFieldsExcluded'=>true);}

function mcpCoreNormalizeBatch(array $raw,array $actor,string $mode): array {
  $rolledBack=(bool)($raw['rolledBack']??false);$items=array();$effects=array();foreach($raw['items']??array() as $index=>$entry){$status=(string)($entry['status']??'error');if(in_array($status,array('invalid','error'),true))$status='error';elseif($rolledBack&&in_array($status,array('created','updated','deleted'),true))$status='rolled_back';elseif(!in_array($status,array('created','updated','deleted'),true))$status='unchanged';$class=(string)($entry['objectClass']??'');$id=isset($entry['id'])?(int)$entry['id']:null;$fields=array_values($entry['requestedFields']??array());$saved=null;if(!$rolledBack&&$id&&in_array($status,array('created','updated'),true)){$object=new $class($id);if($object->id)$saved=$object;}$error=null;if($status==='error'){$error=array('code'=>(string)($entry['error']['code']??'core_operation_failed'),'message'=>cleanApiMessage($entry['error']['message']??'Core operation failed'));foreach(array('expectedVersion','actualVersion','field') as $detail)if(isset($entry['error'][$detail])&&is_scalar($entry['error'][$detail]))$error[$detail]=(string)$entry['error'][$detail];}$items[]=mcpCoreItem((int)($entry['index']??$index),$status,$class,$id,$fields,$saved,$error);if(!$rolledBack&&$id&&in_array($status,array('created','updated','deleted'),true))$effects[]=mcpCoreEffect($status==='deleted'?'delete':($status==='created'?'create':'update'),$class,$id);}
  return array('ok'=>(bool)($raw['ok']??false),'rolledBack'=>$rolledBack,'transactionMode'=>$mode,'actor'=>$actor,'items'=>$items,'effects'=>$effects);
}

function mcpCoreObjectBulkUpdate(array $arguments,string $username,string $action): array {$actor=mcpCoreActor($username);$mode=(string)($arguments['transactionMode']??'atomic');$raw=mcpExecuteOperationsArray(mcpCoreBulkOperations($arguments),$mode,true);return mcpCoreNormalizeBatch($raw,$actor,$mode);}

function mcpCoreSubTaskStatus(SubTask $task,string $status): void {$flags=array('paused'=>0,'handled'=>0,'done'=>0,'idle'=>0);if($status!=='pending')$flags[$status]=1;foreach($flags as $field=>$value)$task->$field=$value;}
function mcpCoreSubTaskTarget(int $id,string $parentClass,int $parentId,string $expectedVersion): SubTask {$task=new SubTask($id);if(!$task->id)mcpJsonError(404,'subtask_not_found',"SubTask #$id was not found");if((string)$task->refType!==$parentClass||(int)$task->refId!==$parentId)mcpJsonError(403,'subtask_parent_mismatch','SubTask does not belong to the authorized parent');mcpCoreRequireVersion($task,$expectedVersion,"SubTask #$id");return $task;}

function mcpCoreValidateSubTasks(array $arguments): array {
  $class=(string)$arguments['parentClass'];$parent=mcpCoreTarget($class,(int)$arguments['parentId'],'update',(string)$arguments['expectedParentVersion']);$operations=$arguments['operations']??array();$order=$arguments['order']??array();if(!$operations&&!$order)mcpJsonError(400,'empty_subtask_change','Supply at least one operation or order entry');if(count($operations)+count($order)>200)mcpJsonError(400,'invalid_batch','Subtask changes are limited to 200 entries');$validated=array();$seenOperations=array();foreach($operations as $index=>$entry){$verb=(string)$entry['operation'];$id=(int)($entry['id']??0);if($verb==='create'){if($id||isset($entry['expectedVersion']))mcpJsonError(400,'invalid_subtask_create','Subtask create cannot include id or expectedVersion');$data=$entry['data']??array();if(empty($data['name']))mcpJsonError(400,'subtask_name_required','Subtask create requires data.name');$validated[]=array('index'=>$index,'verb'=>$verb,'entry'=>$entry,'task'=>null);continue;}if($id<1||empty($entry['expectedVersion']))mcpJsonError(409,'expected_version_required',"SubTask $verb requires id and expectedVersion");if(isset($seenOperations[$id]))mcpJsonError(400,'duplicate_subtask_operation','Each existing SubTask may have only one lifecycle operation');$seenOperations[$id]=$verb;$task=mcpCoreSubTaskTarget($id,$class,(int)$parent->id,(string)$entry['expectedVersion']);if($verb==='update'&&!($entry['data']??array()))mcpJsonError(400,'subtask_data_required','Subtask update requires data');if($verb==='delete'&&isset($entry['data']))mcpJsonError(400,'subtask_delete_data','Subtask delete does not accept data');$validated[]=array('index'=>$index,'verb'=>$verb,'entry'=>$entry,'task'=>$task);}
  $validatedOrder=array();$seenOrder=array();foreach($order as $offset=>$entry){$id=(int)$entry['id'];if(isset($seenOrder[$id]))mcpJsonError(400,'duplicate_subtask_order','Each SubTask may appear only once in order');if(($seenOperations[$id]??null)==='delete')mcpJsonError(400,'deleted_subtask_order','A deleted SubTask cannot also be reordered');$seenOrder[$id]=true;$validatedOrder[]=array('index'=>count($operations)+$offset,'entry'=>$entry,'task'=>mcpCoreSubTaskTarget($id,$class,(int)$parent->id,(string)$entry['expectedVersion']));}return array($parent,$validated,$validatedOrder);
}

function mcpCoreSubTaskPreview(array $arguments,string $username,string $action): array {$actor=mcpCoreActor($username);list($parent,$operations,$order)=mcpCoreValidateSubTasks($arguments);return array('action'=>$action,'actor'=>$actor,'parent'=>mcpCoreIdentity((string)$arguments['parentClass'],$parent),'operationCount'=>count($operations),'orderCount'=>count($order),'creates'=>count(array_filter($operations,fn($item)=>$item['verb']==='create')),'updates'=>count(array_filter($operations,fn($item)=>$item['verb']==='update')),'deletes'=>count(array_filter($operations,fn($item)=>$item['verb']==='delete')));}

function mcpCoreSubTaskManage(array $arguments,string $username,string $action): array {
  $actor=mcpCoreActor($username);list($parent,$operations,$order)=mcpCoreValidateSubTasks($arguments);$class=(string)$arguments['parentClass'];$items=array();$effects=array();Sql::beginTransaction();try{foreach($operations as $change){$entry=$change['entry'];$verb=$change['verb'];$data=$entry['data']??array();if($verb==='delete'){$task=$change['task'];$id=(int)$task->id;SqlElement::setDeleteConfirmed();$raw=$task->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'subtask_delete_failed',cleanApiMessage($raw));$items[]=mcpCoreItem($change['index'],'deleted','SubTask',$id,array(),null);$effects[]=mcpCoreEffect('delete','SubTask',$id);continue;}$task=$verb==='create'?new SubTask():$change['task'];$new=$verb==='create';$fields=array();if($new){$task->refType=$class;$task->refId=(int)$parent->id;$task->idProject=$parent->idProject??null;$task->idUser=$actor['id'];$task->creationDateTime=date('Y-m-d H:i:s');if(property_exists($task,'idTargetProductVersion')&&property_exists($parent,'idTargetProductVersion'))$task->idTargetProductVersion=$parent->idTargetProductVersion;}foreach(array('name'=>'name','priorityId'=>'idPriority','resourceId'=>'idResource','dueDate'=>'dueDate','sortOrder'=>'sortOrder') as $input=>$field)if(array_key_exists($input,$data)){$task->$field=$data[$input];$fields[]=$field;}if(isset($data['status'])){mcpCoreSubTaskStatus($task,(string)$data['status']);$fields=array_merge($fields,array('paused','handled','done','idle'));}$status=mcpCoreSave($task,'SubTask',$new?'create':'update');$saved=new SubTask((int)$task->id);$items[]=mcpCoreItem($change['index'],$status,'SubTask',(int)$saved->id,array_values(array_unique($fields)),$saved);if($status!=='unchanged')$effects[]=mcpCoreEffect($new?'create':'update','SubTask',(int)$saved->id);}foreach($order as $change){$task=$change['task'];$task->sortOrder=(int)$change['entry']['sortOrder'];$status=mcpCoreSave($task,'SubTask','update');$saved=new SubTask((int)$task->id);$items[]=mcpCoreItem($change['index'],$status,'SubTask',(int)$saved->id,array('sortOrder'),$saved);if($status!=='unchanged')$effects[]=mcpCoreEffect('update','SubTask',(int)$saved->id);}Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  usort($items,fn($left,$right)=>$left['index']<=>$right['index']);return array('ok'=>true,'rolledBack'=>false,'transactionMode'=>'atomic','actor'=>$actor,'items'=>$items,'effects'=>$effects);
}
