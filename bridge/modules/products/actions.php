<?php
declare(strict_types=1);

/**
 * Semantic Products workflows.  The module owns only product/version domain
 * behavior; authentication, action idempotency, confirmation tokens and audit
 * records remain in the shared action runtime.
 */

function mcpProductsActionAvailable(array $action): bool {
  foreach ($action['permissionClasses']??array() as $class) {
    $policy=mcpClassPolicy((string)$class);
    if (!($policy['supported']??false)) return false;
  }
  return true;
}

function mcpProductsFields(array $item,array $allowed): array {
  $data=array();foreach($allowed as $field)if(array_key_exists($field,$item))$data[$field]=$item[$field];return $data;
}

function mcpProductsEffects(array $result): array {
  $effects=array();
  foreach($result['items']??array() as $item){
    $status=(string)($item['status']??'');
    if(!in_array($status,array('created','updated','deleted','existing'),true))continue;
    foreach(($item['relatedIds']??array($item['id']??null)) as $id)if($id)$effects[]=array(
      'action'=>$status==='deleted'?'delete':($status==='created'?'create':'update'),
      'objectClass'=>$item['objectClass']??null,'id'=>(int)$id
    );
  }
  return $effects;
}

function mcpProductsBatch(array $entries,string $mode,callable $executor): array {
  if(count($entries)<1||count($entries)>200)mcpJsonError(400,'invalid_batch','Products actions require 1 to 200 items');
  if(!in_array($mode,array('atomic','best_effort'),true))mcpJsonError(400,'invalid_transaction_mode','transactionMode must be atomic or best_effort');
  $items=array();if($mode==='atomic')Sql::beginTransaction();
  foreach($entries as $index=>$entry){
    if($mode==='best_effort')Sql::beginTransaction();$GLOBALS['mcpCaptureErrors']=true;
    try{
      $item=$executor($entry,$index);$item['index']=$index;$items[]=$item;
      if($mode==='best_effort')Sql::commitTransaction();
    }catch(Throwable $error){
      if($mode==='best_effort')Sql::rollbackTransaction();
      $details=$error instanceof McpBridgeException?array_merge(array('code'=>$error->errorCode,'message'=>$error->getMessage()),$error->details):array('code'=>'products_operation_failed','message'=>cleanApiMessage($error->getMessage()));
      $items[]=array('index'=>$index,'status'=>'error','objectClass'=>$entry['objectClass']??'ProductsInternal','id'=>$entry['id']??null,'error'=>$details);
      if($mode==='atomic'){Sql::rollbackTransaction();$result=array('ok'=>false,'rolledBack'=>true,'transactionMode'=>$mode,'items'=>$items,'effects'=>array());$GLOBALS['mcpCaptureErrors']=false;return $result;}
    }finally{$GLOBALS['mcpCaptureErrors']=false;}
  }
  if($mode==='atomic')Sql::commitTransaction();
  $result=array('ok'=>!count(array_filter($items,fn($item)=>($item['status']??'')==='error')),'rolledBack'=>false,'transactionMode'=>$mode,'items'=>$items);
  $result['effects']=mcpProductsEffects($result);return $result;
}

function mcpProductsNaturalObject(string $class,array $criteria): object {
  foreach($criteria as $value)if($value===null||$value==='')return new $class();
  return SqlElement::getSingleSqlElementFromCriteria($class,$criteria);
}

function mcpProductsRequireVersion(object $object,array $entry,string $operation): void {
  if(!$object->id||!in_array($operation,array('update','delete'),true))return;
  $expected=(string)($entry['expectedVersion']??'');
  if($expected==='')mcpJsonError(409,'expected_version_required','Updates and deletes require expectedVersion',array('objectClass'=>get_class($object),'id'=>(int)$object->id));
  $actual=mcpObjectVersion($object);
  if(!hash_equals($actual,$expected))mcpJsonError(409,'version_conflict',get_class($object).' #'.$object->id.' has changed',array('expectedVersion'=>$expected,'actualVersion'=>$actual));
}

