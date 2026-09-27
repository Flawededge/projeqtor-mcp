<?php
declare(strict_types=1);

$batchMode=true; $apiMode=true; $contextForAttributes='global';
chdir('/var/www/html/mcp-api');
require_once '/var/www/html/tool/projeqtor.php';
require_once '/var/www/html/mcp-api/router.php';
require_once '/usr/local/lib/projeqtor/cron-control.php';
$batchMode=false;

const MCP_WORKER_LEASE_SECONDS=120;
function workerLeaseOwner(): string { static $owner=null;if($owner===null)$owner=(gethostname()?:'worker').':'.getmypid().':'.bin2hex(random_bytes(4));return $owner; }
function workerHeartbeatPath(): string { return '/var/lib/projeqtor/mcp-worker-heartbeat'; }
function workerTouchHeartbeat(): void { $path=workerHeartbeatPath();$tmp=$path.'.tmp-'.getmypid();file_put_contents($tmp,(string)time(),LOCK_EX);rename($tmp,$path); }

function workerUpdate(int $id,string $status,int $progress,?array $result=null,?string $path=null): void {
  $terminal=in_array($status,array('succeeded','failed','cancelled','recovery_required'),true);
  $sql='UPDATE mcpoperation SET status='.Sql::str($status).', progress='.(int)$progress.', updated_at=CURRENT_TIMESTAMP, heartbeat_at=CURRENT_TIMESTAMP';
  if($status==='running')$sql.=', started_at=COALESCE(started_at,CURRENT_TIMESTAMP)';
  if($status==='running')$sql.=', lease_expires_at=CURRENT_TIMESTAMP + INTERVAL \''.MCP_WORKER_LEASE_SECONDS.' seconds\'';
  if($terminal)$sql.=', completed_at=CURRENT_TIMESTAMP,lease_owner=NULL,lease_expires_at=NULL';
  if($result!==null)$sql.=', result_json='.Sql::str(json_encode($result));
  if($result!==null)$sql.=', effects_json='.Sql::str(json_encode($result['effects']??array()));
  if($path!==null)$sql.=', result_path='.Sql::str($path);
  Sql::query($sql.' WHERE id='.Sql::fmtId($id));workerTouchHeartbeat();
}

function workerCancelled(int $id): bool {
  $result=Sql::query('SELECT cancel_requested FROM mcpoperation WHERE id='.Sql::fmtId($id));$row=Sql::fetchLine($result);return $row&&(int)$row['cancel_requested']===1;
}

function workerMaintenance(): void {
  Sql::query("UPDATE mcpoperation SET status='expired', completed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP, nonce=NULL WHERE status='prepared' AND expires_at IS NOT NULL AND expires_at < CURRENT_TIMESTAMP");
  Sql::query("UPDATE mcpoperation SET status='queued',progress=0,lease_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE status='running' AND retry_policy='safe' AND lease_expires_at<CURRENT_TIMESTAMP AND attempts<max_attempts");
  Sql::query("UPDATE mcpoperation SET status='failed',error_code='retry_limit_reached',completed_at=CURRENT_TIMESTAMP,lease_owner=NULL,lease_expires_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE status='running' AND retry_policy='safe' AND lease_expires_at<CURRENT_TIMESTAMP AND attempts>=max_attempts");
  Sql::query("UPDATE mcpoperation SET status='recovery_required',recovery_state='interrupted_non_idempotent',error_code='worker_lease_expired',completed_at=CURRENT_TIMESTAMP,lease_owner=NULL,lease_expires_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE status='running' AND retry_policy='recovery_required' AND lease_expires_at<CURRENT_TIMESTAMP");
  Sql::query("UPDATE mcpoperation SET status='failed',error_code='worker_lease_expired',completed_at=CURRENT_TIMESTAMP,lease_owner=NULL,lease_expires_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE status='running' AND retry_policy='never' AND lease_expires_at<CURRENT_TIMESTAMP");
  Sql::query("DELETE FROM mcpoperation WHERE completed_at IS NOT NULL AND completed_at < CURRENT_TIMESTAMP - INTERVAL '30 days'");
  foreach(glob('/var/lib/projeqtor/mcp-jobs/job-*')?:array() as $file)if(filemtime($file)<time()-7*86400)@unlink($file);
  foreach(glob('/var/lib/projeqtor/mcp-jobs/*.tmp-*')?:array() as $file)if(filemtime($file)<time()-300)@unlink($file);
  foreach(glob('/var/lib/projeqtor/mcp-uploads/*')?:array() as $file)if(filemtime($file)<time()-3600)@unlink($file);
  workerTouchHeartbeat();
}

