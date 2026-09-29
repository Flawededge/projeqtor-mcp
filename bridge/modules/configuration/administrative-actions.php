<?php
declare(strict_types=1);

function mcpConfigurationRequireAdmin(): void {
  if(securityGetAccessRightYesNo('menuAdmin','read')!=='YES')mcpJsonError(403,'forbidden','Administration access is required');
}

function mcpConfigurationValidateAdminBranch(array $arguments): array {
  $branch=(string)($arguments['branch']??'');$schemas=mcpConfigurationAdminBranchSchemas();
  if(!isset($schemas[$branch]))mcpJsonError(400,'invalid_admin_branch','Unsupported administration branch');
  $payload=$arguments['arguments']??null;if(!is_array($payload)||(array_is_list($payload)&&$payload!==array()))mcpJsonError(400,'invalid_admin_arguments','Administration branch arguments must be an object');
  $errors=mcpValidateSchemaValue($payload,$schemas[$branch],'$.arguments');
  if($errors)mcpJsonError(400,'action_validation_failed','Administration branch arguments do not match the declared schema',array('invalidFields'=>$errors));
  if($branch==='send_alert'&&$payload['audience']==='users'&&empty($payload['userIds']))mcpJsonError(400,'alert_recipients_required','userIds is required for the users audience');
  if($branch==='terminate_sessions'&&$payload['scope']==='users'&&empty($payload['userIds']))mcpJsonError(400,'session_users_required','userIds is required for the users scope');
  return array($branch,$payload);
}

function mcpConfigurationNotificationArguments(array $arguments): array {
  $audience=(string)$arguments['audience'];$ids=array();
  if($audience==='users')$ids=$arguments['userIds']??array();
  elseif($audience==='connected'){
    $audit=new Audit();foreach($audit->getSqlElementsFromCriteria(array('idle'=>'0')) as $entry)$ids[]=(int)$entry->idUser;
  }else foreach(SqlList::getList('User') as $id=>$name)$ids[]=(int)$id;
  $ids=array_values(array_unique(array_filter(array_map('intval',$ids),fn($id)=>$id>0)));
  if(!$ids)mcpJsonError(400,'alert_recipients_required','The selected audience has no recipients');
  if(count($ids)>200)mcpJsonError(400,'alert_recipient_limit','Alert delivery is limited to 200 recipients per request');
  $parts=explode(' ',(string)$arguments['scheduledAt'],2);$items=array();
  foreach($ids as $id)$items[]=array('idUser'=>$id,'title'=>(string)$arguments['title'],'content'=>'['.(string)$arguments['alertType'].'] '.(string)$arguments['message'],'notificationDate'=>$parts[0],'notificationTime'=>$parts[1]??'','idNotificationType'=>(int)$arguments['notificationTypeId'],'sendEmail'=>(bool)($arguments['sendEmail']??false));
  return array('items'=>$items);
}

function mcpConfigurationInvokePublicAction(string $target,array $arguments,string $username): array {
  $hook=$GLOBALS['mcpConfigurationPublicActionInvoker']??null;
  if(is_callable($hook)){
    $result=$hook($target,$arguments,$username);if(!is_array($result))throw new RuntimeException('Configuration public action hook returned an invalid result');
    return array('targetAction'=>$target,'result'=>$result);
  }
  $registry=mcpActionRegistry();$actual=$target;$payload=$arguments;
  if(!isset($registry[$actual])&&$actual==='tools.alert.send'&&isset($registry['tools.notification.send'])){$actual='tools.notification.send';$payload=mcpConfigurationNotificationArguments($arguments);}
  if(!isset($registry[$actual]))mcpJsonError(503,'module_service_unavailable',"Required public action '$target' is not registered");
  $result=mcpExecuteActionValue($actual,$payload,$username,true);
  return array('targetAction'=>$actual,'result'=>$result);
}

function mcpConfigurationDelegationResult(string $branch,array $delegated): array {
  $result=$delegated['result'];$job=is_array($result['job']??null)?$result['job']:array();$queued=(bool)($result['queued']??false);
  return array('ok'=>(bool)($result['ok']??true),'branch'=>$branch,'targetAction'=>(string)$delegated['targetAction'],'status'=>$queued?'queued':'completed','queued'=>$queued,'jobId'=>isset($job['id'])?(int)$job['id']:null,'jobResource'=>isset($job['resultResource'])?(string)$job['resultResource']:null);
}