function mcpProductsDirectAccess(object $object,string $operation): bool {
  try{return (bool)Security::checkValidAccessForUser($object,$operation,null,null,false);}catch(Throwable $error){return false;}
}

function mcpProductsRequireTarget(string $class,int $id,string $operation='update'): object {
  Security::checkValidClass($class);$object=new $class($id);
  if(!$object->id)mcpJsonError(404,'products_target_not_found',"$class #$id was not found");
  if(!mcpProductsDirectAccess($object,$operation))mcpJsonError(403,'forbidden',"$operation access is denied for $class #$id");
  return $object;
}

function mcpProductsVersionTarget(int $id,string $operation='update'): object {
  $version=new Version($id,true);if(!$version->id)mcpJsonError(404,'products_target_not_found',"Version #$id was not found");
  $class=$version->scope==='Component'?'ComponentVersion':'ProductVersion';return mcpProductsRequireTarget($class,$id,$operation);
}

function mcpProductsProductTarget(int $id,string $operation='update'): object {
  $base=new ProductOrComponent($id,true);if(!$base->id)mcpJsonError(404,'products_target_not_found',"Product or component #$id was not found");
  $class=$base->scope==='Component'?'Component':'Product';return mcpProductsRequireTarget($class,$id,$operation);
}

function mcpProductsSave(object $object,string $operation,array $entry,array $requested,bool $contextualAccess=false): array {
  $class=get_class($object);mcpRequireClassOperation($class,$operation==='create'?'create':$operation);
  mcpProductsRequireVersion($object,$entry,$operation);
  if(!$contextualAccess&&!mcpProductsDirectAccess($object,$operation))mcpJsonError(403,'forbidden',"$operation access is denied for '$class'");
  $id=(int)($object->id??0);
  if($operation==='delete'){
    SqlElement::setDeleteConfirmed();$raw=$object->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'delete_failed',cleanApiMessage($raw));
    return array('status'=>'deleted','objectClass'=>$class,'id'=>$id,'requestedFields'=>array(),'appliedFields'=>array(),'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>array(),'concurrencyUnchecked'=>false);
  }
  foreach($requested as $field=>$value)$object->$field=$value;
  $control=method_exists($object,'control')?cleanApiMessage($object->control()):'OK';if($control!==''&&strtoupper($control)!=='OK')mcpJsonError(400,'validation_failed',$control);
  $new=!$object->id;$raw=$object->save();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'save_failed',cleanApiMessage($raw));
  return mcpOperationResult($new?'created':'updated',$class,$object,$requested,array('concurrencyUnchecked'=>false));
}

function mcpProductsResolveOperation(string $class,array $entry,array $criteria): array {
  $explicitId=!empty($entry['id']);$verb=(string)($entry['operation']??'upsert');$object=$explicitId?new $class((int)$entry['id']):mcpProductsNaturalObject($class,$criteria);
  if($explicitId&&!$object->id)mcpJsonError(404,'products_target_not_found',"$class #{$entry['id']} was not found");
  if($verb==='upsert')$verb=$object->id?'update':'create';
  if($verb==='create'&&$object->id)mcpJsonError(409,'products_duplicate','The relationship already exists',array('objectClass'=>$class,'id'=>(int)$object->id));
  if(in_array($verb,array('update','delete'),true)&&!$object->id)mcpJsonError(404,'products_target_not_found',"$class target was not found",array('criteria'=>$criteria));
  if($verb==='create')$object=new $class();return array($object,$verb);
}

