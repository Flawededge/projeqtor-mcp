<?php
declare(strict_types=1);
require_once __DIR__.'/actions.php';require_once __DIR__.'/worker.php';
$ok=mcpObjectSchema(array('ok'=>array('type'=>'boolean')),array('ok'),true);
$projectIds=array('type'=>'array','minItems'=>1,'maxItems'=>200,'items'=>array('type'=>'integer','minimum'=>1));
return array('id'=>'planning','version'=>'4.0.0','dependencies'=>array('core','configuration','environment'),'actions'=>array(
  'project.snapshot'=>mcpActionSpec(mcpObjectSchema(array('idProject'=>array('type'=>'integer','minimum'=>1),'sections'=>array('type'=>'array','items'=>array('type'=>'string'))),array('idProject')),$ok,'read',true,'mcpPlanningSnapshotWorker',array(),'planning.project.snapshot',array('retryPolicy'=>'safe')),
  'planning.calculate'=>mcpActionSpec(mcpObjectSchema(array('projectIds'=>$projectIds,'startDate'=>array('type'=>'string','maxLength'=>30),'criticalPath'=>array('type'=>'boolean'),'allowOverbooking'=>array('type'=>'boolean'),'criticalResourceMode'=>array('type'=>'boolean')),array('projectIds')),$ok,'write',true,'mcpPlanningCalculateWorker',array('tool:startPlanningCalculation','tool:planningCalculation','tool:plan','tool:refreshCriticalResources'),'planning.calculate'),
  'planning.diagnostics'=>mcpActionSpec(mcpObjectSchema(array('idProject'=>array('type'=>'integer','minimum'=>1)),array('idProject')),$ok,'read',false,'mcpPlanningDiagnosticsAction',array(),'planning.diagnostics',array('transaction'=>'none')),
  'planning.baseline.create'=>mcpActionSpec(mcpObjectSchema(array('idProject'=>array('type'=>'integer','minimum'=>1),'name'=>array('type'=>'string','minLength'=>1,'maxLength'=>200),'date'=>array('type'=>'string','maxLength'=>30),'privacy'=>array('type'=>'integer')),array('idProject','name')),$ok,'write',true,'mcpPlanningBaselineWorker',array('tool:saveBaseline','tool:savePlanningBaseline'),'planning.baseline.create'),
  'planning.baseline.delete'=>mcpActionSpec(mcpObjectSchema(array('id'=>array('type'=>'integer','minimum'=>1),'expectedVersion'=>array('type'=>'string','maxLength'=>200)),array('id')),$ok,'destructive',false,'mcpPlanningBaselineDelete',array('tool:deleteBaseline'),'planning.baseline.delete')
));
