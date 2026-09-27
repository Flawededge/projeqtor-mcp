<?php
declare(strict_types=1);
require_once __DIR__.'/actions.php';require_once __DIR__.'/worker.php';
$ok=mcpObjectSchema(array('ok'=>array('type'=>'boolean')),array('ok'),true);$empty=mcpObjectSchema();$admin=array('availability'=>'mcpConfigurationAdminAvailable');
return array('id'=>'configuration','version'=>'4.0.0','dependencies'=>array('core'),'actions'=>array(
  'user.trigger_password_reset'=>mcpActionSpec(mcpObjectSchema(array('idUser'=>array('type'=>'integer','minimum'=>1)),array('idUser')),$ok,'external',false,'mcpConfigurationResetPassword',array('tool:sendResetPassword','tool:resetPassword'),'configuration.user.reset',$admin),
  'cron.check'=>mcpActionSpec($empty,$ok,'read',false,'mcpConfigurationCronAction',array('tool:cronCheck'),'configuration.cron.check',array_merge($admin,array('transaction'=>'none'))),
  'cron.start'=>mcpActionSpec($empty,$ok,'administrative',true,'mcpConfigurationCronStartWorker',array('tool:cronActivation'),'configuration.cron.start',array_merge($admin,array('retryPolicy'=>'safe'))),
  'cron.stop'=>mcpActionSpec($empty,$ok,'administrative',false,'mcpConfigurationCronAction',array('tool:cronStop'),'configuration.cron.stop',array_merge($admin,array('transaction'=>'none'))),
  'cron.restart'=>mcpActionSpec($empty,$ok,'administrative',true,'mcpConfigurationCronRestartWorker',array('tool:cronRelaunch','tool:cronRun'),'configuration.cron.restart',array_merge($admin,array('retryPolicy'=>'safe')))
));