function mcpProductsHierarchyAction(array $arguments,string $username,string $action): array {
  $allowed=array(
    'Product'=>array('name','designation','idClient','idContact','idProduct','idProductType','creationDate','idStatus','idle','description'),
    'Component'=>array('name','designation','idClient','idContact','idProduct','idComponentType','creationDate','idStatus','idle','description'),
    'ProductVersion'=>array('idProduct','versionNumber','name','idContact','idResource','creationDate','initialStartDate','plannedStartDate','realStartDate','isStarted','initialDeliveryDate','plannedDeliveryDate','realDeliveryDate','isDelivered','initialEisDate','plannedEisDate','realEisDate','isEis','initialEndDate','plannedEndDate','realEndDate','idle','idProductVersionType','idStatus','description'),
    'ComponentVersion'=>array('idComponent','versionNumber','name','idContact','idResource','creationDate','initialStartDate','plannedStartDate','realStartDate','isStarted','initialDeliveryDate','plannedDeliveryDate','realDeliveryDate','isDelivered','initialEisDate','plannedEisDate','realEisDate','isEis','initialEndDate','plannedEndDate','realEndDate','idle','idComponentVersionType','idStatus','description')
  );
  return mcpProductsBatch($arguments['items'],(string)($arguments['transactionMode']??'atomic'),function(array $entry)use($allowed):array{
    $class=(string)$entry['objectClass'];$verb=(string)($entry['operation']??'upsert');$id=(int)($entry['id']??0);
    if($verb==='upsert')$verb=$id?'update':'create';if(in_array($verb,array('update','delete'),true)&&!$id)mcpJsonError(400,'id_required',"$verb requires id");
    $object=$id?new $class($id):new $class();if($id&&!$object->id)mcpJsonError(404,'products_target_not_found',"$class #$id was not found");
    $data=mcpProductsFields($entry,$allowed[$class]);if($verb==='create'&&!array_key_exists('creationDate',$data))$data['creationDate']=date('Y-m-d');
    return mcpProductsSave($object,$verb,$entry,$data,false);
  });
}

function mcpProductsVersionTransitionAction(array $arguments,string $username,string $action): array {
  $fields=array('idStatus','isStarted','realStartDate','isDelivered','realDeliveryDate','isEis','realEisDate','idle','realEndDate');
  return mcpProductsBatch($arguments['versions'],(string)($arguments['transactionMode']??'atomic'),function(array $entry)use($fields):array{
    $class=(string)$entry['objectClass'];$object=new $class((int)$entry['id']);if(!$object->id)mcpJsonError(404,'products_target_not_found',"$class #{$entry['id']} was not found");
    $data=mcpProductsFields($entry,$fields);if(!$data)mcpJsonError(400,'missing_transition','At least one lifecycle field is required');
    return mcpProductsSave($object,'update',$entry,$data,false);
  });
}

function mcpProductsCompositionAction(array $arguments,string $username,string $action): array {
  return mcpProductsBatch($arguments['links'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $criteria=array('idProduct'=>(int)$entry['parentId'],'idComponent'=>(int)$entry['componentId']);list($object,$verb)=mcpProductsResolveOperation('ProductStructure',$entry,$criteria);
    mcpProductsProductTarget((int)$entry['parentId']);mcpProductsRequireTarget('Component',(int)$entry['componentId']);
    $data=$verb==='delete'?array():array('idProduct'=>(int)$entry['parentId'],'idComponent'=>(int)$entry['componentId'],'comment'=>$entry['comment']??null,'idUser'=>(int)getSessionUser()->id,'creationDate'=>date('Y-m-d'),'idle'=>!empty($entry['idle'])?1:0);
    return mcpProductsSave($object,$verb,$entry,$data,true);
  });
}

function mcpProductsVersionCompositionAction(array $arguments,string $username,string $action): array {
  return mcpProductsBatch($arguments['links'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $criteria=array('idProductVersion'=>(int)$entry['parentVersionId'],'idComponentVersion'=>(int)$entry['componentVersionId']);list($object,$verb)=mcpProductsResolveOperation('ProductVersionStructure',$entry,$criteria);
    $parent=mcpProductsVersionTarget((int)$entry['parentVersionId']);mcpProductsRequireTarget('ComponentVersion',(int)$entry['componentVersionId']);
    global $doNotUpdateAllVersionProject;$doNotUpdateAllVersionProject=(($parent->scope??'')!=='Product');
    $data=$verb==='delete'?array():array('idProductVersion'=>(int)$entry['parentVersionId'],'idComponentVersion'=>(int)$entry['componentVersionId'],'comment'=>$entry['comment']??null,'idUser'=>(int)getSessionUser()->id,'creationDate'=>date('Y-m-d'),'idle'=>!empty($entry['idle'])?1:0);
    try{return mcpProductsSave($object,$verb,$entry,$data,true);}finally{$doNotUpdateAllVersionProject=false;}
  });
}

