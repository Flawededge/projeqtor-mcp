<?php
declare(strict_types=1);
require_once __DIR__.'/actions.php';
$objectRef=array('objectClass'=>array('type'=>'string','minLength'=>1,'maxLength'=>100),'id'=>array('type'=>'integer','minimum'=>1));
$ok=mcpObjectSchema(array('ok'=>array('type'=>'boolean')),array('ok'),true);
return array('id'=>'core','version'=>'4.0.0','dependencies'=>array(),'actions'=>array(
  'object.copy'=>mcpActionSpec(mcpObjectSchema($objectRef,array('objectClass','id')),$ok,'write',false,'mcpCoreCopy',array('tool:copyObject','tool:copyObjectTo','tool:copyProjectTo'),'core.object.copy'),
  'workflow.transition'=>mcpActionSpec(mcpObjectSchema($objectRef+array('idStatus'=>array('type'=>'integer','minimum'=>1),'expectedVersion'=>array('type'=>'string','maxLength'=>200)),array('objectClass','id','idStatus')),$ok,'write',false,'mcpCoreTransition',array('tool:changeObjectStatus'),'core.workflow.transition')
));
