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

/** Keep semantic action results closed and smaller than native SqlElement dumps. */
function mcpToolsNormalizeItem(array $item): array {
  $saved=is_array($item['saved']??null)?$item['saved']:array();
  $allowed=array('index','localKey','status','objectClass','id','sourceId','requestedFields','appliedFields','recalculatedFields','ignoredFields','rejectedFields','concurrencyUnchecked','error','relatedDocumentVersionIds','emailSent','recipientHash','fileName','mimeType','fileSize','archiveName','bytes','deleteAllowed');
  $result=array();foreach($allowed as $field)if(array_key_exists($field,$item))$result[$field]=$item[$field];
  if(is_array($result['error']??null)){$source=$result['error'];$result['error']=array('code'=>(string)($source['code']??'operation_failed'),'message'=>(string)($source['message']??'Operation failed'));foreach(array('expectedVersion','actualVersion') as $field)if(isset($source[$field]))$result['error'][$field]=(string)$source[$field];}
  $version=$saved['_version']??($item['version']??null);if(is_string($version)&&$version!=='')$result['version']=$version;
  return $result;
}
function mcpToolsNormalizeBatchResult(array $result): array { $result['items']=array_map('mcpToolsNormalizeItem',$result['items']??array());$result['effects']=mcpToolsEffects($result);return $result; }
function mcpToolsRequireExpectedVersion(array $operation,int $index): void { if(($operation['operation']??'')==='update'&&empty($operation['expectedVersion']))mcpJsonError(400,'expected_version_required',"Operation $index requires expectedVersion for an existing record"); }
function mcpToolsRequireParentRead(string $class,int $id,string $label='Parent'): object { Security::checkValidClass($class);$parent=new $class($id);if(!$parent->id||!Security::checkValidAccessForUser($parent,'read',null,null,false))mcpJsonError(403,'forbidden',"$label is unavailable");return $parent; }
function mcpToolsValidateSemanticParents(string $class,array $data): void {
  if(in_array($class,array('Document','DocumentDirectory'),true)){if(!empty($data['idProject']))mcpToolsRequireParentRead('Project',(int)$data['idProject'],'Document project');if(!empty($data['idDocumentDirectory']))mcpToolsRequireParentRead('DocumentDirectory',(int)$data['idDocumentDirectory'],'Document directory');if(!empty($data['idDocumentType']))mcpToolsRequireParentRead('DocumentType',(int)$data['idDocumentType'],'Document type');}
  if($class==='DocumentRight'){
    if(!empty($data['idDocument']))mcpToolsRequireParentRead('Document',(int)$data['idDocument'],'Document right target');
    if(!empty($data['idDocumentDirectory']))mcpToolsRequireParentRead('DocumentDirectory',(int)$data['idDocumentDirectory'],'Document right directory');
  }
  if($class==='Note'&&!empty($data['refType'])&&!empty($data['refId']))mcpToolsRequireParentRead((string)$data['refType'],(int)$data['refId'],'Note target');
  if($class==='Link')foreach(array(1,2) as $side)if(!empty($data['ref'.$side.'Type'])&&!empty($data['ref'.$side.'Id']))mcpToolsRequireParentRead((string)$data['ref'.$side.'Type'],(int)$data['ref'.$side.'Id'],'Link endpoint');
  foreach(array('idProject'=>'Project','idResource'=>'Resource','idUser'=>'User','idContact'=>'Contact','idTeam'=>'Team','idProduct'=>'Product','idProductVersion'=>'ProductVersion','idComponent'=>'Component','idComponentVersion'=>'ComponentVersion') as $field=>$parent)if(!empty($data[$field]))mcpToolsRequireParentRead($parent,(int)$data[$field],"$class $field reference");
}
function mcpToolsSemanticOnlyClasses(): array { return array('DataCloning','EventForMail','LocalizationTranslatorLanguage'); }
function mcpToolsApplyNamedSemanticOperation(array $operation,bool $allowGuarded): array {
  $class=(string)$operation['objectClass'];$verb=(string)$operation['action'];if(!in_array($class,mcpToolsSemanticOnlyClasses(),true))return mcpApplyOperation($operation,$allowGuarded);
  // Generic CRUD remains denied. Only a named guarded Tools action may reach these models.
  if(!$allowGuarded||securityGetAccessRightYesNo('menuAdmin','read')!=='YES')mcpJsonError(403,'forbidden',"Administrative access is required for $class");
  if(!in_array($verb,array('create','update'),true))mcpJsonError(400,'invalid_operation','Semantic-only Tools records support create or update');
  $id=(int)($operation['id']??0);if($verb==='update'&&$id<1)mcpJsonError(400,'id_required','update requires a positive id');$object=$id?new $class($id):new $class();if($id&&!$object->id)mcpJsonError(404,'not_found',"$class #$id was not found");
  if(!Security::checkValidAccessForUser($object,$verb,null,null,false))mcpJsonError(403,'forbidden',"$verb access is denied for $class");if($id&&!hash_equals(mcpObjectVersion($object),(string)$operation['expectedVersion']))mcpJsonError(409,'version_conflict',"$class #$id has changed");
  $data=is_array($operation['data']??null)?$operation['data']:array();mcpToolsValidateSemanticParents($class,$data);mcpFillObject($object,$data);$control=method_exists($object,'control')?cleanApiMessage($object->control()):'OK';if($control!==''&&strtoupper($control)!=='OK')return array('status'=>'invalid','objectClass'=>$class,'id'=>$id?:null,'error'=>array('code'=>'validation_failed','message'=>$control));
  $raw=$object->save();if(getLastOperationStatus($raw)!=='OK')return array('status'=>'error','objectClass'=>$class,'id'=>$object->id?:null,'error'=>array('code'=>'save_failed','message'=>cleanApiMessage($raw)));return mcpOperationResult($verb==='create'?'created':'updated',$class,$object,$data);
}
function mcpToolsExecuteNamedOperations(array $operations,string $mode,bool $allowGuarded): array {
  if(!$operations||count($operations)>200)mcpJsonError(400,'invalid_batch','operations must contain 1 to 200 items');$results=array();if($mode==='atomic')Sql::beginTransaction();
  foreach($operations as $index=>$operation){if($mode==='best_effort')Sql::beginTransaction();$GLOBALS['mcpCaptureErrors']=true;try{$item=mcpToolsApplyNamedSemanticOperation($operation,$allowGuarded);$item['index']=$index;$item['localKey']=$operation['localKey']??null;$failed=in_array($item['status'],array('invalid','error'),true);if($mode==='best_effort')$failed?Sql::rollbackTransaction():Sql::commitTransaction();$results[]=$item;if($failed&&$mode==='atomic'){Sql::rollbackTransaction();return mcpToolsNormalizeBatchResult(array('ok'=>false,'rolledBack'=>true,'transactionMode'=>$mode,'items'=>$results));}}catch(Throwable $error){if($mode==='best_effort')Sql::rollbackTransaction();$details=$error instanceof McpBridgeException?array_merge(array('code'=>$error->errorCode,'message'=>$error->getMessage()),$error->details):array('code'=>'operation_failed','message'=>cleanApiMessage($error->getMessage()));$results[]=array('index'=>$index,'localKey'=>$operation['localKey']??null,'objectClass'=>$operation['objectClass']??null,'status'=>'error','error'=>$details);if($mode==='atomic'){Sql::rollbackTransaction();return mcpToolsNormalizeBatchResult(array('ok'=>false,'rolledBack'=>true,'transactionMode'=>$mode,'items'=>$results));}}finally{$GLOBALS['mcpCaptureErrors']=false;}}
  if($mode==='atomic')Sql::commitTransaction();return mcpToolsNormalizeBatchResult(array('ok'=>!count(array_filter($results,fn($item)=>in_array($item['status'],array('invalid','error'),true))),'rolledBack'=>false,'transactionMode'=>$mode,'items'=>$results));
}

