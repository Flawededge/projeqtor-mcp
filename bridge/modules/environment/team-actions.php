<?php
declare(strict_types=1);

function mcpEnvironmentTeamCascadeOperations(array $item): array {
  if (empty($item['id'])||Parameter::getGlobalParameter('autoAffectationPool')!=='IMPLICIT') return array();
  $membership=new ResourceTeamAffectation((int)$item['id']);
  if (!$membership->id) return array();
  $start=$item['startDate']??$membership->startDate;$end=$item['endDate']??$membership->endDate;
  if ((string)$start===(string)$membership->startDate&&(string)$end===(string)$membership->endDate) return array();
  $operations=array();$affectation=new Affectation();
  foreach ($affectation->getSqlElementsFromCriteria(array('idResource'=>(int)($item['idResource']??$membership->idResource),'idResourceTeam'=>(int)$membership->idResourceTeam),false,null,'id asc') as $resourceAffectation) {
    $pool=new Affectation();$poolList=$pool->getSqlElementsFromCriteria(array('idProject'=>(int)$resourceAffectation->idProject,'idResource'=>(int)$membership->idResourceTeam),false,null,'id asc');
    $poolAffectation=count($poolList)?$poolList[0]:null;
    if (!$poolAffectation) continue;
    $resourceStart=$resourceAffectation->startDate;$resourceEnd=$resourceAffectation->endDate;
    $overlaps=(!$resourceStart||!$poolAffectation->endDate||$resourceStart<=$poolAffectation->endDate)
      &&(!$resourceEnd||!$poolAffectation->startDate||$resourceEnd>=$poolAffectation->startDate);
    if (!$overlaps) {
      $operations[]=array('action'=>'delete','objectClass'=>'Affectation','id'=>(int)$resourceAffectation->id,'expectedVersion'=>mcpObjectVersion($resourceAffectation));
      continue;
    }
    $data=array();
    if ((string)$start!==(string)$membership->startDate) $data['startDate']=(!$resourceStart||($poolAffectation->startDate&&$poolAffectation->startDate>$resourceStart))?$poolAffectation->startDate:$resourceStart;
    if ((string)$end!==(string)$membership->endDate) $data['endDate']=(!$resourceEnd||($poolAffectation->endDate&&$poolAffectation->endDate<$resourceEnd))?$poolAffectation->endDate:$resourceEnd;
    if ($data) $operations[]=array('action'=>'update','objectClass'=>'Affectation','id'=>(int)$resourceAffectation->id,'expectedVersion'=>mcpObjectVersion($resourceAffectation),'data'=>$data);
  }
  return $operations;
}
