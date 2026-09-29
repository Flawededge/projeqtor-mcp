<?php
declare(strict_types=1);
require_once __DIR__.'/semantic-actions.php';
require_once __DIR__.'/administrative-actions.php';
function mcpConfigurationAdminAvailable(array $action): bool { return securityGetAccessRightYesNo('menuAdmin','read')==='YES'; }
function mcpConfigurationResetPassword(array $arguments,string $username,string $action): array { return mcpTriggerPasswordReset((int)($arguments['idUser']??0)); }
function mcpConfigurationCronAction(array $arguments,string $username,string $action): array {
  if($action==='cron.check')return array('ok'=>true,'cronStatus'=>Cron::check());
  if($action==='cron.stop'){Cron::setStopFlag();return array('ok'=>true,'cronStatus'=>'stopping');}
  throw new RuntimeException("Unsupported synchronous Cron action '$action'");
}
