<?php
declare(strict_types=1);

/**
 * Environment actions deliberately delegate persistence to the canonical
 * operation engine.  The module only translates domain-shaped requests into
 * whitelisted ProjeQtOr objects and natural keys.
 */

function mcpEnvironmentActionAvailable(array $action): bool {
  foreach ($action['permissionClasses'] ?? array() as $class) {
    $policy=mcpClassPolicy((string)$class);
    if (!($policy['supported'] ?? false)) return false;
    try {
      if (Security::checkValidAccessForUser(new $class(),'create',null,null,false)) continue;
      if (Security::checkValidAccessForUser(null,'read',$class,null,false)) continue;
    } catch (Throwable $error) {}
    return false;
  }
  return true;
}

function mcpEnvironmentFields(array $item,array $fields): array {
  $data=array();
  foreach ($fields as $field) if (array_key_exists($field,$item)) $data[$field]=$item[$field];
  return $data;
}

function mcpEnvironmentNaturalId(string $class,array $criteria): ?int {
  foreach ($criteria as $value) if ($value===null || $value==='') return null;
  $object=SqlElement::getSingleSqlElementFromCriteria($class,$criteria);
  return $object->id ? (int)$object->id : null;
}

function mcpEnvironmentOperation(string $class,array $item,array $data,array $naturalCriteria=array()): array {
  $verb=(string)($item['operation']??'upsert');
  $id=isset($item['id'])?(int)$item['id']:null;
  if (!$id && $naturalCriteria) $id=mcpEnvironmentNaturalId($class,$naturalCriteria);
  if ($verb==='upsert') $verb=$id?'update':'create';
  if (in_array($verb,array('update','delete'),true) && !$id) {
    mcpJsonError(400,'environment_target_not_found',"$class target was not found",array('objectClass'=>$class,'criteria'=>$naturalCriteria));
  }
  if (in_array($verb,array('update','delete'),true) && empty($item['expectedVersion'])) {
    mcpJsonError(409,'expected_version_required',"$class #$id requires expectedVersion");
  }
  $operation=array('action'=>$verb,'objectClass'=>$class);
  if ($id) $operation['id']=$id;
  if ($verb!=='delete') $operation['data']=$data;
  if (!empty($item['expectedVersion'])) $operation['expectedVersion']=(string)$item['expectedVersion'];
  if (!empty($item['idempotencyKey'])) $operation['idempotencyKey']=(string)$item['idempotencyKey'];
  return $operation;
}

function mcpEnvironmentEffects(array $result): array {
  $effects=array();
  foreach ($result['items']??array() as $item) {
    $status=(string)($item['status']??'');
    if (!in_array($status,array('created','updated','deleted','existing'),true)) continue;
    $ids=!empty($item['relatedIds'])?$item['relatedIds']:array($item['id']??null);
    foreach ($ids as $id) $effects[]=array(
      'action'=>$status==='deleted'?'delete':($status==='created'?'create':'update'),
      'objectClass'=>$item['objectClass']??null,'id'=>$id
    );
  }
  return $effects;
}

function mcpEnvironmentExecuteBatch(array $operations,array $arguments): array {
  if (count($operations)<1 || count($operations)>200) mcpJsonError(400,'invalid_batch','Environment actions require 1 to 200 operations');
  $mode=(string)($arguments['transactionMode']??'atomic');
  $result=mcpExecuteOperationsArray($operations,$mode,true);
  $result['effects']=mcpEnvironmentEffects($result);
  return $result;
}

function mcpEnvironmentCalendarAction(array $arguments,string $username,string $action): array {
  $operations=array();
  foreach ($arguments['entries'] as $entry) {
    $date=(string)$entry['calendarDate'];
    $year=(int)substr($date,0,4);$month=(int)substr($date,5,2);$day=(int)substr($date,8,2);
    if (!checkdate($month,$day,$year)) mcpJsonError(400,'invalid_calendar_date','calendarDate is not a real date',array('calendarDate'=>$date));
    $weekYear=$year;$week=(int)date('W',strtotime($date));
    if ($week===1 && $month===12) $weekYear++;
    elseif ($week>50 && $month===1) $weekYear--;
    $data=mcpEnvironmentFields($entry,array('idCalendarDefinition','calendarDate','isOffDay','name','idle'));
    $data+=array('day'=>sprintf('%04d%02d%02d',$year,$month,$day),'month'=>sprintf('%04d%02d',$year,$month),'year'=>(string)$year,'week'=>sprintf('%04d%02d',$weekYear,$week));
    $criteria=array('idCalendarDefinition'=>(int)$entry['idCalendarDefinition'],'calendarDate'=>$date);
    $operations[]=mcpEnvironmentOperation('Calendar',$entry,$data,$criteria);
  }
  return mcpEnvironmentExecuteBatch($operations,$arguments);
}

