<?php
declare(strict_types=1);

require_once dirname(__DIR__,3).'/core/module-registry.php';
$module=require dirname(__DIR__).'/module.php';

function expect(bool $condition,string $message): void {
  if(!$condition){fwrite(STDERR,"FAIL: $message\n");exit(1);}
}

expect($module['id']==='tools','module id');
expect($module['dependencies']===array('core','configuration'),'module dependencies');
expect(count($module['actions'])===31,'Tools exposes 31 canonical actions');

$legacy=array('import.start','import.cleanup','export.start','attachment.upload.begin','attachment.upload.chunk','attachment.upload.commit','attachment.upload.abort');
foreach($legacy as $action)expect(isset($module['actions'][$action]),"legacy action $action remains registered");

$families=array('document','attachment','note','link','clone','notification','mail','automation','localization','asset','import','export');
foreach($families as $family)expect(count(array_filter(array_keys($module['actions']),fn($id)=>str_contains($id,$family)))>0,"$family workflow family is represented");

$handlers=array();
foreach($module['actions'] as $id=>$action){
  foreach(array('schema','resultSchema','risk','async','retryPolicy','idempotency','transaction','testContract') as $field)expect(array_key_exists($field,$action),"$id declares $field");
  expect(($action['idempotency']['scope']??null)==='actor',"$id idempotency is actor scoped");
  expect(($action['idempotency']['conflictOnDifferentBody']??false)===true,"$id rejects idempotency body conflicts");
  expect(is_callable($action[$action['async']?'worker':'executor']),"$id executor exists");
  foreach($action['mappedHandlers']??array() as $handler){expect(!isset($handlers[$handler]),"$handler has one semantic owner");$handlers[$handler]=$id;}
}

foreach(array('tools.document.version.delete','tools.attachment.delete','tools.note.delete','tools.link.delete','tools.relationship.unlink','tools.notification.unsubscribe','import.cleanup','attachment.upload.abort') as $id){
  expect($module['actions'][$id]['risk']==='destructive',"$id is guarded destructive");
  expect(isset($module['actions'][$id]['preview']),"$id has a preview callback");
}
expect($module['actions']['import.cleanup']['async']===true,'import cleanup executes in the worker');
expect($module['actions']['import.cleanup']['retryPolicy']==='recovery_required','interrupted import cleanup is never silently replayed');
foreach(array('tools.notification.send','tools.mail.send') as $id){
  expect($module['actions'][$id]['risk']==='external',"$id is guarded external");
  expect($module['actions'][$id]['async']===true,"$id executes in the worker");
  expect(isset($module['actions'][$id]['preview']),"$id redacts a preview");
  expect($module['actions'][$id]['retryPolicy']==='recovery_required',"$id cannot silently replay delivery");
}

foreach(array('tools.document.manage','tools.note.manage','tools.link.manage','tools.relationship.link','tools.clone.start','tools.notification.status','tools.notification.subscribe','tools.notification.send','tools.automation.manage','tools.localization.manage','tools.asset.manage') as $id){
  $schema=$module['actions'][$id]['schema'];$properties=$schema['properties'];$bounded=$properties['operations']??$properties['items']??null;
  expect(is_array($bounded)&&($bounded['maxItems']??null)===200,"$id enforces the 200-item maximum");
}

$encoded=json_encode($module,JSON_UNESCAPED_SLASHES);
foreach(array('password','apiKey','oauthSecret','smtpPassword','credential') as $secret)expect(stripos($encoded,$secret)===false,"secret field $secret is not accepted");

