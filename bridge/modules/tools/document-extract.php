<?php
declare(strict_types=1);

function mcpToolsArchiveName(string $name,int $id): string {
  $name=preg_replace('/[^A-Za-z0-9._ -]/','_',basename($name));return $name!==''?$name:'file-'.$id;
}

function mcpToolsAttachmentPath(Attachment $attachment): string {
  $directory=str_replace('${attachmentDirectory}',rtrim((string)Parameter::getGlobalParameter('paramAttachmentDirectory'),'/').'/',(string)$attachment->subDirectory);
  return rtrim($directory,'/').'/'.$attachment->fileName;
}

function mcpToolsDocumentExtractWorker(int $jobId,array $arguments,string $username): array {
  if(!class_exists('ZipArchive'))throw new RuntimeException('ZIP support is unavailable');
  $path=workerArtifactPath($jobId,'zip');$temporary=$path.'.tmp-'.bin2hex(random_bytes(6));$zip=new ZipArchive();
  if($zip->open($temporary,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('Unable to create extraction archive');
  $items=array();$totalBytes=0;$maxBytes=max(1048576,(int)(getenv('MCP_JOB_ARTIFACT_MAX_BYTES')?:536870912));$input=$arguments['items']??array();
  try{
    foreach($input as $index=>$entry){
      if(workerCancelled($jobId))throw new RuntimeException('cancelled');$id=(int)$entry['id'];$kind=(string)$entry['kind'];
      if($kind==='document_version'){
        $version=new DocumentVersion($id);if(!$version->id)throw new RuntimeException("DocumentVersion #$id was not found");$document=new Document((int)$version->idDocument);
        if(!$document->id||!Security::checkValidAccessForUser($document,'read',null,null,false))throw new RuntimeException("DocumentVersion #$id is unavailable");
        $source=$version->getUploadFileName();$name=!empty($arguments['preserveUploadedFileName'])?$version->fileName:($version->fullName?:$version->fileName);
        $objectClass='DocumentVersion';
      }else{
        $attachment=new Attachment($id);if(!$attachment->id||(!Security::checkValidAccessForUser($attachment,'read',null,null,false)&&!mcpContextualReadAllowed($attachment)))throw new RuntimeException("Attachment #$id is unavailable");
        $source=mcpToolsAttachmentPath($attachment);$name=$attachment->fileName;$objectClass='Attachment';
      }
      if(!is_file($source))throw new RuntimeException("$objectClass #$id has no readable file");$bytes=(int)filesize($source);if($totalBytes+$bytes>$maxBytes)throw new RuntimeException('Extraction archive exceeds the configured artifact limit');$totalBytes+=$bytes;$archiveName=mcpToolsArchiveName((string)$name,$id);$candidate=$archiveName;$suffix=1;while($zip->locateName($candidate)!==false)$candidate=$suffix++.'_'.$archiveName;
      if(!$zip->addFile($source,$candidate))throw new RuntimeException("Unable to add $objectClass #$id to archive");$items[]=array('index'=>$index,'objectClass'=>$objectClass,'id'=>$id,'archiveName'=>$candidate,'bytes'=>$bytes);
      workerUpdate($jobId,'running',min(95,5+(int)(90*($index+1)/max(1,count($input)))));
    }
  }catch(Throwable $error){$zip->close();@unlink($temporary);throw $error;}
  if(!$zip->close()){ @unlink($temporary);throw new RuntimeException('Unable to finalize extraction archive'); }
  if(!rename($temporary,$path)){@unlink($temporary);throw new RuntimeException('Atomic extraction publication failed');}
  return array('ok'=>true,'items'=>$items,'count'=>count($items),'bytes'=>$totalBytes,'maxBytes'=>$maxBytes,'format'=>'zip','resource'=>'projeqtor://jobs/'.$jobId.'/result','path'=>$path);
}
