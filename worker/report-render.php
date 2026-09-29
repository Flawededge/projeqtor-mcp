<?php
declare(strict_types=1);

$batchMode=true;
$apiMode=true;
$contextForAttributes='global';
chdir('/var/www/html/mcp-api');
require_once '/var/www/html/tool/projeqtor.php';
require_once '/var/www/html/mcp-api/router.php';
require_once '/var/www/html/mcp-api/modules/reports/module.php';
$batchMode=false;

function reportRunnerStatus(string $path,array $status): void {
  $temporary=$path.'.tmp-'.getmypid();
  file_put_contents($temporary,json_encode($status,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),LOCK_EX);
  rename($temporary,$path);
}

$inputPath=(string)($argv[1]??'');
$output=(string)($argv[2]??'');
$statusPath=$output.'.status';
if($output===''||!preg_match('#^/var/lib/projeqtor/mcp-jobs/job-[0-9]+\.[a-z0-9_-]+\.capture-[a-f0-9]{12}\.html$#D',$output)||$inputPath!==$output.'.input'||!is_file($inputPath)){
  reportRunnerStatus($statusPath,array('ok'=>false,'code'=>'invalid_capture_path','message'=>'Native report capture path is invalid'));
  exit(2);
}
$input=(string)file_get_contents($inputPath);
$payload=json_decode($input,true);
if(!is_array($payload)||!isset($payload['username'],$payload['idReport'],$payload['format'])||!is_array($payload['parameters']??null)){
  reportRunnerStatus($statusPath,array('ok'=>false,'code'=>'invalid_capture_request','message'=>'Native report capture request is invalid'));
  exit(2);
}
$GLOBALS['mcpCaptureErrors']=true;
try{
  $user=SqlElement::getSingleSqlElementFromCriteria('User',array('name'=>(string)$payload['username']));
  if(!$user->id||$user->idle||$user->locked)throw new RuntimeException('Originating user is unavailable');
  $user->_API=true;
  setSessionUser($user);
  $report=mcpReportsRequireReport((int)$payload['idReport'],(string)$payload['format']);
  $content=mcpReportsCaptureHtml($report,$payload['parameters'],(string)$payload['format']);
  if(file_put_contents($output,$content,LOCK_EX)===false)throw new RuntimeException('Native report capture could not be written');
  reportRunnerStatus($statusPath,array('ok'=>true));
}catch(Throwable $error){
  $code=$error instanceof McpBridgeException?$error->errorCode:'native_report_failed';
  reportRunnerStatus($statusPath,array('ok'=>false,'code'=>$code,'message'=>cleanApiMessage($error->getMessage())));
  exit(2);
}
