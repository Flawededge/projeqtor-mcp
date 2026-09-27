<?php
declare(strict_types=1);
require_once __DIR__.'/worker.php';
$ok=mcpObjectSchema(array('ok'=>array('type'=>'boolean')),array('ok'),true);
$schema=mcpObjectSchema(array('idReport'=>array('type'=>'integer','minimum'=>1),'format'=>array('type'=>'string','enum'=>array('pdf','png','csv','json')),'parameters'=>array('type'=>'object')),array('idReport'));
return array('id'=>'reports','version'=>'4.0.0','dependencies'=>array('core','configuration','tools'),'actions'=>array(
  'report.start'=>mcpActionSpec($schema,$ok,'read',true,'mcpReportsStartWorker',array('view:print'),'reports.render.legacy',array('retryPolicy'=>'safe')),
  'reports.render'=>mcpActionSpec($schema,$ok,'read',true,'mcpReportsRenderWorker',array(),'reports.render',array('retryPolicy'=>'safe','availability'=>'mcpReportsRenderAvailable'))
));
