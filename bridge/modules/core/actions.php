<?php
declare(strict_types=1);

function mcpCoreCopy(array $arguments,string $username,string $action): array {
  mcpRequireKeys($arguments,array('objectClass','id'));$class=(string)$arguments['objectClass'];mcpRequireClassOperation($class,'create');
  $source=new $class((int)$arguments['id']);
  if(!$source->id||!Security::checkValidAccessForUser($source,'read',null,null,false)||!Security::checkValidAccessForUser(new $class(),'create',null,null,false))mcpJsonError(403,'forbidden','Copy access is denied');
  Sql::beginTransaction();$copy=$source->copy();
  if(!$copy||!$copy->id){Sql::rollbackTransaction();mcpJsonError(400,'copy_failed','Object copy failed');}
  Sql::commitTransaction();
  return array('ok'=>true,'source'=>array('objectClass'=>$class,'id'=>(int)$source->id),'copy'=>mcpObjectArray($copy),'effects'=>array(array('action'=>'create','objectClass'=>$class,'id'=>(int)$copy->id)));
}

function mcpCoreTransition(array $arguments,string $username,string $action): array {
  mcpRequireKeys($arguments,array('objectClass','id','idStatus'));
  $operation=array('action'=>'update','objectClass'=>$arguments['objectClass'],'id'=>(int)$arguments['id'],'expectedVersion'=>$arguments['expectedVersion']??null,'data'=>array('idStatus'=>(int)$arguments['idStatus']));
  $result=mcpExecuteOperationsArray(array($operation),'atomic',false);$result['effects']=array(array('action'=>'update','objectClass'=>$arguments['objectClass'],'id'=>(int)$arguments['id']));return $result;
}
