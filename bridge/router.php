<?php
declare(strict_types=1);

require_once __DIR__ . '/compat.php';
require_once __DIR__ . '/core/module-graph.php';
require_once __DIR__ . '/core/action-domains.php';
require_once __DIR__ . '/core/module-registry.php';
require_once __DIR__ . '/core/action-discovery.php';
require_once __DIR__ . '/core/action-metadata.php';
require_once __DIR__ . '/core/job-artifact-resource.php';
require_once __DIR__ . '/full-control.php';
require_once __DIR__ . '/operations.php';
require_once __DIR__ . '/actions.php';

function mcpResourceFile(string $type, int $id, string $username): never {
  if ($id < 1) mcpJsonError(400,'invalid_resource','A positive resource id is required');
  if ($type === 'attachment') {
    $object=new Attachment($id); if(!$object->id||(!Security::checkValidAccessForUser($object,'read',null,null,false)&&!mcpContextualReadAllowed($object)))mcpJsonError(404,'resource_not_found','Attachment is unavailable');
    $base=rtrim(Parameter::getGlobalParameter('paramAttachmentDirectory'),'/').'/';
    $directory=str_replace('${attachmentDirectory}',$base,(string)$object->subDirectory);
    $path=$directory.$object->fileName; $mime=$object->mimeType?:'application/octet-stream';
  } else if ($type === 'document-version') {
    $object=new DocumentVersion($id); if(!$object->id||(!Security::checkValidAccessForUser($object,'read',null,null,false)&&!mcpContextualReadAllowed($object)))mcpJsonError(404,'resource_not_found','Document version is unavailable');
    $path=(string)$object->fullName; $mime=$object->mimeType?:'application/octet-stream';
  } else if ($type === 'job-result') {
    mcpEnsureOperationTable(); $result=Sql::query('SELECT * FROM mcpoperation WHERE id='.Sql::fmtId($id).' AND username='.Sql::str($username)); $row=Sql::fetchLine($result); if(!$row||!in_array($row['status'],array('succeeded','failed','cancelled'),true))mcpJsonError(404,'resource_not_found','Job result is unavailable');
    if($row['result_path']){$artifact=mcpResolveJobArtifact($id,(string)$row['result_path']);if(!$artifact)mcpJsonError(404,'resource_not_found','Job result is unavailable');$path=$artifact['path'];$mime=$artifact['mimeType'];}
    else { mcpJsonResponse(array('mimeType'=>'application/json','base64'=>base64_encode((string)($row['result_json']??'{}')))); }
  } else mcpJsonError(404,'resource_not_found','Unknown resource type');
  $real=realpath($path); $allowed=array(realpath('/var/lib/projeqtor/attachments'),realpath('/var/lib/projeqtor/documents'),realpath('/var/lib/projeqtor/mcp-jobs'));
  if(!$real||!is_file($real)||!array_filter($allowed,fn($root)=>$root&&str_starts_with($real,$root.'/')))mcpJsonError(404,'resource_not_found','Resource file is unavailable');
  $size=filesize($real); if($size>16*1024*1024)mcpJsonError(413,'resource_too_large','Resource exceeds the 16 MiB MCP resource limit; use a bounded export');
  mcpJsonResponse(array('mimeType'=>$mime,'base64'=>base64_encode((string)file_get_contents($real)),'bytes'=>$size));
}

function mcpHandleV2(string $uri, string $method, string $body, string $username): never {
  $input=mcpDecodeBody($body);
  if($method==='GET'&&$uri==='__mcp/v2/whoami'){
    $user=getSessionUser(); $profile=new Profile($user->idProfile);
    $isAuth0=(bool)preg_match('/^auth0-[0-9a-f]{64}$/D',(string)$user->name);
    $publicUsername=$isAuth0 ? ($user->email?:'Auth0 user') : $user->name;
    mcpJsonResponse(array('username'=>$publicUsername,'displayName'=>$user->resourceName?:$publicUsername,'authenticationProvider'=>$isAuth0?'auth0':'local','id'=>(int)$user->id,'idProfile'=>(int)$user->idProfile,'profile'=>$profile->name,'profileCode'=>$profile->profileCode??null,'isResource'=>(bool)$user->isResource,'isContact'=>(bool)$user->isContact,'scopes'=>array('projeqtor:read','projeqtor:write','projeqtor:actions'),'credentialsExposed'=>false));
  }
  if($method==='POST'&&$uri==='__mcp/v2/classes')mcpHandleClassCatalog($input);
  if($method==='POST'&&$uri==='__mcp/v2/item')mcpHandleGetItem($input);
  if($method==='POST'&&$uri==='__mcp/v2/ui-handlers')mcpHandleUiHandlers($input);
  if($method==='POST'&&$uri==='__mcp/v2/query')mcpHandleQuery($input);
  if($method==='POST'&&$uri==='__mcp/v2/changes')mcpHandleChanges($input);
  if($method==='POST'&&$uri==='__mcp/v2/operations/validate')mcpHandleValidateOperations($input);
  if($method==='POST'&&$uri==='__mcp/v2/operations/execute')mcpHandleExecuteOperations($input,$username);
  if($method==='POST'&&$uri==='__mcp/v2/changes/prepare')mcpHandlePrepareChange($input,$username);
  if($method==='POST'&&$uri==='__mcp/v2/changes/commit')mcpHandleCommitChange($input,$username);
  if($method==='POST'&&$uri==='__mcp/v2/actions')mcpHandleListActions($input);
  if($method==='GET'&&preg_match('#^__mcp/v2/actions/([a-z][a-z0-9_.-]{2,100})$#D',$uri,$matches))mcpHandleActionSchema($matches[1]);
  if($method==='POST'&&$uri==='__mcp/v2/actions/execute')mcpHandleExecuteAction($input,$username);
  if($method==='POST'&&$uri==='__mcp/v2/actions/prepare')mcpHandlePrepareAction($input,$username);
  if($method==='POST'&&$uri==='__mcp/v2/actions/commit')mcpHandleCommitAction($input,$username);
  if($method==='POST'&&$uri==='__mcp/v2/jobs')mcpHandleListJobs($input,$username);
  if($method==='GET'&&preg_match('#^__mcp/v2/jobs/([0-9]+)$#D',$uri,$matches))mcpHandleGetJob((int)$matches[1],$username);
  if($method==='POST'&&$uri==='__mcp/v2/jobs/cancel')mcpHandleCancelJob($input,$username);
  if($method==='GET'&&preg_match('#^__mcp/v2/resources/(attachment|document-version|job-result)/([0-9]+)$#D',$uri,$matches))mcpResourceFile($matches[1],(int)$matches[2],$username);
  if($method==='POST'&&$uri==='__mcp/v2/jobs/retry')mcpHandleRetryJob($input,$username);
  mcpJsonError(404,'endpoint_not_found','Unknown MCP bridge endpoint');
}
