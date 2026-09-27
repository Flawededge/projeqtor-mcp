<?php
declare(strict_types=1);

function mcpTicketingActionAvailable(array $action): bool {
  if(!SqlElement::class_exists('Ticket'))return false;
  $policy=mcpClassPolicy('Ticket');
  if(!($policy['supported']??false))return false;
  try{return Security::checkValidAccessForUser(null,'read','Ticket',null,false);}catch(Throwable $error){return false;}
}

function mcpTicketingRequireTicket(int $id,string $operation='update'): Ticket {
  mcpRequireClassOperation('Ticket',$operation);$ticket=new Ticket($id);
  if(!$ticket->id)mcpJsonError(404,'ticket_not_found',"Ticket #$id was not found");
  if(!Security::checkValidAccessForUser($ticket,$operation,null,null,false))mcpJsonError(403,'forbidden',"$operation access is denied for Ticket #$id");
  return $ticket;
}

function mcpTicketingRequireExpected(object $object,?string $expectedVersion): void {
  if($expectedVersion===null||$expectedVersion==='')mcpJsonError(409,'expected_version_required',get_class($object).' #'.(int)$object->id.' requires expectedVersion');
  $actual=mcpObjectVersion($object);
  if(!hash_equals($actual,$expectedVersion))mcpJsonError(409,'version_conflict',get_class($object).' #'.(int)$object->id.' has changed',array('expectedVersion'=>$expectedVersion,'actualVersion'=>$actual));
}

function mcpTicketingReference(string $class,int $id): object {
  mcpRequireClassOperation($class,'read');$reference=new $class($id);
  if(!$reference->id||((property_exists($reference,'idle')&&$reference->idle)))mcpJsonError(400,'invalid_reference',"$class #$id is unavailable",array('objectClass'=>$class,'id'=>$id));
  if(!Security::checkValidAccessForUser($reference,'read',null,null,false))mcpJsonError(403,'forbidden',"Read access is denied for $class #$id");
  return $reference;
}

function mcpTicketingResolvedResource(array $item): array {
  $resourceId=(int)($item['resourceId']??0);$teamId=(int)($item['teamId']??0);
  if(($resourceId>0)==($teamId>0))mcpJsonError(400,'dispatch_target_required','Exactly one of resourceId or teamId is required');
  if($teamId){
    $team=mcpTicketingReference('Team',$teamId);$resourceId=(int)($team->idResource??0);
    if(!$resourceId)mcpJsonError(400,'team_has_no_manager',"Team #$teamId has no responsible resource");
  }
  $resource=mcpTicketingReference('Resource',$resourceId);
  return array($resource,$teamId?:null);
}

function mcpTicketingSavedResult(Ticket $ticket,string $status,array $applied,array $before=array(),array $extra=array()): array {
  $saved=new Ticket((int)$ticket->id);$recalculated=array();
  foreach(array('idAccountable','handled','handledDateTime','paused','pausedDateTime','done','doneDateTime','idle','idleDateTime','cancelled','initialDueDateTime','actualDueDateTime','idActivity') as $field){
    if(array_key_exists($field,$before)&&property_exists($saved,$field)&&$before[$field]!=$saved->$field)$recalculated[]=$field;
  }
  return array_merge(array('status'=>$status,'objectClass'=>'Ticket','id'=>(int)$saved->id,'saved'=>mcpTicketingTicketData($saved),'appliedFields'=>$applied,'recalculatedFields'=>$recalculated,'rejectedFields'=>array(),'ignoredFields'=>array(),'effects'=>array(array('action'=>'update','objectClass'=>'Ticket','id'=>(int)$saved->id))),$extra);
}

function mcpTicketingSave(Ticket $ticket,string $failureCode): string {
  $raw=$ticket->save();$status=getLastOperationStatus($raw);
  if(!in_array($status,array('OK','NO_CHANGE'),true))mcpJsonError(400,$failureCode,cleanApiMessage($raw));
  return $status;
}

