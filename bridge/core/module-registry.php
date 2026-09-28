<?php
declare(strict_types=1);

const MCP_MODULE_IDS = array(
  'core','configuration','environment','tools','planning','hr','products',
  'ticketing','follow_up','financial','scrum','steering','reports'
);

function mcpObjectSchema(array $properties=array(),array $required=array(),bool $additional=false): array {
  return array('type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>$additional);
}

function mcpWorkerInternalResultField(string $field): bool {
  return in_array($field,array('path','resultPath','result_path','artifactPath','artifact_path','filePath','file_path','filesystemPath','filesystem_path','absolutePath','absolute_path'),true);
}

function mcpPublicActionResultSchema(array $schema): array {
  $public=array();
  foreach($schema as $keyword=>$value){
    if($keyword==='properties'&&is_array($value)){
      $properties=array();foreach($value as $field=>$property)if(!mcpWorkerInternalResultField((string)$field))$properties[$field]=is_array($property)?mcpPublicActionResultSchema($property):$property;
      $public[$keyword]=$properties;
    }elseif($keyword==='required'&&is_array($value))$public[$keyword]=array_values(array_filter($value,fn($field)=>!mcpWorkerInternalResultField((string)$field)));
    elseif(is_array($value))$public[$keyword]=mcpPublicActionResultSchema($value);
    else $public[$keyword]=$value;
  }
  return $public;
}

function mcpPublicAsyncActionResult(array $result): array {
  $public=array();
  foreach($result as $field=>$value){
    if(mcpWorkerInternalResultField((string)$field))continue;
    $public[$field]=is_array($value)?mcpPublicAsyncActionResult($value):$value;
  }
  return $public;
}

function mcpActionSpec(array $schema,array $resultSchema,string $risk,bool $async,string $callable,array $mappedHandlers,string $testContract,array $options=array()): array {
  $retry=$options['retryPolicy']??($async?'recovery_required':'never');
  return array_merge(array(
    'risk'=>$risk,'async'=>$async,'schema'=>$schema,'resultSchema'=>$resultSchema,
    $async?'worker':'executor'=>$callable,'mappedHandlers'=>$mappedHandlers,
    'retryPolicy'=>$retry,'maxAttempts'=>$retry==='safe'?3:1,
    'idempotency'=>array('supported'=>true,'scope'=>'actor','sameBodyReturnsOriginal'=>true,'conflictOnDifferentBody'=>true),
    'sideEffectClassification'=>$risk,'transaction'=>$async?'worker':'atomic','testContract'=>$testContract
  ),$options);
}

function mcpModuleCatalog(): array {
  static $catalog=null;
  if($catalog!==null)return $catalog;
  $catalog=array();$actions=array();$handlerOwners=array();
  foreach(MCP_MODULE_IDS as $moduleId){
    $path=dirname(__DIR__).'/modules/'.$moduleId.'/module.php';
    if(!is_file($path))throw new RuntimeException("MCP module '$moduleId' is missing");
    $module=require $path;
    if(!is_array($module)||($module['id']??null)!==$moduleId)throw new RuntimeException("MCP module '$moduleId' has an invalid descriptor");
    if(isset($catalog[$moduleId]))throw new RuntimeException("Duplicate MCP module '$moduleId'");
    $module['version']=(string)($module['version']??'1');
    $module['dependencies']=array_values(array_unique($module['dependencies']??array()));
    $module['actions']=$module['actions']??array();
    foreach($module['dependencies'] as $dependency)if(!in_array($dependency,MCP_MODULE_IDS,true))throw new RuntimeException("MCP module '$moduleId' has unknown dependency '$dependency'");
    foreach($module['actions'] as $actionId=>&$action){
      if(!preg_match('/^[a-z][a-z0-9_.-]{2,100}$/D',(string)$actionId))throw new RuntimeException("MCP module '$moduleId' has invalid action '$actionId'");
      if(isset($actions[$actionId]))throw new RuntimeException("Duplicate MCP action '$actionId'");
      foreach(array('risk','async','schema','resultSchema','retryPolicy','maxAttempts','idempotency','sideEffectClassification','transaction','testContract') as $required)if(!array_key_exists($required,$action))throw new RuntimeException("MCP action '$actionId' is missing '$required'");
      if(!is_array($action['schema'])||!is_array($action['resultSchema']))throw new RuntimeException("MCP action '$actionId' has invalid schemas");
      if(!in_array($action['risk'],array('read','write','destructive','administrative','external'),true))throw new RuntimeException("MCP action '$actionId' has invalid risk");
      if(!in_array($action['retryPolicy'],array('safe','recovery_required','never'),true))throw new RuntimeException("MCP action '$actionId' has invalid retry policy");
      if(!in_array($action['transaction'],array('none','atomic','best_effort','worker'),true))throw new RuntimeException("MCP action '$actionId' has invalid transaction policy");
      if(!empty($action['async'])&&!isset($action['worker']))throw new RuntimeException("Async MCP action '$actionId' has no worker executor");
      if(empty($action['async'])&&!isset($action['executor']))throw new RuntimeException("Synchronous MCP action '$actionId' has no executor");
      $action['module']=$moduleId;$action['domain']=$action['domain']??mcpLegacyActionDomain((string)$actionId,$moduleId);$action['actionVersion']=(string)($action['actionVersion']??$module['version']);
      $action['mappedHandlers']=array_values(array_unique($action['mappedHandlers']??array()));
      foreach($action['mappedHandlers'] as $handler){
        if(isset($handlerOwners[$handler])&&$handlerOwners[$handler]!==$actionId)throw new RuntimeException("Handler '$handler' is mapped to more than one action");
        $handlerOwners[$handler]=$actionId;
      }
      $actions[$actionId]=$action;
    }unset($action);
    $catalog[$moduleId]=$module;
  }
  foreach($catalog as $moduleId=>$module)foreach($module['dependencies'] as $dependency)if(!isset($catalog[$dependency]))throw new RuntimeException("MCP module '$moduleId' dependency '$dependency' is unavailable");
  mcpValidateModuleGraph($catalog);
  $GLOBALS['mcpModuleActions']=$actions;
  return $catalog;
}

function mcpActionRegistry(): array {
  mcpModuleCatalog();
  return $GLOBALS['mcpModuleActions'];
}

function mcpModuleSummary(): array {
  $catalog=mcpModuleCatalog();$actions=mcpActionRegistry();$items=array();
  foreach($catalog as $id=>$module){
    $owned=array_filter($actions,fn($action)=>$action['module']===$id);
    $items[]=array('id'=>$id,'version'=>$module['version'],'dependencies'=>$module['dependencies'],'actionCount'=>count($owned),'enabled'=>mcpModuleEnabled($id),'availabilityReason'=>mcpModuleEnabled($id)?null:'module_disabled');
  }
  return $items;
}

function mcpModuleEnabled(string $moduleId): bool {
  $module=mcpModuleCatalog()[$moduleId]??null;
  if(!$module)return false;
  $callback=$module['enabled']??null;
  return $callback&&is_callable($callback)?(bool)$callback():true;
}

function mcpActionAvailable(string $actionId): bool {
  $action=mcpActionRegistry()[$actionId]??null;
  if(!$action||!mcpModuleEnabled($action['module']))return false;
  $callback=$action['availability']??null;
  return $callback&&is_callable($callback)?(bool)$callback($action):true;
}

function mcpSchemaTypeMatches(mixed $value,string $type): bool {
  return match($type){
    'object'=>is_array($value)&&(!array_is_list($value)||$value===array()),
    'array'=>is_array($value)&&array_is_list($value),
    'string'=>is_string($value),
    'integer'=>is_int($value),
    'number'=>is_int($value)||is_float($value),
    'boolean'=>is_bool($value),
    'null'=>$value===null,
    default=>true
  };
}

function mcpValidateSchemaValue(mixed $value,array $schema,string $path='$'): array {
  $errors=array();$types=$schema['type']??null;$types=is_array($types)?$types:($types!==null?array($types):array());
  if($types&&count(array_filter($types,fn($type)=>mcpSchemaTypeMatches($value,(string)$type)))===0)return array(array('path'=>$path,'code'=>'type','expected'=>$types));
  if(isset($schema['enum'])&&!in_array($value,$schema['enum'],true))$errors[]=array('path'=>$path,'code'=>'enum','allowed'=>$schema['enum']);
  if(is_string($value)){
    if(isset($schema['minLength'])&&mb_strlen($value)<(int)$schema['minLength'])$errors[]=array('path'=>$path,'code'=>'minLength');
    if(isset($schema['maxLength'])&&mb_strlen($value)>(int)$schema['maxLength'])$errors[]=array('path'=>$path,'code'=>'maxLength');
    if(isset($schema['pattern'])&&!preg_match('~'.str_replace('~','\\~',(string)$schema['pattern']).'~D',$value))$errors[]=array('path'=>$path,'code'=>'pattern');
  }
  if((is_int($value)||is_float($value))&&isset($schema['minimum'])&&$value<$schema['minimum'])$errors[]=array('path'=>$path,'code'=>'minimum');
  $isDeclaredArray=in_array('array',$types,true);$isDeclaredObject=in_array('object',$types,true);
  if(is_array($value)&&($isDeclaredArray||(!$types&&array_is_list($value)))){
    if(isset($schema['minItems'])&&count($value)<(int)$schema['minItems'])$errors[]=array('path'=>$path,'code'=>'minItems');
    if(isset($schema['maxItems'])&&count($value)>(int)$schema['maxItems'])$errors[]=array('path'=>$path,'code'=>'maxItems');
    if(isset($schema['items'])&&is_array($schema['items']))foreach($value as $index=>$entry)$errors=array_merge($errors,mcpValidateSchemaValue($entry,$schema['items'],$path.'['.$index.']'));
  }
  if(is_array($value)&&($isDeclaredObject||(!$types&&!array_is_list($value)))){
    foreach($schema['required']??array() as $field)if(!array_key_exists($field,$value))$errors[]=array('path'=>$path.'.'.$field,'code'=>'required');
    $properties=$schema['properties']??array();
    foreach($value as $field=>$entry){
      if(isset($properties[$field]))$errors=array_merge($errors,mcpValidateSchemaValue($entry,$properties[$field],$path.'.'.$field));
      elseif(($schema['additionalProperties']??true)===false)$errors[]=array('path'=>$path.'.'.$field,'code'=>'additional_property');
    }
  }
  return $errors;
}

function mcpValidateActionArguments(string $actionId,array $arguments): void {
  $action=mcpActionRegistry()[$actionId]??null;if(!$action)mcpJsonError(404,'action_not_found','Action is not registered');
  $errors=mcpValidateSchemaValue($arguments,$action['schema']);
  if($errors)mcpJsonError(400,'action_validation_failed','Action arguments do not match the declared schema',array('invalidFields'=>$errors));
}

function mcpValidateActionResult(string $actionId,array $result): array {
  $schema=mcpActionRegistry()[$actionId]['resultSchema'];$errors=mcpValidateSchemaValue($result,$schema);
  if($errors)throw new RuntimeException("Action '$actionId' returned a result that violates its contract");
  return $result;
}

function mcpInvokeActionExecutor(string $actionId,array $arguments,string $username): array {
  $action=mcpActionRegistry()[$actionId]??null;if(!$action)mcpJsonError(404,'action_not_found','Action is not registered');
  $executor=$action['executor']??null;if(!$executor||!is_callable($executor))throw new RuntimeException("Action '$actionId' executor is unavailable");
  $result=$executor($arguments,$username,$actionId);
  if(!is_array($result))throw new RuntimeException("Action '$actionId' returned an invalid result");
  return mcpValidateActionResult($actionId,$result);
}

function mcpWorkerActionRegistry(): array {
  $registry=array();
  foreach(mcpActionRegistry() as $actionId=>$action)if(!empty($action['async'])){
    $worker=$action['worker'];if(isset($registry[$actionId]))throw new RuntimeException("Duplicate worker action '$actionId'");
    if(!is_callable($worker))throw new RuntimeException("Worker executor for '$actionId' is unavailable");
    $registry[$actionId]=$worker;
  }
  return $registry;
}
