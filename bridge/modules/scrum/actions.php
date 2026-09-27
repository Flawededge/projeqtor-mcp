<?php
declare(strict_types=1);

function mcpScrumActionAvailable(array $action): bool {
  foreach($action['permissionClasses']??array() as $class)if(!class_exists((string)$class))return false;
  return true;
}
function mcpScrumFields(array $entry,array $allowed): array { $data=array();foreach($allowed as $field)if(array_key_exists($field,$entry))$data[$field]=$entry[$field];return $data; }
function mcpScrumDirect(object $object,string $operation): bool { try{return (bool)Security::checkValidAccessForUser($object,$operation,null,null,false);}catch(Throwable $error){return false;} }
function mcpScrumRequireDirect(object $object,string $operation): void { if(!mcpScrumDirect($object,$operation))mcpJsonError(403,'forbidden',"$operation access is denied"); }
function mcpScrumRequireMenu(string $menu,string $operation): void { if(securityGetAccessRightYesNo($menu,$operation)!=='YES')mcpJsonError(403,'forbidden',"$operation access is denied"); }

function mcpScrumRequireVersion(object $object,array $entry,string $field='expectedVersion'): void {
  $expected=(string)($entry[$field]??'');if($expected==='')mcpJsonError(409,'expected_version_required','An object version is required');
  $actual=mcpObjectVersion($object);if(!hash_equals($actual,$expected))mcpJsonError(409,'version_conflict','The object has changed',array('objectClass'=>get_class($object),'id'=>(int)$object->id,'expectedVersion'=>$expected,'actualVersion'=>$actual));
}
function mcpScrumTarget(string $class,int $id,string $operation='update'): object {
  Security::checkValidClass($class);$object=new $class($id);if(!$object->id)mcpJsonError(404,'scrum_target_not_found','The requested Scrum object was not found');mcpScrumRequireDirect($object,$operation);return $object;
}
function mcpScrumResult(object $object,string $status,array $requested,array $applied=array(),array $recalculated=array()): array {
  return array('status'=>$status,'objectClass'=>get_class($object),'id'=>(int)($object->id??0),'requestedFields'=>$requested,'appliedFields'=>$applied,'recalculatedFields'=>$recalculated,'ignoredFields'=>array_values(array_diff($requested,$applied,$recalculated)),'rejectedFields'=>array(),'saved'=>mcpObjectArray($object),'concurrencyUnchecked'=>false);
}
function mcpScrumSave(object $object,string $operation,array $entry,array $data,bool $internal=false): array {
  $class=get_class($object);$create=$operation==='create';if(!$internal)mcpRequireClassOperation($class,$operation);if(!$create){mcpScrumRequireVersion($object,$entry);if(!$internal)mcpScrumRequireDirect($object,$operation);}
  $requested=array_keys($data);
  if($operation==='delete'){$id=(int)$object->id;SqlElement::setDeleteConfirmed();$raw=$object->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'delete_failed',cleanApiMessage($raw));return array('status'=>'deleted','objectClass'=>$class,'id'=>$id,'requestedFields'=>array(),'appliedFields'=>array(),'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>array(),'saved'=>array(),'concurrencyUnchecked'=>false);}
  foreach($data as $field=>$value){if(!property_exists($object,$field))mcpJsonError(400,'unsupported_field',"$class does not expose $field",array('invalidFields'=>array($field)));$object->$field=is_bool($value)?($value?1:0):$value;}
  if($create&&!$internal)mcpScrumRequireDirect($object,'create');
  $raw=$object->save();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'save_failed',cleanApiMessage($raw));$saved=new $class($object->id);return mcpScrumResult($saved,$create?'created':'updated',$requested,$requested);
}
function mcpScrumEffects(array $items): array {
  $effects=array();foreach($items as $item){$status=$item['status']??'';if(!in_array($status,array('created','updated','deleted'),true)||empty($item['id']))continue;$effects[]=array('action'=>$status==='deleted'?'delete':($status==='created'?'create':'update'),'objectClass'=>(string)$item['objectClass'],'id'=>(int)$item['id']);}return $effects;
}
function mcpScrumBatch(array $entries,string $mode,callable $executor): array {
  if(count($entries)<1||count($entries)>200)mcpJsonError(400,'invalid_batch','Scrum actions require 1 to 200 items');if(!in_array($mode,array('atomic','best_effort'),true))mcpJsonError(400,'invalid_transaction_mode','transactionMode must be atomic or best_effort');
  $items=array();if($mode==='atomic')Sql::beginTransaction();
  foreach($entries as $index=>$entry){
    if($mode==='best_effort')Sql::beginTransaction();$GLOBALS['mcpCaptureErrors']=true;
    try{$item=$executor($entry,$index);$item['index']=$index;$items[]=$item;if($mode==='best_effort')Sql::commitTransaction();}
    catch(Throwable $error){if($mode==='best_effort')Sql::rollbackTransaction();$details=$error instanceof McpBridgeException?array_merge(array('code'=>$error->errorCode,'message'=>$error->getMessage()),$error->details):array('code'=>'scrum_operation_failed','message'=>cleanApiMessage($error->getMessage()));$items[]=array('index'=>$index,'status'=>'error','objectClass'=>$entry['objectClass']??'ScrumInternal','id'=>$entry['id']??$entry['idPokerItem']??null,'error'=>$details);if($mode==='atomic'){Sql::rollbackTransaction();$GLOBALS['mcpCaptureErrors']=false;return array('ok'=>false,'rolledBack'=>true,'transactionMode'=>$mode,'items'=>$items,'effects'=>array());}}
    finally{$GLOBALS['mcpCaptureErrors']=false;}
  }
  if($mode==='atomic')Sql::commitTransaction();$ok=!count(array_filter($items,fn($item)=>($item['status']??'')==='error'));return array('ok'=>$ok,'rolledBack'=>false,'transactionMode'=>$mode,'items'=>$items,'effects'=>mcpScrumEffects($items));
}

