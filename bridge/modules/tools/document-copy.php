<?php
declare(strict_types=1);

function mcpToolsCopyDocumentVersions(Document $source,Document $copy,string $mode): array {
  if($mode==='none')return array();$version=new DocumentVersion();$criteria=array('idDocument'=>(int)$source->id);
  if($mode==='reference')$criteria['isRef']=1;
  $order=$mode==='latest'?'id desc':'id asc';$limit=$mode==='latest'?1:null;
  $versions=$version->getSqlElementsFromCriteria($criteria,null,false,$order,null,false,$limit);$created=array();$files=array();$last=null;
  try{
    foreach($versions as $original){
      $target=new DocumentVersion();foreach(array('name','version','revision','draft','fileName','fileSize','mimeType','versionDate','idAuthor','idStatus','description','isRef','approved','disapproved','idle') as $field)if(property_exists($original,$field))$target->$field=$original->$field;
      $target->idDocument=(int)$copy->id;$raw=$target->save();if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));
      $sourcePath=$original->getUploadFileName();$targetPath=$target->getUploadFileName();if(is_file($sourcePath)&&!copy($sourcePath,$targetPath))throw new RuntimeException('Unable to copy a document version file');
      if(is_file($targetPath))$files[]=$targetPath;$created[]=(int)$target->id;$last=(int)$target->id;
    }
    $copy->idDocumentVersion=$last;if(!$last){$copy->version=null;$copy->revision=null;}$raw=$copy->save();if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));
  }catch(Throwable $error){foreach($files as $file)@unlink($file);throw $error;}
  return $created;
}