function mcpConfigurationAdminPreview(array $arguments,string $username,string $action): array {
  list($branch,$payload)=mcpConfigurationValidateAdminBranch($arguments);mcpConfigurationRejectSensitive($payload);$target=mcpConfigurationAdminTargets()[$branch];
  return array('branch'=>$branch,'targetAction'=>$target,'argumentFields'=>array_values(array_keys($payload)),'requiresPublicService'=>!str_starts_with($target,'configuration.'));
}

function mcpConfigurationAdminExecute(array $arguments,string $username,string $action): array {
  mcpConfigurationRequireAdmin();list($branch,$payload)=mcpConfigurationValidateAdminBranch($arguments);mcpConfigurationRejectSensitive($payload);
  return mcpConfigurationDelegationResult($branch,mcpConfigurationInvokePublicAction(mcpConfigurationAdminTargets()[$branch],$payload,$username));
}

function mcpConfigurationMaintenanceWhere(string $item,int $days): array {
  $cutoff=date('Y-m-d H:i:s',strtotime('-'.$days.' days'));
  $where=match($item){'Alert'=>"alertInitialDateTime<'$cutoff'",'Mail'=>"mailDateTime<'$cutoff'",'Audit'=>"disconnectionDateTime<'$cutoff'",'Notification'=>"notificationDate<'".substr($cutoff,0,10)."'",'Logfile'=>$cutoff,default=>throw new RuntimeException('Unsupported maintenance item')};
  return array($cutoff,$where);
}

function mcpConfigurationMaintenancePreview(array $arguments,string $username,string $action): array {
  mcpConfigurationRequireAdmin();list($cutoff,$where)=mcpConfigurationMaintenanceWhere((string)$arguments['item'],(int)$arguments['olderThanDays']);
  return array('operation'=>$arguments['operation'],'item'=>$arguments['item'],'cutoff'=>$cutoff,'boundedWhereKind'=>$arguments['item']==='Logfile'?'log_date':'database_date','candidateCount'=>null);
}

function mcpConfigurationMaintenanceRun(array $arguments,string $username,string $action): array {
  mcpConfigurationRequireAdmin();$operation=(string)$arguments['operation'];$item=(string)$arguments['item'];
  if($operation==='read'&&$item!=='Alert')mcpJsonError(400,'invalid_maintenance_operation','The read operation is only valid for Alert');
  list($cutoff,$where)=mcpConfigurationMaintenanceWhere($item,(int)$arguments['olderThanDays']);$object=new $item();$transactional=$item!=='Logfile';
  if($operation==='read')$where='readFlag=0 and idUser='.Sql::fmtId((int)getSessionUser()->id);
  if($transactional)Sql::beginTransaction();ob_start();
  try{
    if($operation==='close'&&$item==='Alert')$object->read($where);
    $raw=$operation==='delete'?$object->purge($where):($operation==='close'?$object->close($where):$object->read($where));
    ob_end_clean();$status=getLastOperationStatus($raw);if($status==='ERROR')throw new RuntimeException(cleanApiMessage($raw));if($transactional)Sql::commitTransaction();
  }catch(Throwable $error){if(ob_get_level()>0)ob_end_clean();if($transactional)Sql::rollbackTransaction();throw $error;}
  $affected=isset(Sql::$lastQueryNbRows)?(int)Sql::$lastQueryNbRows:null;$effectAction=$operation==='delete'?'delete':'update';
  return array('ok'=>true,'operation'=>$operation,'item'=>$item,'cutoff'=>$cutoff,'affectedCount'=>$affected,'status'=>'completed','effects'=>array(array('action'=>$effectAction,'objectClass'=>$item,'count'=>$affected)));
}

function mcpConfigurationConsistencyMethods(): array {
  return array('wbs'=>'checkWbs','bbs'=>'checkBbs','sbs'=>'checkSbs','duplicate_work'=>'checkDuplicateWork','work_on_ticket'=>'checkWorkOnTicket','work_on_assignment'=>'checkWorkOnAssignment','idle_propagation'=>'checkIdlePropagation','missing_planning_element'=>'checkMissingPlanningElement','work_on_activity'=>'checkWorkOnActivity','work_on_meeting'=>'checkWorkOnMeeting','periodic_meeting_assignment'=>'checkPeriodicMeetingAssign','budget'=>'checkBudget','invalid_filters'=>'checkInvalidFilters','pools'=>'checkPools','project'=>'checkProject','assignment_selection'=>'checkAssignmentSelection','subtask_project'=>'checkSubTaskProject');
}

