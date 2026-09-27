<?php
declare(strict_types=1);

const MCP_TOOLS_IMAGE_HARD_MAX_BYTES=26214400;
const MCP_TOOLS_IMAGE_MAX_PIXELS=50000000;

function mcpToolsImageMimeMap(): array {
  return array(
    'jpg'=>array('image/jpeg'),'jpeg'=>array('image/jpeg'),'gif'=>array('image/gif'),
    'tif'=>array('image/tiff'),'tiff'=>array('image/tiff'),'png'=>array('image/png'),
    'bmp'=>array('image/bmp','image/x-ms-bmp'),'webp'=>array('image/webp')
  );
}
function mcpToolsImageExtensions(): array { return array_keys(mcpToolsImageMimeMap()); }
function mcpToolsImageMaxBytes(): int {
  $configured=(int)Parameter::getGlobalParameter('paramAttachmentMaxSize')*1024*1024;
  $module=(int)(getenv('MCP_IMAGE_UPLOAD_MAX_BYTES')?:MCP_TOOLS_IMAGE_HARD_MAX_BYTES);
  $limits=array_filter(array(MCP_TOOLS_IMAGE_HARD_MAX_BYTES,$module,$configured),fn($value)=>$value>0);
  return min($limits);
}
function mcpToolsRequireImageUploadPermission(): void {
  $admin=securityGetAccessRightYesNo('menuAdmin','read')==='YES';$native=false;
  try{$native=securityGetAccessRightYesNo('menuDocument','read')==='YES'&&Security::checkValidAccessForUser(null,'create','Document',null,false);}catch(Throwable $error){}
  if(!$native&&!$admin)mcpJsonError(403,'forbidden','Document create or administration access is required for editor image uploads');
}
function mcpToolsImageDirectory(): string {
  $root=realpath('/var/www/html/files');if($root===false||!is_dir($root))throw new RuntimeException('Application file root is unavailable');
  $directory=$root.'/images';if(!is_dir($directory)&&!mkdir($directory,0770,true))throw new RuntimeException('Image directory is unavailable');
  $real=realpath($directory);if($real===false||!str_starts_with($real,$root.'/')||is_link($directory))throw new RuntimeException('Unsafe image directory configuration');
  return $real;
}
function mcpToolsValidateImageFile(string $path,string $fileName,string $declaredMime): array {
  if(!is_file($path)||is_link($path))throw new RuntimeException('Image upload file is unavailable');
  Security::checkEvilFile($path);$extension=strtolower((string)pathinfo($fileName,PATHINFO_EXTENSION));$map=mcpToolsImageMimeMap();if(!isset($map[$extension]))throw new RuntimeException('Image extension is not permitted');
  $finfo=new finfo(FILEINFO_MIME_TYPE);$detected=(string)$finfo->file($path);if(!in_array($detected,$map[$extension],true))throw new RuntimeException('Image content does not match its extension');
  if($declaredMime!==''&&!in_array(strtolower(trim(explode(';',$declaredMime,2)[0])),$map[$extension],true))throw new RuntimeException('Declared image MIME type does not match its extension');
  $image=@getimagesize($path);if(!$image||empty($image[0])||empty($image[1])||!in_array((string)($image['mime']??''),$map[$extension],true))throw new RuntimeException('Uploaded file is not a decodable image');
  if((int)$image[0]>10000||(int)$image[1]>10000||(int)$image[0]*(int)$image[1]>MCP_TOOLS_IMAGE_MAX_PIXELS)throw new RuntimeException('Image dimensions exceed the safety limit');
  return array('extension'=>$extension,'mimeType'=>$detected,'width'=>(int)$image[0],'height'=>(int)$image[1]);
}

