<?php
declare(strict_types=1);

function mcpActionRegistry(): array {
  $objectRef = array('type'=>'object','required'=>array('objectClass','id'),'properties'=>array('objectClass'=>array('type'=>'string'),'id'=>array('type'=>'integer')));
  return array(
    'object.copy'=>array('domain'=>'core','risk'=>'write','async'=>false,'schema'=>array('type'=>'object','required'=>array('objectClass','id'),'properties'=>$objectRef['properties'])),
    'workflow.transition'=>array('domain'=>'workflow','risk'=>'write','async'=>false,'schema'=>array('type'=>'object','required'=>array('objectClass','id','idStatus'),'properties'=>array('objectClass'=>array('type'=>'string'),'id'=>array('type'=>'integer'),'idStatus'=>array('type'=>'integer'),'expectedVersion'=>array('type'=>'string')))),
    'project.snapshot'=>array('domain'=>'project','risk'=>'read','async'=>true,'schema'=>array('type'=>'object','required'=>array('idProject'),'properties'=>array('idProject'=>array('type'=>'integer'),'sections'=>array('type'=>'array')))),
    'planning.calculate'=>array('domain'=>'planning','risk'=>'write','async'=>true,'schema'=>array('type'=>'object','required'=>array('projectIds'),'properties'=>array('projectIds'=>array('type'=>'array'),'startDate'=>array('type'=>'string'),'criticalPath'=>array('type'=>'boolean'),'allowOverbooking'=>array('type'=>'boolean'),'criticalResourceMode'=>array('type'=>'boolean')))),
    'planning.diagnostics'=>array('domain'=>'planning','risk'=>'read','async'=>false,'schema'=>array('type'=>'object','required'=>array('idProject'),'properties'=>array('idProject'=>array('type'=>'integer')))),
    'planning.baseline.create'=>array('domain'=>'planning','risk'=>'write','async'=>true,'schema'=>array('type'=>'object','required'=>array('idProject','name'),'properties'=>array('idProject'=>array('type'=>'integer'),'name'=>array('type'=>'string'),'date'=>array('type'=>'string'),'privacy'=>array('type'=>'integer')))),
    'planning.baseline.delete'=>array('domain'=>'planning','risk'=>'destructive','async'=>false,'schema'=>array('type'=>'object','required'=>array('id'),'properties'=>array('id'=>array('type'=>'integer'),'expectedVersion'=>array('type'=>'string')))),
    'import.start'=>array('domain'=>'exchange','risk'=>'write','async'=>true,'schema'=>array('type'=>'object','required'=>array('uploadId','objectClass'),'properties'=>array('uploadId'=>array('type'=>'string'),'objectClass'=>array('type'=>'string'),'importRunId'=>array('type'=>'string')))),
    'import.cleanup'=>array('domain'=>'exchange','risk'=>'destructive','async'=>false,'schema'=>array('type'=>'object','required'=>array('importRunId'),'properties'=>array('importRunId'=>array('type'=>'string'),'force'=>array('type'=>'boolean')))),
    'export.start'=>array('domain'=>'exchange','risk'=>'read','async'=>true,'schema'=>array('type'=>'object','required'=>array('objectClass'),'properties'=>array('objectClass'=>array('type'=>'string'),'filter'=>array('type'=>'object'),'format'=>array('enum'=>array('json','ndjson','csv'))))),
    'report.start'=>array('domain'=>'report','risk'=>'read','async'=>true,'schema'=>array('type'=>'object','required'=>array('idReport'),'properties'=>array('idReport'=>array('type'=>'integer'),'parameters'=>array('type'=>'object')))),
    'attachment.upload.begin'=>array('domain'=>'document','risk'=>'write','async'=>false,'schema'=>array('type'=>'object','required'=>array('refType','refId','fileName','expectedBytes'),'properties'=>array('refType'=>array('type'=>'string'),'refId'=>array('type'=>'integer'),'fileName'=>array('type'=>'string'),'mimeType'=>array('type'=>'string'),'expectedBytes'=>array('type'=>'integer'),'description'=>array('type'=>'string')))),
    'attachment.upload.chunk'=>array('domain'=>'document','risk'=>'write','async'=>false,'schema'=>array('type'=>'object','required'=>array('uploadId','offset','base64'),'properties'=>array('uploadId'=>array('type'=>'string'),'offset'=>array('type'=>'integer'),'base64'=>array('type'=>'string')))),
    'attachment.upload.commit'=>array('domain'=>'document','risk'=>'write','async'=>false,'schema'=>array('type'=>'object','required'=>array('uploadId'),'properties'=>array('uploadId'=>array('type'=>'string')))),
    'attachment.upload.abort'=>array('domain'=>'document','risk'=>'destructive','async'=>false,'schema'=>array('type'=>'object','required'=>array('uploadId'),'properties'=>array('uploadId'=>array('type'=>'string')))),
    'user.trigger_password_reset'=>array('domain'=>'administration','risk'=>'external','async'=>false,'schema'=>array('type'=>'object','required'=>array('idUser'),'properties'=>array('idUser'=>array('type'=>'integer')))),
    'cron.check'=>array('domain'=>'administration','risk'=>'read','async'=>false,'schema'=>array('type'=>'object','properties'=>array())),
    'cron.start'=>array('domain'=>'administration','risk'=>'administrative','async'=>true,'schema'=>array('type'=>'object','properties'=>array())),
    'cron.stop'=>array('domain'=>'administration','risk'=>'administrative','async'=>false,'schema'=>array('type'=>'object','properties'=>array())),
    'cron.restart'=>array('domain'=>'administration','risk'=>'administrative','async'=>true,'schema'=>array('type'=>'object','properties'=>array()))
  );
}

