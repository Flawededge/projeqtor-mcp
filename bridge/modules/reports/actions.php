<?php
declare(strict_types=1);

const MCP_REPORTS_PARAMETER_LIMIT=100;
const MCP_REPORTS_PARAMETER_BYTES=65536;

function mcpReportsError(string $code,string $message): never {
  $status=$code==='version_conflict'?409:(str_contains($code,'access_denied')||$code==='object_access_denied'||$code==='recipient_not_actor'?403:(str_contains($code,'unavailable')?404:400));
  if(class_exists('McpBridgeException'))throw new McpBridgeException($status,$code,$message);
  throw new RuntimeException($code.': '.$message);
}

function mcpReportsValidateParameters(array $parameters): array {
  if(count($parameters)>MCP_REPORTS_PARAMETER_LIMIT)mcpReportsError('report_parameter_limit','A report accepts at most 100 parameters');
  $reserved=array('page','outMode','attach','context','csrfToken','directAccessIndex');
  foreach($parameters as $name=>$value){
    if(!is_string($name)||!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/D',$name)||in_array($name,$reserved,true))mcpReportsError('invalid_report_parameter','A parameter name is invalid or reserved');
    $values=is_array($value)?$value:array($value);if(count($values)>200)mcpReportsError('report_parameter_limit','A list parameter accepts at most 200 values');
    foreach($values as $entry)if(!is_string($entry)&&!is_int($entry)&&!is_float($entry)&&!is_bool($entry)&&$entry!==null)mcpReportsError('invalid_report_parameter','Parameter values must be scalar');
  }
  $encoded=json_encode($parameters,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  if($encoded===false||strlen($encoded)>MCP_REPORTS_PARAMETER_BYTES)mcpReportsError('report_parameter_bytes','Report parameters exceed 64 KiB');
  return $parameters;
}

function mcpReportsRequireReport(int $idReport,string $format='json'): Report {
  $report=new Report($idReport,true);if(!$report->id||$report->idle)mcpReportsError('report_unavailable','Report is unavailable');
  $user=getSessionUser();$right=SqlElement::getSingleSqlElementFromCriteria('HabilitationReport',array('idProfile'=>$user->idProfile,'idReport'=>$report->id,'allowAccess'=>'1'));
  if(!$right->id||!Module::isReportActive($report->name))mcpReportsError('report_access_denied','Native report permission is denied');
  $flags=array('pdf'=>'hasPdf','png'=>'hasView','jpeg'=>'hasView','csv'=>'hasCsv','json'=>'hasView');
  if(isset($flags[$format])&&!$report->{$flags[$format]})mcpReportsError('report_format_denied',"Report does not expose $format output");
  if(str_contains((string)$report->file,"\0")||str_contains((string)$report->file,'\\'))mcpReportsError('unsafe_report_path','Report path is unsafe');
  return $report;
}

function mcpReportsNativeTarget(Report $report,string $format): array {
  $parts=parse_url((string)$report->file);if($parts===false||isset($parts['scheme'])||isset($parts['host'])||isset($parts['fragment']))mcpReportsError('unsafe_report_path','Report path is unsafe');
  $relative=rawurldecode((string)($parts['path']??''));$query=array();parse_str((string)($parts['query']??''),$query);
  if(str_starts_with($relative,'../tool/')){$tool=substr($relative,8);$allowed=array('jsonPlanning.php','jsonResourcePlanning.php');if(!in_array($tool,$allowed,true))mcpReportsError('unsafe_report_path','Tool report is not allowlisted');if($format==='pdf')$tool=substr($tool,0,-4).'_pdf.php';$base='/var/www/html/tool';$candidate=$base.'/'.$tool;}
  else{if(str_contains($relative,'/')||$relative===''||!preg_match('/^[A-Za-z0-9_.-]+\.php$/D',$relative))mcpReportsError('unsafe_report_path','Report file is not allowlisted');$base='/var/www/html/report';$candidate=$base.'/'.$relative;}
  $real=realpath($candidate);$root=realpath($base);if(!$real||!$root||!str_starts_with($real,$root.DIRECTORY_SEPARATOR)||!is_file($real))mcpReportsError('report_file_unavailable','Native report file is unavailable');
  return array('path'=>$real,'query'=>mcpReportsValidateParameters($query));
}

function mcpReportsRequireOwned(object $object,string $version): void {
  $owner=property_exists($object,'idUser')?(int)$object->idUser:(property_exists($object,'idResource')?(int)$object->idResource:0);
  if(!$object->id||$owner!==(int)getSessionUser()->id)mcpReportsError('object_access_denied','Object is not owned by the actor');
  if($version==='')mcpReportsError('expected_version_required','expectedVersion is required');
  if(!hash_equals(mcpObjectVersion($object),$version))mcpReportsError('version_conflict','Object was modified');
}
function mcpReportsSave(object $object): void {$raw=$object->save();if(getLastOperationStatus($raw)!=='OK')mcpReportsError('native_save_failed',cleanApiMessage($raw));}
function mcpReportsDelete(object $object): void {$raw=$object->delete();if(getLastOperationStatus($raw)!=='OK')mcpReportsError('native_delete_failed',cleanApiMessage($raw));}
function mcpReportsEffect(string $action,string $class,int $id): array{return array('action'=>$action,'objectClass'=>$class,'id'=>$id);}
function mcpReportsClassForAction(string $actionId): string {return str_contains($actionId,'layout')?'ReportLayout':(str_contains($actionId,'dashboard')?'Today':(str_contains($actionId,'schedule')?'AutoSendReport':'Favorite'));}
function mcpReportsErrorPayload(Throwable $error): array {$code=$error instanceof McpBridgeException?$error->errorCode:'reports_operation_failed';return array('code'=>(string)$code,'message'=>cleanApiMessage($error->getMessage()));}
function mcpReportsSuccess(array $items,array $effects,string $mode='atomic'): array {return array('ok'=>true,'rolledBack'=>false,'transactionMode'=>$mode,'items'=>$items,'effects'=>$effects);}
function mcpReportsFailure(array $items,int $index,array $entry,Throwable $error,string $actionId): array {foreach($items as &$item){$item['status']='rolled_back';unset($item['version']);}unset($item);$items[]=array('index'=>$index,'status'=>'error','objectClass'=>mcpReportsClassForAction($actionId),'id'=>isset($entry['id'])?(int)$entry['id']:null,'error'=>mcpReportsErrorPayload($error));return array('ok'=>false,'rolledBack'=>true,'transactionMode'=>'atomic','items'=>$items,'effects'=>array());}
function mcpReportsBestEffort(array $arguments,string $username,string $actionId,callable $callable): ?array {
  $mode=(string)($arguments['transactionMode']??'atomic');if($mode!=='best_effort')return null;$items=array();$effects=array();$ok=true;
  foreach($arguments['items'] as $index=>$entry){try{$result=$callable(array('items'=>array($entry),'transactionMode'=>'atomic'),$username,$actionId);$item=$result['items'][0]??array('status'=>'error','error'=>array('code'=>'missing_result','message'=>'The operation returned no item result'));if(!($result['ok']??false))$ok=false;foreach($result['effects']??array() as $effect)$effects[]=$effect;}catch(Throwable $error){$ok=false;$item=array('status'=>'error','objectClass'=>mcpReportsClassForAction($actionId),'id'=>isset($entry['id'])?(int)$entry['id']:null,'error'=>mcpReportsErrorPayload($error));}$item['index']=$index;$items[]=$item;}
  return array('ok'=>$ok,'rolledBack'=>false,'transactionMode'=>'best_effort','items'=>$items,'effects'=>$effects);
}

function mcpReportsCatalogAction(array $arguments,string $username,string $actionId): array {
  $requested=(int)($arguments['idReport']??0);$reports=array();$source=new HabilitationReport();
  foreach($source->getSqlElementsFromCriteria(array('idProfile'=>getSessionUser()->idProfile,'allowAccess'=>'1'),false,null,'idReport asc') as $right){
    if($requested&&(int)$right->idReport!==$requested)continue;$report=new Report((int)$right->idReport,true);if(!$report->id||$report->idle||!Module::isReportActive($report->name))continue;
    $parameters=array();foreach((new ReportParameter())->getSqlElementsFromCriteria(array('idReport'=>$report->id),false,null,'sortOrder asc,id asc') as $entry)$parameters[]=array('name'=>(string)$entry->name,'type'=>(string)$entry->paramType,'required'=>(bool)$entry->required,'defaultValue'=>$entry->defaultValue);
    $formats=array();foreach(array('pdf'=>'hasPdf','csv'=>'hasCsv','json'=>'hasView','png'=>'hasView','jpeg'=>'hasView') as $format=>$flag)if($report->$flag)$formats[]=$format;
    $reports[]=array('id'=>(int)$report->id,'name'=>(string)$report->name,'categoryId'=>(int)$report->idReportCategory,'orientation'=>(string)$report->orientation,'formats'=>$formats,'parameters'=>$parameters);
  }
  return array('ok'=>true,'reports'=>$reports,'count'=>count($reports),'effects'=>array());
}

function mcpReportsDashboardAction(array $arguments,string $username,string $actionId): array {
  $kind=(string)$arguments['dashboard'];$projectId=(int)($arguments['projectId']??0);$widgets=array();
  if($projectId){$project=new Project($projectId,true);if(!$project->id||!Security::checkValidAccessForUser($project,'read',null,null,false))mcpReportsError('project_access_denied','Project is unavailable');$widgets[]=array('key'=>'project','value'=>(string)$project->name);}
  $classes=$kind==='ticket'?array('Ticket'):($kind==='requirement'?array('Requirement','TestSession'):array('Activity','Milestone','Risk','Issue'));
  foreach($classes as $class){if(!Security::checkValidAccessForUser(null,'read',$class,null,false))mcpReportsError('dashboard_access_denied',"Read access is denied for $class");$object=new $class();$where=getAccesRestrictionClause($class,null,true);if($projectId&&property_exists($object,'idProject'))$where.=' AND idProject='.Sql::fmtId($projectId);$widgets[]=array('key'=>strtolower($class).'Count','value'=>(string)$object->countSqlElementsFromCriteria(null,$where));}
  return array('ok'=>true,'dashboard'=>$kind,'projectId'=>$projectId?:null,'generatedAt'=>date(DATE_ATOM),'widgets'=>$widgets,'effects'=>array());
}

function mcpReportsPreview(array $arguments,string $username,string $actionId): array {
  return array('action'=>$actionId,'actor'=>$username,'itemCount'=>count($arguments['items']??array()),'externalDelivery'=>str_contains($actionId,'schedule')||str_contains($actionId,'delivery'),'payloadRedacted'=>true);
}
function mcpReportsRenderAvailable(array $action): bool {if(!class_exists('Report')||!class_exists('HabilitationReport'))return false;try{$user=getSessionUser();return (new HabilitationReport())->countSqlElementsFromCriteria(array('idProfile'=>(int)$user->idProfile,'allowAccess'=>'1'))>0;}catch(Throwable $error){return false;}}
