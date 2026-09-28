<?php
declare(strict_types=1);

function mcpCoreRequireReferenceAdmin(): void {
  if(securityGetAccessRightYesNo('menuAdmin','read')!=='YES')throw new RuntimeException('forbidden');
}

function mcpCoreReferenceClasses(?string $requested): array {
  if($requested!==null&&$requested!==''){Security::checkValidClass($requested);$classes=array($requested);}
  else $classes=array_values(SqlList::getListNotTranslated('Referencable'));
  $classes=array_values(array_unique(array_filter(array_map('strval',$classes))));
  sort($classes,SORT_STRING);return $classes;
}

function mcpCoreReferenceRebuildPreview(array $arguments,string $username,string $action): array {
  mcpCoreRequireReferenceAdmin();
  $requested=isset($arguments['objectClass'])?(string)$arguments['objectClass']:null;
  $items=array();$total=0;
  foreach(mcpCoreReferenceClasses($requested) as $class){
    if(!class_exists($class))continue;
    $object=new $class();if(!property_exists($object,'reference'))continue;
    mcpRequireClassOperation($class,'update');
    $count=(int)$object->countSqlElementsFromCriteria(null);
    $items[]=array('objectClass'=>$class,'count'=>$count);$total+=$count;
  }
  return array('scope'=>$requested??'all','classCount'=>count($items),'objectCount'=>$total,'items'=>$items);
}

function mcpCoreReferenceRebuildWorker(int $jobId,array $arguments,string $username): array {
  mcpCoreRequireReferenceAdmin();
  if(function_exists('projeqtor_set_time_limit'))projeqtor_set_time_limit(0);
  $requested=isset($arguments['objectClass'])?(string)$arguments['objectClass']:null;
  $items=array();$total=0;
  foreach(mcpCoreReferenceClasses($requested) as $class){
    if(workerCancelled($jobId))throw new RuntimeException('cancelled');
    if(!class_exists($class))continue;
    $object=new $class();if(!property_exists($object,'reference'))continue;
    mcpRequireClassOperation($class,'update');
    Sql::beginTransaction();
    try{
      SqlDirectElement::execute('update '.$object->getDatabaseTableName().' set reference=null');
      $records=$object->getSqlElementsFromCriteria(null,false);$changed=0;
      foreach($records as $record){
        if(($changed%100)===0&&workerCancelled($jobId))throw new RuntimeException('cancelled');
        $record->setReference(true);$changed++;
      }
      Sql::commitTransaction();$total+=$changed;$items[]=array('objectClass'=>$class,'updated'=>$changed);
    }catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  }
  return array('ok'=>true,'status'=>'rebuilt','scope'=>$requested??'all','classCount'=>count($items),'objectCount'=>$total,'items'=>$items,
    'effects'=>array(array('action'=>'update','objectClass'=>'Referencable','count'=>$total)));
}
