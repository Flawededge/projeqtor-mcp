<?php
declare(strict_types=1);

require_once __DIR__.'/image-upload.php';
require_once __DIR__.'/relationship.php';
require_once __DIR__.'/administrative.php';
function mcpToolsActionAvailable(array $action): bool {
  $contract=(string)($action['testContract']??'');
  $classes=match(true){
    str_contains($contract,'document.version')=>array('Document','DocumentVersion'),
    str_contains($contract,'document')=>array('Document'),
    str_contains($contract,'attachment')=>array('Attachment'),
    str_contains($contract,'note')=>array('Note'),
    str_contains($contract,'link')=>array('Link'),
    str_contains($contract,'notification')=>array('Notification'),
    str_contains($contract,'automation')=>array('NotificationDefinition'),
    str_contains($contract,'localization')=>array('LocalizationItem'),
    str_contains($contract,'asset')=>array('Asset'),
    default=>array()
  };
  foreach($classes as $class)if(!SqlElement::class_exists($class))return false;
  return true;
}

function mcpToolsMailAvailable(array $action): bool {
  return function_exists('sendMail');
}

function mcpToolsMode(array $arguments): string {
  $mode=(string)($arguments['transactionMode']??'atomic');
  if(!in_array($mode,array('atomic','best_effort'),true))mcpJsonError(400,'invalid_transaction_mode','transactionMode must be atomic or best_effort');
  return $mode;
}

function mcpToolsEffects(array $result): array {
  $effects=array();
  foreach($result['items']??array() as $item){
    $status=(string)($item['status']??'');
    if(!in_array($status,array('created','updated','deleted','existing'),true))continue;
    $effects[]=array('action'=>$status==='existing'?'read':rtrim($status,'d'),'objectClass'=>$item['objectClass']??null,'id'=>$item['id']??null);
  }
  return $effects;
}

function mcpToolsBatchForClass(array $arguments,string $class,array $allowedClasses=array(),bool $allowGuarded=false): array {
  $allowed=$allowedClasses?:array($class);$operations=array();
  foreach($arguments['operations']??array() as $index=>$operation){
    if(!is_array($operation))mcpJsonError(400,'invalid_operation',"Operation $index must be an object");
    $selected=(string)($operation['objectClass']??$class);
    if(!in_array($selected,$allowed,true))mcpJsonError(400,'invalid_object_class',"$selected is not owned by this Tools action");
    $canonical=array(
      'action'=>(string)($operation['operation']??''),'objectClass'=>$selected,
      'data'=>is_array($operation['data']??null)?$operation['data']:array()
    );
    foreach(array('id','expectedVersion','localKey') as $field)if(array_key_exists($field,$operation))$canonical[$field]=$operation[$field];
    $operations[]=$canonical;
  }
  $result=mcpExecuteOperationsArray($operations,mcpToolsMode($arguments),$allowGuarded);
  $result['effects']=mcpToolsEffects($result);
  return $result;
}

function mcpToolsDocumentManage(array $arguments,string $username,string $action): array {
  return mcpToolsBatchForClass($arguments,'Document',array('Document','DocumentDirectory'));
}
function mcpToolsNoteManage(array $arguments,string $username,string $action): array {
  foreach($arguments['operations']??array() as &$operation){
    if(($operation['operation']??'')==='create'){
      $operation['data']['idUser']=$operation['data']['idUser']??(int)getSessionUser()->id;
      $operation['data']['creationDate']=$operation['data']['creationDate']??date('Y-m-d H:i:s');
      if(!isset($operation['data']['idPrivacy']))$operation['data']['idPrivacy']=1;
    }
  }unset($operation);
  return mcpToolsBatchForClass($arguments,'Note');
}
function mcpToolsLinkManage(array $arguments,string $username,string $action): array {
  foreach($arguments['operations']??array() as &$operation)if(($operation['operation']??'')==='create'){
    $operation['data']['idUser']=$operation['data']['idUser']??(int)getSessionUser()->id;
    $operation['data']['creationDate']=$operation['data']['creationDate']??date('Y-m-d H:i:s');
  }unset($operation);
  return mcpToolsBatchForClass($arguments,'Link');
}
function mcpToolsAutomationManage(array $arguments,string $username,string $action): array {
  return mcpToolsBatchForClass($arguments,'NotificationDefinition',array('NotificationDefinition','EventForMail','StatusMail','StatusMailPerProject','EmailTemplate'),true);
}
function mcpToolsLocalizationManage(array $arguments,string $username,string $action): array {
  return mcpToolsBatchForClass($arguments,'LocalizationItem',array('LocalizationItem','LocalizationRequest','LocalizationTranslator','LocalizationTranslatorLanguage'));
}
function mcpToolsAssetManage(array $arguments,string $username,string $action): array {
  return mcpToolsBatchForClass($arguments,'Asset',array('Asset','AssetCategory','AssetType'));
}

