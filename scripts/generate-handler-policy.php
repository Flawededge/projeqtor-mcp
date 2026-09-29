<?php
declare(strict_types=1);
if ($argc!==3){fwrite(STDERR,"Usage: php generate-handler-policy.php <source> <output>\n");exit(2);}
$root=rtrim(realpath($argv[1])?:'','/');
if(!$root||!is_dir("$root/tool")||!is_dir("$root/view")){fwrite(STDERR,"Invalid ProjeQtOr source root\n");exit(2);}
const SOURCE_VERSION='13.1.0';
const SOURCE_ARCHIVE_SHA256='221c2a0b2facbdfc0b5af9878e030cdd7ded6e3f989b1da9cd609eccebaa0a69';
$issues=array(
 'planning_followup_environment'=>'https://github.com/Flawededge/projeqtor-mcp/issues/2',
 'ticketing_scrum'=>'https://github.com/Flawededge/projeqtor-mcp/issues/3',
 'steering_reports'=>'https://github.com/Flawededge/projeqtor-mcp/issues/4',
 'financial_products'=>'https://github.com/Flawededge/projeqtor-mcp/issues/5',
 'hr_tools_configuration'=>'https://github.com/Flawededge/projeqtor-mcp/issues/6'
);
$registered=array(
 'copyObject'=>'object.copy','copyObjectTo'=>'object.copy','copyProjectTo'=>'object.copy',
 'changeObjectStatus'=>'workflow.transition','saveStatus'=>'workflow.transition',
 'saveBaseline'=>'planning.baseline.create','deleteBaseline'=>'planning.baseline.delete',
 'startPlanningCalculation'=>'planning.calculate','planningCalculation'=>'planning.calculate',
 'importData'=>'import.start','importDataFromFile'=>'import.start','exportData'=>'export.start','exportPlanning'=>'export.start',
 'saveAttachment'=>'attachment.upload.commit','deleteAttachment'=>'attachment.upload.abort',
 'sendResetPassword'=>'user.trigger_password_reset','resetPassword'=>'user.trigger_password_reset',
 'cronCheck'=>'cron.check','cronActivation'=>'cron.start','cronStop'=>'cron.stop','cronRelaunch'=>'cron.restart','cronRun'=>'cron.restart'
);
function scannerTokens(string $source):array{
 $out=array();foreach(token_get_all($source) as $token){
  if(is_array($token)){if(in_array($token[0],array(T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_OPEN_TAG,T_CLOSE_TAG),true))continue;$out[]=strtolower($token[1]);}
  else $out[]=strtolower($token);
 }return $out;
}
function scannerMutationKinds(array $tokens):array{
 $joined=implode(' ',$tokens);$out=array();$patterns=array(
  'object_create_update'=>'/(?:->|::)\s*(?:save|savework|savewithplanning|create|insert)\s*\(/',
  'object_delete'=>'/(?:->|::)\s*(?:delete|purge|remove)\s*\(/',
  'sql_write'=>'/\bsql\s*::\s*query\s*\([^;]*(?:insert\s+into|update\s+|delete\s+from|alter\s+|create\s+table|drop\s+)/s',
  'file_write'=>'/\b(?:file_put_contents|fwrite|fputcsv|rename|unlink|move_uploaded_file|mkdir|rmdir)\s*\(/',
  'mail_or_notification'=>'/\b(?:sendmail|sendnotification|sendalert|sendmailgroup)\s*\(/',
  'workflow_or_calculation'=>'/(?:->|::)\s*(?:plan|calculate|approve|reject|submit|validate|close|reopen|cancel|copy)\s*\(/',
  'session_or_configuration'=>'/\b(?:setsessionuser|setglobalparameter|setuserparameter)\s*\(/'
 );foreach($patterns as $kind=>$pattern)if(preg_match($pattern,$joined))$out[]=$kind;return $out;
}
function scannerModule(string $name):string{
 $v=strtolower($name);$groups=array(
  'planning_followup_environment'=>'/(planning|planned|work|calendar|leave|resource|capacity|assignment|affectation|follow|environment|critical|surbook|meeting)/',
  'ticketing_scrum'=>'/(ticket|sprint|kanban|backlog|scrum|agile|story|impediment)/',
  'steering_reports'=>'/(report|dashboard|indicator|kpi|risk|issue|opportunity|decision|question|steer|review|approval|vote|checklist)/',
  'financial_products'=>'/(expense|budget|bill|payment|tender|quotation|order|invoice|contract|product|version|component|supplier|revenue|cost)/',
  'hr_tools_configuration'=>'/(employee|skill|absence|human|user|profile|parameter|workflow|notification|mail|automation|locali|asset|admin|config|menu|filter|layout|oauth|password)/'
 );foreach($groups as $group=>$pattern)if(preg_match($pattern,$v))return $group;return 'hr_tools_configuration';
}
function scannerExclusion(string $name):?string{
 $v=strtolower($name);
 if(preg_match('/(rawsql|sqlquery|sqlsearch)/',$v))return 'raw_sql';
 if(preg_match('/(password|oauth|apikey|credential|secret|token)/',$v))return 'secrets_or_credentials';
 if(preg_match('/(plugin|subscriptioncode|installplugin)/',$v))return 'plugin_installation';
 if(preg_match('/(database|backup|restore|container|hostadmin)/',$v))return 'host_container_database_or_backup_administration';
 return null;
}
$paths=array();
foreach(array('tool','view') as $dir)foreach(glob("$root/$dir/*.php")?:array() as $path){
 $relative=substr($path,strlen($root)+1);
 if($relative==='tool/parametersLocation.php')continue;
 $paths[]=$path;
}
sort($paths,SORT_STRING);
$handlers=array();$mutations=0;$tree=hash_init('sha256');
foreach($paths as $path){
 $relative=substr($path,strlen($root)+1);$source=(string)file_get_contents($path);$hash=hash('sha256',$source);hash_update($tree,$relative."\0".$hash."\n");
 $name=basename($path,'.php');$kinds=scannerMutationKinds(scannerTokens($source));
 // This endpoint only clears the current session after a rejected request.
 // It does not mutate application data or persistent configuration.
 if($name==='hackMessage')$kinds=array();
 $isMutation=(bool)count($kinds);if($isMutation)$mutations++;
 $actions=array();$classes=array();$exclusion=scannerExclusion($name);
 if(!$isMutation){$classification='read_only';$risk='read';}
 elseif(preg_match('/^(saveObject|deleteObject|deleteObjectMultiple|deleteObjectMultipleControl)$/',$name)){$classification='generic_crud';$risk=str_starts_with($name,'delete')?'destructive':'write';$classes=array('*');}
 elseif(isset($registered[$name])){$classification='registered_action';$risk=str_contains(strtolower($name),'delete')||$name==='cronStop'?'destructive':'write';$actions=array($registered[$name]);}
 elseif($exclusion!==null){$classification='intentional_exclusion';$risk='excluded';}
 else{$classification='deferred_beta4';$risk='write';}
 $module=scannerModule($relative);
 $handlers[]=array('id'=>str_replace('/',':',substr($relative,0,-4)),'path'=>$relative,'sourceHash'=>$hash,'modules'=>array($module),'mutationTypes'=>$kinds,'classification'=>$classification,'mappedClasses'=>$classes,'mappedActions'=>$actions,'risk'=>$risk,'availability'=>'installed','exclusionReason'=>$exclusion,'beta4Issue'=>$classification==='deferred_beta4'?$issues[$module]:null);
}
$counts=array_fill_keys(array('generic_crud','registered_action','read_only','intentional_exclusion','deferred_beta4'),0);foreach($handlers as $handler)$counts[$handler['classification']]++;
$manifest=array('policyVersion'=>3,'sourceVersion'=>SOURCE_VERSION,'sourceArchiveSha256'=>SOURCE_ARCHIVE_SHA256,'sourceTreeHash'=>hash_final($tree),'entrypointCount'=>count($handlers),'mutationCandidateCount'=>$mutations,'classificationCounts'=>$counts,'handlers'=>$handlers);
file_put_contents($argv[2],json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDOUT,json_encode(array('entrypoints'=>count($handlers),'mutations'=>$mutations,'counts'=>$counts))."\n");