function mcpToolsBatchForClass(array $arguments,string $class,array $allowedClasses=array(),bool $allowGuarded=false): array {
  $allowed=$allowedClasses?:array($class);$operations=array();
  foreach($arguments['operations']??array() as $index=>$operation){
    if(!is_array($operation))mcpJsonError(400,'invalid_operation',"Operation $index must be an object");
    mcpToolsRequireExpectedVersion($operation,$index);
    $selected=(string)($operation['objectClass']??$class);
    if(!in_array($selected,$allowed,true))mcpJsonError(400,'invalid_object_class',"$selected is not owned by this Tools action");
    $canonical=array(
      'action'=>(string)($operation['operation']??''),'objectClass'=>$selected,
      'data'=>is_array($operation['data']??null)?$operation['data']:array()
    );
    foreach(array('id','expectedVersion','localKey') as $field)if(array_key_exists($field,$operation))$canonical[$field]=$operation[$field];
    mcpToolsValidateSemanticParents($selected,$canonical['data']);$operations[]=$canonical;
  }
  return mcpToolsExecuteNamedOperations($operations,mcpToolsMode($arguments),$allowGuarded);
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
  return mcpToolsBatchForClass($arguments,'LocalizationItem',array('LocalizationItem','LocalizationRequest','LocalizationTranslator','LocalizationTranslatorLanguage'),true);
}
function mcpToolsAssetManage(array $arguments,string $username,string $action): array {
  return mcpToolsBatchForClass($arguments,'Asset',array('Asset','AssetCategory','AssetType'));
}