function mcpProductsCompatibilityAction(array $arguments,string $username,string $action): array {
  return mcpProductsBatch($arguments['links'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $left=(int)$entry['versionAId'];$right=(int)$entry['versionBId'];if($left===$right)mcpJsonError(400,'validation_failed','A version cannot be compatible with itself');
    mcpProductsVersionTarget($left);mcpProductsVersionTarget($right);
    $direct=mcpProductsNaturalObject('VersionCompatibility',array('idVersionA'=>$left,'idVersionB'=>$right));
    $reverse=mcpProductsNaturalObject('VersionCompatibility',array('idVersionA'=>$right,'idVersionB'=>$left));
    $explicitId=!empty($entry['id']);$object=$explicitId?new VersionCompatibility((int)$entry['id']):($direct->id?$direct:$reverse);
    if($explicitId&&!$object->id)mcpJsonError(404,'products_target_not_found',"VersionCompatibility #{$entry['id']} was not found");
    $verb=(string)($entry['operation']??'upsert');if($verb==='upsert')$verb=$object->id?'update':'create';
    if($verb==='create'&&$object->id)mcpJsonError(409,'products_duplicate','The compatibility link already exists',array('id'=>(int)$object->id));
    if(in_array($verb,array('update','delete'),true)&&!$object->id)mcpJsonError(404,'products_target_not_found','The compatibility link was not found');
    if($verb==='create')$object=new VersionCompatibility();
    $data=$verb==='delete'?array():array('idVersionA'=>$left,'idVersionB'=>$right,'comment'=>$entry['comment']??null,'idUser'=>(int)getSessionUser()->id,'creationDate'=>date('Y-m-d'),'idle'=>!empty($entry['idle'])?1:0);
    return mcpProductsSave($object,$verb,$entry,$data,true);
  });
}

function mcpProductsContextAction(array $arguments,string $username,string $action): array {
  return mcpProductsBatch($arguments['links'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $type=(string)$entry['targetType'];$isVersion=str_ends_with($type,'Version');$class=$isVersion?'VersionContext':'ProductContext';$targetId=(int)$entry['targetId'];
    $isVersion?mcpProductsVersionTarget($targetId):mcpProductsRequireTarget($type,$targetId);$field=$isVersion?'idVersion':'idProduct';$scope=str_replace('Version','',$type);
    $criteria=array($field=>$targetId,'scope'=>$scope,'idContext'=>(int)$entry['idContext']);list($object,$verb)=mcpProductsResolveOperation($class,$entry,$criteria);
    $data=$verb==='delete'?array():array($field=>$targetId,'scope'=>$scope,'idContext'=>(int)$entry['idContext'],'idUser'=>(int)getSessionUser()->id,'creationDate'=>date('Y-m-d'),'idle'=>!empty($entry['idle'])?1:0);
    return mcpProductsSave($object,$verb,$entry,$data,true);
  });
}

function mcpProductsLanguageAction(array $arguments,string $username,string $action): array {
  return mcpProductsBatch($arguments['links'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $type=(string)$entry['targetType'];$isVersion=str_ends_with($type,'Version');$class=$isVersion?'VersionLanguage':'ProductLanguage';$targetId=(int)$entry['targetId'];
    $isVersion?mcpProductsVersionTarget($targetId):mcpProductsRequireTarget($type,$targetId);$field=$isVersion?'idVersion':'idProduct';$scope=str_replace('Version','',$type);
    $criteria=array($field=>$targetId,'scope'=>$scope,'idLanguage'=>(int)$entry['idLanguage']);list($object,$verb)=mcpProductsResolveOperation($class,$entry,$criteria);
    $data=$verb==='delete'?array():array($field=>$targetId,'scope'=>$scope,'idLanguage'=>(int)$entry['idLanguage'],'idUser'=>(int)getSessionUser()->id,'creationDate'=>date('Y-m-d'),'idle'=>!empty($entry['idle'])?1:0);
    return mcpProductsSave($object,$verb,$entry,$data,true);
  });
}