function mcpScrumSprintManage(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['operations'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $operation=(string)$entry['operation'];
    if($operation==='rank_backlog'){
      if(empty($entry['itemId']))mcpJsonError(400,'validation_failed','rank_backlog requires itemId',array('missingFields'=>array('itemId')));
      $priorityId=(int)($entry['priorityId']??0);if(!$priorityId){$neighborId=(int)($entry['beforeItemId']??$entry['afterItemId']??0);if(!$neighborId)mcpJsonError(400,'validation_failed','rank_backlog requires priorityId or a neighboring story',array('missingFields'=>array('priorityId')));$neighbor=mcpScrumTarget('UserStory',$neighborId,'read');$priorityId=(int)$neighbor->idScrumPriority;}
      if(!$priorityId)mcpJsonError(400,'validation_failed','The neighboring story has no Scrum priority');$story=mcpScrumTarget('UserStory',(int)$entry['itemId'],'update');$priority=mcpScrumTarget('ScrumPriority',$priorityId,'read');return mcpScrumSave($story,'update',$entry,array('idScrumPriority'=>(int)$priority->id,'idSprint'=>$entry['sprintId']??$story->idSprint),false);
    }
    if(str_ends_with($operation,'_sprint')){
      if(empty($entry['sprintId']))mcpJsonError(400,'validation_failed',"$operation requires sprintId",array('missingFields'=>array('sprintId')));$state=str_replace('_sprint','',$operation);$sprint=mcpScrumTarget('Sprint',(int)$entry['sprintId'],'update');return mcpScrumSave($sprint,'update',$entry,mcpScrumLifecycleData($sprint,$state),false);
    }
    if(empty($entry['itemId'])||!array_key_exists('columnId',$entry))mcpJsonError(400,'validation_failed','move_kanban_card requires itemId and columnId',array('missingFields'=>array('itemId','columnId')));
    if(!empty($entry['boardId'])){$board=new Kanban((int)$entry['boardId']);if(!$board->id)mcpJsonError(404,'scrum_target_not_found','The Kanban board was not found');mcpScrumBoardAccess($board,'read');}
    $class=(string)($entry['objectClass']??'UserStory');$object=mcpScrumTarget($class,(int)$entry['itemId'],'update');$column=(string)($entry['columnType']??'Status');$target=$entry['columnId'];$data=array();
    if($column==='Status'){if($target===null)mcpJsonError(400,'validation_failed','A status column cannot be null');mcpScrumApplyStatus($object,(int)$target,$data);}else{if(!property_exists($object,'idSprint'))mcpJsonError(400,'unsupported_column','This object cannot be assigned to a sprint');if($target!==null)mcpScrumTarget('Sprint',(int)$target,'read');$data['idSprint']=$target===null?null:(int)$target;}
    return mcpScrumSave($object,'update',$entry,$data,false);
  });
}
function mcpScrumStoryManage(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['stories'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $operation=(string)$entry['operation'];$id=(int)($entry['id']??0);$object=$id?new UserStory($id):new UserStory();
    if($operation==='create'){
      if($id)mcpJsonError(400,'validation_failed','Create must not include id');foreach(array('name','idProject','idUserStoryType','idStatus') as $required)if(empty($entry[$required]))mcpJsonError(400,'validation_failed','Story creation is missing required fields',array('missingFields'=>array($required)));
      $entry['creationDate']=date('Y-m-d');$entry['idUser']=(int)getSessionUser()->id;
    }elseif(!$object->id)mcpJsonError(404,'scrum_target_not_found','The user story was not found');
    $data=mcpScrumFields($entry,array('name','idProject','idUserStoryType','idStatus','idResource','idEpic','idSprint','idScrumPriority','storyPoints','businessValue','featureAction','featureGoal','featureOpposite','acceptationCriteria','description','result','creationDate','idUser','idle'));
    return mcpScrumSave($object,$operation,$entry,$data,false);
  });
}
function mcpScrumBacklogPrioritize(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['items'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $story=mcpScrumTarget('UserStory',(int)$entry['id'],'update');$priority=mcpScrumTarget('ScrumPriority',(int)$entry['idScrumPriority'],'read');return mcpScrumSave($story,'update',$entry,array('idScrumPriority'=>(int)$priority->id,'idSprint'=>$entry['idSprint']??$story->idSprint),false);
  });
}
function mcpScrumLifecycleData(object $object,string $state,?int $requestedStatus=null): array {
  $today=date('Y-m-d');$data=array();
  if($state==='start'||$state==='resume'){$data=array('handled'=>1,'handledDate'=>$today,'done'=>0,'doneDate'=>null);if(property_exists($object,'paused'))$data['paused']=0;}
  elseif($state==='pause'){if(property_exists($object,'paused'))$data=array('paused'=>1);else $data=array('handled'=>0,'handledDate'=>null);}
  elseif($state==='close'||$state==='finish')$data=array('handled'=>1,'handledDate'=>$object->handledDate?:$today,'done'=>1,'doneDate'=>$today);
  elseif($state==='reopen')$data=array('done'=>0,'doneDate'=>null,'idle'=>0,'idleDate'=>null);
  if($requestedStatus)$data['idStatus']=$requestedStatus;else{
    $allowed=Workflow::getAllowedStatusListForObject($object);foreach($allowed as $candidate){$status=$candidate instanceof Status?$candidate:new Status((int)($candidate->id??$candidate));if(($state==='start'||$state==='resume')&&$status->setHandledStatus){$data['idStatus']=$status->id;break;}if(($state==='close'||$state==='finish')&&$status->setDoneStatus){$data['idStatus']=$status->id;break;}if($state==='reopen'&&!$status->setDoneStatus&&!$status->setIdleStatus){$data['idStatus']=$status->id;break;}}
  }
  return $data;
}
function mcpScrumSprintLifecycle(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['sprints'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{$sprint=mcpScrumTarget('Sprint',(int)$entry['id'],'update');$data=mcpScrumLifecycleData($sprint,(string)$entry['state'],isset($entry['idStatus'])?(int)$entry['idStatus']:null);if(array_key_exists('result',$entry))$data['result']=$entry['result'];return mcpScrumSave($sprint,'update',$entry,$data,false);});
}

