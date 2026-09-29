<?php
declare(strict_types=1);

const MCP_POLICY_VERSION = 4;

function mcpSourceInventoryManifest(): array {
  static $manifest=null;if($manifest!==null)return $manifest;
  $path=__DIR__.'/source-inventory-v4.json';$decoded=is_file($path)?json_decode((string)file_get_contents($path),true):null;
  if(!is_array($decoded)||(int)($decoded['inventoryVersion']??0)!==MCP_POLICY_VERSION||!is_array($decoded['files']??null))throw new RuntimeException('The MCP source inventory manifest is missing or invalid');
  $manifest=$decoded;return $manifest;
}

function mcpPolicyManifest(): array {
  static $manifest=null;if($manifest!==null)return $manifest;
  $path=__DIR__.'/class-policy-v4.json';$decoded=is_file($path)?json_decode((string)file_get_contents($path),true):null;
  if(!is_array($decoded)||(int)($decoded['policyVersion']??0)!==MCP_POLICY_VERSION||!is_array($decoded['classes']??null))throw new RuntimeException('The MCP class policy manifest is missing or invalid');
  $manifest=$decoded;return $manifest;
}

function mcpHandlerPolicyManifest(): array {
  static $manifest=null;if($manifest!==null)return $manifest;
  $path=__DIR__.'/ui-handler-policy-v4.json';$decoded=is_file($path)?json_decode((string)file_get_contents($path),true):null;
  if(!is_array($decoded)||(int)($decoded['policyVersion']??0)!==MCP_POLICY_VERSION||!is_array($decoded['handlers']??null))throw new RuntimeException('The MCP UI handler policy manifest is missing or invalid');
  $manifest=$decoded;return $manifest;
}

function mcpSensitiveField(string $field): bool {
  return (bool)preg_match('/(^password$|apiKey|salt$|token$|secret|credential|privateKey|smtp.*pass|ldap.*pass)/i',$field);
}