function mcpActionAvailable(string $action): bool {
  if (str_starts_with($action,'cron.') || $action==='user.trigger_password_reset') {
    return securityGetAccessRightYesNo('menuAdmin','read') === 'YES';
  }
  return true;
}

function mcpHandleListActions(array $input): never {
  $domain=$input['domain']??null; $availableOnly=(bool)($input['availableOnly']??true); $items=array();
  foreach(mcpActionRegistry() as $id=>$action){
    if($domain && $action['domain']!==$domain)continue;
    $available=mcpActionAvailable($id);
    if($availableOnly&&!$available)continue;
    $items[]=array('action'=>$id,'domain'=>$action['domain'],'risk'=>$action['risk'],'async'=>$action['async'],'available'=>$available,'requiresConfirmation'=>in_array($action['risk'],array('destructive','administrative','external'),true));
  }
  mcpJsonResponse(array('returned'=>count($items),'items'=>$items));
}

function mcpHandleActionSchema(string $action): never {
  $registry=mcpActionRegistry(); if(!isset($registry[$action]))mcpJsonError(404,'action_not_found',"Action '$action' is not registered");
  mcpJsonResponse(array_merge(array('action'=>$action),$registry[$action]));
}

function mcpQueueJob(string $username,string $action,array $arguments): array {
  $id=mcpOperationInsert($username,$action,'queued',array('action'=>$action,'arguments'=>$arguments),null,date('Y-m-d H:i:s',time()+30*86400));
  return array('ok'=>true,'queued'=>true,'job'=>array('id'=>$id,'type'=>$action,'status'=>'queued','progress'=>0,'resultResource'=>'projeqtor://jobs/'.$id.'/result'));
}

