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

echo "Tools module contract: 31 actions, ".count($handlers)." mapped handlers, all checks passed\n";