function mcpTicketingBefore(Ticket $ticket): array {
  $result=array();foreach(array('idAccountable','handled','handledDateTime','paused','pausedDateTime','done','doneDateTime','idle','idleDateTime','cancelled','initialDueDateTime','actualDueDateTime','idActivity') as $field)if(property_exists($ticket,$field))$result[$field]=$ticket->$field;return $result;
}

function mcpTicketingError(Throwable $error,int $index,array $item): array {
  $details=$error instanceof McpBridgeException?array_merge(array('code'=>$error->errorCode,'message'=>cleanApiMessage($error->getMessage())),$error->details):array('code'=>'ticketing_operation_failed','message'=>cleanApiMessage($error->getMessage()));
  return array('index'=>$index,'status'=>'error','objectClass'=>'Ticket','id'=>isset($item['ticketId'])?(int)$item['ticketId']:null,'appliedFields'=>array(),'recalculatedFields'=>array(),'rejectedFields'=>array_values(array_filter(array_keys($item),fn($field)=>!in_array($field,array('ticketId','expectedVersion','operation'),true))),'ignoredFields'=>array(),'error'=>$details);
}

function mcpTicketingBatch(array $items,string $mode,callable $executor): array {
  if(count($items)<1||count($items)>200)mcpJsonError(400,'invalid_batch','Ticketing actions require 1 to 200 items');
  if(!in_array($mode,array('atomic','best_effort'),true))mcpJsonError(400,'invalid_transaction_mode','transactionMode must be atomic or best_effort');
  $results=array();$effects=array();$rolledBack=false;$previousCapture=$GLOBALS['mcpCaptureErrors']??false;
  if($mode==='atomic')Sql::beginTransaction();
  foreach($items as $index=>$item){
    if($mode==='best_effort')Sql::beginTransaction();$GLOBALS['mcpCaptureErrors']=true;
    try{
      if(!is_array($item))mcpJsonError(400,'invalid_item',"Ticketing item $index must be an object");
      $result=$executor($item,$index);$result['index']=$index;
      foreach($result['effects']??array() as $effect)$effects[]=$effect;unset($result['effects']);$results[]=$result;
      if($mode==='best_effort')Sql::commitTransaction();
    }catch(Throwable $error){
      if($mode==='best_effort')Sql::rollbackTransaction();$results[]=mcpTicketingError($error,$index,is_array($item)?$item:array());
      if($mode==='atomic'){
        Sql::rollbackTransaction();$rolledBack=true;$effects=array();
        foreach($results as &$entry)if(($entry['status']??'')!=='error'){$entry['status']='rolled_back';$entry['rejectedFields']=array_values(array_unique(array_merge($entry['rejectedFields']??array(),$entry['appliedFields']??array())));$entry['appliedFields']=array();$entry['recalculatedFields']=array();}
        unset($entry);break;
      }
    }finally{$GLOBALS['mcpCaptureErrors']=$previousCapture;}
  }
  if($mode==='atomic'&&!$rolledBack)Sql::commitTransaction();
  $ok=!$rolledBack&&!count(array_filter($results,fn($item)=>($item['status']??'')==='error'));
  return array('ok'=>$ok,'rolledBack'=>$rolledBack,'transactionMode'=>$mode,'items'=>$results,'effects'=>$effects);
}

function mcpTicketingDispatchOne(array $item): array {
  $ticket=mcpTicketingRequireTicket((int)($item['ticketId']??0));mcpTicketingRequireExpected($ticket,$item['expectedVersion']??null);[$resource,$teamId]=mcpTicketingResolvedResource($item);$before=mcpTicketingBefore($ticket);
  $ticket->idResource=(int)$resource->id;$saveStatus=mcpTicketingSave($ticket,'ticket_dispatch_failed');
  return mcpTicketingSavedResult($ticket,$saveStatus==='NO_CHANGE'?'unchanged':'dispatched',array($teamId?'teamId':'resourceId'),$before,$teamId?array('resolvedResourceId'=>(int)$resource->id):array());
}

