<?php
declare(strict_types=1);

function mcpReportsCaptureHtml(Report $report,array $parameters,string $format): string {
  global $displayResource,$outMode,$showMilestone,$portfolio,$columnsDescription,$graphEnabled,$showProject,$rgbPalette,$arrayColors,$cronnedScript,$print;
  $target=mcpReportsNativeTarget($report,$format);$parameters=array_merge(mcpReportsValidateParameters($parameters),$target['query']);$request=$_REQUEST;$post=$_POST;$directory=getcwd();$priorOut=$outMode??null;$priorPrint=$print??null;$priorCronned=$cronnedScript??null;
  $_REQUEST=$parameters;$_POST=$parameters;$outMode=$format==='csv'?'csv':($format==='pdf'?'pdf':'html');$print=true;$cronnedScript=true;foreach($parameters as $name=>$value)RequestHandler::setValue($name,$value);chdir(dirname($target['path']));ob_start();
  try{include $target['path'];$content=(string)ob_get_clean();}catch(Throwable $error){ob_end_clean();throw $error;}finally{$_REQUEST=$request;$_POST=$post;chdir($directory);$outMode=$priorOut;$print=$priorPrint;$cronnedScript=$priorCronned;}
  if($content==='')mcpReportsError('empty_report','Native report returned no content');return $content;
}
function mcpReportsCaptureIsolated(int $jobId,Report $report,array $parameters,string $nativeFormat,string $finalFormat,string $username): string {
  $capture=null;$statusPath=null;$inputPath=null;
  $previousHandler=set_error_handler(static function(int $severity,string $message,string $file,int $line): never {throw new ErrorException($message,0,$severity,$file,$line);});
  error_log(json_encode(array('operationId'=>$jobId,'component'=>'report-runner','phase'=>'start')));
  try{
    $final=workerArtifactPath($jobId,$finalFormat);
    $capture=$final.'.capture-'.bin2hex(random_bytes(6)).'.html';
    $statusPath=$capture.'.status';
    $inputPath=$capture.'.input';
    $payload=json_encode(array('username'=>$username,'idReport'=>(int)$report->id,'format'=>$nativeFormat,'parameters'=>$parameters),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    if(file_put_contents($inputPath,$payload,LOCK_EX)===false)mcpReportsError('report_runner_input_failed','Native report request could not be staged');
    error_log(json_encode(array('operationId'=>$jobId,'component'=>'report-runner','phase'=>'input_staged')));
    $descriptors=array(0=>array('file','/dev/null','r'),1=>array('file','/dev/null','a'),2=>array('file','/dev/null','a'));
    $process=proc_open(array(PHP_BINARY,'/usr/local/lib/projeqtor/report-render.php',$inputPath,$capture),$descriptors,$pipes,null,null,array('bypass_shell'=>true));
    if(!is_resource($process))mcpReportsError('report_runner_unavailable','Native report runner could not be started');
    $exit=proc_close($process);
    error_log(json_encode(array('operationId'=>$jobId,'component'=>'report-runner','phase'=>'child_exited','exitCode'=>$exit)));
    $status=is_file($statusPath)?json_decode((string)file_get_contents($statusPath),true):null;
    if($exit!==0||!is_array($status)||($status['ok']??false)!==true||!is_file($capture)){
      $code=is_array($status)?(string)($status['code']??'native_report_failed'):'native_report_terminated';
      $message=is_array($status)?(string)($status['message']??'Native report failed'):'Native report terminated before publishing a capture';
      mcpReportsError($code,$message);
    }
    $bytes=(int)filesize($capture);
    $max=max(1048576,(int)(getenv('MCP_REPORT_CAPTURE_MAX_BYTES')?:67108864));
    if($bytes<1||$bytes>$max)mcpReportsError('report_capture_size','Native report capture is empty or too large');
    $content=file_get_contents($capture);
    if($content===false)mcpReportsError('report_capture_read_failed','Native report capture could not be read');
    return $content;
  }finally{
    if($capture!==null&&is_file($capture))unlink($capture);
    if($statusPath!==null&&is_file($statusPath))unlink($statusPath);
    if($inputPath!==null&&is_file($inputPath))unlink($inputPath);
    restore_error_handler();
  }
}
function mcpReportsStructured(string $html,Report $report,array $parameters): string {
  $tables=array();$text='';
  if(class_exists('DOMDocument')){$document=new DOMDocument();$previous=libxml_use_internal_errors(true);$document->loadHTML('<?xml encoding="UTF-8">'.$html,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);libxml_clear_errors();libxml_use_internal_errors($previous);
    foreach($document->getElementsByTagName('table') as $table){$rows=array();foreach($table->getElementsByTagName('tr') as $tr){$cells=array();foreach($tr->childNodes as $cell)if($cell instanceof DOMElement&&in_array(strtolower($cell->tagName),array('td','th'),true))$cells[]=trim(preg_replace('/\s+/u',' ',(string)$cell->textContent));if($cells)$rows[]=$cells;}if($rows)$tables[]=array('rows'=>$rows);}$text=trim(preg_replace('/\s+/u',' ',(string)$document->textContent));
  }else{$text=trim(preg_replace('/\s+/u',' ',strip_tags($html)));}
  return json_encode(array('schemaVersion'=>1,'report'=>array('id'=>(int)$report->id,'name'=>(string)$report->name),'parametersHash'=>hash('sha256',json_encode($parameters)),'tables'=>array_slice($tables,0,100),'text'=>mb_substr($text,0,100000)),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
}
function mcpReportsNativeImage(string $html,int $jobId,string $artifactFormat): string {
  $matches=array();$root=realpath('/var/www/html/files');
  if(preg_match_all('/<img\b[^>]*\bsrc\s*=\s*["\x27]([^"\x27]+)["\x27]/i',$html,$matches)){
    foreach($matches[1] as $source){$path=(string)(parse_url(html_entity_decode($source,ENT_QUOTES|ENT_HTML5,'UTF-8'),PHP_URL_PATH)??'');if($path===''||str_contains($path,"\0")||preg_match('#^[a-z]+://#i',$path))continue;if(str_starts_with($path,'../files/'))$candidate='/var/www/html/files/'.substr($path,9);elseif(str_starts_with($path,'/files/'))$candidate='/var/www/html'.$path;else continue;$real=realpath($candidate);if($real&&$root&&str_starts_with($real,$root.DIRECTORY_SEPARATOR)&&is_file($real)){mcpReportsValidateSignature('png',$real);return $real;}}
  }
  if(!function_exists('imagecreatetruecolor')||!function_exists('imagepng'))mcpReportsError('native_image_unavailable','GD image rendering is unavailable');
  $final=workerArtifactPath($jobId,$artifactFormat);
  $temporary=$final.'.render-'.bin2hex(random_bytes(6)).'.png';
  $image=imagecreatetruecolor(1600,900);
  if(!$image)mcpReportsError('native_image_unavailable','Report canvas could not be created');
  $white=imagecolorallocate($image,255,255,255);
  $black=imagecolorallocate($image,25,31,38);
  imagefilledrectangle($image,0,0,1599,899,$white);
  $plain=trim(preg_replace('/\s+/u',' ',html_entity_decode(strip_tags(str_ireplace(array('<br>','<br/>','<br />','</tr>','</p>'),"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8')));
  $lines=preg_split('/\R/u',wordwrap($plain,150,"\n",true))?:array();
  imagestring($image,5,24,20,'ProjeQtOr report',$black);
  $y=52;foreach(array_slice($lines,0,48) as $line){imagestring($image,3,24,$y,mb_substr($line,0,180),$black);$y+=17;}
  if(!imagepng($image,$temporary,6)){imagedestroy($image);mcpReportsError('native_image_unavailable','Report image could not be written');}
  imagedestroy($image);
  return $temporary;
}
function mcpReportsValidateSignature(string $format,string $path): void {
  $bytes=(string)file_get_contents($path,false,null,0,16);
  if($format==='pdf'&&!str_starts_with($bytes,'%PDF-'))mcpReportsError('invalid_artifact_signature','PDF signature is invalid');
  if($format==='png'&&!str_starts_with($bytes,"\x89PNG\r\n\x1a\n"))mcpReportsError('invalid_artifact_signature','PNG signature is invalid');
  if($format==='jpeg'&&!str_starts_with($bytes,"\xff\xd8\xff"))mcpReportsError('invalid_artifact_signature','JPEG signature is invalid');
  if($format==='json'){try{json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}catch(Throwable){mcpReportsError('invalid_artifact_signature','JSON artifact is invalid');}}
  if($format==='csv'&&($bytes===''||str_contains($bytes,"\0")))mcpReportsError('invalid_artifact_signature','CSV artifact is invalid');
}
function mcpReportsPublish(int $jobId,string $format,string $content,?string $copySource=null): array {
  $extension=$format==='jpeg'?'jpg':$format;$path=workerArtifactPath($jobId,$extension);$temporary=$path.'.tmp-'.bin2hex(random_bytes(6));
  if($copySource!==null){if(!copy($copySource,$temporary))mcpReportsError('artifact_write_failed','Unable to copy native image');}elseif(file_put_contents($temporary,$content,LOCK_EX)===false)mcpReportsError('artifact_write_failed','Unable to write report artifact');
  $bytes=is_file($temporary)?(int)filesize($temporary):0;$max=max(1048576,(int)(getenv('MCP_JOB_ARTIFACT_MAX_BYTES')?:536870912));if($bytes<1||$bytes>$max){@unlink($temporary);mcpReportsError('artifact_size','Artifact is empty or too large');}
  mcpReportsValidateSignature($format,$temporary);if(workerCancelled($jobId)){@unlink($temporary);throw new RuntimeException('cancelled');}if(!rename($temporary,$path)){@unlink($temporary);mcpReportsError('artifact_publish_failed','Atomic publication failed');}return array($path,$bytes,$max);
}
function mcpReportsRenderWorker(int $jobId,array $arguments,string $username): array {
  $format=(string)($arguments['format']??'pdf');if(!in_array($format,array('pdf','png','jpeg','csv','json'),true))mcpReportsError('unsupported_report_format','Format is not allowlisted');
  $report=mcpReportsRequireReport((int)$arguments['idReport'],$format);$parameters=mcpReportsValidateParameters($arguments['parameters']??array());if(workerCancelled($jobId))throw new RuntimeException('cancelled');workerUpdate($jobId,'running',10);$nativeFormat=in_array($format,array('png','jpeg','json'),true)?'json':$format;$artifactFormat=$format==='jpeg'?'jpg':$format;$output=mcpReportsCaptureIsolated($jobId,$report,$parameters,$nativeFormat,$artifactFormat,$username);workerUpdate($jobId,'running',60);$copy=null;
  if($format==='pdf'){$autoload='/var/www/html/external/html2pdf/vendor/autoload.php';if(!class_exists('Spipu\\Html2Pdf\\Html2Pdf'))require_once $autoload;$temporary=workerArtifactPath($jobId,'pdf').'.render-'.bin2hex(random_bytes(6)).'.pdf';$pdf=new \Spipu\Html2Pdf\Html2Pdf(in_array($report->orientation,array('P','L'),true)?$report->orientation:'L','A4','en');$pdf->setDefaultFont(Parameter::getGlobalParameter('fontForPDF')?:'freesans');$pdf->writeHTML('<html><body>'.$output.'</body></html>');$pdf->output($temporary,'F');$copy=$temporary;$output='';}
  elseif($format==='json')$output=mcpReportsStructured($output,$report,$parameters);
  elseif(in_array($format,array('png','jpeg'),true)){$native=mcpReportsNativeImage($output,$jobId,$artifactFormat);if($format==='png')$copy=$native;else{if(!function_exists('imagecreatefrompng'))mcpReportsError('jpeg_unavailable','GD is unavailable');$image=imagecreatefrompng($native);if(!$image)mcpReportsError('jpeg_unavailable','PNG cannot be decoded');$temporary=workerArtifactPath($jobId,'jpg').'.render-'.bin2hex(random_bytes(6));imagejpeg($image,$temporary,90);imagedestroy($image);if(str_contains($native,'.render-')&&is_file($native))unlink($native);$copy=$temporary;}$output='';}
  if(workerCancelled($jobId)){if($copy&&str_contains($copy,'.render-'))@unlink($copy);throw new RuntimeException('cancelled');}try{[$path,$bytes,$max]=mcpReportsPublish($jobId,$format,$output,$copy);}finally{if($copy&&str_contains($copy,'.render-'))@unlink($copy);}
  $media=array('pdf'=>'application/pdf','png'=>'image/png','jpeg'=>'image/jpeg','csv'=>'text/csv','json'=>'application/json');
  return array('ok'=>true,'reportId'=>(int)$report->id,'reportName'=>(string)$report->name,'format'=>$format,'mediaType'=>$media[$format],'bytes'=>$bytes,'maxBytes'=>$max,'parametersHash'=>hash('sha256',json_encode($parameters)),'resource'=>'projeqtor://jobs/'.$jobId.'/result','effects'=>array());
}
function mcpReportsStartWorker(int $jobId,array $arguments,string $username): array{return mcpReportsRenderWorker($jobId,$arguments,$username);}
function mcpReportsDeliveryWorker(int $jobId,array $arguments,string $username): array {
  $schedule=new AutoSendReport((int)$arguments['scheduleId'],true);mcpReportsRequireOwned($schedule,(string)$arguments['expectedVersion']);if((int)$schedule->idResource!==(int)getSessionUser()->id||(int)$schedule->idReceiver!==(int)getSessionUser()->id||trim((string)$schedule->otherReceiver)!=='')mcpReportsError('recipient_not_actor','Delivery is not bound exclusively to the actor');
  mcpReportsRequireReport((int)$schedule->idReport,'pdf');if(workerCancelled($jobId))throw new RuntimeException('cancelled');workerUpdate($jobId,'running',25);$schedule->sendReport((int)$schedule->idReport,(string)$schedule->reportParameter);
  return array('ok'=>true,'scheduleId'=>(int)$schedule->id,'status'=>'sent','recipientResourceId'=>(int)getSessionUser()->id,'effects'=>array(array('action'=>'report.delivery','objectClass'=>'AutoSendReport','id'=>(int)$schedule->id,'externalDelivery'=>true)));
}
