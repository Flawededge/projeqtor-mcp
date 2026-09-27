<?php
declare(strict_types=1);

function mcpToolsRelationshipField(string $parentClass): string {
  Security::checkValidClass($parentClass);return 'id'.$parentClass;
}

function mcpToolsRelationshipOperations(array $arguments,bool $unlink): array {
  $operations=array();
  foreach($arguments['items']??array() as $index=>$entry){
    if(empty($entry['expectedVersion']))mcpJsonError(400,'expected_version_required',"Relationship item $index requires expectedVersion");
    $class=(string)$entry['objectClass'];Security::checkValidClass($class);$field=mcpToolsRelationshipField((string)$entry['parentClass']);
    $target=new $class((int)$entry['id']);if(!$target->id||!Security::checkValidAccessForUser($target,'update',null,null,false))mcpJsonError(403,'forbidden','Linked object update access is denied');
    if(!property_exists($target,$field))mcpJsonError(400,'invalid_relationship',"$class does not expose $field");
    $value=null;if(!$unlink){$parentClass=(string)$entry['parentClass'];$parent=new $parentClass((int)$entry['parentId']);if(!$parent->id||!Security::checkValidAccessForUser($parent,'read',null,null,false))mcpJsonError(403,'forbidden','Relationship parent is unavailable');$value=(int)$parent->id;}
    $operations[]=array_filter(array('action'=>'update','objectClass'=>$class,'id'=>(int)$target->id,'expectedVersion'=>$entry['expectedVersion']??null,'data'=>array($field=>$value)),fn($item)=>$item!==null);
  }
  return mcpToolsNormalizeBatchResult(mcpExecuteOperationsArray($operations,$unlink?'atomic':mcpToolsMode($arguments),$unlink));
}

function mcpToolsRelationshipLink(array $arguments,string $username,string $action): array { return mcpToolsRelationshipOperations($arguments,false); }
function mcpToolsRelationshipUnlink(array $arguments,string $username,string $action): array { return mcpToolsRelationshipOperations($arguments,true); }

function mcpToolsPreviewRelationshipUnlink(array $arguments,string $username,string $action): array {
  $items=array();$allowed=0;
  foreach($arguments['items']??array() as $index=>$entry){
    if(empty($entry['expectedVersion']))mcpJsonError(400,'expected_version_required',"Relationship item $index requires expectedVersion");
    $class=(string)$entry['objectClass'];Security::checkValidClass($class);$field=mcpToolsRelationshipField((string)$entry['parentClass']);$object=new $class((int)$entry['id']);
    if(!$object->id){$items[]=array('objectClass'=>$class,'id'=>(int)$entry['id'],'status'=>'missing');continue;}
    if(!property_exists($object,$field))mcpJsonError(400,'invalid_relationship',"$class does not expose $field");
    $version=mcpObjectVersion($object);$conflict=!empty($entry['expectedVersion'])&&!hash_equals((string)$entry['expectedVersion'],$version);$canUpdate=Security::checkValidAccessForUser($object,'update',null,null,false);if($canUpdate&&!$conflict)$allowed++;
    $items[]=array('objectClass'=>$class,'id'=>(int)$object->id,'field'=>$field,'currentParentId'=>$object->$field===null?null:(int)$object->$field,'version'=>$version,'versionConflict'=>$conflict,'updateAllowed'=>$canUpdate);
  }
  return array('counts'=>array('requested'=>count($arguments['items']??array()),'allowed'=>$allowed),'items'=>$items,'effect'=>'clear_parent_relationship');
}