function mcpProductsAssetLinkAction(array $arguments,string $username,string $action): array {
  return mcpProductsBatch($arguments['links'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $versionId=(int)$entry['versionId'];$assetId=(int)$entry['assetId'];mcpProductsVersionTarget($versionId);mcpProductsRequireTarget('Asset',$assetId);
    $criteria=array('idProductVersion'=>$versionId,'idAsset'=>$assetId);list($object,$verb)=mcpProductsResolveOperation('ProductAsset',$entry,$criteria);
    $data=$verb==='delete'?array():array('idProductVersion'=>$versionId,'idAsset'=>$assetId,'comment'=>$entry['comment']??null,'idUser'=>(int)getSessionUser()->id,'creationDate'=>date('Y-m-d'));
    return mcpProductsSave($object,$verb,$entry,$data,true);
  });
}

function mcpProductsAssetCompositionAction(array $arguments,string $username,string $action): array {
  return mcpProductsBatch($arguments['assets'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $asset=mcpProductsRequireTarget('Asset',(int)$entry['id'],'update');$parent=$entry['parentAssetId']??null;if($parent!==null){if((int)$parent===(int)$entry['id'])mcpJsonError(400,'validation_failed','An asset cannot contain itself');mcpProductsRequireTarget('Asset',(int)$parent);}
    return mcpProductsSave($asset,'update',$entry,array('idAsset'=>$parent===null?null:(int)$parent),false);
  });
}

function mcpProductsProjectLinksAction(array $arguments,string $username,string $action): array {
  return mcpProductsBatch($arguments['links'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $kind=(string)$entry['kind'];$class=$kind==='product'?'ProductProject':'VersionProject';$targetField=$kind==='product'?'idProduct':'idVersion';$targetId=(int)$entry['targetId'];
    $kind==='product'?mcpProductsRequireTarget('Product',$targetId):mcpProductsVersionTarget($targetId);mcpProductsRequireTarget('Project',(int)$entry['idProject']);
    $criteria=array($targetField=>$targetId,'idProject'=>(int)$entry['idProject']);list($object,$verb)=mcpProductsResolveOperation($class,$entry,$criteria);
    $data=$verb==='delete'?array():array($targetField=>$targetId,'idProject'=>(int)$entry['idProject'],'startDate'=>$entry['startDate']??null,'endDate'=>$entry['endDate']??null,'idle'=>!empty($entry['idle'])?1:0);
    if($class==='VersionProject'){
      global $doNotUpdateAllVersionProject;$doNotUpdateAllVersionProject=true;
      try{$result=mcpProductsSave($object,$verb,$entry,$data,true);if($verb==='delete')$object->propagateDeletionToComponentVersions();else $object->propagateCreationToComponentVersions();return $result;}finally{$doNotUpdateAllVersionProject=false;}
    }
    return mcpProductsSave($object,$verb,$entry,$data,true);
  });
}

function mcpProductsBusinessFeatureAction(array $arguments,string $username,string $action): array {
  return mcpProductsBatch($arguments['features'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    mcpProductsRequireTarget('Product',(int)$entry['idProduct']);$criteria=array('idProduct'=>(int)$entry['idProduct'],'name'=>(string)($entry['name']??''));list($object,$verb)=mcpProductsResolveOperation('BusinessFeature',$entry,$criteria);
    $data=$verb==='delete'?array():array('name'=>(string)$entry['name'],'idProduct'=>(int)$entry['idProduct'],'creationDate'=>date('Y-m-d'),'idUser'=>(int)getSessionUser()->id,'idle'=>!empty($entry['idle'])?1:0);
    return mcpProductsSave($object,$verb,$entry,$data,true);
  });
}

