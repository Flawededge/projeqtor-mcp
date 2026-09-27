<?php
declare(strict_types=1);

require_once __DIR__.'/image-upload.php';
require_once __DIR__.'/filtered-export.php';
require_once __DIR__.'/document-extract.php';
require_once __DIR__.'/document-copy.php';
function mcpToolsImportWorker(int $jobId,array $arguments,string $username): array { return workerImport($arguments,$username); }
function mcpToolsExportWorker(int $jobId,array $arguments,string $username): array { return mcpToolsFilteredExport($jobId,$arguments); }

function mcpToolsDocumentVersionWorker(int $jobId,array $arguments,string $username): array {
  $uploadId=(string)$arguments['uploadId'];$meta=mcpReadUpload($uploadId,$username);
  if(workerCancelled($jobId))throw new RuntimeException('cancelled');
  $document=new Document((int)$arguments['idDocument']);
  mcpRequireClassOperation('DocumentVersion','create');
  if(!$document->id||!Security::checkValidAccessForUser($document,'update',null,null,false))throw new RuntimeException('Document update access is denied');
  if(empty($arguments['expectedDocumentVersion']))throw new RuntimeException('expectedDocumentVersion is required');
  if(!hash_equals(mcpObjectVersion($document),(string)$arguments['expectedDocumentVersion']))throw new RuntimeException('Document version conflict');
  $source=mcpUploadDataPath($uploadId);$received=is_file($source)?filesize($source):-1;
  if($received!==(int)$meta['expectedBytes'])throw new RuntimeException('Document version upload is incomplete');
  Security::checkEvilFile($source);workerUpdate($jobId,'running',25);
  $version=new DocumentVersion();$version->idDocument=(int)$document->id;
  $version->version=(int)$arguments['version'];$version->revision=(int)$arguments['revision'];
  $version->draft=(int)($arguments['draft']??0);$version->versionDate=(string)$arguments['versionDate'];
  $version->idAuthor=(int)getSessionUser()->id;$version->idStatus=(int)($arguments['idStatus']??1);
  $version->description=(string)($arguments['description']??'');$version->isRef=!empty($arguments['isRef'])?1:0;
  $version->fileName=(string)$meta['fileName'];$version->mimeType=(string)$meta['mimeType'];
  $version->fileSize=$received;$version->importFile=$source;
  if(workerCancelled($jobId))throw new RuntimeException('cancelled');
  Sql::beginTransaction();$raw=$version->save();if(getLastOperationStatus($raw)!=='OK'){Sql::rollbackTransaction();throw new RuntimeException(cleanApiMessage($raw));}
  Sql::commitTransaction();@unlink(mcpUploadMetaPath($uploadId));if(is_file($source))@unlink($source);
  $saved=new DocumentVersion($version->id);return array(
    'ok'=>true,'items'=>array(array('status'=>'created','objectClass'=>'DocumentVersion','id'=>(int)$saved->id,'version'=>mcpObjectVersion($saved))),
    'resource'=>'projeqtor://document-versions/'.$saved->id,
    'effects'=>array(array('action'=>'create','objectClass'=>'DocumentVersion','id'=>(int)$saved->id))
  );
}

function mcpToolsCloneWorker(int $jobId,array $arguments,string $username): array {
  $items=array();$effects=array();$input=$arguments['items']??array();
  foreach($input as $index=>$entry){
    if(workerCancelled($jobId))throw new RuntimeException('cancelled');$class=(string)$entry['objectClass'];
    mcpRequireClassOperation($class,'read');mcpRequireClassOperation($class,'create');$source=new $class((int)$entry['id']);$empty=new $class();
    if(!$source->id||!Security::checkValidAccessForUser($source,'read',null,null,false)||!Security::checkValidAccessForUser($empty,'create',null,null,false))throw new RuntimeException("Copy access is denied for $class #".(int)$entry['id']);
    if(empty($entry['expectedVersion']))throw new RuntimeException("expectedVersion is required for $class #".(int)$entry['id']);
    if(!hash_equals(mcpObjectVersion($source),(string)$entry['expectedVersion']))throw new RuntimeException("Version conflict for $class #".(int)$entry['id']);
    $overrides=is_array($entry['overrides']??null)?$entry['overrides']:array();mcpToolsValidateSemanticParents($class,$overrides);Sql::beginTransaction();$related=array();
    try{$copy=$source->copy();if(!$copy||!$copy->id)throw new RuntimeException("Copy failed for $class #".(int)$entry['id']);if($overrides){mcpFillObject($copy,$overrides);$raw=$copy->save();if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));}if($class==='Document')$related=mcpToolsCopyDocumentVersions($source,$copy,(string)($entry['copyDocumentVersions']??'none'));Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
    $saved=new $class($copy->id);$items[]=array('index'=>$index,'status'=>'created','sourceId'=>(int)$source->id,'objectClass'=>$class,'id'=>(int)$saved->id,'relatedDocumentVersionIds'=>$related,'version'=>mcpObjectVersion($saved));$effects[]=array('action'=>'create','objectClass'=>$class,'id'=>(int)$saved->id);
    workerUpdate($jobId,'running',min(95,5+(int)(90*($index+1)/max(1,count($input)))));
  }
  return array('ok'=>true,'items'=>$items,'effects'=>$effects);
}

