<?php
declare(strict_types=1);

function mcpToolsCloneSchedule(array $arguments,string $username,string $action): array { return mcpToolsBatchForClass($arguments,'DataCloning',array('DataCloning'),true); }

function mcpToolsDocumentRights(array $arguments,string $username,string $action): array {
  return mcpToolsBatchForClass($arguments,'DocumentRight',array('DocumentRight'),true);
}

function mcpToolsPreviewAdministrativeBatch(array $arguments,string $username,string $action): array {
  $allowed=match($action){
    'tools.document.rights'=>array('DocumentRight'),
    'tools.clone.schedule'=>array('DataCloning'),
    'tools.automation.manage'=>array('NotificationDefinition','EventForMail','StatusMail','StatusMailPerProject','EmailTemplate'),
    default=>array()
  };
  if(!$allowed)mcpJsonError(400,'preview_unavailable','Administrative preview is unavailable');
  $items=array();$permitted=0;
  foreach($arguments['operations']??array() as $index=>$operation){
    $class=(string)($operation['objectClass']??$allowed[0]);if(!in_array($class,$allowed,true))mcpJsonError(400,'invalid_object_class',"$class is not owned by this administrative action");
    $verb=(string)($operation['operation']??'');$id=(int)($operation['id']??0);$object=$id?new $class($id):new $class();$available=(!$id||$object->id)&&Security::checkValidAccessForUser($object,$verb==='create'?'create':'update',null,null,false);if($available)$permitted++;
    $items[]=array('index'=>$index,'operation'=>$verb,'objectClass'=>$class,'id'=>$id?:null,'available'=>$available,'expectedVersion'=>$operation['expectedVersion']??null,'payloadRedacted'=>true);
  }
  return array('counts'=>array('requested'=>count($items),'permitted'=>$permitted),'items'=>$items,'effect'=>'guarded_administrative_change');
}
