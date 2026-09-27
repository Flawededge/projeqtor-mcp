<?php
declare(strict_types=1);

function mcpEnvironmentAssertInterventionCompatible(int $idResource,string $workDate,string $period,int $currentId=0): void {
  $relation=new ResourceIncompatible();$ids=array();
  foreach ($relation->getSqlElementsFromCriteria(array('idResource'=>$idResource),false,null,'id asc') as $entry) $ids[]=(int)$entry->idIncompatible;
  if (!$ids) return;
  $ids=array_values(array_unique(array_filter($ids,fn($id)=>$id>0)));
  if (!$ids) return;
  $manual=new PlannedWorkManual();
  $where='idResource in ('.implode(',',$ids).') and workDate='.Sql::str($workDate).' and period='.Sql::str($period).' and refType is not null and refId is not null';
  if ($currentId>0) $where.=' and id<>'.Sql::fmtId($currentId);
  if ($manual->countSqlElementsFromCriteria(null,$where)>0) mcpJsonError(409,'incompatible_resource_conflict','An incompatible resource is already scheduled for this period',array('idResource'=>$idResource,'workDate'=>$workDate,'period'=>$period));
}