function mcpTicketingTransitionOne(array $item): array {
  $ticket=mcpTicketingRequireTicket((int)($item['ticketId']??0));mcpTicketingRequireExpected($ticket,$item['expectedVersion']??null);$status=mcpTicketingReference('Status',(int)($item['statusId']??0));$before=mcpTicketingBefore($ticket);
  $ticket->idStatus=(int)$status->id;$saveStatus=mcpTicketingSave($ticket,'ticket_transition_failed');
  return mcpTicketingSavedResult($ticket,$saveStatus==='NO_CHANGE'?'unchanged':'transitioned',array('statusId'),$before);
}

function mcpTicketingEscalateOne(array $item): array {
  $ticket=mcpTicketingRequireTicket((int)($item['ticketId']??0));mcpTicketingRequireExpected($ticket,$item['expectedVersion']??null);$reason=trim((string)($item['reason']??''));
  if($reason==='')mcpJsonError(400,'escalation_reason_required','reason is required for ticket escalation');
  $before=mcpTicketingBefore($ticket);$applied=array();
  foreach(array('priorityId'=>array('Priority','idPriority'),'urgencyId'=>array('Urgency','idUrgency'),'criticalityId'=>array('Criticality','idCriticality'),'statusId'=>array('Status','idStatus')) as $field=>$mapping)if(isset($item[$field])){[$class,$property]=$mapping;$reference=mcpTicketingReference($class,(int)$item[$field]);$ticket->$property=(int)$reference->id;$applied[]=$field;}
  if(isset($item['resourceId'])||isset($item['teamId'])){[$resource,$teamId]=mcpTicketingResolvedResource($item);$ticket->idResource=(int)$resource->id;$applied[]=$teamId?'teamId':'resourceId';}
  if(!$applied)mcpJsonError(400,'escalation_target_required','Escalation requires a priority, urgency, criticality, status, resource, or team change');
  mcpTicketingSave($ticket,'ticket_escalation_failed');
  mcpRequireClassOperation('Note','create');$note=new Note();$note->refType='Ticket';$note->refId=(int)$ticket->id;$note->note=$reason;$note->idPrivacy=1;$note->idUser=(int)getSessionUser()->id;$note->creationDate=date('Y-m-d H:i:s');
  if(!Security::checkValidAccessForUser($note,'create',null,null,false))mcpJsonError(403,'forbidden','Creating an escalation note is denied');
  $raw=$note->save();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'escalation_note_failed',cleanApiMessage($raw));
  $result=mcpTicketingSavedResult($ticket,'escalated',array_merge($applied,array('reason')),$before,array('noteId'=>(int)$note->id));$result['effects'][]=array('action'=>'create','objectClass'=>'Note','id'=>(int)$note->id);return $result;
}

function mcpTicketingSynchronizeOne(array $item): array {
  $ticket=mcpTicketingRequireTicket((int)($item['ticketId']??0));mcpTicketingRequireExpected($ticket,$item['expectedVersion']??null);$definition=Synchronization::getProjectSynchronizationDefinition((int)$ticket->idProject);
  if(!$definition||!$definition->id)mcpJsonError(409,'synchronization_not_configured','The ticket project has no synchronization definition');
  if($definition->originType!=='Ticket'||$definition->targetType!=='Activity')mcpJsonError(409,'unsupported_synchronization','Only Ticket to Activity synchronization is supported');
  mcpRequireClassOperation('Activity','create');$candidate=new Activity();$candidate->idProject=(int)$ticket->idProject;$candidate->idActivityType=(int)$definition->idTargetType;
  if(!Security::checkValidAccessForUser($candidate,'create',null,null,false))mcpJsonError(403,'forbidden','Activity creation is denied for this ticket project');
  $existing=SynchronizedItems::getSynchronizedItemObj('Ticket',(int)$ticket->id);$before=mcpTicketingBefore($ticket);$target=Synchronization::startSynchronization($ticket);
  if(!$target||!$target->id)mcpJsonError(400,'ticket_synchronization_failed',cleanApiMessage(Synchronization::getLastErrorMessage()));
  $result=mcpTicketingSavedResult(new Ticket((int)$ticket->id),$existing?'unchanged':'synchronized',array('synchronize'),$before,array('synchronizedActivityId'=>(int)$target->id));
  if(!$existing)$result['effects'][]=array('action'=>'create','objectClass'=>'Activity','id'=>(int)$target->id);return $result;
}