function mcpToolsImageUploadBegin(array $arguments,string $username,string $action): array {
  mcpToolsRequireImageUploadPermission();$fileName=Security::checkValidFileName((string)$arguments['fileName'],true,true);$extension=strtolower((string)pathinfo($fileName,PATHINFO_EXTENSION));$map=mcpToolsImageMimeMap();
  if(!isset($map[$extension]))mcpJsonError(400,'invalid_image_type','Image type is not permitted',array('allowedExtensions'=>mcpToolsImageExtensions()));
  $mime=strtolower(trim(explode(';',(string)$arguments['mimeType'],2)[0]));if(!in_array($mime,$map[$extension],true))mcpJsonError(400,'invalid_image_mime','Declared MIME type does not match the image extension');
  $expected=(int)$arguments['expectedBytes'];$max=mcpToolsImageMaxBytes();if($expected<1||$expected>$max)mcpJsonError(413,'image_too_large','Image exceeds the configured or hard image limit',array('maxBytes'=>$max));
  $id=bin2hex(random_bytes(24));$meta=array('uploadId'=>$id,'kind'=>'image','username'=>$username,'fileName'=>$fileName,'mimeType'=>$mime,'expectedBytes'=>$expected,'createdAt'=>date(DATE_ATOM),'expiresAt'=>date(DATE_ATOM,time()+3600));
  file_put_contents(mcpUploadMetaPath($id),json_encode($meta),LOCK_EX);file_put_contents(mcpUploadDataPath($id),'',LOCK_EX);
  return array('ok'=>true,'upload'=>$meta,'maxBytes'=>$max,'chunkBytes'=>524288,'nextAction'=>'attachment.upload.chunk','commitAction'=>'tools.image.upload.commit');
}

function mcpToolsImageUploadWorker(int $jobId,array $arguments,string $username): array {
  if(workerCancelled($jobId))throw new RuntimeException('cancelled');mcpToolsRequireImageUploadPermission();$uploadId=(string)$arguments['uploadId'];$meta=mcpReadUpload($uploadId,$username);
  if(($meta['kind']??'')!=='image')throw new RuntimeException('Upload session is not an image upload');$source=mcpUploadDataPath($uploadId);$received=is_file($source)?filesize($source):-1;$max=mcpToolsImageMaxBytes();
  if($received!==(int)$meta['expectedBytes'])throw new RuntimeException('Image upload is incomplete');if($received<1||$received>$max)throw new RuntimeException('Image exceeds the configured or hard image limit');
  $validated=mcpToolsValidateImageFile($source,(string)$meta['fileName'],(string)$meta['mimeType']);if(workerCancelled($jobId))throw new RuntimeException('cancelled');$directory=mcpToolsImageDirectory();
  $safe=Security::checkValidFileName((string)$meta['fileName'],true,true);$name=date('YmdHis').'_'.(int)getSessionUser()->id.'_'.bin2hex(random_bytes(8)).'_'.$safe;if(basename($name)!==$name)throw new RuntimeException('Unsafe image file name');
  $temporary=$directory.'/.mcp-'.bin2hex(random_bytes(12)).'.tmp';$target=$directory.'/'.$name;if(!rename($source,$temporary))throw new RuntimeException('Unable to stage image upload');chmod($temporary,0660);
  try{if(workerCancelled($jobId))throw new RuntimeException('cancelled');if(file_exists($target)||!rename($temporary,$target))throw new RuntimeException('Unable to publish image upload');}catch(Throwable $error){@unlink($temporary);@unlink($target);throw $error;}
  @unlink(mcpUploadMetaPath($uploadId));return array('ok'=>true,'items'=>array(array('status'=>'created','fileName'=>$name,'mimeType'=>$validated['mimeType'],'fileSize'=>$received)),'relativeUrl'=>'files/images/'.rawurlencode($name),'width'=>$validated['width'],'height'=>$validated['height'],'effects'=>array(array('action'=>'file.create','kind'=>'editor_image','fileName'=>$name,'fileSize'=>$received)));
}
function mcpToolsPreviewImageCommit(array $arguments,string $username,string $action): array {
  mcpToolsRequireImageUploadPermission();$meta=mcpReadUpload((string)($arguments['uploadId']??''),$username);if(($meta['kind']??'')!=='image')mcpJsonError(400,'invalid_upload','Upload session is not an image');
  return array('uploadId'=>$meta['uploadId'],'fileName'=>$meta['fileName'],'expectedBytes'=>(int)$meta['expectedBytes'],'effect'=>'publish_editor_image','permissionRecheckedAtCommit'=>true);
}