function mcpToolsDeleteClass(array $arguments,string $class): array {
  foreach($arguments['items']??array() as $index=>$item)if(empty($item['expectedVersion']))mcpJsonError(400,'expected_version_required',"Delete item $index requires expectedVersion");
  $operations=array_map(fn($item)=>array_filter(array(
    'action'=>'delete','objectClass'=>$class,'id'=>(int)($item['id']??0),
    'expectedVersion'=>$item['expectedVersion']??null
  ),fn($value)=>$value!==null),$arguments['items']??array());
  return mcpToolsNormalizeBatchResult(mcpExecuteOperationsArray($operations,'atomic',true));
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
    if(!Security::checkValidAccessForUser($object,'delete',null,null,false))mcpJsonError(403,'forbidden',"$class #$id is unavailable");
    $version=mcpObjectVersion($object);$conflict=!empty($entry['expectedVersion'])&&!hash_equals((string)$entry['expectedVersion'],$version);
    $allowed++;if($conflict)$conflicts++;
    $items[]=array('id'=>$id,'objectClass'=>$class,'name'=>$object->name??null,'version'=>$version,'versionConflict'=>$conflict,'deleteAllowed'=>true);
  }
  return array('objectClass'=>$class,'counts'=>array('requested'=>count($arguments['items']??array()),'allowed'=>$allowed,'missing'=>$missing,'versionConflicts'=>$conflicts),'items'=>$items);
}

function mcpToolsNotificationStatus(array $arguments,string $username,string $action): array {
  $operations=array();foreach($arguments['items']??array() as $index=>$item){if(empty($item['expectedVersion']))mcpJsonError(400,'expected_version_required',"Notification item $index requires expectedVersion");$data=array();if(isset($item['statusId']))$data['idStatusNotification']=(int)$item['statusId'];if(array_key_exists('idle',$item))$data['idle']=$item['idle']?1:0;
    if(!$data)$data['idStatusNotification']=2;
    $operations[]=array_filter(array('action'=>'update','objectClass'=>'Notification','id'=>(int)$item['id'],'expectedVersion'=>$item['expectedVersion']??null,'data'=>$data),fn($value)=>$value!==null);
  }
  return mcpToolsNormalizeBatchResult(mcpExecuteOperationsArray($operations,mcpToolsMode($arguments),false));
}