function mcpTicketingManage(array $arguments,string $username,string $action): array {
  return mcpTicketingBatch($arguments['operations']??array(),(string)($arguments['transactionMode']??'atomic'),function(array $item): array {
    return match((string)($item['operation']??'')){
      'dispatch'=>mcpTicketingDispatchOne($item),'transition'=>mcpTicketingTransitionOne($item),'escalate'=>mcpTicketingEscalateOne($item),'synchronize'=>mcpTicketingSynchronizeOne($item),
      default=>mcpJsonError(400,'unsupported_ticket_operation','operation must be dispatch, transition, escalate, or synchronize')
    };
  });
}

function mcpTicketingDispatch(array $arguments,string $username,string $action): array {return mcpTicketingBatch($arguments['items']??array(),(string)($arguments['transactionMode']??'atomic'),'mcpTicketingDispatchOne');}
function mcpTicketingTransition(array $arguments,string $username,string $action): array {return mcpTicketingBatch($arguments['items']??array(),(string)($arguments['transactionMode']??'atomic'),'mcpTicketingTransitionOne');}
function mcpTicketingEscalate(array $arguments,string $username,string $action): array {return mcpTicketingBatch($arguments['items']??array(),(string)($arguments['transactionMode']??'atomic'),'mcpTicketingEscalateOne');}
function mcpTicketingSynchronize(array $arguments,string $username,string $action): array {return mcpTicketingBatch($arguments['items']??array(),(string)($arguments['transactionMode']??'atomic'),'mcpTicketingSynchronizeOne');}

function mcpTicketingDelayRules(Ticket $ticket): array {
  mcpRequireClassOperation('TicketDelay','read');if(!Security::checkValidAccessForUser(null,'read','TicketDelay',null,false))mcpJsonError(403,'forbidden','Ticket SLA rule access is denied');
  $delay=new TicketDelay();$where='idTicketType='.Sql::fmtId((int)$ticket->idTicketType).' and idUrgency='.Sql::fmtId((int)$ticket->idUrgency).' and idle=0';$rules=$delay->getSqlElementsFromCriteria(null,false,$where,'id asc',false,true);$projectOrder=array((int)$ticket->idProject);
  $project=new Project((int)$ticket->idProject);if($project->id&&method_exists($project,'getTopProjectList'))foreach($project->getTopProjectList(true) as $id)if(!in_array((int)$id,$projectOrder,true))$projectOrder[]=(int)$id;
  $selected=array();foreach($rules as $rule){$projectId=(int)($rule->idProject??0);$rank=$projectId?array_search($projectId,$projectOrder,true):count($projectOrder);if($rank===false)continue;$macro=(int)$rule->idMacroTicketStatus;if(!isset($selected[$macro])||$rank<$selected[$macro]['rank'])$selected[$macro]=array('rank'=>$rank,'rule'=>$rule);}
  ksort($selected,SORT_NUMERIC);return array_values(array_map(fn($entry)=>$entry['rule'],$selected));
}

function mcpTicketingSlaEvaluate(array $arguments,string $username,string $action): array {
  $ids=$arguments['ticketIds']??array();if(count($ids)<1||count($ids)>200)mcpJsonError(400,'invalid_batch','ticketIds must contain 1 to 200 entries');$items=array();$now=time();
  foreach($ids as $id){$ticket=mcpTicketingRequireTicket((int)$id,'read');$due=(string)($ticket->actualDueDateTime?:$ticket->initialDueDateTime);$closed=(bool)($ticket->done||$ticket->idle||$ticket->cancelled);$dueTime=$due?strtotime($due):false;$rules=array();
    foreach(mcpTicketingDelayRules($ticket) as $rule)$rules[]=array('id'=>(int)$rule->id,'projectId'=>$rule->idProject?(int)$rule->idProject:null,'macroStatusId'=>(int)$rule->idMacroTicketStatus,'value'=>(float)$rule->value,'delayUnitId'=>(int)$rule->idDelayUnit,'_version'=>mcpObjectVersion($rule));
    $items[]=array('ticketId'=>(int)$ticket->id,'_version'=>mcpObjectVersion($ticket),'statusId'=>(int)$ticket->idStatus,'dueAt'=>$due?:null,'closed'=>$closed,'breached'=>!$closed&&$dueTime!==false&&$dueTime<$now,'overdueSeconds'=>(!$closed&&$dueTime!==false&&$dueTime<$now)?$now-$dueTime:0,'rules'=>$rules);
  }
  return array('ok'=>true,'evaluatedAt'=>date(DATE_ATOM,$now),'items'=>$items);
}

