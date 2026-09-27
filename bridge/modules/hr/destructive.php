<?php
declare(strict_types=1);

function mcpHrAbsenceRemovePreview(array $arguments,string $username,string $action): array {
  $items=array();
  foreach($arguments['entries']??array() as $entry){
    $work=mcpHrRequireExisting('Work',(int)($entry['workId']??0),'delete');mcpHrRequireExpected($work,$entry['expectedVersion']??null);
    if($work->refType!=='Activity'||!mcpHrMayManageEmployee((int)$work->idResource)||!Project::isTheLeaveProject((int)$work->idProject))mcpJsonError(403,'forbidden','Work is not an accessible leave-project absence');
    $items[]=array(
      'id'=>(int)$work->id,'exists'=>true,
      'resourceId'=>(int)$work->idResource,'workDate'=>(string)$work->workDate,
      'work'=>(float)$work->work,'version'=>mcpObjectVersion($work)
    );
  }
  return array('count'=>count($items),'items'=>$items);
}

function mcpHrAbsenceRemove(array $arguments,string $username,string $action): array {
  return mcpHrRunBatch($arguments['entries']??array(),'atomic',function(array $entry): array {
    $id=(int)$entry['workId'];$work=mcpHrRequireExisting('Work',$id,'delete');mcpHrRequireExpected($work,$entry['expectedVersion']??null);
    if($work->refType!=='Activity'||!mcpHrMayManageEmployee((int)$work->idResource))mcpJsonError(403,'forbidden','Work is not an absence entry in the actor employee scope');
    $activity=new Activity((int)$work->refId);
    if(!$activity->id||!Project::isTheLeaveProject((int)$work->idProject))mcpJsonError(409,'not_absence_work','Work does not belong to the configured leave project');
    $raw=method_exists($work,'deleteWork')?$work->deleteWork():$work->delete();
    if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'absence_delete_failed',cleanApiMessage($raw));
    return array('status'=>'deleted','objectClass'=>'Work','id'=>$id,'effects'=>array(array('action'=>'delete','objectClass'=>'Work','id'=>$id)));
  });
}
