<?php
declare(strict_types=1);

function mcpFollowUpImputationAlertWorker(int $jobId,array $arguments,string $username): array {
  mcpFollowUpRequireAlertAdmin();
  mcpFollowUpAlertPreview($arguments,$username,'follow_up.imputation_alert.generate');
  if(workerCancelled($jobId))throw new RuntimeException('cancelled');
  workerUpdate($jobId,'running',15);
  $workingDirectory=getcwd();
  chdir('/var/www/html/tool');
  try { require_once '/var/www/html/tool/generateImputationAlert.php'; } finally { chdir($workingDirectory); }
  if(workerCancelled($jobId))throw new RuntimeException('cancelled');
  workerUpdate($jobId,'running',35);
  $deliveries=array(
    'resource'=>(string)($arguments['resourceDelivery']??'NO'),
    'projectLeader'=>(string)($arguments['projectLeaderDelivery']??'NO'),
    'teamManager'=>(string)($arguments['teamManagerDelivery']??'NO'),
    'organizationManager'=>(string)($arguments['organizationManagerDelivery']??'NO')
  );
  generateImputationAlert((string)$arguments['startDate'],(string)$arguments['endDate'],$deliveries['resource'],$deliveries['projectLeader'],$deliveries['teamManager'],$deliveries['organizationManager'],!empty($arguments['onlyIncompleteResource']),!empty($arguments['onlyIncompleteProjectLeader']),!empty($arguments['onlyIncompleteTeamManager']),!empty($arguments['onlyIncompleteOrganizationManager']));
  workerUpdate($jobId,'running',95);
  return array('ok'=>true,'startDate'=>(string)$arguments['startDate'],'endDate'=>(string)$arguments['endDate'],'deliveries'=>$deliveries,'status'=>'completed','effects'=>array());
}