function mcpTicketingProject(int $id,string $operation): Project {
  mcpRequireClassOperation('Project',$operation);$project=new Project($id);if(!$project->id)mcpJsonError(404,'project_not_found',"Project #$id was not found");if(!Security::checkValidAccessForUser($project,$operation,null,null,false))mcpJsonError(403,'forbidden',"$operation access is denied for Project #$id");return $project;
}

function mcpTicketingSynchronizationLink(int $ticketId): ?SynchronizedItems {
  $link=new SynchronizedItems();$links=$link->getSqlElementsFromCriteria(array('ref2Type'=>'Ticket','ref2Id'=>$ticketId),false,null,'id asc',false,true,2);
  if(count($links)>1)mcpJsonError(409,'duplicate_synchronization_links',"Ticket #$ticketId has more than one synchronization link");
  return $links?reset($links):null;
}

function mcpTicketingSynchronizationTickets(array $arguments,bool $requireVersions): array {
  if(empty($arguments['includeExisting']))return array();$projectId=(int)$arguments['projectId'];$status=mcpTicketingReference('Status',(int)$arguments['statusId']);$ticket=new Ticket();$where='idProject='.Sql::fmtId($projectId).' and idle=0';
  if(!empty($arguments['ticketTypeId']))$where.=' and idTicketType='.Sql::fmtId((int)$arguments['ticketTypeId']);$list=$ticket->getSqlElementsFromCriteria(null,false,$where,'id asc',false,true,201);$selected=array();
  foreach($list as $candidate){$candidateStatus=new Status((int)$candidate->idStatus);if((int)$candidateStatus->sortOrder<(int)$status->sortOrder)continue;if(!Security::checkValidAccessForUser($candidate,'update',null,null,false))mcpJsonError(403,'forbidden','Update access is denied for an existing ticket selected for synchronization');$selected[]=$candidate;}
  if(count($selected)>200)mcpJsonError(409,'synchronization_batch_too_large','At most 200 existing tickets may be synchronized in one action');
  if($requireVersions){$versions=array();foreach($arguments['expectedTicketVersions']??array() as $entry)$versions[(int)($entry['ticketId']??0)]=(string)($entry['expectedVersion']??'');foreach($selected as $candidate)mcpTicketingRequireExpected($candidate,$versions[(int)$candidate->id]??null);}
  return $selected;
}

function mcpTicketingSynchronizationInspect(array $arguments,string $username,string $action): array {
  $project=mcpTicketingProject((int)$arguments['projectId'],'read');$definition=Synchronization::getProjectSynchronizationDefinition((int)$project->id);$items=array();$ticket=new Ticket();$tickets=$ticket->getSqlElementsFromCriteria(array('idProject'=>(int)$project->id),false,null,'id asc',false,true,201);
  if(count($tickets)>200)mcpJsonError(409,'synchronization_inspect_too_large','Synchronization inspection is limited to 200 project tickets');
  foreach($tickets as $candidate){if(!Security::checkValidAccessForUser($candidate,'read',null,null,false))continue;$link=mcpTicketingSynchronizationLink((int)$candidate->id);$target=$link?SynchronizedItems::getSynchronizedItemObj('Ticket',(int)$candidate->id):null;if($target&&!Security::checkValidAccessForUser($target,'read',null,null,false))$target=null;$items[]=array('ticketId'=>(int)$candidate->id,'ticketVersion'=>mcpObjectVersion($candidate),'linkId'=>$link?(int)$link->id:null,'linkVersion'=>$link?mcpObjectVersion($link):null,'targetType'=>$target?get_class($target):null,'targetId'=>$target?(int)$target->id:null);}
  return array('ok'=>true,'projectId'=>(int)$project->id,'definition'=>$definition&&$definition->id?array('id'=>(int)$definition->id,'_version'=>mcpObjectVersion($definition),'statusId'=>(int)$definition->idStatus,'ticketTypeId'=>$definition->idOrigineType?(int)$definition->idOrigineType:null,'activityTypeId'=>(int)$definition->idTargetType,'setActivity'=>(bool)$definition->setActivity):null,'items'=>$items);
}

