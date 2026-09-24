<?php
declare(strict_types=1);

function mcpFieldOwner(object $object,string $field,int $depth=0): ?object {
  if(property_exists($object,$field))return $object;
  if($depth>=2)return null;
  foreach(get_object_vars($object) as $name=>$child){
    if(!is_object($child)||str_starts_with((string)$name,'_'))continue;
    $found=mcpFieldOwner($child,$field,$depth+1);
    if($found)return $found;
  }
  return null;
}

function mcpFieldValue(object $object,string $field): array {
  $owner=mcpFieldOwner($object,$field);
  return $owner?array(true,$owner->$field):array(false,null);
}

function mcpCollectObjectFields(object $object,array &$result,int $depth=0): void {
  foreach(get_object_vars($object) as $field=>$value){
    if(str_starts_with((string)$field,'_')||mcpSensitiveField((string)$field))continue;
    if(is_object($value)){
      if($depth<2)mcpCollectObjectFields($value,$result,$depth+1);
      continue;
    }
    if(is_array($value)||is_resource($value))continue;
    if(!array_key_exists($field,$result))$result[$field]=$value;
  }
}

function mcpObjectArray(object $object,?array $fields=null): array {
  $result=array();
  if($fields===null)mcpCollectObjectFields($object,$result);
  else foreach(array_values(array_unique(array_merge(array('id'),$fields))) as $field){
    $owner=mcpFieldOwner($object,(string)$field);
    if(!$owner)continue;
    $value=$owner->$field;
    if(!is_array($value)&&!is_object($value)&&!is_resource($value))$result[$field]=$value;
  }
  $result['_version']=mcpObjectVersion($object);
  return mcpRedactObject($result);
}

function mcpValidateField(object $object,string $field,bool $writable=false): void {
  if(!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D',$field)||str_starts_with($field,'_')||mcpSensitiveField($field))mcpJsonError(400,'invalid_field',"Field '$field' is not available",array('field'=>$field));
  $owner=mcpFieldOwner($object,$field);
  if(!$owner)mcpJsonError(400,'invalid_field',"Field '$field' is not available",array('field'=>$field));
  if($writable){
    if($field==='id')mcpJsonError(400,'invalid_field','The id field is not writable');
    if(method_exists($owner,'setAttributes'))$owner->setAttributes();
    $attributes=method_exists($owner,'getFieldAttributes')?(string)$owner->getFieldAttributes($field):'';
    foreach(array('readonly','calculated','noImport','hiddenforce') as $flag){
      if(str_contains($attributes,$flag))mcpJsonError(400,'readonly_field',"Field '$field' is not writable",array('field'=>$field));
    }
  }
}