function mcpEnvironmentEasterDate(int $year,int $offsetDays): string {
  $base=function_exists('getEaster')?getEaster($year):easter_date($year);
  // ProjeQtOr's getEaster() returns the Saturday preceding Easter Sunday.
  if (function_exists('getEaster')) $offsetDays++;
  return date('Y-m-d',$base+($offsetDays*86400));
}

function mcpEnvironmentBankHolidayAction(array $arguments,string $username,string $action): array {
  $calendarId=(int)$arguments['idCalendarDefinition'];$year=(int)$arguments['year'];$operations=array();
  if (!empty($arguments['clearExisting'])) {
    $calendar=new Calendar();$existing=$calendar->getSqlElementsFromCriteria(array('idCalendarDefinition'=>$calendarId,'year'=>(string)$year),false,null,'id asc');
    foreach ($existing as $entry) $operations[]=array('action'=>'delete','objectClass'=>'Calendar','id'=>(int)$entry->id,'expectedVersion'=>mcpObjectVersion($entry));
  }
  $bank=new CalendarBankOffDays();
  foreach ($bank->getSqlElementsFromCriteria(array('idCalendarDefinition'=>$calendarId),false,null,'id asc') as $entry) {
    if ($entry->easterDay!==null && $entry->easterDay!=='') {
      $offsets=array(0=>0,1=>39,2=>50,3=>-2,4=>60);$offset=$offsets[(int)$entry->easterDay]??null;
      if ($offset===null) continue;
      $date=mcpEnvironmentEasterDate($year,$offset);
    } else {
      $date=sprintf('%04d-%02d-%02d',$year,(int)$entry->month,(int)$entry->day);
      if (!checkdate((int)$entry->month,(int)$entry->day,$year)) continue;
    }
    $month=(int)substr($date,5,2);$day=(int)substr($date,8,2);$week=(int)date('W',strtotime($date));$weekYear=$year;
    if ($week===1 && $month===12) $weekYear++; elseif ($week>50 && $month===1) $weekYear--;
    $criteria=array('idCalendarDefinition'=>$calendarId,'calendarDate'=>$date);
    $item=array('operation'=>!empty($arguments['clearExisting'])?'create':'upsert','idCalendarDefinition'=>$calendarId,'calendarDate'=>$date);
    $data=array('idCalendarDefinition'=>$calendarId,'calendarDate'=>$date,'isOffDay'=>1,'name'=>(string)$entry->name,'idle'=>0,
      'day'=>sprintf('%04d%02d%02d',$year,$month,$day),'month'=>sprintf('%04d%02d',$year,$month),'year'=>(string)$year,'week'=>sprintf('%04d%02d',$weekYear,$week));
    $operations[]=mcpEnvironmentOperation('Calendar',$item,$data,$criteria);
  }
  return mcpEnvironmentExecuteBatch($operations,$arguments);
}

function mcpEnvironmentResourceAction(array $arguments,string $username,string $action): array {
  $fields=array('name','initials','email','capacity','maxDailyWork','maxWeeklyWork','idCalendarDefinition','idProfile','idOrganization','idTeam','contactFunction','phone','mobile','fax','startDate','endDate','isContact','isEmployee','student','subcontractor','isLeaveManager','isMaterial','idle','description','idRole','dontReceiveTeamMails');
  $operations=array();foreach($arguments['resources'] as $item)$operations[]=mcpEnvironmentOperation('Resource',$item,mcpEnvironmentFields($item,$fields));
  return mcpEnvironmentExecuteBatch($operations,$arguments);
}

function mcpEnvironmentTeamAction(array $arguments,string $username,string $action): array {
  $operations=array();
  foreach ($arguments['teams'] as $item) $operations[]=mcpEnvironmentOperation('Team',$item,mcpEnvironmentFields($item,array('name','idResource','idle','description')));
  foreach ($arguments['memberships'] as $item) {
    $operations=array_merge($operations,mcpEnvironmentTeamCascadeOperations($item));
    $criteria=array('idResourceTeam'=>(int)$item['idResourceTeam'],'idResource'=>(int)$item['idResource'],'startDate'=>$item['startDate']??null,'endDate'=>$item['endDate']??null);
    $operations[]=mcpEnvironmentOperation('ResourceTeamAffectation',$item,mcpEnvironmentFields($item,array('idResourceTeam','idResource','rate','description','startDate','endDate','idle')),$criteria);
  }
  return mcpEnvironmentExecuteBatch($operations,$arguments);
}

function mcpEnvironmentContactAction(array $arguments,string $username,string $action): array {
  $operations=array();
  foreach ($arguments['links'] as $item) {
    if ($item['operation']==='link' && empty($item['targetId'])) mcpJsonError(400,'missing_field','targetId is required when linking a contact',array('field'=>'targetId'));
    $field=$item['targetType']==='provider'?'idProvider':'idClient';
    $data=array($field=>$item['operation']==='unlink'?null:(int)$item['targetId']);
    $operations[]=mcpEnvironmentOperation((string)$item['objectClass'],array('operation'=>'update','id'=>(int)$item['id'],'expectedVersion'=>$item['expectedVersion']??null),$data);
  }
  return mcpEnvironmentExecuteBatch($operations,$arguments);
}