function expectClosedObjects(array $schema,string $path): void {
  $types=$schema['type']??null;$types=is_array($types)?$types:array($types);
  if(in_array('object',$types,true))expect(($schema['additionalProperties']??null)===false,"$path is a closed object contract");
  foreach($schema as $key=>$value)if(is_array($value)){
    if(isset($value['type'])||isset($value['properties'])||isset($value['items']))expectClosedObjects($value,$path.'.'.$key);
    elseif(array_is_list($value))foreach($value as $index=>$entry)if(is_array($entry))expectClosedObjects($entry,$path.'.'.$key.'['.$index.']');
  }
}
foreach($module['actions'] as $id=>$action){expectClosedObjects($action['schema'],$id.'.input');expectClosedObjects($action['resultSchema'],$id.'.result');}
foreach(array('tools.document.manage','tools.document.rights','tools.note.manage','tools.link.manage','tools.clone.schedule','tools.automation.manage','tools.localization.manage','tools.asset.manage') as $id){
  $operation=$module['actions'][$id]['schema']['properties']['operations']['items'];
  expect(($operation['properties']['data']['additionalProperties']??true)===false,"$id data contract is closed");
  expect(isset($operation['allOf']),"$id publishes conditional update version requirements");
}
foreach(array('tools.document.version.delete','tools.attachment.delete','tools.note.delete','tools.link.delete') as $id)expect(in_array('expectedVersion',$module['actions'][$id]['schema']['properties']['items']['items']['required'],true),"$id requires expectedVersion");
foreach(array('tools.relationship.link','tools.relationship.unlink','tools.notification.status') as $id)expect(in_array('expectedVersion',$module['actions'][$id]['schema']['properties']['items']['items']['required'],true),"$id requires expectedVersion");
expect(in_array('expectedVersion',$module['actions']['tools.clone.start']['schema']['properties']['items']['items']['required'],true),'clone source requires expectedVersion');
expect(in_array('expectedVersion',$module['actions']['tools.notification.unsubscribe']['schema']['properties']['items']['items']['required'],true),'unsubscribe requires Subscription expectedVersion');
expect(in_array('expectedTargetVersion',$module['actions']['tools.notification.subscribe']['schema']['properties']['items']['items']['required'],true),'subscribe binds the target version');
expect(in_array('expectedDocumentVersion',$module['actions']['tools.document.version']['schema']['required'],true),'document version creation binds the parent version');
expect(isset($module['actions']['tools.mail.send']['schema']['allOf']),'mail publishes conditional referenced-target version requirement');
expect($module['actions']['tools.image.upload.commit']['risk']==='administrative','image commit is guarded');
expect(isset($module['actions']['tools.image.upload.commit']['preview']),'image commit has an actor-bound preview path');
expect(($module['actions']['tools.image.upload.begin']['schema']['properties']['expectedBytes']['maximum']??0)===26214400,'image schema has a hard byte ceiling');
$actionsSource=file_get_contents(dirname(__DIR__).'/actions.php');$workerSource=file_get_contents(dirname(__DIR__).'/worker.php');$imageSource=file_get_contents(dirname(__DIR__).'/image-upload.php');
expect(str_contains($actionsSource,"mcpToolsSemanticOnlyClasses(): array { return array('DataCloning','EventForMail','LocalizationTranslatorLanguage'); }"),'semantic-only persistence classes use a named module path');
expect(str_contains($actionsSource,'Generic CRUD remains denied'),'semantic path documents the generic CRUD boundary');
$policy=json_decode(file_get_contents(dirname(__DIR__,3).'/class-policy-v3.json'),true);foreach(array('DataCloning','EventForMail','LocalizationTranslatorLanguage') as $class)expect(($policy['classes'][$class]['operations']??null)===array(),"$class remains denied through generic CRUD policy");
expect(str_contains($workerSource,"mcpRequireClassOperation('Note','create')"),'saveAsNote checks Note create policy');
expect(str_contains($workerSource,"Security::checkValidAccessForUser(\$probe,'create'"),'saveAsNote checks native Note create permission');
expect(str_contains($actionsSource,"Security::checkValidAccessForUser(\$object,'delete'"),'delete previews enforce object-level delete permission before metadata');
expect(str_contains($actionsSource,"Security::checkValidAccessForUser(\$target,'update'"),'mail previews enforce referenced-target update permission');
expect(str_contains($actionsSource,"mcpToolsRequireParentRead('User'"),'notification previews validate recipient visibility');
expect(substr_count($workerSource,'workerCancelled($jobId)')>=5,'asynchronous Tools workers cooperatively check cancellation');
foreach(array('MCP_TOOLS_IMAGE_HARD_MAX_BYTES','workerCancelled','FILEINFO_MIME_TYPE','getimagesize','MCP_TOOLS_IMAGE_MAX_PIXELS','is_link','rawurlencode','mcpToolsRequireImageUploadPermission') as $guard)expect(str_contains($imageSource,$guard),"image upload includes $guard guard");
expect(!str_contains($imageSource,"'svg'"),'active SVG uploads are not accepted');

echo "Tools module contract: 31 actions, ".count($handlers)." mapped handlers, closed schemas and hardening checks passed\n";