function mcpToolsSubscribe(array $arguments,string $username,string $action): array {
  $operations=array();$existing=array();
  foreach($arguments['items']??array() as $index=>$item){
    if((int)$item['idAffectable']!==(int)getSessionUser()->id && securityGetAccessRightYesNo('menuAdmin','read')!=='YES')mcpJsonError(403,'forbidden','Subscribing another user requires administration access');
    $target=mcpToolsRequireParentRead((string)$item['refType'],(int)$item['refId'],'Subscription target');
    if(empty($item['expectedTargetVersion'])||!hash_equals(mcpObjectVersion($target),(string)$item['expectedTargetVersion']))mcpJsonError(409,'version_conflict','Subscription target has changed');
    $criteria=array('refType'=>$item['refType'],'refId'=>(int)$item['refId'],'idAffectable'=>(int)$item['idAffectable']);
    $found=SqlElement::getSingleSqlElementFromCriteria('Subscription',$criteria);
    if($found->id){$existing[$index]=array('index'=>$index,'status'=>'existing','objectClass'=>'Subscription','id'=>(int)$found->id,'version'=>mcpObjectVersion($found));continue;}
    $operations[]=array('action'=>'create','objectClass'=>'Subscription','data'=>array_merge($criteria,array('idUser'=>(int)getSessionUser()->id,'creationDateTime'=>date('Y-m-d H:i:s'))));
  }
  $result=$operations?mcpExecuteOperationsArray($operations,mcpToolsMode($arguments),false):array('ok'=>true,'rolledBack'=>false,'transactionMode'=>mcpToolsMode($arguments),'items'=>array());
  foreach($existing as $item)$result['items'][]=$item;return mcpToolsNormalizeBatchResult($result);
}

function mcpToolsSubscriptions(array $arguments,bool $delete): array {
  $operations=array();$items=array();
  foreach($arguments['items']??array() as $index=>$item){
    if((int)$item['idAffectable']!==(int)getSessionUser()->id && securityGetAccessRightYesNo('menuAdmin','read')!=='YES')mcpJsonError(403,'forbidden','Unsubscribing another user requires administration access');
    mcpToolsRequireParentRead((string)$item['refType'],(int)$item['refId'],'Subscription target');
    $criteria=array('refType'=>$item['refType'],'refId'=>(int)$item['refId'],'idAffectable'=>(int)$item['idAffectable']);
    $found=SqlElement::getSingleSqlElementFromCriteria('Subscription',$criteria);
    if(!$found->id){$items[]=array('index'=>$index,'status'=>'missing','objectClass'=>'Subscription','id'=>null);continue;}
    if(empty($item['expectedVersion']))mcpJsonError(400,'expected_version_required',"Subscription item $index requires expectedVersion");
    if(!hash_equals(mcpObjectVersion($found),(string)$item['expectedVersion']))mcpJsonError(409,'version_conflict','Subscription has changed');
    if(!Security::checkValidAccessForUser($found,'delete',null,null,false))mcpJsonError(403,'forbidden','Subscription is unavailable');
    $operations[]=array('action'=>'delete','objectClass'=>'Subscription','id'=>(int)$found->id,'expectedVersion'=>(string)$item['expectedVersion']);
    $items[]=array('index'=>$index,'status'=>'present','objectClass'=>'Subscription','id'=>(int)$found->id,'deleteAllowed'=>true);
  }
  if(!$delete)return array('counts'=>array('requested'=>count($arguments['items']??array()),'present'=>count($operations)),'items'=>$items);
  $result=$operations?mcpExecuteOperationsArray($operations,'atomic',true):array('ok'=>true,'rolledBack'=>false,'transactionMode'=>'atomic','items'=>array());return mcpToolsNormalizeBatchResult($result);
}
function mcpToolsPreviewSubscriptions(array $arguments,string $username,string $action): array { return mcpToolsSubscriptions($arguments,false); }
function mcpToolsUnsubscribe(array $arguments,string $username,string $action): array { return mcpToolsSubscriptions($arguments,true); }