function mcpScrumBoardAccess(Kanban $board,string $operation): void {
  mcpScrumRequireMenu('menuKanban',$operation==='read'?'read':'update');$userId=(int)getSessionUser()->id;
  if($operation==='read'){if((int)$board->idUser!==$userId&&!(int)$board->isShared)mcpJsonError(404,'scrum_target_not_found','The Kanban board was not found');return;}
  if((int)$board->idUser!==$userId)mcpJsonError(403,'forbidden','Only the board owner may change it');
}
function mcpScrumColumns(Kanban $board): array { $param=json_decode((string)$board->param,true);if(!is_array($param))$param=array();return is_array($param['column']??null)?array_values($param['column']):array(); }
function mcpScrumBoardPayload(Kanban $board): array { $param=json_decode((string)$board->param,true);if(!is_array($param))$param=array();return array('id'=>(int)$board->id,'name'=>(string)$board->name,'columnType'=>(string)$board->type,'cardType'=>(string)($param['typeData']??''),'isShared'=>(bool)$board->isShared,'columns'=>mcpScrumColumns($board),'_version'=>mcpObjectVersion($board)); }
function mcpScrumKanbanGet(array $arguments,string $username,string $action): array { $board=new Kanban((int)$arguments['id']);if(!$board->id)mcpJsonError(404,'scrum_target_not_found','The Kanban board was not found');mcpScrumBoardAccess($board,'read');return array('ok'=>true,'board'=>mcpScrumBoardPayload($board),'effects'=>array()); }
function mcpScrumBoardParam(array $entry,?Kanban $old=null): string {
  $current=$old?json_decode((string)$old->param,true):array();if(!is_array($current))$current=array();$columns=$entry['columns']??($current['column']??array());$cardType=$entry['cardType']??($current['typeData']??'UserStory');return json_encode(array('column'=>array_values($columns),'typeData'=>$cardType),JSON_UNESCAPED_SLASHES);
}
function mcpScrumKanbanBoardManage(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['boards'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $operation=(string)$entry['operation'];$id=(int)($entry['id']??0);
    if($operation==='create'){
      mcpScrumRequireMenu('menuKanban','update');foreach(array('name','columnType','cardType','columns') as $required)if(!isset($entry[$required]))mcpJsonError(400,'validation_failed','Kanban creation is missing fields',array('missingFields'=>array($required)));
      $board=new Kanban();$board->idUser=(int)getSessionUser()->id;$board->name=$entry['name'];$board->type=$entry['columnType'];$board->isShared=!empty($entry['isShared'])?1:0;$board->param=mcpScrumBoardParam($entry);$raw=$board->save();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'save_failed',cleanApiMessage($raw));return mcpScrumResult(new Kanban($board->id),'created',array('name','type','isShared','param'),array('name','type','isShared','param'));
    }
    $board=new Kanban($id);if(!$board->id)mcpJsonError(404,'scrum_target_not_found','The Kanban board was not found');mcpScrumBoardAccess($board,$operation==='copy'?'read':'update');mcpScrumRequireVersion($board,$entry);
    if($operation==='copy'){$copy=$board->copy();$copy->idUser=(int)getSessionUser()->id;$copy->isShared=0;$copy->name=(string)($entry['name']??('Copy - '.$board->name));$raw=$copy->save();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'save_failed',cleanApiMessage($raw));return mcpScrumResult(new Kanban($copy->id),'created',array('sourceId','name'),array('sourceId','name'));}
    if($operation==='delete')return mcpScrumSave($board,'delete',$entry,array(),true);
    $data=array();if($operation==='share')$data['isShared']=array_key_exists('isShared',$entry)?$entry['isShared']:!(bool)$board->isShared;else{foreach(array('name','isShared') as $field)if(array_key_exists($field,$entry))$data[$field]=$entry[$field];if(isset($entry['columnType']))$data['type']=$entry['columnType'];if(isset($entry['columns'])||isset($entry['cardType']))$data['param']=mcpScrumBoardParam($entry,$board);}
    return mcpScrumSave($board,'update',$entry,$data,true);
  });
}
function mcpScrumKanbanColumnsReplace(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['boards'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{$board=new Kanban((int)$entry['id']);if(!$board->id)mcpJsonError(404,'scrum_target_not_found','The Kanban board was not found');mcpScrumBoardAccess($board,'update');$param=json_decode((string)$board->param,true);if(!is_array($param))$param=array();$param['column']=array_values($entry['columns']);return mcpScrumSave($board,'update',$entry,array('param'=>json_encode($param,JSON_UNESCAPED_SLASHES)),true);});
}
function mcpScrumApplyStatus(object $object,int $statusId,array &$data): void {
  $status=new Status($statusId);if(!$status->id)mcpJsonError(400,'invalid_reference','The target status does not exist');$data['idStatus']=$statusId;
  foreach(array('handled'=>'Handled','done'=>'Done','idle'=>'Idle') as $field=>$suffix)if(property_exists($object,$field)){$flag='set'.$suffix.'Status';$data[$field]=$status->$flag?1:0;$dateField=$field.'Date';if(property_exists($object,$dateField))$data[$dateField]=$status->$flag?date('Y-m-d'):null;}
  if(property_exists($object,'cancelled'))$data['cancelled']=$status->setCancelledStatus?1:0;
}
function mcpScrumKanbanCardMove(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['cards'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $class=(string)$entry['objectClass'];$object=mcpScrumTarget($class,(int)$entry['id'],'update');$data=mcpScrumFields($entry,array('idResource','idResolution','description','result'));$column=(string)$entry['columnType'];$target=$entry['columnId'];
    if($column==='Status'){if($target===null)mcpJsonError(400,'validation_failed','A status column cannot be null');mcpScrumApplyStatus($object,(int)$target,$data);}else{if(!property_exists($object,'idSprint'))mcpJsonError(400,'unsupported_column','This object cannot be assigned to a sprint');if($target!==null)mcpScrumTarget('Sprint',(int)$target,'read');$data['idSprint']=$target===null?null:(int)$target;}
    return mcpScrumSave($object,'update',$entry,$data,false);
  });
}
function mcpScrumKanbanPreferences(array $arguments,string $username,string $action): array {
  mcpScrumRequireMenu('menuKanban','read');$mapping=array('idKanban'=>'kanbanIdKanban','showIdle'=>'kanbanShowIdle','showWork'=>'kanbanSeeWork','hideBacklog'=>'kanbanHideBacklog','fullWidth'=>'kanbanFullWidthElement');
  foreach($mapping as $field=>$parameter)if(array_key_exists($field,$arguments)){if($field==='idKanban'&&$arguments[$field]!==null){$board=new Kanban((int)$arguments[$field]);if(!$board->id)mcpJsonError(404,'scrum_target_not_found','The Kanban board was not found');mcpScrumBoardAccess($board,'read');}Parameter::storeUserParameter($parameter,is_bool($arguments[$field])?($arguments[$field]?'on':'off'):$arguments[$field]);}
  if(isset($arguments['orderBy']))setSessionValue('kanbanOrderBy',$arguments['orderBy']);return array('ok'=>true,'preferences'=>$arguments,'effects'=>array());
}