function mcpPlanningDiagnostics(int $idProject): array {
  $project=new Project($idProject); if(!$project->id||!Security::checkValidAccessForUser($project,'read',null,null,false))mcpJsonError(404,'project_not_found','Project is unavailable');
  $pe=new PlanningElement(); $where='idProject='.Sql::fmtId($idProject).' and (notPlannedWork>0 or surbooked=1)';
  $elements=array_map(fn($item)=>array('id'=>(int)$item->id,'refType'=>$item->refType,'refId'=>(int)$item->refId,'name'=>$item->refName,'notPlannedWork'=>(float)$item->notPlannedWork,'unscheduledReason'=>(float)$item->notPlannedWork>0?((float)$item->assignedWork<=0?'no_assignment':'capacity_or_constraint'):null,'surbooked'=>(bool)$item->surbooked,'plannedStartDate'=>$item->plannedStartDate,'plannedEndDate'=>$item->plannedEndDate),$pe->getSqlElementsFromCriteria(null,false,$where,'id asc',false,true));
  $ass=new Assignment(); $assignments=array_map(fn($item)=>array('id'=>(int)$item->id,'refType'=>$item->refType,'refId'=>(int)$item->refId,'idResource'=>(int)$item->idResource,'notPlannedWork'=>(float)$item->notPlannedWork,'unscheduledReason'=>(float)$item->notPlannedWork>0?'resource_capacity_calendar_or_constraint':null,'surbooked'=>(bool)$item->surbooked,'leftWork'=>(float)$item->leftWork,'plannedWork'=>(float)$item->plannedWork),$ass->getSqlElementsFromCriteria(array('idProject'=>$idProject),false,null,'id asc',false,true));
  $pw=new PlannedWork(); $overloads=array_map(fn($item)=>array('id'=>(int)$item->id,'idResource'=>(int)$item->idResource,'refType'=>$item->refType,'refId'=>(int)$item->refId,'workDate'=>$item->workDate,'work'=>(float)$item->work,'surbookedWork'=>(float)$item->surbookedWork),$pw->getSqlElementsFromCriteria(null,false,'idProject='.Sql::fmtId($idProject).' and surbooked=1','workDate asc,id asc',false,true));
  return array('ok'=>true,'idProject'=>$idProject,'needsReplan'=>(bool)($project->ProjectPlanningElement->needReplan??false),'elements'=>$elements,'assignments'=>$assignments,'overloads'=>$overloads,'counts'=>array('elements'=>count($elements),'assignments'=>count($assignments),'overloads'=>count($overloads)));
}

function mcpUploadDirectory(): string { $path='/var/lib/projeqtor/mcp-uploads'; if(!is_dir($path))mkdir($path,0700,true); return $path; }
function mcpUploadMetaPath(string $id): string { if(!preg_match('/^[a-f0-9]{48}$/D',$id))mcpJsonError(400,'invalid_upload','Invalid upload id'); return mcpUploadDirectory().'/'.$id.'.json'; }
function mcpUploadDataPath(string $id): string { return mcpUploadDirectory().'/'.$id.'.part'; }
function mcpReadUpload(string $id,string $username): array { $path=mcpUploadMetaPath($id); if(!is_file($path))mcpJsonError(404,'upload_not_found','Upload session was not found'); $meta=json_decode((string)file_get_contents($path),true); if(!is_array($meta)||($meta['username']??'')!==$username||strtotime((string)$meta['expiresAt'])<time())mcpJsonError(403,'upload_unavailable','Upload session is unavailable'); return $meta; }

