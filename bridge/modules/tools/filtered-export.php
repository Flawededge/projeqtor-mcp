<?php
declare(strict_types=1);

function mcpToolsFilteredExport(int $jobId,array $arguments): array {
  $class=(string)($arguments['objectClass']??'');mcpRequireClassOperation($class,'read');
  if(!Security::checkValidAccessForUser(null,'read',$class,null,false))throw new RuntimeException("Export access is denied for '$class'");
  $object=new $class();$where=getAccesRestrictionClause($class,null,true);
  $filter=$arguments['filter']??null;if(is_array($filter)&&count($filter))$where.=' AND '.mcpFilterSql($object,$filter);
  $format=(string)($arguments['format']??'ndjson');if(!in_array($format,array('json','ndjson','csv'),true))$format='ndjson';
  $path=workerArtifactPath($jobId,$format);$temporary=$path.'.tmp-'.bin2hex(random_bytes(6));$handle=fopen($temporary,'xb');
  if(!$handle)throw new RuntimeException('Unable to create export artifact');$maxBytes=max(1048576,(int)(getenv('MCP_JOB_ARTIFACT_MAX_BYTES')?:536870912));
  $list=$object->getSqlElementsFromCriteria(null,false,$where,'id asc',false,true);
  if($format==='json')fwrite($handle,'[');$first=true;$headers=null;
  foreach($list as $index=>$item){
    if(workerCancelled($jobId)){fclose($handle);@unlink($temporary);throw new RuntimeException('cancelled');}
    $row=mcpObjectArray($item);
    if($format==='ndjson')fwrite($handle,json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."\n");
    elseif($format==='json'){if(!$first)fwrite($handle,',');fwrite($handle,json_encode($row,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));$first=false;}
    else{if($headers===null){$headers=array_keys($row);fputcsv($handle,$headers);}fputcsv($handle,array_map(fn($key)=>is_scalar($row[$key]??null)?$row[$key]:'',$headers));}
    if(ftell($handle)>$maxBytes){fclose($handle);@unlink($temporary);throw new RuntimeException('Export artifact exceeds the configured artifact limit');}
    if($index%100===0)workerUpdate($jobId,'running',min(90,5+(int)(85*($index+1)/max(1,count($list)))));
  }
  if($format==='json')fwrite($handle,']');fflush($handle);fclose($handle);
  if(!rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('Atomic export publication failed');}
  return array('ok'=>true,'objectClass'=>$class,'format'=>$format,'count'=>count($list),'bytes'=>filesize($path),'maxBytes'=>$maxBytes,'filtered'=>is_array($filter)&&count($filter)>0,'resource'=>'projeqtor://jobs/'.$jobId.'/result','path'=>$path);
}
