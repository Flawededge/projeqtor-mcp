<?php
declare(strict_types=1);

function mcpToolsImageExtensions(): array { return array('jpg','jpeg','gif','tiff','png','bmp','svg','ico'); }

function mcpToolsImageUploadBegin(array $arguments,string $username,string $action): array {
  if(securityGetAccessRightYesNo('menuDocument','read')!=='YES')mcpJsonError(403,'forbidden','Document access is required for image uploads');
  $fileName=Security::checkValidFileName((string)$arguments['fileName'],true,true);
  $extension=strtolower((string)pathinfo($fileName,PATHINFO_EXTENSION));
  if(!in_array($extension,mcpToolsImageExtensions(),true))mcpJsonError(400,'invalid_image_type','Image type is not permitted',array('allowedExtensions'=>mcpToolsImageExtensions()));
  $expected=(int)$arguments['expectedBytes'];$max=(int)Parameter::getGlobalParameter('paramAttachmentMaxSize')*1024*1024;
  if($expected<1||($max>0&&$expected>$max))mcpJsonError(413,'image_too_large','Image exceeds the configured attachment limit');
  $id=bin2hex(random_bytes(24));$meta=array(
    'uploadId'=>$id,'kind'=>'image','username'=>$username,'fileName'=>$fileName,
    'mimeType'=>(string)$arguments['mimeType'],'expectedBytes'=>$expected,
    'createdAt'=>date(DATE_ATOM),'expiresAt'=>date(DATE_ATOM,time()+3600)
  );
  file_put_contents(mcpUploadMetaPath($id),json_encode($meta),LOCK_EX);file_put_contents(mcpUploadDataPath($id),'',LOCK_EX);
  return array('ok'=>true,'upload'=>$meta,'chunkBytes'=>524288,'nextAction'=>'attachment.upload.chunk','commitAction'=>'tools.image.upload.commit');
}

function mcpToolsImageUploadWorker(int $jobId,array $arguments,string $username): array {
  global $targetDirImageUpload;
  $uploadId=(string)$arguments['uploadId'];$meta=mcpReadUpload($uploadId,$username);
  if(($meta['kind']??'')!=='image')throw new RuntimeException('Upload session is not an image upload');
  $source=mcpUploadDataPath($uploadId);$received=is_file($source)?filesize($source):-1;
  if($received!==(int)$meta['expectedBytes'])throw new RuntimeException('Image upload is incomplete');
  Security::checkEvilFile($source);$image=@getimagesize($source);$extension=strtolower((string)pathinfo($meta['fileName'],PATHINFO_EXTENSION));
  if(!$image&&!in_array($extension,array('svg','ico'),true))throw new RuntimeException('Uploaded file is not a valid image');
  $directory=(string)($targetDirImageUpload??'../files/images');if(!str_starts_with($directory,'/'))$directory='/var/www/html/'.ltrim(str_replace('../','',$directory),'/');
  if(!is_dir($directory)&&!mkdir($directory,0770,true))throw new RuntimeException('Image directory is unavailable');
  $safe=Security::checkValidFileName((string)$meta['fileName'],true,true);$name=date('YmdHis').'_'.(int)getSessionUser()->id.'_'.bin2hex(random_bytes(4)).'_'.$safe;$target=rtrim($directory,'/').'/'.$name;
  if(!rename($source,$target))throw new RuntimeException('Unable to commit image upload');@unlink(mcpUploadMetaPath($uploadId));
  return array('ok'=>true,'items'=>array(array('status'=>'created','fileName'=>$name,'mimeType'=>$meta['mimeType'],'fileSize'=>$received)),'relativeUrl'=>'files/images/'.rawurlencode($name),'effects'=>array(array('action'=>'file.create','kind'=>'editor_image','fileName'=>$name,'fileSize'=>$received)));
}
