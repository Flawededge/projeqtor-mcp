<?php
declare(strict_types=1);

function mcpContextualReadAllowed(object $object): bool {
  if (!(int)($object->id??0)) return false;
  if (Security::checkValidAccessForUser($object,'read',null,null,false)) return true;
  if (get_class($object)==='Dependency') {
    foreach(array(array('predecessorRefType','predecessorRefId'),array('successorRefType','successorRefId')) as $fields){
      $type=(string)($object->{$fields[0]}??'');$id=(int)($object->{$fields[1]}??0);
      if(!$type||!$id||!SqlElement::class_exists($type))return false;
      $parent=new $type($id);
      if(!$parent->id||!Security::checkValidAccessForUser($parent,'read',null,null,false))return false;
    }
    return true;
  }
  if(property_exists($object,'refType')&&property_exists($object,'refId')){
    $type=(string)$object->refType;$id=(int)$object->refId;
    if($type&&$id&&SqlElement::class_exists($type)){
      $parent=new $type($id);
      if($parent->id&&Security::checkValidAccessForUser($parent,'read',null,null,false))return true;
    }
  }
  foreach(array(array('idProject','Project'),array('idDocument','Document'),array('idResource','Resource')) as $reference){
    if(property_exists($object,$reference[0])&&(int)$object->{$reference[0]}){
      $class=$reference[1];$parent=new $class((int)$object->{$reference[0]});
      if($parent->id&&Security::checkValidAccessForUser($parent,'read',null,null,false))return true;
    }
  }
  return false;
}
