<?php
declare(strict_types=1);

function cleanupFail(string $message, int $code = 1): never {
  fwrite(STDERR, "Beta 4 state cleanup: $message\n");
  exit($code);
}

$snapshotPath='/var/lib/projeqtor/mcp-harness-module-state.json';
if(!is_file($snapshotPath)){
  fwrite(STDOUT,"Beta 4 state cleanup: no captured state; nothing to restore\n");
  exit(0);
}
$snapshot=json_decode((string)file_get_contents($snapshotPath),true);
if(!is_array($snapshot)||($snapshot['version']??0)!==2||!is_array($snapshot['modules']??null)||!is_array($snapshot['parameters']??null))cleanupFail('captured state is malformed',64);

$_SERVER['SCRIPT_NAME']='/tool/mcp-harness-cleanup.php';
$_SERVER['PHP_SELF']=$_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_URI']=$_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_METHOD']='GET';
$_SERVER['REMOTE_ADDR']='127.0.0.1';
$_SERVER['HTTP_HOST']='localhost';
$_SERVER['SERVER_NAME']='localhost';
$_SERVER['SERVER_PORT']='80';
$batchMode=true;
$cronnedScript=true;
$noScriptLog=false;
chdir('/var/www/html/tool');
require '/var/www/html/tool/projeqtor.php';

$admin=SqlElement::getSingleSqlElementFromCriteria('User',array('name'=>'admin'));
if(!$admin->id)cleanupFail('official admin user is missing',65);
setSessionUser($admin);

foreach($snapshot['parameters'] as $parameterCode=>$parameterState){
  if(!is_string($parameterCode)||!is_array($parameterState)||!array_key_exists('exists',$parameterState))cleanupFail('captured parameter state is malformed',66);
  $parameter=SqlElement::getSingleSqlElementFromCriteria('Parameter',array('idUser'=>null,'idProject'=>null,'parameterCode'=>$parameterCode));
  if($parameterState['exists']===true){
    Parameter::storeGlobalParameter($parameterCode,(string)($parameterState['value']??''));
    if((string)Parameter::getGlobalParameter($parameterCode)!==(string)($parameterState['value']??''))cleanupFail("could not restore parameter $parameterCode",67);
  }elseif($parameter->id){
    $result=$parameter->delete();
    if(!in_array(getLastOperationStatus($result),array('OK','NO_CHANGE'),true))cleanupFail("could not remove disposable parameter $parameterCode",68);
  }
}

$parentModuleIds=array();
foreach($snapshot['modules'] as $moduleName=>$active){
  $module=SqlElement::getSingleSqlElementFromCriteria('Module',array('name'=>$moduleName));
  if(!$module->id)cleanupFail("captured module $moduleName is missing",69);
  $module->active=(int)$active;
  $result=$module->save();
  if(!in_array(getLastOperationStatus($result),array('OK','NO_CHANGE'),true))cleanupFail("could not restore module $moduleName",70);
  if((int)$module->idModule>0)$parentModuleIds[(int)$module->idModule]=true;
}
foreach(array_keys($parentModuleIds) as $parentModuleId){
  $parent=new Module((int)$parentModuleId);
  if(!$parent->id)cleanupFail("parent module $parentModuleId is missing",71);
  $result=$parent->save();
  if(!in_array(getLastOperationStatus($result),array('OK','NO_CHANGE'),true))cleanupFail("could not refresh parent module $parentModuleId",72);
}
if(!unlink($snapshotPath))cleanupFail('could not remove restored state snapshot',73);
fwrite(STDOUT,"Beta 4 state cleanup: captured module and mail state restored\n");