function mcpExecuteUploadAction(string $action,array $arguments,string $username): array {
  if($action==='attachment.upload.begin'){
    mcpRequireKeys($arguments,array('refType','refId','fileName','expectedBytes')); $refType=(string)$arguments['refType']; $refId=(int)$arguments['refId']; Security::checkValidClass($refType); $ref=new $refType($refId);
    if(!$ref->id||!Security::checkValidAccessForUser($ref,'update',null,null,false))mcpJsonError(403,'forbidden','Attachment target is unavailable');
    $max=(int)Parameter::getGlobalParameter('paramAttachmentMaxSize')*1024*1024; $expected=(int)$arguments['expectedBytes']; if($expected<0||($max>0&&$expected>$max))mcpJsonError(413,'attachment_too_large','Attachment exceeds the configured limit');
    $id=bin2hex(random_bytes(24)); $fileName=Security::checkValidFileName((string)$arguments['fileName'],true,true); $meta=array('uploadId'=>$id,'username'=>$username,'refType'=>$refType,'refId'=>$refId,'fileName'=>$fileName,'mimeType'=>(string)($arguments['mimeType']??getMimeTypeFromFileName($fileName)),'description'=>(string)($arguments['description']??''),'expectedBytes'=>$expected,'createdAt'=>date(DATE_ATOM),'expiresAt'=>date(DATE_ATOM,time()+3600));
    file_put_contents(mcpUploadMetaPath($id),json_encode($meta),LOCK_EX); file_put_contents(mcpUploadDataPath($id),'',LOCK_EX); return array('ok'=>true,'upload'=>$meta,'chunkBytes'=>524288);
  }
  $id=(string)($arguments['uploadId']??''); $meta=mcpReadUpload($id,$username);
  if($action==='attachment.upload.chunk'){
    $binary=base64_decode((string)($arguments['base64']??''),true); if($binary===false||strlen($binary)>524288)mcpJsonError(400,'invalid_chunk','Chunk must be valid base64 and no larger than 524288 bytes');
    $path=mcpUploadDataPath($id); $offset=(int)($arguments['offset']??-1); $size=is_file($path)?filesize($path):0; if($offset!==$size)mcpJsonError(409,'upload_offset_conflict','Chunk offset does not match current upload size',array('expectedOffset'=>$size));
    $handle=fopen($path,'ab'); flock($handle,LOCK_EX); fwrite($handle,$binary); fflush($handle); flock($handle,LOCK_UN); fclose($handle); return array('ok'=>true,'uploadId'=>$id,'receivedBytes'=>$size+strlen($binary),'expectedBytes'=>(int)$meta['expectedBytes']);
  }
  if($action==='attachment.upload.abort'){ @unlink(mcpUploadDataPath($id)); @unlink(mcpUploadMetaPath($id)); return array('ok'=>true,'uploadId'=>$id,'status'=>'aborted'); }
  if($action==='attachment.upload.commit'){
    $source=mcpUploadDataPath($id); $size=is_file($source)?filesize($source):-1; if($size!==(int)$meta['expectedBytes'])mcpJsonError(409,'upload_incomplete','Upload byte count does not match expectedBytes',array('receivedBytes'=>$size));
    $attachment=new Attachment(); $attachment->refType=$meta['refType']; $attachment->refId=$meta['refId']; $attachment->fileName=$meta['fileName']; $attachment->description=$meta['description']; $attachment->type='file'; $attachment->idUser=getSessionUser()->id; $attachment->creationDate=date('Y-m-d H:i:s');
    Sql::beginTransaction(); $raw=$attachment->save(); if(getLastOperationStatus($raw)!=='OK'){Sql::rollbackTransaction();mcpJsonError(400,'attachment_save_failed',cleanApiMessage($raw));}
    $directory=rtrim(Parameter::getGlobalParameter('paramAttachmentDirectory'),'/').'/attachment_'.$attachment->id.'/'; if(!is_dir($directory))mkdir($directory,0770,true); $target=$directory.$meta['fileName'];
    if(!rename($source,$target)){Sql::rollbackTransaction();mcpJsonError(500,'attachment_move_failed','Unable to commit uploaded file');} try{Security::checkEvilFile($target);}catch(Throwable $error){Sql::rollbackTransaction();@unlink($target);mcpJsonError(400,'unsafe_attachment',cleanApiMessage($error->getMessage()));}
    $attachment->subDirectory=str_replace(Parameter::getGlobalParameter('paramAttachmentDirectory'),'${attachmentDirectory}',$directory); $attachment->fileSize=$size; $attachment->mimeType=$meta['mimeType']; $raw=$attachment->save(); if(getLastOperationStatus($raw)!=='OK'){Sql::rollbackTransaction();@unlink($target);mcpJsonError(400,'attachment_save_failed',cleanApiMessage($raw));}
    Sql::commitTransaction(); @unlink(mcpUploadMetaPath($id)); return array('ok'=>true,'attachment'=>mcpObjectArray(new Attachment($attachment->id)),'resource'=>'projeqtor://attachments/'.$attachment->id);
  }
  mcpJsonError(404,'action_not_found','Unknown upload action');
}

