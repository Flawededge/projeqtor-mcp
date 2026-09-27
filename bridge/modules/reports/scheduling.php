<?php
declare(strict_types=1);

function mcpReportsCron(array $entry): string {
  $frequency=(string)$entry['frequency'];$hour=(int)$entry['hour'];$minute=(int)$entry['minute'];$day=(int)($entry['day']??1);
  if($hour>23||$minute>59)mcpReportsError('invalid_schedule_time','Schedule time is invalid');
  return match($frequency){'daily'=>"$minute $hour * * *",'weekly'=>"$minute $hour * * ".max(1,min(7,$day)),'monthly'=>"$minute $hour ".max(1,min(28,$day))." * *",default=>mcpReportsError('invalid_schedule_frequency','Schedule frequency is invalid')};
}

function mcpReportsScheduleAction(array $arguments,string $username,string $actionId): array {
  if(!securityCheckDisplayMenu(null,'AutoSendReport'))mcpReportsError('schedule_access_denied','Auto-send report access is denied');
  $items=array();$effects=array();Sql::beginTransaction();
  try{foreach($arguments['items'] as $index=>$entry){$operation=(string)$entry['operation'];
    if(in_array($operation,array('update','delete'),true))foreach(array('id','expectedVersion') as $field)if(!array_key_exists($field,$entry))mcpReportsError('schedule_field_required',"$field is required");
    if(in_array($operation,array('create','update'),true))foreach(array('reportId','name','frequency','hour','minute') as $field)if(!array_key_exists($field,$entry))mcpReportsError('schedule_field_required',"$field is required");
    if($operation==='delete'){$schedule=new AutoSendReport((int)$entry['id'],true);mcpReportsRequireOwned($schedule,(string)$entry['expectedVersion']);if((int)$schedule->idReceiver!==(int)getSessionUser()->id)mcpReportsError('recipient_not_actor','Recipient must be the actor');mcpReportsDelete($schedule);$items[]=array('index'=>$index,'status'=>'deleted','id'=>(int)$entry['id']);$effects[]=mcpReportsEffect('delete','AutoSendReport',(int)$entry['id']);continue;}
    $report=mcpReportsRequireReport((int)$entry['reportId'],'pdf');
    if($operation==='create'){$schedule=new AutoSendReport();$schedule->idResource=getSessionUser()->id;$schedule->idReceiver=getSessionUser()->id;}
    else{$schedule=new AutoSendReport((int)$entry['id'],true);mcpReportsRequireOwned($schedule,(string)($entry['expectedVersion']??''));if((int)$schedule->idReceiver!==(int)getSessionUser()->id)mcpReportsError('recipient_not_actor','Recipient must be the actor');}
    $schedule->name=(string)$entry['name'];$schedule->idReport=$report->id;$schedule->idResource=getSessionUser()->id;$schedule->idReceiver=getSessionUser()->id;$schedule->otherReceiver='';$schedule->sendFrequency=(string)$entry['frequency'];$schedule->cron=mcpReportsCron($entry);$schedule->reportParameter=json_encode(mcpReportsValidateParameters($entry['parameters']??array()),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);$schedule->idle=!empty($entry['idle'])?1:0;mcpReportsSave($schedule);$schedule->calculNextTime($schedule->cron);
    $saved=new AutoSendReport($schedule->id,true);$status=$operation==='create'?'created':'updated';$items[]=array('index'=>$index,'status'=>$status,'id'=>(int)$saved->id,'version'=>mcpObjectVersion($saved));$effects[]=array('action'=>$status,'objectClass'=>'AutoSendReport','id'=>(int)$saved->id,'externalDelivery'=>true);
  }Sql::commitTransaction();}catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  return array('ok'=>true,'items'=>$items,'effects'=>$effects);
}