function workerArtifactPath(int $id,string $extension): string {
  $directory='/var/lib/projeqtor/mcp-jobs';if(!is_dir($directory))mkdir($directory,0700,true);return $directory.'/job-'.$id.'.'.$extension;
}

function workerProjectReferenceWhere(array $references,string $typeField='refType',string $idField='refId'): string {
  $parts=array();
  foreach($references as $class=>$identifiers){
    $ids=array_values(array_filter(array_map('intval',array_keys($identifiers)),fn($id)=>$id>0));
    if(!count($ids))continue;
    $parts[]='('.$typeField.'='.Sql::str($class).' AND '.$idField.' IN ('.implode(',',$ids).'))';
  }
  return count($parts)?'('.implode(' OR ',$parts).')':'(1=0)';
}

function workerSnapshot(int $jobId,array $arguments): array {
  $idProject=(int)($arguments['idProject']??0);$project=new Project($idProject);if(!$project->id||!Security::checkValidAccessForUser($project,'read',null,null,false))throw new RuntimeException('Project is unavailable');
  $classes=array('Project','Activity','Milestone','Meeting','PeriodicMeeting','TestSession','Sprint','Ticket','Requirement','Risk','Issue','Opportunity','Action','Document','Affectation','Assignment','Dependency','Link','Note','Attachment','Baseline','PlanningElement','PlannedWork','Work');
  $path=workerArtifactPath($jobId,'ndjson');$temporary=$path.'.tmp-'.bin2hex(random_bytes(6));$handle=fopen($temporary,'xb');$watermark=date(DATE_ATOM);$counts=array();$projectRefs=array('Project'=>array($idProject=>true));
  fwrite($handle,json_encode(array('recordType'=>'snapshot','schemaVersion'=>1,'idProject'=>$idProject,'historyWatermark'=>$watermark))."\n");
  foreach($classes as $index=>$class){if(workerCancelled($jobId)){fclose($handle);@unlink($temporary);throw new RuntimeException('cancelled');} $policy=mcpClassPolicy($class);if(!$policy['supported']||!in_array('read',$policy['operations'],true))continue;$obj=new $class();
    if($class==='Project')$where='id='.Sql::fmtId($idProject);
    else if(property_exists($obj,'idProject'))$where='idProject='.Sql::fmtId($idProject);
    else if($class==='Dependency')$where='('.workerProjectReferenceWhere($projectRefs,'predecessorRefType','predecessorRefId').' OR '.workerProjectReferenceWhere($projectRefs,'successorRefType','successorRefId').')';
    else if(property_exists($obj,'refType')&&property_exists($obj,'refId'))$where=workerProjectReferenceWhere($projectRefs);
    else continue;
    $nativeClassRead=Security::checkValidAccessForUser(null,'read',$class,null,false);$where.=' AND '.($nativeClassRead?getAccesRestrictionClause($class,null,true):'1=1');$list=$obj->getSqlElementsFromCriteria(null,false,$where,'id asc',false,true);
    if(!$nativeClassRead)$list=array_values(array_filter($list,fn($item)=>mcpContextualReadAllowed($item)));
    $counts[$class]=count($list);foreach($list as $item){$projectRefs[$class][(int)$item->id]=true;fwrite($handle,json_encode(array('recordType'=>'object','objectClass'=>$class,'item'=>mcpObjectArray($item)),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");}workerUpdate($jobId,'running',min(90,5+(int)(85*($index+1)/count($classes))));
  }
  fflush($handle);fclose($handle);if(!rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('Atomic snapshot publication failed');}return array('ok'=>true,'idProject'=>$idProject,'historyWatermark'=>$watermark,'counts'=>$counts,'resource'=>'projeqtor://jobs/'.$jobId.'/result','path'=>$path);
}

function workerPlanning(int $jobId,array $arguments): array {
  $projectIds=array_values(array_filter(array_map('intval',$arguments['projectIds']??array()),fn($id)=>$id>0));if(!count($projectIds))throw new RuntimeException('At least one project id is required');foreach($projectIds as $id){$project=new Project($id);if(!$project->id||!Security::checkValidAccessForUser($project,'update',null,null,false))throw new RuntimeException("Planning access is denied for project #$id");}
  $start=(string)($arguments['startDate']??date('Y-m-d'));$criticalPath=!empty($arguments['criticalPath'])?1:0;$overbooking=!empty($arguments['allowOverbooking']);$critical=!empty($arguments['criticalResourceMode']);workerUpdate($jobId,'running',10);if(workerCancelled($jobId))throw new RuntimeException('cancelled');ob_start();$raw=PlannedWork::plan($projectIds,$start,$criticalPath,$overbooking,false,$critical,null,0);ob_end_clean();workerUpdate($jobId,'running',90);
  $diagnostics=array();foreach($projectIds as $id)$diagnostics[] = mcpPlanningDiagnostics($id);return array('ok'=>!str_contains((string)$raw,'lastPlanStatus" value="ERROR'),'status'=>str_contains((string)$raw,'INCOMPLETE')?'incomplete':'complete','message'=>cleanApiMessage($raw),'projects'=>$projectIds,'diagnostics'=>$diagnostics);
}

function workerBaseline(array $arguments): array {
  $project=new Project((int)($arguments['idProject']??0));if(!$project->id||!Security::checkValidAccessForUser($project,'update',null,null,false))throw new RuntimeException('Project is unavailable');$baseline=new Baseline();$baseline->idProject=$project->id;$baseline->name=(string)$arguments['name'];$baseline->baselineDate=(string)($arguments['date']??date('Y-m-d'));$baseline->idUser=getSessionUser()->id;$baseline->creationDateTime=date('Y-m-d H:i:s');$baseline->idPrivacy=(int)($arguments['privacy']??1);$resource=new Resource(getSessionUser()->id);$baseline->idTeam=$resource->idTeam;$raw=$baseline->saveWithPlanning();if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));return array('ok'=>true,'baseline'=>mcpObjectArray(new Baseline($baseline->id)));
}

function workerExport(int $jobId,array $arguments): array {
  $class=(string)($arguments['objectClass']??'');mcpRequireClassOperation($class,'read');$obj=new $class();$where=getAccesRestrictionClause($class,null,true);$list=$obj->getSqlElementsFromCriteria(null,false,$where,'id asc',false,true);$format=(string)($arguments['format']??'ndjson');if(!in_array($format,array('json','ndjson','csv'),true))$format='ndjson';$path=workerArtifactPath($jobId,$format);$temporary=$path.'.tmp-'.bin2hex(random_bytes(6));$handle=fopen($temporary,'xb');
  if($format==='json')fwrite($handle,'[');$first=true;$headers=null;foreach($list as $index=>$item){if(workerCancelled($jobId)){fclose($handle);@unlink($temporary);throw new RuntimeException('cancelled');}$row=mcpObjectArray($item);if($format==='ndjson')fwrite($handle,json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");else if($format==='json'){if(!$first)fwrite($handle,',');fwrite($handle,json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$first=false;}else{if($headers===null){$headers=array_keys($row);fputcsv($handle,$headers);}fputcsv($handle,array_map(fn($key)=>is_scalar($row[$key]??null)?$row[$key]:'',$headers));}if($index%100===0)workerUpdate($jobId,'running',min(90,5+(int)(85*($index+1)/max(1,count($list)))));}
  if($format==='json')fwrite($handle,']');fflush($handle);fclose($handle);if(!rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('Atomic export publication failed');}return array('ok'=>true,'objectClass'=>$class,'format'=>$format,'count'=>count($list),'resource'=>'projeqtor://jobs/'.$jobId.'/result','path'=>$path);
}

function workerImport(array $arguments,string $username): array {
  $uploadId=(string)($arguments['uploadId']??'');$meta=mcpReadUpload($uploadId,$username);$class=(string)($arguments['objectClass']??'');mcpRequireClassOperation($class,'create');
  $path=mcpUploadDataPath($uploadId);if(!is_file($path))throw new RuntimeException('Import upload is unavailable');
  $runId=(string)($arguments['importRunId']??('mcp:'.date('YmdHis')));$object=new $class();$where=getAccesRestrictionClause($class,null,true);
  $before=array();foreach($object->getSqlElementsFromCriteria(null,false,$where,'id asc',false,true) as $item)$before[(int)$item->id]=true;
  $raw=Importable::import($path,$class);
  $created=array();foreach($object->getSqlElementsFromCriteria(null,false,$where,'id asc',false,true) as $item){
    if(isset($before[(int)$item->id]))continue;
    $created[]=array('status'=>'created','objectClass'=>$class,'id'=>(int)$item->id,'saved'=>array('_version'=>mcpObjectVersion($item)));
  }
  $result=array('ok'=>!str_contains(strtoupper((string)$raw),'ERROR'),'objectClass'=>$class,'importRunId'=>$runId,'message'=>cleanApiMessage($raw),'created'=>count($created));
  $result['importJournal']=mcpRecordImportRun($username,$runId,array('items'=>$created));
  return $result;
}

function workerExecute(array $row): array {
  $payload=json_decode((string)$row['payload'],true);if(!is_array($payload))throw new RuntimeException('Invalid job payload');$action=(string)($payload['action']??$row['operation_type']);$arguments=is_array($payload['arguments']??null)?$payload['arguments']:array();$id=(int)$row['id'];
  mcpValidateActionArguments($action,$arguments);
  $registry=mcpWorkerActionRegistry();if(!isset($registry[$action]))throw new RuntimeException("Unsupported job action '$action'");$executor=$registry[$action];$result=$executor($id,$arguments,(string)$row['username']);
  if(!is_array($result))throw new RuntimeException("Worker action '$action' returned an invalid result");
  return mcpValidateActionResult($action,$result);
}

mcpAssertPolicyComplete();
mcpEnsureOperationTable();
$lastMaintenance=0;workerTouchHeartbeat();
while(true){
  if(time()-$lastMaintenance>=60){workerMaintenance();$lastMaintenance=time();}
  Sql::beginTransaction();$result=Sql::query("SELECT * FROM mcpoperation WHERE status='queued' AND attempts<max_attempts ORDER BY id ASC FOR UPDATE SKIP LOCKED LIMIT 1");$row=Sql::fetchLine($result);if($row)Sql::query("UPDATE mcpoperation SET status='running',progress=1,attempts=attempts+1,lease_owner=".Sql::str(workerLeaseOwner()).",lease_expires_at=CURRENT_TIMESTAMP + INTERVAL '".MCP_WORKER_LEASE_SECONDS." seconds',heartbeat_at=CURRENT_TIMESTAMP,started_at=COALESCE(started_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE id=".Sql::fmtId($row['id'])." AND status='queued'");Sql::commitTransaction();workerTouchHeartbeat();
  if(!$row){if(getenv('MCP_WORKER_ONCE')==='1')break;sleep(2);continue;}
  $startedAt=microtime(true);$user=SqlElement::getSingleSqlElementFromCriteria('User',array('name'=>$row['username']));if(!$user->id||$user->idle||$user->locked){workerUpdate((int)$row['id'],'failed',100,array('ok'=>false,'error'=>array('code'=>'user_unavailable','message'=>'Originating user is unavailable')));Sql::query("UPDATE mcpoperation SET error_code='user_unavailable' WHERE id=".Sql::fmtId($row['id']));error_log(json_encode(array('operationId'=>(int)$row['id'],'actor'=>$row['username'],'action'=>$row['operation_type'],'durationMs'=>(int)((microtime(true)-$startedAt)*1000),'outcome'=>'failed:user_unavailable')));continue;}$user->_API=true;setSessionUser($user);
  try{if(workerCancelled((int)$row['id']))throw new RuntimeException('cancelled');$output=workerExecute($row);$path=$output['path']??null;unset($output['path']);workerUpdate((int)$row['id'],'succeeded',100,$output,$path);$outcome='succeeded';}catch(Throwable $error){$cancelled=$error->getMessage()==='cancelled';$code=$cancelled?'cancelled':'job_failed';workerUpdate((int)$row['id'],$cancelled?'cancelled':'failed',100,array('ok'=>false,'error'=>array('code'=>$code,'message'=>cleanApiMessage($error->getMessage()))));Sql::query('UPDATE mcpoperation SET error_code='.Sql::str($code).' WHERE id='.Sql::fmtId($row['id']));foreach(glob('/var/lib/projeqtor/mcp-jobs/job-'.(int)$row['id'].'.*.tmp-*')?:array() as $temporary)@unlink($temporary);$outcome=($cancelled?'cancelled':'failed:'.$code);}
  error_log(json_encode(array('operationId'=>(int)$row['id'],'actor'=>$row['username'],'action'=>$row['operation_type'],'durationMs'=>(int)((microtime(true)-$startedAt)*1000),'outcome'=>$outcome)));
  workerMaintenance();$lastMaintenance=time();
  if(getenv('MCP_WORKER_ONCE')==='1')break;
}