function mcpScrumPokerContext(array $entry,string $operation='update'): array {
  $session=mcpScrumTarget('PokerSession',(int)$entry['idPokerSession'],$operation);$sessionField=array_key_exists('sessionExpectedVersion',$entry)?'sessionExpectedVersion':'expectedVersion';mcpScrumRequireVersion($session,$entry,$sessionField);
  $item=null;if(!empty($entry['idPokerItem'])||!empty($entry['id'])){$itemId=(int)($entry['idPokerItem']??$entry['id']);$item=new PokerItem($itemId);if(!$item->id||(int)$item->idPokerSession!==(int)$session->id)mcpJsonError(404,'scrum_target_not_found','The poker item was not found in this session');$itemField=array_key_exists('itemExpectedVersion',$entry)?'itemExpectedVersion':'expectedVersion';mcpScrumRequireVersion($item,$entry,$itemField);}
  return array($session,$item);
}
function mcpScrumPokerSessionLifecycle(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['sessions'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{$session=mcpScrumTarget('PokerSession',(int)$entry['id'],'update');$data=mcpScrumLifecycleData($session,(string)$entry['state']);if($entry['state']==='pause')$data=array('handled'=>0,'handledDate'=>null);return mcpScrumSave($session,'update',$entry,$data,false);});
}
function mcpScrumPokerItemManage(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['items'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $operation=(string)$entry['operation'];$session=mcpScrumTarget('PokerSession',(int)$entry['idPokerSession'],'update');
    if($operation==='create'){
      foreach(array('refType','refId') as $required)if(empty($entry[$required]))mcpJsonError(400,'validation_failed','Poker item creation is missing fields',array('missingFields'=>array($required)));$target=mcpScrumTarget((string)$entry['refType'],(int)$entry['refId'],'read');$object=new PokerItem();$data=array('idPokerSession'=>(int)$session->id,'refType'=>get_class($target),'refId'=>(int)$target->id,'name'=>(string)($entry['name']??$target->name),'comment'=>$entry['comment']??null,'isOpen'=>0,'flipped'=>0);return mcpScrumSave($object,'create',$entry,$data,true);
    }
    list($session,$object)=mcpScrumPokerContext($entry,'update');if($operation==='delete')return mcpScrumSave($object,'delete',$entry,array(),true);return mcpScrumSave($object,'update',$entry,mcpScrumFields($entry,array('name','comment')),true);
  });
}
function mcpScrumVoteSetVersion(int $sessionId,int $itemId): string {
  $votes=(new PokerVote())->getSqlElementsFromCriteria(array('idPokerSession'=>$sessionId,'idPokerItem'=>$itemId),false,null,'id asc');$versions=array();foreach($votes as $vote)$versions[]=(int)$vote->id.':'.mcpObjectVersion($vote);return hash('sha256',implode('|',$versions));
}
function mcpScrumPokerStateGet(array $arguments,string $username,string $action): array {
  $session=mcpScrumTarget('PokerSession',(int)$arguments['idPokerSession'],'read');$criteria=array('idPokerSession'=>(int)$session->id);if(!empty($arguments['idPokerItem']))$criteria['id']=(int)$arguments['idPokerItem'];$rows=(new PokerItem())->getSqlElementsFromCriteria($criteria,false,null,'id asc');$items=array();$userId=(int)getSessionUser()->id;
  foreach(array_slice($rows,0,200) as $item){$votes=(new PokerVote())->getSqlElementsFromCriteria(array('idPokerSession'=>(int)$session->id,'idPokerItem'=>(int)$item->id),false,null,'id asc');$own=null;$revealed=array();foreach($votes as $vote){if((int)$vote->idResource===$userId)$own=array('id'=>(int)$vote->id,'value'=>$vote->value,'_version'=>mcpObjectVersion($vote));if($item->flipped)$revealed[]=array('idResource'=>(int)$vote->idResource,'value'=>$vote->value);}$row=array('id'=>(int)$item->id,'name'=>(string)$item->name,'refType'=>(string)$item->refType,'refId'=>(int)$item->refId,'isOpen'=>(bool)$item->isOpen,'flipped'=>(bool)$item->flipped,'value'=>$item->value,'voteCount'=>count($votes),'voteSetVersion'=>mcpScrumVoteSetVersion((int)$session->id,(int)$item->id),'_version'=>mcpObjectVersion($item));if($own)$row['ownVote']=$own;if($item->flipped)$row['revealedVotes']=$revealed;$items[]=$row;}
  return array('ok'=>true,'session'=>array('id'=>(int)$session->id,'handled'=>(bool)$session->handled,'done'=>(bool)$session->done,'_version'=>mcpObjectVersion($session)),'items'=>$items,'effects'=>array());
}
function mcpScrumPokerItemState(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['items'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    list($session,$item)=mcpScrumPokerContext($entry,'update');$mode=(string)$entry['mode'];
    if($mode==='open')return mcpScrumSave($item,'update',array('expectedVersion'=>$entry['itemExpectedVersion']),array('isOpen'=>1),true);
    if($mode==='pause')return mcpScrumSave($item,'update',array('expectedVersion'=>$entry['itemExpectedVersion']),array('isOpen'=>0),true);
    if(!$item->flipped)mcpJsonError(409,'votes_not_revealed','Votes must be revealed before finalization');if(empty($entry['idPokerComplexity'])||empty($entry['targetExpectedVersion']))mcpJsonError(400,'validation_failed','Finalization requires idPokerComplexity and targetExpectedVersion',array('missingFields'=>array('idPokerComplexity','targetExpectedVersion')));
    $complexity=new PokerComplexity((int)$entry['idPokerComplexity']);if(!$complexity->id)mcpJsonError(400,'invalid_reference','The poker complexity does not exist');$target=mcpScrumTarget((string)$item->refType,(int)$item->refId,'update');mcpScrumRequireVersion($target,$entry,'targetExpectedVersion');
    $itemResult=mcpScrumSave($item,'update',array('expectedVersion'=>$entry['itemExpectedVersion']),array('value'=>$complexity->value,'isOpen'=>0),true);
    if($item->refType==='UserStory')$targetData=array('storyPoints'=>$complexity->value);elseif($item->refType==='Requirement')$targetData=array('plannedWork'=>$complexity->itemWork);elseif($item->refType==='Ticket'){$target->WorkElement->plannedWork=$complexity->itemWork;$targetData=array();}else{$target->ActivityPlanningElement->validatedWork=$complexity->itemWork;$targetData=array();}
    $targetResult=mcpScrumSave($target,'update',array('expectedVersion'=>$entry['targetExpectedVersion']),$targetData,false);$itemResult['recalculatedFields']=array('target:'.get_class($target).'#'.$target->id);$itemResult['saved']['target']=$targetResult['saved'];return $itemResult;
  });
}
function mcpScrumPokerVoteCast(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['votes'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    list($session,$item)=mcpScrumPokerContext($entry,'read');if(!$item->isOpen)mcpJsonError(409,'voting_closed','The poker item is not open for voting');$user=getSessionUser();if(empty($user->isResource))mcpJsonError(403,'forbidden','Only a resource may cast a poker vote');
    $vote=SqlElement::getSingleSqlElementFromCriteria('PokerVote',array('idPokerSession'=>(int)$session->id,'idPokerItem'=>(int)$item->id,'idResource'=>(int)$user->id));$value=$entry['value'];
    if($vote->id){mcpScrumRequireVersion($vote,$entry,'expectedVoteVersion');if($value===null)return mcpScrumSave($vote,'delete',array('expectedVersion'=>$entry['expectedVoteVersion']),array(),true);if((string)$vote->value===(string)$value)return mcpScrumResult($vote,'existing',array('value'),array('value'));return mcpScrumSave($vote,'update',array('expectedVersion'=>$entry['expectedVoteVersion']),array('value'=>$value),true);}
    if($value===null)mcpJsonError(404,'vote_not_found','There is no vote to remove');$vote=new PokerVote();return mcpScrumSave($vote,'create',$entry,array('idPokerSession'=>(int)$session->id,'idPokerItem'=>(int)$item->id,'idResource'=>(int)$user->id,'value'=>$value),true);
  });
}
function mcpScrumPokerVoteVisibility(array $arguments,string $username,string $action): array {
  return mcpScrumBatch($arguments['items'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    list($session,$item)=mcpScrumPokerContext($entry,'update');$actual=mcpScrumVoteSetVersion((int)$session->id,(int)$item->id);if(!hash_equals($actual,(string)$entry['expectedVoteSetVersion']))mcpJsonError(409,'vote_set_conflict','The set of votes has changed',array('actualVoteSetVersion'=>$actual));
    if($entry['mode']==='reveal'){if((new PokerVote())->countSqlElementsFromCriteria(array('idPokerSession'=>(int)$session->id,'idPokerItem'=>(int)$item->id))<1)mcpJsonError(409,'no_votes','No votes are available to reveal');return mcpScrumSave($item,'update',array('expectedVersion'=>$entry['itemExpectedVersion']),array('flipped'=>1),true);}
    $votes=(new PokerVote())->getSqlElementsFromCriteria(array('idPokerSession'=>(int)$session->id,'idPokerItem'=>(int)$item->id));foreach($votes as $vote){SqlElement::setDeleteConfirmed();$raw=$vote->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'delete_failed',cleanApiMessage($raw));}return mcpScrumSave($item,'update',array('expectedVersion'=>$entry['itemExpectedVersion']),array('flipped'=>0),true);
  });
}

