<?php
declare(strict_types=1);

function mcpPlanningSnapshotWorker(int $jobId,array $arguments,string $username): array {
  $result=workerSnapshot($jobId,$arguments);
  unset($result['path']);
  return $result;
}

function mcpPlanningCalculateWorker(int $jobId,array $arguments,string $username): array {
  $result=workerPlanning($jobId,$arguments);
  if(array_key_exists('includeDiagnostics',$arguments)&&empty($arguments['includeDiagnostics']))unset($result['diagnostics']);
  return $result;
}

function mcpPlanningBaselineWorker(int $jobId,array $arguments,string $username): array {
  return workerBaseline($arguments);
}

function mcpPlanningCriticalResourcesWorker(int $jobId,array $arguments,string $username): array {
  if(!empty($arguments['scenarioId']))throw new RuntimeException('named_scenario_requires_activation');
  $planArguments=$arguments;
  $planArguments['criticalPath']=true;
  $planArguments['criticalResourceMode']=true;
  $planArguments['includeDiagnostics']=false;
  $planning=workerPlanning($jobId,$planArguments);
  if(workerCancelled($jobId))throw new RuntimeException('cancelled');
  $projectIds=array_values(array_unique(array_map('intval',$arguments['projectIds']??array())));
  $where='idProject in ('.implode(',',$projectIds).')';
  if(!empty($arguments['startDate']))$where.=' and workDate>='.Sql::str((string)$arguments['startDate']);
  if(!empty($arguments['endDate']))$where.=' and workDate<='.Sql::str((string)$arguments['endDate']);
  $resources=array();$plannedWork=new PlannedWork();
  foreach($plannedWork->getSqlElementsFromCriteria(null,false,$where,'idResource asc,workDate asc,id asc',false,true) as $entry){
    $id=(int)$entry->idResource;if(!$id)continue;
    if(!isset($resources[$id])){$resource=new Affectable($id);$resources[$id]=array('idResource'=>$id,'name'=>$resource->name??null,'plannedWork'=>0.0,'surbookedWork'=>0.0,'overloadedDays'=>0,'firstDate'=>null,'lastDate'=>null);}
    $resources[$id]['plannedWork']+=(float)$entry->work;
    $resources[$id]['surbookedWork']+=(float)$entry->surbookedWork;
    if((float)$entry->surbookedWork>0)$resources[$id]['overloadedDays']++;
    $date=(string)$entry->workDate;
    if(!$resources[$id]['firstDate']||$date<$resources[$id]['firstDate'])$resources[$id]['firstDate']=$date;
    if(!$resources[$id]['lastDate']||$date>$resources[$id]['lastDate'])$resources[$id]['lastDate']=$date;
  }
  $assignment=new Assignment();
  foreach($assignment->getSqlElementsFromCriteria(null,false,'idProject in ('.implode(',',$projectIds).')','idResource asc,id asc',false,true) as $entry){
    $id=(int)$entry->idResource;if(!$id)continue;
    if(!isset($resources[$id])){$resource=new Affectable($id);$resources[$id]=array('idResource'=>$id,'name'=>$resource->name??null,'plannedWork'=>0.0,'surbookedWork'=>0.0,'overloadedDays'=>0,'firstDate'=>null,'lastDate'=>null);}
    $resources[$id]['notPlannedWork']=($resources[$id]['notPlannedWork']??0.0)+(float)$entry->notPlannedWork;
  }
  usort($resources,fn($left,$right)=>($right['surbookedWork']<=>$left['surbookedWork'])?: (($right['notPlannedWork']??0)<=>($left['notPlannedWork']??0))?:($left['idResource']<=>$right['idResource']));
  $max=max(1,min(200,(int)($arguments['maxResources']??50)));$resources=array_slice($resources,0,$max);
  return array('ok'=>$planning['ok'],'status'=>$planning['status'],'message'=>$planning['message'],'projects'=>$projectIds,'startDate'=>$arguments['startDate']??null,'endDate'=>$arguments['endDate']??null,'resources'=>$resources,'counts'=>array('resources'=>count($resources),'overloaded'=>count(array_filter($resources,fn($item)=>$item['surbookedWork']>0))),'effects'=>$planning['effects']??array());
}

function mcpPlanningWbsRenumberWorker(int $jobId,array $arguments,string $username): array {
  if(securityGetAccessRightYesNo('menuAdmin','read')!=='YES')throw new RuntimeException('forbidden');
  if(workerCancelled($jobId))throw new RuntimeException('cancelled');
  if(function_exists('projeqtor_set_time_limit'))projeqtor_set_time_limit(900);
  $oldIndicator=IndicatorValue::$_doNotUpdate;IndicatorValue::$_doNotUpdate=true;
  $priorityChanges=0;$structureChanges=0;Sql::beginTransaction();
  try{
    $planning=new PlanningElement();
    if(!array_key_exists('fixProjectOrder',$arguments)||!empty($arguments['fixProjectOrder']))$priorityChanges=(int)$planning->renumberWbs(true,true);
    if(workerCancelled($jobId))throw new RuntimeException('cancelled');
    $structureChanges=(int)$planning->renumberWbs(true,false);
    Sql::commitTransaction();
  }catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  finally{IndicatorValue::$_doNotUpdate=$oldIndicator;}
  return array('ok'=>true,'status'=>'renumbered','priorityChanges'=>$priorityChanges,'structureChanges'=>$structureChanges,
    'effects'=>array(array('action'=>'update','objectClass'=>'PlanningElement','count'=>$priorityChanges+$structureChanges)));
}
