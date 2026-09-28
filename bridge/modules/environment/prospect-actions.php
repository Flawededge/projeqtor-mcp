<?php
declare(strict_types=1);

function mcpEnvironmentRequireMutation(object $object,string $operation,?string $expectedVersion=null): void {
  $class=get_class($object);
  // These child records remain blocked from generic CRUD. Their semantic
  // workflows still enforce ProjeQtOr's native object permission checks below.
  if(!in_array($class,array('ProspectEvent','OtherClient'),true))mcpRequireClassOperation($class,$operation);
  if(!(int)($object->id??0)||!Security::checkValidAccessForUser($object,$operation,null,null,false))mcpJsonError(403,'forbidden',"$class $operation access is denied");
  if($expectedVersion===null||$expectedVersion==='')mcpJsonError(409,'expected_version_required',"$class #".(int)$object->id." requires expectedVersion");
  $actual=mcpObjectVersion($object);if(!hash_equals($actual,$expectedVersion))mcpJsonError(409,'version_conflict',"$class #".(int)$object->id." has changed",array('expectedVersion'=>$expectedVersion,'actualVersion'=>$actual));
}
function mcpEnvironmentCreateAllowed(object $object): void {
  $class=get_class($object);if(!in_array($class,array('ProspectEvent','OtherClient'),true))mcpRequireClassOperation($class,'create');if(!Security::checkValidAccessForUser($object,'create',null,null,false))mcpJsonError(403,'forbidden',"$class create access is denied");
}
function mcpEnvironmentWorkflowPreview(array $arguments,string $username,string $action): array {
  return array('action'=>$action,'actor'=>$username,'itemCount'=>count($arguments['items']??array()),'payloadRedacted'=>true);
}
function mcpEnvironmentProspectEventAction(array $arguments,string $username,string $action): array {
  return mcpEnvironmentExecuteInternalBatch($arguments['items'],(string)($arguments['transactionMode']??'atomic'),function(array $entry): array {
    $operation=(string)$entry['operation'];$event=$operation==='create'?new ProspectEvent():new ProspectEvent((int)($entry['id']??0));
    if($operation!=='create')mcpEnvironmentRequireMutation($event,$operation==='delete'?'delete':'update',$entry['expectedVersion']??null);
    $refType=$operation==='create'?(string)$entry['refType']:(string)$event->refType;$refId=$operation==='create'?(int)$entry['refId']:(int)$event->refId;
    if(isset($entry['refType'])&&((string)$entry['refType']!==$refType||(int)$entry['refId']!==$refId))mcpJsonError(409,'prospect_event_parent_mismatch','Prospect event parent cannot change');
    if(!in_array($refType,array('Prospect','Contact'),true))mcpJsonError(400,'invalid_event_parent','Prospect events belong to Prospect or Contact');
    $parent=new $refType($refId);if(!$parent->id||!Security::checkValidAccessForUser($parent,$refType==='Prospect'?'update':'read',null,null,false))mcpJsonError(403,'forbidden','Prospect event parent is unavailable');
    if($refType==='Prospect'){if(empty($entry['parentExpectedVersion']))mcpJsonError(409,'expected_version_required',"Prospect #$refId requires parentExpectedVersion");$actual=mcpObjectVersion($parent);if(!hash_equals($actual,(string)$entry['parentExpectedVersion']))mcpJsonError(409,'version_conflict',"Prospect #$refId has changed",array('actualVersion'=>$actual));}
    if($operation==='delete'){$saved=mcpEnvironmentInternalSave($event,'delete',$entry['expectedVersion']??null);}
    else{
      foreach(array('name','eventTypeId','eventDateTime') as $field)if($operation==='create'&&!array_key_exists($field,$entry))mcpJsonError(400,'missing_field',"$field is required for ProspectEvent create",array('field'=>$field));
      $event->refType=$refType;$event->refId=$refId;$event->idContact=$refType==='Contact'?$refId:($entry['contactId']??null);if(!$event->idUser)$event->idUser=getCurrentUserId();
      if(array_key_exists('eventDateTime',$entry))$event->eventDateTime=(string)$entry['eventDateTime'];if(array_key_exists('name',$entry))$event->name=(string)$entry['name'];if(array_key_exists('eventTypeId',$entry))$event->idProspectEventType=(int)$entry['eventTypeId'];if(array_key_exists('description',$entry))$event->description=$entry['description'];$event->idle=0;
      if($operation==='create')mcpEnvironmentCreateAllowed($event);$saved=mcpEnvironmentInternalSave($event,$operation,$entry['expectedVersion']??null);
    }
    $effects=array(array('action'=>$saved['status']==='deleted'?'delete':($saved['status']==='created'?'create':'update'),'objectClass'=>'ProspectEvent','id'=>(int)$saved['id']));
    if($refType==='Prospect'){$probe=new ProspectEvent();$parent->lastEventDatetime=$probe->getMaxValueFromCriteria('eventDateTime',array('refType'=>'Prospect','refId'=>$refId));$parentSaved=mcpEnvironmentInternalSave($parent,'update',(string)$entry['parentExpectedVersion']);$effects[]=array('action'=>'update','objectClass'=>'Prospect','id'=>$refId);$saved['parentVersion']=$parentSaved['saved']['_version']??mcpObjectVersion($parent);}
    $saved['parentClass']=$refType;$saved['parentId']=$refId;$saved['effects']=$effects;return $saved;
  });
}
function mcpEnvironmentProspectTypeId(?int $requested): int {
  if($requested){$type=new ClientType($requested);if(!$type->id||!Security::checkValidAccessForUser($type,'read',null,null,false))mcpJsonError(403,'forbidden','Client type is unavailable');return (int)$type->id;}
  $type=new ClientType();$types=$type->getSqlElementsFromCriteria(null,null,"(name like '%Prospect%') and idle=0",'sortOrder asc,id asc');if(!$types)$types=$type->getSqlElementsFromCriteria(array('idle'=>'0'),false,null,'sortOrder asc,id asc');if(!$types)mcpJsonError(409,'client_type_unavailable','No active ClientType is available');return (int)$types[0]->id;
}
function mcpEnvironmentProspectLink(string $class,int $objectId,int $prospectId): array {
  $link=SqlElement::getSingleSqlElementFromCriteria('Link',array('ref1Type'=>$class,'ref1Id'=>$objectId,'ref2Type'=>'Prospect','ref2Id'=>$prospectId));if($link->id)return array((int)$link->id,false);
  $link=new Link();$link->ref1Type=$class;$link->ref1Id=$objectId;$link->ref2Type='Prospect';$link->ref2Id=$prospectId;mcpEnvironmentCreateAllowed($link);$saved=mcpEnvironmentInternalSave($link,'create');return array((int)$saved['id'],true);
}
function mcpEnvironmentProspectConvertAction(array $arguments,string $username,string $action): array {
  return mcpEnvironmentExecuteInternalBatch($arguments['items'],(string)($arguments['transactionMode']??'atomic'),function(array $entry): array {
    $prospect=new Prospect((int)$entry['prospectId']);mcpEnvironmentRequireMutation($prospect,'update',(string)$entry['expectedVersion']);$clientId=null;$contactId=null;$linkIds=array();$effects=array();
    if(!empty($prospect->prospectNameCompany)){
      $linked=SqlElement::getSingleSqlElementFromCriteria('Link',array('ref1Type'=>'Client','ref2Type'=>'Prospect','ref2Id'=>(int)$prospect->id));$client=$linked->id?new Client((int)$linked->ref1Id):new Client();
      if(!$client->id){$client->name=(string)$prospect->prospectNameCompany;$client->idClientType=mcpEnvironmentProspectTypeId(isset($entry['clientTypeId'])?(int)$entry['clientTypeId']:null);foreach(array('designation','street','complement','zip','city','state','country') as $field)$client->$field=$prospect->$field??null;$client->fillRequiredFields();mcpEnvironmentCreateAllowed($client);$clientSaved=mcpEnvironmentInternalSave($client,'create');$clientId=(int)$clientSaved['id'];$effects[]=array('action'=>'create','objectClass'=>'Client','id'=>$clientId);[$linkId,$created]=mcpEnvironmentProspectLink('Client',$clientId,(int)$prospect->id);$linkIds[]=$linkId;if($created)$effects[]=array('action'=>'create','objectClass'=>'Link','id'=>$linkId);}else{$clientId=(int)$client->id;$linkIds[]=(int)$linked->id;}
    }
    if(!empty($prospect->prospectNameContact)){
      $linked=SqlElement::getSingleSqlElementFromCriteria('Link',array('ref1Type'=>'Contact','ref2Type'=>'Prospect','ref2Id'=>(int)$prospect->id));$contact=$linked->id?new Contact((int)$linked->ref1Id):new Contact();
      if(!$contact->id){$contact->name=(string)$prospect->prospectNameContact;$contact->email=$prospect->email??null;$contact->contactFunction=$prospect->prospectFunction??null;$contact->phone=$prospect->phone??null;$contact->mobile=$prospect->mobile??null;$contact->fax=$prospect->fax??null;$contact->idClient=$clientId;foreach(array('designation','street','complement','zip','city','state','country') as $field)$contact->$field=$prospect->$field??null;$contact->fillRequiredFields();mcpEnvironmentCreateAllowed($contact);$contactSaved=mcpEnvironmentInternalSave($contact,'create');$contactId=(int)$contactSaved['id'];$effects[]=array('action'=>'create','objectClass'=>'Contact','id'=>$contactId);[$linkId,$created]=mcpEnvironmentProspectLink('Contact',$contactId,(int)$prospect->id);$linkIds[]=$linkId;if($created)$effects[]=array('action'=>'create','objectClass'=>'Link','id'=>$linkId);}else{$contactId=(int)$contact->id;$linkIds[]=(int)$linked->id;}
    }
    if(!$clientId&&!$contactId)mcpJsonError(409,'prospect_conversion_empty','Prospect has no company or contact name to convert');$prospect->lastEventDatetime=date('Y-m-d H:i:s');$saved=mcpEnvironmentInternalSave($prospect,'update',(string)$entry['expectedVersion']);$effects[]=array('action'=>'update','objectClass'=>'Prospect','id'=>(int)$prospect->id);
    return array('status'=>'updated','objectClass'=>'Prospect','id'=>(int)$prospect->id,'saved'=>$saved['saved'],'clientId'=>$clientId,'contactId'=>$contactId,'linkIds'=>$linkIds,'effects'=>$effects);
  });
}
function mcpEnvironmentClientPromoteAction(array $arguments,string $username,string $action): array {
  return mcpEnvironmentExecuteInternalBatch($arguments['items'],(string)($arguments['transactionMode']??'atomic'),function(array $entry): array {
    $relationship=new OtherClient((int)$entry['id']);mcpEnvironmentRequireMutation($relationship,'delete',(string)$entry['expectedVersion']);$class=(string)$relationship->refType;Security::checkValidClass($class);$target=new $class((int)$relationship->refId);mcpEnvironmentRequireMutation($target,'update',(string)$entry['targetExpectedVersion']);
    if(!property_exists($target,'idClient'))mcpJsonError(400,'invalid_client_target',"$class cannot have a primary client");$previous=(int)$target->idClient;$promoted=(int)$relationship->idClient;if(!$promoted)mcpJsonError(409,'client_relationship_invalid','OtherClient has no client to promote');
    $target->idClient=$promoted;$targetSaved=mcpEnvironmentInternalSave($target,'update',(string)$entry['targetExpectedVersion']);$deletedId=(int)$relationship->id;mcpEnvironmentInternalSave($relationship,'delete',(string)$entry['expectedVersion']);$replacementId=null;$effects=array(array('action'=>'update','objectClass'=>$class,'id'=>(int)$target->id),array('action'=>'delete','objectClass'=>'OtherClient','id'=>$deletedId));
    if($previous){$replacement=new OtherClient();$replacement->refType=$class;$replacement->refId=(int)$target->id;$replacement->creationDate=date('Y-m-d H:i:s');$replacement->idUser=(int)getSessionUser()->id;$replacement->idClient=$previous;mcpEnvironmentCreateAllowed($replacement);$replacementSaved=mcpEnvironmentInternalSave($replacement,'create');$replacementId=(int)$replacementSaved['id'];$effects[]=array('action'=>'create','objectClass'=>'OtherClient','id'=>$replacementId);}
    return array('status'=>'updated','objectClass'=>$class,'id'=>(int)$target->id,'saved'=>$targetSaved['saved'],'clientId'=>$promoted,'previousClientId'=>$previous?:null,'relationshipId'=>$replacementId,'effects'=>$effects);
  });
}