function mcpTicketingSynchronizationConfigurePreview(array $arguments,string $username,string $action): array {
  $project=mcpTicketingProject((int)$arguments['projectId'],'update');mcpTicketingReference('Status',(int)$arguments['statusId']);if(!empty($arguments['ticketTypeId']))mcpTicketingReference('TicketType',(int)$arguments['ticketTypeId']);mcpTicketingReference('ActivityType',(int)$arguments['activityTypeId']);$definition=Synchronization::getProjectSynchronizationDefinition((int)$project->id);
  if($definition&&$definition->id)mcpTicketingRequireExpected($definition,$arguments['expectedVersion']??null);$tickets=mcpTicketingSynchronizationTickets($arguments,true);
  return array('projectId'=>(int)$project->id,'definitionId'=>$definition&&$definition->id?(int)$definition->id:null,'definitionVersion'=>$definition&&$definition->id?mcpObjectVersion($definition):null,'willSynchronize'=>array_map(fn($ticket)=>array('ticketId'=>(int)$ticket->id,'version'=>mcpObjectVersion($ticket)),$tickets));
}

function mcpTicketingSynchronizationConfigure(array $arguments,string $username,string $action): array {
  $project=mcpTicketingProject((int)$arguments['projectId'],'update');mcpTicketingReference('Status',(int)$arguments['statusId']);if(!empty($arguments['ticketTypeId']))mcpTicketingReference('TicketType',(int)$arguments['ticketTypeId']);mcpTicketingReference('ActivityType',(int)$arguments['activityTypeId']);$definition=Synchronization::getProjectSynchronizationDefinition((int)$project->id);$existing=$definition&&$definition->id;
  if($existing)mcpTicketingRequireExpected($definition,$arguments['expectedVersion']??null);else $definition=new Synchronization();$tickets=mcpTicketingSynchronizationTickets($arguments,true);Sql::beginTransaction();
  try{$definition->idProject=(int)$project->id;$definition->originType='Ticket';$definition->targetType='Activity';$definition->idStatus=(int)$arguments['statusId'];$definition->idOrigineType=!empty($arguments['ticketTypeId'])?(int)$arguments['ticketTypeId']:null;$definition->idTargetType=(int)$arguments['activityTypeId'];$definition->setActivity=!empty($arguments['setActivity'])?1:0;$raw=$definition->save();if(!in_array(getLastOperationStatus($raw),array('OK','NO_CHANGE'),true))mcpJsonError(400,'synchronization_definition_failed',cleanApiMessage($raw));$effects=array(array('action'=>$existing?'update':'create','objectClass'=>'Synchronization','id'=>(int)$definition->id));$synchronized=array();
    foreach($tickets as $ticket){$prior=SynchronizedItems::getSynchronizedItemObj('Ticket',(int)$ticket->id);$target=Synchronization::startSynchronization($ticket);if(!$target||!$target->id)mcpJsonError(400,'ticket_synchronization_failed',cleanApiMessage(Synchronization::getLastErrorMessage()),array('ticketId'=>(int)$ticket->id));$synchronized[]=array('ticketId'=>(int)$ticket->id,'activityId'=>(int)$target->id);if(!$prior)$effects[]=array('action'=>'create','objectClass'=>'Activity','id'=>(int)$target->id);}
    Sql::commitTransaction();$saved=new Synchronization((int)$definition->id);return array('ok'=>true,'status'=>$existing?'updated':'created','definition'=>mcpTicketingDefinitionData($saved),'synchronized'=>$synchronized,'effects'=>$effects);
  }catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
}