function mcpProductsOtherVersionAction(array $arguments,string $username,string $action): array {
  return mcpProductsBatch($arguments['links'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $refType=(string)$entry['refType'];Security::checkValidClass($refType);$ref=mcpProductsRequireTarget($refType,(int)$entry['refId']);$scope=(string)$entry['scope'];$field='id'.$scope;
    if(!property_exists($ref,$field))mcpJsonError(400,'unsupported_version_scope',"$refType does not expose $field");
    $operation=(string)($entry['operation']??'upsert');$versionId=(int)$entry['versionId'];mcpProductsVersionTarget($versionId);
    $criteria=array('refType'=>$refType,'refId'=>(int)$entry['refId'],'idVersion'=>$versionId,'scope'=>$scope);$other=mcpProductsNaturalObject('OtherVersion',$criteria);
    if($operation==='make_primary'){
      $old=(int)($ref->$field??0);mcpProductsRequireVersion($ref,$entry,'update');$ref->$field=$versionId;$primary=mcpProductsSave($ref,'update',$entry,array($field=>$versionId),false);$related=array();
      if($other->id)$related[]=(int)mcpProductsSave($other,'delete',array('expectedVersion'=>mcpObjectVersion($other)),array(),true)['id'];
      if($old&&$old!==$versionId){$replacement=new OtherVersion();$related[]=(int)mcpProductsSave($replacement,'create',array(),array('refType'=>$refType,'refId'=>(int)$entry['refId'],'idVersion'=>$old,'scope'=>$scope,'comment'=>$entry['comment']??null,'creationDate'=>date('Y-m-d H:i:s'),'idUser'=>(int)getSessionUser()->id),true)['id'];}
      $primary['relatedIds']=$related;return $primary;
    }
    if($operation==='delete'){if(!$other->id)mcpJsonError(404,'products_target_not_found','Other version link was not found');return mcpProductsSave($other,'delete',$entry,array(),true);}
    if(!(int)($ref->$field??0)){mcpProductsRequireVersion($ref,$entry,'update');return mcpProductsSave($ref,'update',$entry,array($field=>$versionId),false);}
    if((int)$ref->$field===$versionId)return array('status'=>'existing','objectClass'=>$refType,'id'=>(int)$ref->id,'requestedFields'=>array($field),'appliedFields'=>array($field),'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>array(),'saved'=>mcpObjectArray($ref),'concurrencyUnchecked'=>false);
    if($other->id)return array('status'=>'existing','objectClass'=>'OtherVersion','id'=>(int)$other->id,'requestedFields'=>array(),'appliedFields'=>array(),'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>array(),'saved'=>mcpObjectArray($other),'concurrencyUnchecked'=>false);
    return mcpProductsSave(new OtherVersion(),'create',$entry,array('refType'=>$refType,'refId'=>(int)$entry['refId'],'idVersion'=>$versionId,'scope'=>$scope,'comment'=>$entry['comment']??null,'creationDate'=>date('Y-m-d H:i:s'),'idUser'=>(int)getSessionUser()->id),true);
  });
}

function mcpProductsRestrictionAction(array $arguments,string $username,string $action): array {
  if(securityGetAccessRightYesNo('menuProfile','update')!=='YES')mcpJsonError(403,'forbidden','Profile update access is required');
  return mcpProductsBatch($arguments['profiles'],(string)($arguments['transactionMode']??'atomic'),function(array $entry):array{
    $profile=mcpProductsRequireTarget('Profile',(int)$entry['idProfile'],'update');$object=SqlElement::getSingleSqlElementFromCriteria('RestrictList',array('idProfile'=>(int)$profile->id));$new=!$object->id;
    $mode=(string)$entry['mode'];foreach(array('showAll','showStarted','showDelivered','showInService') as $field)$object->$field=$field===$mode?1:0;$object->idProfile=(int)$profile->id;
    if(!$new)mcpProductsRequireVersion($object,$entry,'update');$raw=$object->save();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'save_failed',cleanApiMessage($raw));
    return array('status'=>$new?'created':'updated','objectClass'=>'RestrictList','id'=>(int)$object->id,'requestedFields'=>array('idProfile','mode'),'appliedFields'=>array('idProfile','mode'),'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>array(),'saved'=>array('idProfile'=>(int)$profile->id,'mode'=>$mode,'_version'=>mcpObjectVersion($object)),'concurrencyUnchecked'=>false);
  });
}

function mcpProductsComponentFilterAction(array $arguments,string $username,string $action): array {
  if(!Security::checkValidAccessForUser(null,'read','Component',null,false))mcpJsonError(403,'forbidden','Component read access is required');
  $user=getSessionUser();$target=(string)$arguments['target'];if(!isset($user->_arrayFiltersDetail)||!is_array($user->_arrayFiltersDetail))$user->_arrayFiltersDetail=array();$user->_arrayFiltersDetail[$target]=array();
  $ids=array_values(array_unique(array_map('intval',$arguments['ids']??array())));if($ids){$names=array();$class=$target==='ComponentVersion'?'ComponentVersion':'Component';foreach($ids as $id){$obj=new $class($id);if($obj->id&&mcpProductsDirectAccess($obj,'read'))$names[]=(string)$obj->name;else mcpJsonError(403,'forbidden',"$class #$id is unavailable");}
    $user->_arrayFiltersDetail[$target][]=array('disp'=>array('attribute'=>'id','operator'=>'amongst','value'=>implode(', ',$names)),'sql'=>array('attribute'=>'id','operator'=>'IN','value'=>'('.implode(',',$ids).')'),'isDynamic'=>'0','orOperator'=>'0','hidden'=>'1');
  }
  setSessionUser($user);return array('ok'=>true,'target'=>$target,'ids'=>$ids,'count'=>count($ids),'effects'=>array());
}

function mcpProductsUpgradePlan(array $arguments): array {
  $criteria=array('idProductVersion'=>(int)$arguments['productVersionId']);
  if(!empty($arguments['structureId']))$criteria['id']=(int)$arguments['structureId'];
  $rows=(new ProductVersionStructure())->getSqlElementsFromCriteria($criteria,false,null,'id asc');$changes=array();
  foreach($rows as $row){$current=new ComponentVersion((int)$row->idComponentVersion);if(!$current->id)continue;$where='idProduct='.Sql::fmtId((int)$current->idComponent)." AND (isEis=1 OR isDelivered=1) AND versionNumber IS NOT NULL";$latest=$current->getSqlElementsFromCriteria(null,false,$where,'versionNumber DESC',1,true);$next=$latest?reset($latest):null;
    $changes[]=array('structureId'=>(int)$row->id,'fromVersionId'=>(int)$current->id,'toVersionId'=>$next?(int)$next->id:(int)$current->id,'changed'=>(bool)($next&&$next->id!=$current->id),'expectedVersion'=>mcpObjectVersion($row));
  }
  return $changes;
}

function mcpProductsUpgradePreview(array $arguments,string $username,string $action): array {
  $parent=mcpProductsVersionTarget((int)$arguments['productVersionId'],'update');
  mcpProductsRequireVersion($parent,$arguments,'update');
  $changes=mcpProductsUpgradePlan($arguments);return array('count'=>count($changes),'changed'=>count(array_filter($changes,fn($item)=>$item['changed'])),'items'=>array_slice($changes,0,200));
}

function mcpProductsBatchPreview(array $arguments,string $username,string $action): array {
  foreach(array('items','versions','links','assets','features','profiles') as $key)if(isset($arguments[$key])&&is_array($arguments[$key])){
    $items=array();foreach(array_slice($arguments[$key],0,200) as $index=>$entry)$items[]=array(
      'index'=>$index,'operation'=>$entry['operation']??'upsert','objectClass'=>$entry['objectClass']??null,
      'id'=>$entry['id']??null,'expectedVersion'=>$entry['expectedVersion']??null
    );
    return array('count'=>count($arguments[$key]),'transactionMode'=>$arguments['transactionMode']??'atomic','items'=>$items);
  }
  return array('count'=>0,'transactionMode'=>$arguments['transactionMode']??'atomic','items'=>array());
}
