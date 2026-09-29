<?php
declare(strict_types=1);

if(!defined('MCP_JOB_ARTIFACT_ROOT'))define('MCP_JOB_ARTIFACT_ROOT','/var/lib/projeqtor/mcp-jobs');

function mcpJobArtifactMimeTypes(): array {
  return array(
    'json'=>'application/json',
    'ndjson'=>'application/x-ndjson',
    'csv'=>'text/csv',
    'pdf'=>'application/pdf',
    'png'=>'image/png',
    'jpg'=>'image/jpeg',
    'jpeg'=>'image/jpeg',
    'zip'=>'application/zip',
    'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
  );
}

function mcpResolveJobArtifact(int $jobId,string $storedPath): ?array {
  if($jobId<1||$storedPath==='')return null;
  $root=rtrim(MCP_JOB_ARTIFACT_ROOT,DIRECTORY_SEPARATOR);
  $extensions=implode('|',array_map(fn($extension)=>preg_quote($extension,'#'),array_keys(mcpJobArtifactMimeTypes())));
  $pattern='#^'.preg_quote($root,'#').'/job-'.preg_quote((string)$jobId,'#').'\.('.$extensions.')$#D';
  if(!preg_match($pattern,$storedPath,$matches))return null;
  if(is_link($storedPath)||!is_file($storedPath))return null;
  $realRoot=realpath($root);$realPath=realpath($storedPath);
  if(!$realRoot||!$realPath||!hash_equals($storedPath,$realPath)||!hash_equals($realRoot,dirname($realPath)))return null;
  $extension=$matches[1];
  return array('path'=>$realPath,'mimeType'=>mcpJobArtifactMimeTypes()[$extension]);
}