function mcpTicketingSynchronizationItems(int $projectId): array {
  $ticket=new Ticket();$table=$ticket->getDatabaseTableName();$items=new SynchronizedItems();return $items->getSqlElementsFromCriteria(null,false,"ref2Type='Ticket' and exists (select 1 from $table t where t.id=ref2id and t.idProject=".Sql::fmtId($projectId).')','id asc',false,true,201);
}

function mcpTicketingSynchronizationDisablePreview(array $arguments,string $username,string $action): array {
  $project=mcpTicketingProject((int)$arguments['projectId'],'update');$definition=Synchronization::getProjectSynchronizationDefinition((int)$project->id);if(!$definition||!$definition->id)mcpJsonError(404,'synchronization_not_configured','The project has no synchronization definition');mcpTicketingRequireExpected($definition,$arguments['expectedVersion']??null);$items=!empty($arguments['unlinkItems'])?mcpTicketingSynchronizationItems((int)$project->id):array();if(count($items)>200)mcpJsonError(409,'synchronization_batch_too_large','At most 200 synchronized links may be removed in one action');
  $versions=array();foreach($arguments['expectedItemVersions']??array() as $entry)$versions[(int)($entry['id']??0)]=(string)($entry['expectedVersion']??'');foreach($items as $item){mcpTicketingRequireTicket((int)$item->ref2Id,'update');mcpTicketingRequireExpected($item,$versions[(int)$item->id]??null);}
  return array('projectId'=>(int)$project->id,'definition'=>array('id'=>(int)$definition->id,'version'=>mcpObjectVersion($definition)),'unlinkItems'=>(bool)($arguments['unlinkItems']??false),'items'=>array_map(fn($item)=>array('id'=>(int)$item->id,'version'=>mcpObjectVersion($item),'ticketId'=>(int)$item->ref2Id),$items));
}

function mcpTicketingSynchronizationDisable(array $arguments,string $username,string $action): array {
  $preview=mcpTicketingSynchronizationDisablePreview($arguments,$username,$action);$definition=Synchronization::getProjectSynchronizationDefinition((int)$arguments['projectId']);$items=!empty($arguments['unlinkItems'])?mcpTicketingSynchronizationItems((int)$arguments['projectId']):array();Sql::beginTransaction();
  try{$definitionId=(int)$definition->id;SqlElement::setDeleteConfirmed();$raw=$definition->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'synchronization_disable_failed',cleanApiMessage($raw));$effects=array(array('action'=>'delete','objectClass'=>'Synchronization','id'=>$definitionId));$removed=array();foreach($items as $item){$id=(int)$item->id;SqlElement::setDeleteConfirmed();$raw=$item->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'synchronized_item_delete_failed',cleanApiMessage($raw));$removed[]=$id;$effects[]=array('action'=>'delete','objectClass'=>'SynchronizedItems','id'=>$id);}Sql::commitTransaction();return array('ok'=>true,'status'=>'disabled','projectId'=>(int)$arguments['projectId'],'definitionId'=>$definitionId,'removedItemIds'=>$removed,'effects'=>$effects);
  }catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
}
function mcpTicketingTicketData(Ticket $ticket): array {
  $data=array('id'=>(int)$ticket->id,'_version'=>mcpObjectVersion($ticket));
  foreach(array('idProject','idStatus','idResource','idAccountable','idActivity') as $field)$data[$field]=!empty($ticket->$field)?(int)$ticket->$field:null;
  foreach(array('handled','paused','done','idle','cancelled') as $field)$data[$field]=(bool)($ticket->$field??false);
  foreach(array('handledDateTime','pausedDateTime','doneDateTime','idleDateTime','initialDueDateTime','actualDueDateTime') as $field)$data[$field]=($ticket->$field??null)?:null;
  return $data;
}
function mcpTicketingDefinitionData(Synchronization $definition): array {
  return array('id'=>(int)$definition->id,'_version'=>mcpObjectVersion($definition),'statusId'=>(int)$definition->idStatus,'ticketTypeId'=>!empty($definition->idOrigineType)?(int)$definition->idOrigineType:null,'activityTypeId'=>(int)$definition->idTargetType,'setActivity'=>(bool)$definition->setActivity);
}