function mcpConfigurationConsistencyRun(array $arguments,bool $repair): array {
  mcpConfigurationRequireAdmin();$methods=mcpConfigurationConsistencyMethods();$checks=array_values(array_unique($arguments['checks']??array_keys($methods)));$items=array();$completed=0;$errors=0;
  if(function_exists('projeqtor_set_time_limit'))projeqtor_set_time_limit(900);$oldIndicator=IndicatorValue::$_doNotUpdate;$oldControls=SqlElement::$_skipAllControls;IndicatorValue::$_doNotUpdate=true;if($repair)SqlElement::$_skipAllControls=true;
  try{
    foreach($checks as $check){$method=$methods[$check]??null;if(!$method)mcpJsonError(400,'invalid_consistency_check',"Unknown consistency check '$check'");Sql::beginTransaction();ob_start();
      try{$args=array($repair,false);if($check==='subtask_project')$args[]=true;call_user_func_array(array('Consistency',$method),$args);ob_end_clean();Sql::commitTransaction();$completed++;$items[]=array('check'=>$check,'status'=>$repair?'repaired':'checked','error'=>null);}
      catch(Throwable $error){if(ob_get_level()>0)ob_end_clean();Sql::rollbackTransaction();$errors++;$items[]=array('check'=>$check,'status'=>'error','error'=>substr(cleanApiMessage($error->getMessage()),0,1000));}
    }
  }finally{IndicatorValue::$_doNotUpdate=$oldIndicator;SqlElement::$_skipAllControls=$oldControls;}
  return array('ok'=>$errors===0,'mode'=>$repair?'repair':'check','items'=>$items,'completedCount'=>$completed,'errorCount'=>$errors,'effects'=>$repair?array(array('action'=>'consistency.repair','objectClass'=>null,'count'=>$completed)):array());
}
function mcpConfigurationConsistencyCheck(array $arguments,string $username,string $action): array {return mcpConfigurationConsistencyRun($arguments,false);}
function mcpConfigurationConsistencyRepair(array $arguments,string $username,string $action): array {return mcpConfigurationConsistencyRun($arguments,true);}
function mcpConfigurationConsistencyPreview(array $arguments,string $username,string $action): array {mcpConfigurationRequireAdmin();return array('mode'=>'repair','checks'=>array_values(array_unique($arguments['checks']??mcpConfigurationConsistencyNames())),'transactionMode'=>'best_effort');}

function mcpConfigurationDeferredUpdatesPreview(array $arguments,string $username,string $action): array {mcpConfigurationRequireAdmin();return array('scope'=>'all_users','transactionMode'=>'native_best_effort');}
function mcpConfigurationDeferredUpdatesExecute(array $arguments,string $username,string $action): array {mcpConfigurationRequireAdmin();$processed=(int)WaitingUpdate::executeWaiting(true);return array('ok'=>true,'processed'=>$processed,'status'=>'completed','effects'=>array(array('action'=>'deferred_updates.execute','objectClass'=>null,'count'=>$processed)));}

function mcpConfigurationCronConfigurePreview(array $arguments,string $username,string $action): array {
  mcpConfigurationRequireAdmin();$operation=(string)($arguments['operation']??'');
  if($operation==='save_definition'){foreach(array('scope','schedule') as $field)if(!array_key_exists($field,$arguments))mcpJsonError(400,'missing_field',"cron.configure save_definition requires $field");return array('operation'=>$operation,'scope'=>$arguments['scope'],'activeAfterSave'=>false);}
  if($operation==='activate'&&!array_key_exists('active',$arguments))mcpJsonError(400,'missing_field','cron.configure activate requires active');
  return array('operation'=>$operation,'delegatesTo'=>!empty($arguments['active'])?'cron.start':'cron.stop');
}