function mcpToolsDeleteClass(array $arguments,string $class): array {
  $operations=array_map(fn($item)=>array_filter(array(
    'action'=>'delete','objectClass'=>$class,'id'=>(int)($item['id']??0),
    'expectedVersion'=>$item['expectedVersion']??null
  ),fn($value)=>$value!==null),$arguments['items']??array());
  $result=mcpExecuteOperationsArray($operations,'atomic',true);$result['effects']=mcpToolsEffects($result);return $result;
}
function mcpToolsDeleteVersions(array $arguments,string $username,string $action): array { return mcpToolsDeleteClass($arguments,'DocumentVersion'); }
function mcpToolsDeleteAttachments(array $arguments,string $username,string $action): array { return mcpToolsDeleteClass($arguments,'Attachment'); }
function mcpToolsDeleteNotes(array $arguments,string $username,string $action): array { return mcpToolsDeleteClass($arguments,'Note'); }
function mcpToolsDeleteLinks(array $arguments,string $username,string $action): array { return mcpToolsDeleteClass($arguments,'Link'); }

function mcpToolsDeleteClassForAction(string $action): string {
  return match($action){
    'tools.document.version.delete'=>'DocumentVersion','tools.attachment.delete'=>'Attachment',
    'tools.note.delete'=>'Note','tools.link.delete'=>'Link',default=>''
  };
}
function mcpToolsPreviewDeletes(array $arguments,string $username,string $action): array {
  $class=mcpToolsDeleteClassForAction($action);if(!$class)mcpJsonError(400,'preview_unavailable','Delete preview is unavailable');
  $items=array();$allowed=0;$missing=0;$conflicts=0;
  foreach($arguments['items']??array() as $entry){
    $id=(int)($entry['id']??0);$object=new $class($id);
    if(!$object->id){$missing++;$items[]=array('id'=>$id,'status'=>'missing');continue;}
    $version=mcpObjectVersion($object);$conflict=!empty($entry['expectedVersion'])&&!hash_equals((string)$entry['expectedVersion'],$version);
    $canDelete=Security::checkValidAccessForUser($object,'delete',null,null,false);if($canDelete)$allowed++;if($conflict)$conflicts++;
    $items[]=array('id'=>$id,'objectClass'=>$class,'name'=>$object->name??null,'version'=>$version,'versionConflict'=>$conflict,'deleteAllowed'=>$canDelete);
  }
  return array('objectClass'=>$class,'counts'=>array('requested'=>count($arguments['items']??array()),'allowed'=>$allowed,'missing'=>$missing,'versionConflicts'=>$conflicts),'items'=>$items);
}

function mcpToolsNotificationStatus(array $arguments,string $username,string $action): array {
  $operations=array();foreach($arguments['items']??array() as $item){$data=array();if(isset($item['statusId']))$data['idStatusNotification']=(int)$item['statusId'];if(array_key_exists('idle',$item))$data['idle']=$item['idle']?1:0;
    if(!$data)$data['idStatusNotification']=2;
    $operations[]=array_filter(array('action'=>'update','objectClass'=>'Notification','id'=>(int)$item['id'],'expectedVersion'=>$item['expectedVersion']??null,'data'=>$data),fn($value)=>$value!==null);
  }
  $result=mcpExecuteOperationsArray($operations,mcpToolsMode($arguments),false);$result['effects']=mcpToolsEffects($result);return $result;
}