function mcpToolsPreviewNotifications(array $arguments,string $username,string $action): array {
  mcpRequireClassOperation('Notification','create');$probe=new Notification();
  if(!Security::checkValidAccessForUser($probe,'create',null,null,false))mcpJsonError(403,'forbidden','Notification creation access is denied');
  foreach($arguments['items']??array() as $entry)mcpToolsRequireParentRead('User',(int)$entry['idUser'],'Notification recipient');
  return array('count'=>count($arguments['items']??array()),'emailCount'=>count(array_filter($arguments['items']??array(),fn($item)=>!empty($item['sendEmail']))),'delivery'=>'guarded_external','contentRedacted'=>true);
}
function mcpToolsPreviewMail(array $arguments,string $username,string $action): array {
  $refType=(string)($arguments['refType']??'');$refId=(int)($arguments['refId']??0);$target=null;
  if($refType||$refId){
    if(!$refType||!$refId)mcpJsonError(400,'invalid_reference','refType and refId must be supplied together');
    Security::checkValidClass($refType);mcpRequireClassOperation($refType,'update');$target=new $refType($refId);
    if(!$target->id||!Security::checkValidAccessForUser($target,'update',null,null,false))mcpJsonError(403,'forbidden','Mail reference target is unavailable');
    if(empty($arguments['expectedVersion']))mcpJsonError(400,'expected_version_required','expectedVersion is required with a mail reference target');
    if(!hash_equals(mcpObjectVersion($target),(string)$arguments['expectedVersion']))mcpJsonError(409,'version_conflict','Mail reference target has changed');
  }elseif(securityGetAccessRightYesNo('menuAdmin','read')!=='YES')mcpJsonError(403,'forbidden','Direct mail without a reference target requires administration access');
  if(!empty($arguments['saveAsNote'])){
    if(!$target)mcpJsonError(400,'invalid_reference','saveAsNote requires a reference target');
    mcpRequireClassOperation('Note','create');$note=new Note();
    if(!Security::checkValidAccessForUser($note,'create',null,null,false))mcpJsonError(403,'forbidden','Note creation access is denied');
  }
  $domains=array();foreach($arguments['recipients']??array() as $recipient){$parts=explode('@',(string)$recipient);if(count($parts)===2)$domains[strtolower($parts[1])]=true;}
  return array('recipientCount'=>count($arguments['recipients']??array()),'recipientDomains'=>array_keys($domains),'subjectLength'=>mb_strlen((string)($arguments['subject']??'')),'bodyBytes'=>strlen((string)($arguments['body']??'')),'delivery'=>'guarded_external','payloadRedacted'=>true);
}

function mcpToolsAttachmentAction(array $arguments,string $username,string $action): array {
  $result=mcpExecuteUploadAction($action,$arguments,$username);if($action!=='attachment.upload.commit')return $result;
  $allowed=array('id','refType','refId','fileName','description','type','idUser','creationDate','subDirectory','fileSize','mimeType','_version');$attachment=array();foreach($allowed as $field)if(array_key_exists($field,$result['attachment'])&&$result['attachment'][$field]!==null)$attachment[$field]=$result['attachment'][$field];$result['attachment']=$attachment;return $result;
}
function mcpToolsPreviewUploadAbort(array $arguments,string $username,string $action): array { $meta=mcpReadUpload((string)($arguments['uploadId']??''),$username); return array('uploadId'=>$meta['uploadId'],'fileName'=>$meta['fileName'],'receivedBytes'=>is_file(mcpUploadDataPath($meta['uploadId']))?filesize(mcpUploadDataPath($meta['uploadId'])):0,'effect'=>'discard_temporary_upload'); }
function mcpToolsCleanupImport(array $arguments,string $username,string $action): array { return mcpCleanupImportRun($username,$arguments); }
function mcpToolsPreviewImport(array $arguments,string $username,string $action): array { return mcpImportCleanupPreview($username,$arguments); }