function mcpTriggerPasswordReset(int $idUser): array {
  if(securityGetAccessRightYesNo('menuAdmin','read')!=='YES')mcpJsonError(403,'forbidden','Administration access is required');
  if(Parameter::getGlobalParameter('passwordResetEnabled','NO')!=='YES')mcpJsonError(409,'password_reset_disabled','ProjeQtOr password reset is disabled');
  $user=new User($idUser); if(!$user->id||$user->idle||!$user->email)mcpJsonError(400,'user_unavailable','User is unavailable or has no email address');
  $token=bin2hex(random_bytes(32)); $existing=new PasswordResetRequest(); foreach($existing->getSqlElementsFromCriteria(array('idUser'=>$idUser)) as $item){if(!$item->used){$item->used=1;$item->save();}}
  $request=new PasswordResetRequest(); $request->idUser=$idUser; $request->email=$user->email; $request->requestDateTime=date('Y-m-d H:i:s'); $request->token=$token; $request->used=0; $raw=$request->save(); if(getLastOperationStatus($raw)!=='OK')mcpJsonError(500,'reset_request_failed',cleanApiMessage($raw));
  $url='http://projeqtor/view/resetPasswordChangeView.php?token='.urlencode($token); $instance=Parameter::getGlobalParameter('paramDbDisplayName'); $subject="[$instance] ".i18n('mailResetPasswordSubject'); $link='<a rel="noopener" href="'.$url.'" target="_blank">'.i18n('mailResetPasswordLink').'</a>'; $body=i18n('mailResetPasswordBody',array($user->name,$link,Parameter::getGlobalParameter('paramAdminMail')));
  if(!sendMail($user->email,$subject,$body)){mcpJsonError(502,'reset_email_failed','Password reset email could not be sent');}
  return array('ok'=>true,'idUser'=>$idUser,'status'=>'reset_email_sent');
}

function mcpImportCleanupPreview(string $username,array $arguments): array {
  mcpRequireKeys($arguments,array('importRunId'));$runId=(string)$arguments['importRunId'];
  $force=!empty($arguments['force']);$journal=mcpImportJournal($username,$runId);$items=array();$counts=array('total'=>0,'present'=>0,'missing'=>0,'modified'=>0,'inaccessible'=>0);
  foreach($journal['document']['items']??array() as $entry){
    $class=(string)($entry['objectClass']??'');$id=(int)($entry['id']??0);$counts['total']++;
    $policy=mcpClassPolicy($class);if(!$policy['supported']||!in_array('delete',$policy['operations'],true)){$counts['inaccessible']++;continue;}
    $object=new $class($id);$exists=(bool)$object->id;
    if(!$exists){$counts['missing']++;if(count($items)<200)$items[]=array('objectClass'=>$class,'id'=>$id,'status'=>'missing');continue;}
    $counts['present']++;$current=mcpObjectVersion($object);$modified=!empty($entry['version'])&&!hash_equals((string)$entry['version'],$current);
    $allowed=Security::checkValidAccessForUser($object,'delete',null,null,false);if($modified)$counts['modified']++;if(!$allowed)$counts['inaccessible']++;
    if(count($items)<200)$items[]=array('objectClass'=>$class,'id'=>$id,'name'=>$object->name??null,'createdVersion'=>$entry['version']??null,'currentVersion'=>$current,'modified'=>$modified,'deleteAllowed'=>$allowed);
  }
  return array('ok'=>true,'importRunId'=>$runId,'journalOperationId'=>(int)$journal['row']['id'],'force'=>$force,'counts'=>$counts,'truncated'=>$counts['total']>count($items),'items'=>$items);
}