function mcpToolsSubscribe(array $arguments,string $username,string $action): array {
  $operations=array();$existing=array();
  foreach($arguments['items']??array() as $index=>$item){
    if((int)$item['idAffectable']!==(int)getSessionUser()->id && securityGetAccessRightYesNo('menuAdmin','read')!=='YES')mcpJsonError(403,'forbidden','Subscribing another user requires administration access');
    Security::checkValidClass((string)$item['refType']);$target=new $item['refType']((int)$item['refId']);
    if(!$target->id||!Security::checkValidAccessForUser($target,'read',null,null,false))mcpJsonError(403,'forbidden','Subscription target is unavailable');
    $criteria=array('refType'=>$item['refType'],'refId'=>(int)$item['refId'],'idAffectable'=>(int)$item['idAffectable']);
    $found=SqlElement::getSingleSqlElementFromCriteria('Subscription',$criteria);
    if($found->id){$existing[$index]=array('index'=>$index,'status'=>'existing','objectClass'=>'Subscription','id'=>(int)$found->id,'saved'=>mcpObjectArray($found));continue;}
    $operations[]=array('action'=>'create','objectClass'=>'Subscription','data'=>array_merge($criteria,array('idUser'=>(int)getSessionUser()->id,'creationDateTime'=>date('Y-m-d H:i:s'))));
  }
  $result=$operations?mcpExecuteOperationsArray($operations,mcpToolsMode($arguments),false):array('ok'=>true,'rolledBack'=>false,'transactionMode'=>mcpToolsMode($arguments),'items'=>array());
  foreach($existing as $item)$result['items'][]=$item;$result['effects']=mcpToolsEffects($result);return $result;
}

function mcpToolsSubscriptions(array $arguments,bool $delete): array {
  $operations=array();$items=array();
  foreach($arguments['items']??array() as $index=>$item){
    if((int)$item['idAffectable']!==(int)getSessionUser()->id && securityGetAccessRightYesNo('menuAdmin','read')!=='YES')mcpJsonError(403,'forbidden','Unsubscribing another user requires administration access');
    $criteria=array('refType'=>$item['refType'],'refId'=>(int)$item['refId'],'idAffectable'=>(int)$item['idAffectable']);
    $found=SqlElement::getSingleSqlElementFromCriteria('Subscription',$criteria);
    if(!$found->id){$items[]=array('index'=>$index,'status'=>'missing','objectClass'=>'Subscription','id'=>null);continue;}
    $operations[]=array('action'=>'delete','objectClass'=>'Subscription','id'=>(int)$found->id);
    $items[]=array('index'=>$index,'status'=>'present','objectClass'=>'Subscription','id'=>(int)$found->id,'deleteAllowed'=>Security::checkValidAccessForUser($found,'delete',null,null,false));
  }
  if(!$delete)return array('counts'=>array('requested'=>count($arguments['items']??array()),'present'=>count($operations)),'items'=>$items);
  $result=$operations?mcpExecuteOperationsArray($operations,'atomic',true):array('ok'=>true,'rolledBack'=>false,'transactionMode'=>'atomic','items'=>array());$result['effects']=mcpToolsEffects($result);return $result;
}
function mcpToolsPreviewSubscriptions(array $arguments,string $username,string $action): array { return mcpToolsSubscriptions($arguments,false); }
function mcpToolsUnsubscribe(array $arguments,string $username,string $action): array { return mcpToolsSubscriptions($arguments,true); }

function mcpToolsPreviewNotifications(array $arguments,string $username,string $action): array {
  return array('count'=>count($arguments['items']??array()),'emailCount'=>count(array_filter($arguments['items']??array(),fn($item)=>!empty($item['sendEmail']))),'delivery'=>'guarded_external','contentRedacted'=>true);
}
function mcpToolsPreviewMail(array $arguments,string $username,string $action): array {
  $domains=array();foreach($arguments['recipients']??array() as $recipient){$parts=explode('@',(string)$recipient);if(count($parts)===2)$domains[strtolower($parts[1])]=true;}
  return array('recipientCount'=>count($arguments['recipients']??array()),'recipientDomains'=>array_keys($domains),'subjectLength'=>mb_strlen((string)($arguments['subject']??'')),'bodyBytes'=>strlen((string)($arguments['body']??'')),'delivery'=>'guarded_external','payloadRedacted'=>true);
}

function mcpToolsAttachmentAction(array $arguments,string $username,string $action): array { return mcpExecuteUploadAction($action,$arguments,$username); }
function mcpToolsPreviewUploadAbort(array $arguments,string $username,string $action): array { $meta=mcpReadUpload((string)($arguments['uploadId']??''),$username); return array('uploadId'=>$meta['uploadId'],'fileName'=>$meta['fileName'],'receivedBytes'=>is_file(mcpUploadDataPath($meta['uploadId']))?filesize(mcpUploadDataPath($meta['uploadId'])):0,'effect'=>'discard_temporary_upload'); }
function mcpToolsCleanupImport(array $arguments,string $username,string $action): array { return mcpCleanupImportRun($username,$arguments); }
function mcpToolsPreviewImport(array $arguments,string $username,string $action): array { return mcpImportCleanupPreview($username,$arguments); }