function mcpEnvironmentCapacityAction(array $arguments,string $username,string $action): array {
  $operations=array();foreach($arguments['periods'] as $item){$criteria=array('idResource'=>(int)$item['idResource'],'startDate'=>$item['startDate'],'endDate'=>$item['endDate']);$operations[]=mcpEnvironmentOperation('ResourceCapacity',$item,mcpEnvironmentFields($item,array('idResource','capacity','description','startDate','endDate','idle')),$criteria);}return mcpEnvironmentExecuteBatch($operations,$arguments);
}

function mcpEnvironmentCostAction(array $arguments,string $username,string $action): array {
  $operations=array();foreach($arguments['costs'] as $item){$criteria=array('idResource'=>(int)$item['idResource'],'idRole'=>(int)$item['idRole'],'startDate'=>$item['startDate']??null);$operations[]=mcpEnvironmentOperation('ResourceCost',$item,mcpEnvironmentFields($item,array('idResource','idRole','cost','startDate','endDate','idle')),$criteria);}return mcpEnvironmentExecuteBatch($operations,$arguments);
}

function mcpEnvironmentSurbookingAction(array $arguments,string $username,string $action): array {
  $operations=array();foreach($arguments['periods'] as $item){$criteria=array('idResource'=>(int)$item['idResource'],'startDate'=>$item['startDate'],'endDate'=>$item['endDate']);$operations[]=mcpEnvironmentOperation('ResourceSurbooking',$item,mcpEnvironmentFields($item,array('idResource','capacity','description','startDate','endDate','idle')),$criteria);}return mcpEnvironmentExecuteBatch($operations,$arguments);
}

function mcpEnvironmentIncompatibilityAction(array $arguments,string $username,string $action): array {
  $operations=array();foreach($arguments['relationships'] as $item){$criteria=array('idResource'=>(int)$item['idResource'],'idIncompatible'=>(int)$item['idIncompatible']);$operations[]=mcpEnvironmentOperation('ResourceIncompatible',$item,mcpEnvironmentFields($item,array('idResource','idIncompatible','description')),$criteria);}return mcpEnvironmentExecuteBatch($operations,$arguments);
}

function mcpEnvironmentSupportAction(array $arguments,string $username,string $action): array {
  $operations=array();foreach($arguments['relationships'] as $item){$criteria=array('idResource'=>(int)$item['idResource'],'idSupport'=>(int)$item['idSupport']);$operations[]=mcpEnvironmentOperation('ResourceSupport',$item,mcpEnvironmentFields($item,array('idResource','idSupport','rate','description')),$criteria);}return mcpEnvironmentExecuteBatch($operations,$arguments);
}

function mcpEnvironmentOrganizationAction(array $arguments,string $username,string $action): array {
  $fields=array('name','idOrganizationType','idResource','organizationCode','idOrganization','idUser','idle','description','alertOverPct','warningOverPct','okUnderPct','sortOrder');
  $operations=array();foreach($arguments['organizations'] as $item)$operations[]=mcpEnvironmentOperation('Organization',$item,mcpEnvironmentFields($item,$fields));return mcpEnvironmentExecuteBatch($operations,$arguments);
}

function mcpEnvironmentInternalSave(object $object,string $operation,?string $expectedVersion=null): array {
  $class=get_class($object);$id=(int)($object->id??0);
  if ($id && ($expectedVersion===null||$expectedVersion==='')) mcpJsonError(409,'expected_version_required',"$class #$id requires expectedVersion");
  if ($id && $expectedVersion && !hash_equals(mcpObjectVersion($object),$expectedVersion)) mcpJsonError(409,'version_conflict',"$class #$id has changed",array('actualVersion'=>mcpObjectVersion($object)));
  if ($operation==='delete') { SqlElement::setDeleteConfirmed();$raw=$object->delete();$status=getLastOperationStatus($raw);if($status!=='OK')mcpJsonError(400,'delete_failed',cleanApiMessage($raw));return array('status'=>'deleted','objectClass'=>$class,'id'=>$id); }
  $control=method_exists($object,'control')?cleanApiMessage($object->control()):'OK';if($control!==''&&strtoupper($control)!=='OK')mcpJsonError(400,'validation_failed',$control);
  $wasNew=!$id;$raw=$object->save();$status=getLastOperationStatus($raw);if($status!=='OK')mcpJsonError(400,'save_failed',cleanApiMessage($raw));
  return array('status'=>$wasNew?'created':'updated','objectClass'=>$class,'id'=>(int)$object->id,'saved'=>mcpObjectArray($object));
}