function mcpInstalledClasses(): array {
  static $cache=null;if($cache!==null)return array_keys($cache);$cache=array();
  $paths=array_merge(glob('/var/www/html/model/*.php')?:array(),glob('/var/www/html/model/custom/*.php')?:array());
  foreach($paths as $path){$class=basename($path,'.php');if(!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D',$class))continue;
    try{if(SqlElement::class_exists($class)&&is_subclass_of($class,'SqlElement'))$cache[$class]=true;}catch(Throwable $error){}
  }
  ksort($cache,SORT_STRING);return array_keys($cache);
}

function mcpInstalledSourceFiles(string $root='/var/www/html'): array {
  $root=rtrim($root,'/');$files=array();
  foreach(array('api','plugin','report','sso','tool','view') as $directory){
    $base=$root.'/'.$directory;if(!is_dir($base))continue;
    $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
    foreach($iterator as $file){
      if(!$file->isFile()||strtolower($file->getExtension())!=='php')continue;
      $path=$file->getPathname();$relative=substr($path,strlen($root)+1);
      if($relative==='tool/parametersLocation.php')continue;
      $files[$relative]=hash_file('sha256',$path);
    }
  }
  ksort($files,SORT_STRING);return $files;
}

function mcpAssertHandlerPolicyComplete(): array {
  static $result=null;if($result!==null)return $result;$manifest=mcpHandlerPolicyManifest();$inventory=mcpSourceInventoryManifest();$expected=array();$deferred=0;
  foreach($manifest['handlers'] as $handler){$path=(string)($handler['path']??'');if(!$path)throw new RuntimeException('UI handler policy contains an invalid path');$expected[$path]=(string)($handler['sourceHash']??'');
    if(($handler['classification']??'')==='deferred_beta4'){$deferred++;if(empty($handler['beta4Issue']))throw new RuntimeException("Deferred UI handler '$path' has no Beta 4 issue");}
  }
  $expectedSources=array();foreach($inventory['files'] as $entry){$path=(string)($entry['path']??'');if(!$path)throw new RuntimeException('Source inventory contains an invalid path');$expectedSources[$path]=(string)($entry['sourceHash']??'');}
  if(count($expected)!==(int)($inventory['entrypointCount']??-1)||count($expectedSources)!==(int)($inventory['sourceFileCount']??-1))throw new RuntimeException('The MCP v4 handler policy and source inventory disagree');
  $installed=mcpInstalledSourceFiles();$unknown=array_values(array_diff(array_keys($installed),array_keys($expectedSources)));$missing=array_values(array_diff(array_keys($expectedSources),array_keys($installed)));$changed=array();
  foreach($installed as $path=>$hash)if(isset($expectedSources[$path])&&!hash_equals($expectedSources[$path],$hash))$changed[]=$path;
  if($unknown||$missing||$changed)throw new RuntimeException('PHP source inventory drift detected: unknown='.count($unknown).', missing='.count($missing).', changed='.count($changed));
  foreach($expected as $path=>$hash)if(!isset($installed[$path])||!hash_equals($hash,$installed[$path]))throw new RuntimeException("UI handler policy drift detected for '$path'");
  $result=array(
    'policyVersion'=>MCP_POLICY_VERSION,'policyHash'=>hash_file('sha256',__DIR__.'/ui-handler-policy-v4.json'),'manifestHash'=>$manifest['manifestHash']??null,
    'sourceInventoryHash'=>$inventory['inventoryHash']??null,'sourceTreeHash'=>$inventory['sourceTreeHash']??null,
    'installedHandlerCount'=>count($expected),'installedSourceFileCount'=>count($installed),'includedLibraryCount'=>(int)($inventory['includedLibraryCount']??0),
    'unknownHandlers'=>$unknown,'missingHandlers'=>$missing,'changedHandlers'=>$changed,'deferredHandlerCount'=>$deferred,'mutationCandidateCount'=>(int)$manifest['mutationCandidateCount']
  );return $result;
}

function mcpAssertPolicyComplete(): array {
  $manifest=mcpPolicyManifest();$installed=mcpInstalledClasses();$expected=array_keys($manifest['classes']);$unknown=array_values(array_diff($installed,$expected));$missing=array_values(array_diff($expected,$installed));$changed=array();
  foreach($manifest['classes'] as $class=>$policy){$path='/var/www/html/'.($policy['sourcePath']??'');if(is_file($path)&&!hash_equals((string)($policy['sourceHash']??''),hash_file('sha256',$path)))$changed[]=$class;}
  if($unknown||$missing||$changed)throw new RuntimeException('Class policy drift detected: unknown='.count($unknown).', missing='.count($missing).', changed='.count($changed));
  return array('policyVersion'=>MCP_POLICY_VERSION,'policyHash'=>hash_file('sha256',__DIR__.'/class-policy-v4.json'),'manifestHash'=>$manifest['manifestHash']??null,'expectedInstalledClassCount'=>(int)$manifest['expectedInstalledClassCount'],'installedClassCount'=>count($installed),'unknownClasses'=>$unknown,'missingClasses'=>$missing,'changedClasses'=>$changed,'handlers'=>mcpAssertHandlerPolicyComplete());
}

function mcpClassPolicy(string $class): array {
  $manifest=mcpPolicyManifest();
  if(!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D',$class)||!isset($manifest['classes'][$class])||!SqlElement::class_exists($class)||!is_subclass_of($class,'SqlElement'))return array('objectClass'=>$class,'classification'=>'internal','supported'=>false,'reason'=>'not_an_installed_sql_element','operations'=>array(),'guarded'=>false,'policyVersion'=>MCP_POLICY_VERSION);
  return array_merge($manifest['classes'][$class],array('policyVersion'=>MCP_POLICY_VERSION));
}

function mcpRequireClassOperation(string $class,string $operation): array {
  $policy=mcpClassPolicy($class);if(!$policy['supported']||!in_array($operation,$policy['operations'],true))mcpJsonError(403,'unsupported_class_operation',"Operation '$operation' is not exposed for '$class'",array('policy'=>$policy));
  Security::checkValidClass($class);return $policy;
}

function mcpReferenceParentReadAllowed(string $class,?array $policy=null): bool {
  $policy=$policy??mcpClassPolicy($class);
  if(($policy['classification']??'')!=='reference')return false;
  $parents=array('ActivityPlanningMode'=>'Activity');
  $parent=$parents[$class]??null;
  if(!$parent)return false;
  try{return Security::checkValidAccessForUser(null,'read',$parent,null,false);}catch(Throwable $error){return false;}
}

function mcpPolicyForCurrentUser(array $policy): array {
  $class=(string)($policy['objectClass']??'');$permission=array('read'=>'denied','create'=>'denied','update'=>'denied','delete'=>'denied');
  if(!$policy['supported'])return array_merge($policy,array('effectiveOperations'=>array(),'permission'=>$permission));
  try{if(Security::checkValidAccessForUser(null,'read',$class,null,false))$permission['read']='class';elseif(mcpReferenceParentReadAllowed($class,$policy))$permission['read']='reference-parent';elseif(in_array($policy['classification'],array('relation','derived'),true))$permission['read']='parent-scoped';}catch(Throwable $error){}
  if(in_array('create',$policy['operations'],true)){try{if(Security::checkValidAccessForUser(new $class(),'create',null,null,false))$permission['create']='class';}catch(Throwable $error){}}
  foreach(array('update','delete') as $operation)if(in_array($operation,$policy['operations'],true))$permission[$operation]='object-scoped';
  $effective=array();foreach($permission as $operation=>$mode)if($mode!=='denied')$effective[]=$operation;
  return array_merge($policy,array('effectiveOperations'=>$effective,'permission'=>$permission,'permissionEvaluatedFor'=>(string)(getSessionUser()->name??'')));
}

function mcpHandleUiHandlers(array $input): never {
  $manifest=mcpHandlerPolicyManifest();$module=(string)($input['module']??'');$classification=(string)($input['classification']??'');$mutation=(string)($input['mutationType']??'');$search=mb_strtolower((string)($input['search']??''));$size=max(1,min(200,(int)($input['pageSize']??100)));
  $fingerprint=hash('sha256',json_encode(array('module'=>$module,'classification'=>$classification,'mutationType'=>$mutation,'search'=>$search),JSON_UNESCAPED_SLASHES));$cursor=mcpSignedCursorDecode($input['cursor']??null);if($cursor&&(($cursor['kind']??'')!=='ui-handlers'||($cursor['fingerprint']??'')!==$fingerprint))mcpJsonError(400,'cursor_query_mismatch','Cursor does not belong to this UI handler query');$after=(string)($cursor['after']??'');
  $filtered=array();foreach($manifest['handlers'] as $handler){if($module&&!in_array($module,$handler['modules']??array(),true))continue;if($classification&&($handler['classification']??'')!==$classification)continue;if($mutation&&!in_array($mutation,$handler['mutationTypes']??array(),true))continue;if($search&&!str_contains(mb_strtolower(($handler['id']??'').' '.($handler['path']??'')),$search))continue;$filtered[]=$handler;}
  $total=count($filtered);$items=array();foreach($filtered as $handler){if($after&&strcmp((string)$handler['id'],$after)<=0)continue;$items[]=$handler;if(count($items)>$size)break;}$hasMore=count($items)>$size;if($hasMore)array_pop($items);$next=$hasMore?mcpSignedCursorEncode(array('kind'=>'ui-handlers','fingerprint'=>$fingerprint,'after'=>end($items)['id'])):null;
  mcpJsonResponse(array('policyVersion'=>MCP_POLICY_VERSION,'policyHash'=>hash_file('sha256',__DIR__.'/ui-handler-policy-v4.json'),'returned'=>count($items),'total'=>$total,'hasMore'=>$hasMore,'nextCursor'=>$next,'items'=>$items));
}

function mcpRedactObject(array $value): array {
  foreach(array_keys($value) as $field)if(mcpSensitiveField((string)$field))unset($value[$field]);return $value;
}