function mcpCleanupImportRun(string $username,array $arguments): array {
  mcpRequireKeys($arguments,array('importRunId'));$runId=(string)$arguments['importRunId'];$force=!empty($arguments['force']);
  $preview=mcpImportCleanupPreview($username,$arguments);
  if($preview['counts']['inaccessible']>0)mcpJsonError(403,'cleanup_inaccessible','One or more import objects cannot be deleted',array('preview'=>$preview));
  if($preview['counts']['modified']>0&&!$force)mcpJsonError(409,'cleanup_modified','Objects changed after import; prepare a second cleanup with force=true to remove them',array('preview'=>$preview));
  $journal=mcpImportJournal($username,$runId);$document=$journal['document'];$results=array();Sql::beginTransaction();
  foreach(array_reverse($document['items']??array()) as $entry){
    $class=(string)($entry['objectClass']??'');$id=(int)($entry['id']??0);mcpRequireClassOperation($class,'delete');$object=new $class($id);
    if(!$object->id){$results[]=array('objectClass'=>$class,'id'=>$id,'status'=>'missing');continue;}
    if(!Security::checkValidAccessForUser($object,'delete',null,null,false)){Sql::rollbackTransaction();mcpJsonError(403,'cleanup_inaccessible',"Delete access is denied for $class #$id");}
    if(!$force&&!empty($entry['version'])&&!hash_equals((string)$entry['version'],mcpObjectVersion($object))){Sql::rollbackTransaction();mcpJsonError(409,'cleanup_modified',"$class #$id changed after import");}
    SqlElement::setDeleteConfirmed();$raw=$object->delete();if(getLastOperationStatus($raw)!=='OK'){Sql::rollbackTransaction();mcpJsonError(400,'cleanup_delete_failed',cleanApiMessage($raw),array('objectClass'=>$class,'id'=>$id));}
    $results[]=array('objectClass'=>$class,'id'=>$id,'status'=>'deleted');
  }
  $document['cleanup']=array('completedAt'=>date(DATE_ATOM),'force'=>$force,'deleted'=>count(array_filter($results,fn($item)=>$item['status']==='deleted')),'actor'=>$username);
  Sql::query('UPDATE mcpoperation SET result_json='.Sql::str(json_encode($document)).',updated_at=CURRENT_TIMESTAMP WHERE id='.Sql::fmtId($journal['row']['id']));
  Sql::commitTransaction();
  return array('ok'=>true,'importRunId'=>$runId,'journalOperationId'=>(int)$journal['row']['id'],'force'=>$force,'items'=>$results,'counts'=>array('deleted'=>count(array_filter($results,fn($item)=>$item['status']==='deleted')),'missing'=>count(array_filter($results,fn($item)=>$item['status']==='missing'))));
}

function mcpExecuteActionValue(string $action,array $arguments,string $username,bool $allowGuarded=false): array {
  $registry=mcpActionRegistry(); if(!isset($registry[$action]))mcpJsonError(404,'action_not_found',"Action '$action' is not registered"); if(!mcpActionAvailable($action))mcpJsonError(403,'forbidden','Action is unavailable');
  $risk=$registry[$action]['risk']; if(in_array($risk,array('destructive','administrative','external'),true)&&!$allowGuarded)mcpJsonError(409,'guarded_action_required','This action requires prepare_action and commit_action');
  if(str_starts_with($action,'attachment.upload.'))return mcpExecuteUploadAction($action,$arguments,$username);
  if($registry[$action]['async'])return mcpQueueJob($username,$action,$arguments);
  if($action==='cron.check')return array('ok'=>true,'cronStatus'=>Cron::check());
  if($action==='cron.stop'){Cron::setStopFlag();return array('ok'=>true,'cronStatus'=>'stopping');}
  if($action==='planning.diagnostics')return mcpPlanningDiagnostics((int)($arguments['idProject']??0));
  if($action==='object.copy'){mcpRequireKeys($arguments,array('objectClass','id'));$class=(string)$arguments['objectClass'];mcpRequireClassOperation($class,'create');$source=new $class((int)$arguments['id']);if(!$source->id||!Security::checkValidAccessForUser($source,'read',null,null,false)||!Security::checkValidAccessForUser(new $class(),'create',null,null,false))mcpJsonError(403,'forbidden','Copy access is denied');Sql::beginTransaction();$copy=$source->copy();if(!$copy||!$copy->id){Sql::rollbackTransaction();mcpJsonError(400,'copy_failed','Object copy failed');}Sql::commitTransaction();return array('ok'=>true,'source'=>array('objectClass'=>$class,'id'=>(int)$source->id),'copy'=>mcpObjectArray($copy));}
  if($action==='workflow.transition'){mcpRequireKeys($arguments,array('objectClass','id','idStatus'));$operation=array('action'=>'update','objectClass'=>$arguments['objectClass'],'id'=>(int)$arguments['id'],'expectedVersion'=>$arguments['expectedVersion']??null,'data'=>array('idStatus'=>(int)$arguments['idStatus']));return mcpExecuteOperationsArray(array($operation),'atomic',false);}
  if($action==='planning.baseline.delete'){$baseline=new Baseline((int)($arguments['id']??0));if(!$baseline->id||$baseline->idUser!=getSessionUser()->id)mcpJsonError(403,'forbidden','Only the baseline owner may delete it');if(!empty($arguments['expectedVersion'])&&!hash_equals(mcpObjectVersion($baseline),(string)$arguments['expectedVersion']))mcpJsonError(409,'version_conflict','Baseline has changed');Sql::beginTransaction();$raw=$baseline->deleteWithPlanning();if(getLastOperationStatus($raw)!=='OK'){Sql::rollbackTransaction();mcpJsonError(400,'baseline_delete_failed',cleanApiMessage($raw));}Sql::commitTransaction();return array('ok'=>true,'id'=>(int)$arguments['id'],'status'=>'deleted');}
  if($action==='import.cleanup')return mcpCleanupImportRun($username,$arguments);
  if($action==='user.trigger_password_reset')return mcpTriggerPasswordReset((int)($arguments['idUser']??0));
  mcpJsonError(501,'action_not_implemented',"Action '$action' is registered but has no executor");
}

