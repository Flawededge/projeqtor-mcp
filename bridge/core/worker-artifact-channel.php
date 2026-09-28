<?php
declare(strict_types=1);

if(!defined('MCP_WORKER_ARTIFACT_ROOT'))define('MCP_WORKER_ARTIFACT_ROOT','/var/lib/projeqtor/mcp-jobs');

function workerArtifactChannelBegin(int $jobId): void {
  if($jobId<1)throw new RuntimeException('Artifact channel requires a positive job id');
  if(isset($GLOBALS['mcpWorkerArtifactChannel']))throw new RuntimeException('Artifact channel is already active');
  $GLOBALS['mcpWorkerArtifactChannel']=array('jobId'=>$jobId,'path'=>null);
}

function workerArtifactChannelAbort(int $jobId): void {
  $channel=$GLOBALS['mcpWorkerArtifactChannel']??null;
  if(is_array($channel)&&(int)($channel['jobId']??0)===$jobId)unset($GLOBALS['mcpWorkerArtifactChannel']);
}

function workerArtifactPath(int $jobId,string $extension): string {
  $channel=$GLOBALS['mcpWorkerArtifactChannel']??null;
  if(!is_array($channel)||(int)($channel['jobId']??0)!==$jobId)throw new RuntimeException('Artifact path requested outside the active job');
  $extension=strtolower($extension);
  if(!preg_match('/^[a-z0-9][a-z0-9_-]{0,15}$/D',$extension))throw new RuntimeException('Artifact extension is invalid');
  $directory=MCP_WORKER_ARTIFACT_ROOT;
  if(!is_dir($directory)&&!mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('Artifact directory is unavailable');
  $path=$directory.'/job-'.$jobId.'.'.$extension;$registered=$channel['path']??null;
  if($registered!==null&&!hash_equals((string)$registered,$path))throw new RuntimeException('A job may publish only one artifact');
  $GLOBALS['mcpWorkerArtifactChannel']['path']=$path;
  return $path;
}

function workerArtifactChannelFinish(int $jobId,array $result): array {
  $channel=$GLOBALS['mcpWorkerArtifactChannel']??null;
  try{
    if(!is_array($channel)||(int)($channel['jobId']??0)!==$jobId)throw new RuntimeException('Artifact channel does not match the active job');
    $path=$channel['path']??null;$legacy=$result['path']??null;
    if(array_key_exists('path',$result)&&(!is_string($legacy)||$legacy===''))throw new RuntimeException('Worker artifact path is invalid');
    if($legacy!==null&&($path===null||!hash_equals((string)$path,$legacy)))throw new RuntimeException('Worker returned an unregistered artifact path');
    if($path!==null){
      $root=realpath(MCP_WORKER_ARTIFACT_ROOT);$real=realpath((string)$path);
      if(!$root||!$real||!is_file($real)||!str_starts_with($real,$root.DIRECTORY_SEPARATOR))throw new RuntimeException('Worker artifact was not published inside the artifact root');
      $expected=$root.DIRECTORY_SEPARATOR.'job-'.$jobId.'.'.pathinfo((string)$path,PATHINFO_EXTENSION);
      if(!hash_equals($expected,$real))throw new RuntimeException('Worker artifact path is not canonical');
    }
    return array('result'=>mcpPublicAsyncActionResult($result),'resultPath'=>$path);
  }finally{
    unset($GLOBALS['mcpWorkerArtifactChannel']);
  }
}