function mcpScrumStoryPreview(array $arguments,string $username,string $action): array {
  $items=array();foreach($arguments['stories'] as $index=>$entry){$operation=(string)$entry['operation'];if($operation==='create'){mcpRequireClassOperation('UserStory','create');foreach(array('name','idProject','idUserStoryType','idStatus') as $required)if(empty($entry[$required]))mcpJsonError(400,'validation_failed','Story creation is missing required fields',array('missingFields'=>array($required)));$preview=new UserStory();foreach(array('idProject','idUserStoryType','idStatus') as $field)$preview->$field=$entry[$field];mcpScrumRequireDirect($preview,'create');}else{$object=mcpScrumTarget('UserStory',(int)($entry['id']??0),$operation);mcpScrumRequireVersion($object,$entry);}$items[]=array('index'=>$index,'operation'=>$operation,'objectClass'=>'UserStory','id'=>$entry['id']??null);}return array('count'=>count($items),'items'=>$items);
}
function mcpScrumBoardPreview(array $arguments,string $username,string $action): array {
  $items=array();foreach($arguments['boards'] as $index=>$entry){$operation=(string)$entry['operation'];if($operation==='create'){mcpScrumRequireMenu('menuKanban','update');foreach(array('name','columnType','cardType','columns') as $required)if(!isset($entry[$required]))mcpJsonError(400,'validation_failed','Kanban creation is missing fields',array('missingFields'=>array($required)));}else{$board=new Kanban((int)($entry['id']??0));if(!$board->id)mcpJsonError(404,'scrum_target_not_found','The Kanban board was not found');mcpScrumBoardAccess($board,$operation==='copy'?'read':'update');mcpScrumRequireVersion($board,$entry);}$items[]=array('index'=>$index,'operation'=>$operation,'objectClass'=>'Kanban','id'=>$entry['id']??null);}return array('count'=>count($items),'items'=>$items);
}
function mcpScrumColumnsPreview(array $arguments,string $username,string $action): array {
  $items=array();foreach($arguments['boards'] as $index=>$entry){$board=new Kanban((int)$entry['id']);if(!$board->id)mcpJsonError(404,'scrum_target_not_found','The Kanban board was not found');mcpScrumBoardAccess($board,'update');mcpScrumRequireVersion($board,$entry);$items[]=array('index'=>$index,'objectClass'=>'Kanban','id'=>(int)$board->id,'oldColumnCount'=>count(mcpScrumColumns($board)),'newColumnCount'=>count($entry['columns']));}return array('count'=>count($items),'items'=>$items);
}
function mcpScrumPokerItemPreview(array $arguments,string $username,string $action): array {
  $items=array();foreach($arguments['items'] as $index=>$entry){$operation=(string)$entry['operation'];$session=mcpScrumTarget('PokerSession',(int)$entry['idPokerSession'],'update');if($operation==='create'){foreach(array('refType','refId') as $required)if(empty($entry[$required]))mcpJsonError(400,'validation_failed','Poker item creation is missing fields',array('missingFields'=>array($required)));mcpScrumTarget((string)$entry['refType'],(int)$entry['refId'],'read');}else list($session,$item)=mcpScrumPokerContext($entry,'update');$items[]=array('index'=>$index,'operation'=>$operation,'objectClass'=>'PokerItem','id'=>$entry['id']??null,'idPokerSession'=>(int)$session->id);}return array('count'=>count($items),'items'=>$items);
}
function mcpScrumPokerVisibilityPreview(array $arguments,string $username,string $action): array {
  $items=array();foreach($arguments['items'] as $index=>$entry){list($session,$item)=mcpScrumPokerContext($entry,'update');$actual=mcpScrumVoteSetVersion((int)$session->id,(int)$item->id);if(!hash_equals($actual,(string)$entry['expectedVoteSetVersion']))mcpJsonError(409,'vote_set_conflict','The set of votes has changed');$items[]=array('index'=>$index,'mode'=>$entry['mode'],'objectClass'=>'PokerItem','id'=>(int)$item->id,'voteCount'=>(new PokerVote())->countSqlElementsFromCriteria(array('idPokerSession'=>(int)$session->id,'idPokerItem'=>(int)$item->id)));}return array('count'=>count($items),'items'=>$items);
}