function mcpConfigurationCronConfigure(array $arguments,string $username,string $action): array {
  mcpConfigurationRequireAdmin();$operation=(string)$arguments['operation'];
  if($operation==='activate'){
    if(!array_key_exists('active',$arguments))mcpJsonError(400,'missing_field','cron.configure activate requires active');$active=(bool)$arguments['active'];$delegated=mcpConfigurationInvokePublicAction($active?'cron.start':'cron.stop',array(),$username);$normalized=mcpConfigurationDelegationResult('cron',$delegated);
    return array('ok'=>$normalized['ok'],'operation'=>'activate','scope'=>null,'active'=>$active,'status'=>$active?'starting':'stopping','queued'=>$normalized['queued'],'jobId'=>$normalized['jobId'],'jobResource'=>$normalized['jobResource'],'cronStatus'=>$delegated['result']['cronStatus']??null,'effects'=>array());
  }
  if($operation!=='save_definition')mcpJsonError(400,'invalid_cron_operation','operation must be save_definition or activate');foreach(array('scope','schedule') as $field)if(!array_key_exists($field,$arguments))mcpJsonError(400,'missing_field',"cron.configure save_definition requires $field");
  $scope=(string)$arguments['scope'];$schedule=$arguments['schedule'];foreach(array('minutes','hours','dayOfMonth','month','dayOfWeek') as $field)if(!isset($schedule[$field])||!preg_match('/^[0-9*?/,\-]{1,64}$/D',(string)$schedule[$field]))mcpJsonError(400,'invalid_cron_field',"Invalid cron schedule field '$field'");
  $cron=CronExecution::getObjectFromScope($scope);if($cron->id){$expected=(string)($arguments['expectedVersion']??'');if($expected==='')mcpJsonError(409,'expected_version_required','CronExecution update requires expectedVersion');$actual=mcpObjectVersion($cron);if(!hash_equals($actual,$expected))mcpJsonError(409,'version_conflict','CronExecution has changed',array('expectedVersion'=>$expected,'actualVersion'=>$actual));}elseif(!empty($arguments['expectedVersion']))mcpJsonError(409,'version_conflict','CronExecution does not exist for the supplied expectedVersion');
  $cron->idle=1;if(!$cron->fileExecuted)$cron->fileExecuted=str_starts_with($scope,'imputationAlert')?'../tool/generateImputationAlert.php':'../tool/cronExecutionStandard.php';if(!$cron->fonctionName)$cron->fonctionName='cron'.ucfirst($scope);$cron->cron=implode(' ',array($schedule['minutes'],$schedule['hours'],$schedule['dayOfMonth'],$schedule['month'],$schedule['dayOfWeek']));$cron->nextTime=null;
  Sql::beginTransaction();try{$raw=$cron->save();if(!in_array(getLastOperationStatus($raw),array('OK','NO_CHANGE'),true))throw new RuntimeException(cleanApiMessage($raw));if($scope==='runConsistencyCheck'&&array_key_exists('consistencyCheckMail',$arguments))Parameter::storeGlobalParameter('cronConsistencyCheckMail',(string)$arguments['consistencyCheckMail']);Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  return array('ok'=>true,'operation'=>'save_definition','scope'=>$scope,'active'=>false,'status'=>'configured_disabled','queued'=>false,'jobId'=>null,'jobResource'=>null,'cronStatus'=>null,'effects'=>array(array('action'=>'update','objectClass'=>'CronExecution','id'=>(int)$cron->id)));
}

function mcpConfigurationPluginUpdatePreview(array $arguments,string $username,string $action): array {mcpConfigurationRequireAdmin();return array('pluginId'=>$arguments['pluginId'],'installedVersion'=>$arguments['installedVersion'],'availableVersion'=>$arguments['availableVersion'],'recipientUserId'=>$arguments['recipientUserId'],'effect'=>'notification_only');}
function mcpConfigurationPluginUpdateNotify(array $arguments,string $username,string $action): array {
  mcpConfigurationRequireAdmin();if(version_compare((string)$arguments['availableVersion'],(string)$arguments['installedVersion'],'<=') )mcpJsonError(409,'plugin_update_not_newer','availableVersion must be newer than installedVersion');
  $date=(string)($arguments['notificationDate']??date('Y-m-d'));$notification=array('items'=>array(array('idUser'=>(int)$arguments['recipientUserId'],'title'=>'Plugin update available: '.(string)$arguments['pluginName'],'content'=>'A newer version of '.(string)$arguments['pluginName'].' is available (installed '.(string)$arguments['installedVersion'].', available '.(string)$arguments['availableVersion'].').','notificationDate'=>$date,'notificationTime'=>'','idNotificationType'=>(int)$arguments['notificationTypeId'],'sendEmail'=>false)));
  $delegated=mcpConfigurationInvokePublicAction('tools.notification.send',$notification,$username);$normalized=mcpConfigurationDelegationResult('plugin_update',$delegated);
  return array('ok'=>$normalized['ok'],'pluginId'=>(string)$arguments['pluginId'],'installedVersion'=>(string)$arguments['installedVersion'],'availableVersion'=>(string)$arguments['availableVersion'],'notificationAction'=>'tools.notification.send','status'=>$normalized['status'],'queued'=>$normalized['queued'],'jobId'=>$normalized['jobId'],'jobResource'=>$normalized['jobResource'],'effects'=>array());
}