function mcpHandleExecuteAction(array $input,string $username): never { mcpRequireKeys($input,array('action')); mcpJsonResponse(mcpExecuteActionValue((string)$input['action'],is_array($input['arguments']??null)?$input['arguments']:array(),$username,false)); }
function mcpHandlePrepareAction(array $input,string $username): never {
  mcpRequireKeys($input,array('action'));
  $action=(string)$input['action'];$args=is_array($input['arguments']??null)?$input['arguments']:array();
  $registry=mcpActionRegistry();
  if(!isset($registry[$action]))mcpJsonError(404,'action_not_found','Action is not registered');
  if(!mcpActionAvailable($action))mcpJsonError(403,'forbidden','Action is unavailable');
  if(!in_array($registry[$action]['risk'],array('destructive','administrative','external'),true))mcpJsonError(400,'confirmation_not_required','This action does not require confirmation');
  $preview=$action==='import.cleanup'?mcpImportCleanupPreview($username,$args):null;
  $nonce=bin2hex(random_bytes(24));$expires=time()+MCP_V2_CONFIRM_SECONDS;
  $id=mcpOperationInsert($username,'guarded.action','prepared',array('action'=>$action,'arguments'=>$args),$nonce,date('Y-m-d H:i:s',$expires));
  $token=mcpSignConfirmation($username,'action',array('operationId'=>$id,'action'=>$action,'arguments'=>$args),$nonce,$expires);
  mcpJsonResponse(array('ok'=>true,'operationId'=>$id,'action'=>$action,'risk'=>$registry[$action]['risk'],'arguments'=>$args,'preview'=>$preview,'expiresAt'=>date(DATE_ATOM,$expires),'confirmationToken'=>$token));
}
function mcpHandleCommitAction(array $input,string $username): never { mcpRequireKeys($input,array('confirmationToken'));$verified=mcpVerifyConfirmation((string)$input['confirmationToken'],$username,'action');$payload=$verified['document']['payload'];$result=mcpExecuteActionValue((string)$payload['action'],is_array($payload['arguments']??null)?$payload['arguments']:array(),$username,true);Sql::query('UPDATE mcpoperation SET status=' . Sql::str('succeeded') . ', result_json=' . Sql::str(json_encode($result)) . ', completed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=' . $verified['operationId'] . " AND status='prepared'");mcpJsonResponse(array_merge($result,array('operationId'=>$verified['operationId']))); }
