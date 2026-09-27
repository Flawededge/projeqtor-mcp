<?php
declare(strict_types=1);

function mcpPlanningDiagnosticsAction(array $arguments,string $username,string $action): array { return mcpPlanningDiagnostics((int)($arguments['idProject']??0)); }

function mcpPlanningBaselineDelete(array $arguments,string $username,string $action): array {
  $baseline=new Baseline((int)($arguments['id']??0));
  if(!$baseline->id||$baseline->idUser!=getSessionUser()->id)mcpJsonError(403,'forbidden','Only the baseline owner may delete it');
  if(!empty($arguments['expectedVersion'])&&!hash_equals(mcpObjectVersion($baseline),(string)$arguments['expectedVersion']))mcpJsonError(409,'version_conflict','Baseline has changed');
  Sql::beginTransaction();$raw=$baseline->deleteWithPlanning();
  if(getLastOperationStatus($raw)!=='OK'){Sql::rollbackTransaction();mcpJsonError(400,'baseline_delete_failed',cleanApiMessage($raw));}
  Sql::commitTransaction();return array('ok'=>true,'id'=>(int)$arguments['id'],'status'=>'deleted','effects'=>array(array('action'=>'delete','objectClass'=>'Baseline','id'=>(int)$arguments['id'])));
}