function mcpToolsNotificationWorker(int $jobId,array $arguments,string $username): array {
  mcpRequireClassOperation('Notification','create');$items=array();$effects=array();$input=$arguments['items']??array();
  foreach($input as $index=>$entry){
    if(workerCancelled($jobId))throw new RuntimeException('cancelled');$notification=new Notification();
    if(!Security::checkValidAccessForUser($notification,'create',null,null,false))throw new RuntimeException('Notification creation access is denied');
    $notification->idUser=(int)$entry['idUser'];$notification->idResource=(int)getSessionUser()->id;
    $notification->name=(string)$entry['title'];$notification->title=(string)$entry['title'];$notification->content=(string)$entry['content'];
    $notification->notificationDate=(string)$entry['notificationDate'];$notification->notificationTime=(string)($entry['notificationTime']??'');
    $notification->idNotificationType=(int)$entry['idNotificationType'];$notification->idStatusNotification=1;
    $notification->creationDateTime=date('Y-m-d H:i:s');$notification->sendEmail=!empty($entry['sendEmail'])?1:0;
    $raw=$notification->save();if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));
    $emailSent=false;if($notification->sendEmail){$user=new User($notification->idUser);if(!$user->id||!$user->email)throw new RuntimeException('Notification recipient has no email address');$emailSent=(bool)sendMail($user->email,$notification->title,$notification->content);if(!$emailSent)throw new RuntimeException('Notification email delivery failed');$notification->emailSent=1;$notification->save();}
    $items[]=array('index'=>$index,'status'=>'created','objectClass'=>'Notification','id'=>(int)$notification->id,'emailSent'=>$emailSent,'version'=>mcpObjectVersion(new Notification($notification->id)));$effects[]=array('action'=>'create','objectClass'=>'Notification','id'=>(int)$notification->id,'externalDelivery'=>$emailSent);
    workerUpdate($jobId,'running',min(95,5+(int)(90*($index+1)/max(1,count($input)))));
  }
  return array('ok'=>true,'items'=>$items,'effects'=>$effects);
}

function mcpToolsMailWorker(int $jobId,array $arguments,string $username): array {
  $refType=(string)($arguments['refType']??'');$refId=(int)($arguments['refId']??0);$target=null;
  if($refType||$refId){if(!$refType||!$refId)throw new RuntimeException('refType and refId must be supplied together');Security::checkValidClass($refType);mcpRequireClassOperation($refType,'update');$target=new $refType($refId);if(!$target->id||!Security::checkValidAccessForUser($target,'update',null,null,false))throw new RuntimeException('Mail reference target is unavailable');if(empty($arguments['expectedVersion']))throw new RuntimeException('expectedVersion is required with a mail reference target');if(!hash_equals(mcpObjectVersion($target),(string)$arguments['expectedVersion']))throw new RuntimeException('Mail reference target version conflict');}
  elseif(securityGetAccessRightYesNo('menuAdmin','read')!=='YES')throw new RuntimeException('Direct mail without a reference target requires administration access');
  if(!empty($arguments['saveAsNote'])){if(!$target)throw new RuntimeException('saveAsNote requires a reference target');mcpRequireClassOperation('Note','create');$probe=new Note();if(!Security::checkValidAccessForUser($probe,'create',null,null,false))throw new RuntimeException('Note creation access is denied');}
  $recipients=array_values(array_unique(array_map('trim',$arguments['recipients']??array())));foreach($recipients as $recipient)if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))throw new RuntimeException('A recipient address is invalid');
  $sent=array();foreach($recipients as $index=>$recipient){if(workerCancelled($jobId))throw new RuntimeException('cancelled');if(!sendMail($recipient,(string)$arguments['subject'],(string)$arguments['body']))throw new RuntimeException('Mail delivery failed');$sent[]=array('recipientHash'=>substr(hash('sha256',strtolower($recipient)),0,16),'status'=>'sent');workerUpdate($jobId,'running',min(95,5+(int)(90*($index+1)/max(1,count($recipients)))));}
  $noteId=null;if(!empty($arguments['saveAsNote'])&&$target){$note=new Note();$note->refType=$refType;$note->refId=$refId;$note->note=(string)$arguments['body'];$note->idPrivacy=1;$note->idUser=(int)getSessionUser()->id;$note->creationDate=date('Y-m-d H:i:s');$raw=$note->save();if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));$noteId=(int)$note->id;}
  $effect=array('action'=>'mail.send','recipientCount'=>count($sent));if($target)$effect['reference']=array('objectClass'=>$refType,'id'=>$refId);return array('ok'=>true,'items'=>$sent,'recipientCount'=>count($sent),'noteId'=>$noteId,'effects'=>array($effect));
}
