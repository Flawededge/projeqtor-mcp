<?php
declare(strict_types=1);

function mcpEnvironmentIncompatibilityPairAction(array $arguments,string $username,string $action): array {
  return mcpEnvironmentExecuteInternalBatch($arguments['relationships'],(string)($arguments['transactionMode']??'atomic'),function(array $entry): array {
    $left=(int)$entry['idResource'];$right=(int)$entry['idIncompatible'];
    if ($left===$right) mcpJsonError(400,'validation_failed','A resource cannot be incompatible with itself');
    foreach (array($left,$right) as $idResource) {
      $resource=new Resource($idResource);
      if (!$resource->id||!Security::checkValidAccessForUser($resource,'update',null,null,false)) mcpJsonError(403,'forbidden','Resource incompatibility access is denied',array('idResource'=>$idResource));
    }
    $forward=SqlElement::getSingleSqlElementFromCriteria('ResourceIncompatible',array('idResource'=>$left,'idIncompatible'=>$right));
    $reverse=SqlElement::getSingleSqlElementFromCriteria('ResourceIncompatible',array('idResource'=>$right,'idIncompatible'=>$left));
    $verb=(string)($entry['operation']??'upsert');
    if ($verb==='delete') {
      if (!$forward->id&&!$reverse->id) mcpJsonError(404,'environment_target_not_found','Resource incompatibility was not found');
      $deleted=array();
      if ($forward->id) {$deleted[]=(int)$forward->id;mcpEnvironmentInternalSave($forward,'delete',$entry['expectedVersion']??null);}
      if ($reverse->id) {$deleted[]=(int)$reverse->id;mcpEnvironmentInternalSave($reverse,'delete',mcpObjectVersion($reverse));}
      return array('status'=>'deleted','objectClass'=>'ResourceIncompatible','id'=>$deleted[0]??null,'relatedIds'=>$deleted);
    }
    if (!$forward->id) {$forward=new ResourceIncompatible();$forward->idResource=$left;$forward->idIncompatible=$right;}
    if (!$reverse->id) {$reverse=new ResourceIncompatible();$reverse->idResource=$right;$reverse->idIncompatible=$left;}
    if (array_key_exists('description',$entry)) {$forward->description=$entry['description'];$reverse->description=$entry['description'];}
    $primary=mcpEnvironmentInternalSave($forward,'upsert',$entry['expectedVersion']??null);
    $secondary=mcpEnvironmentInternalSave($reverse,'upsert',$reverse->id?mcpObjectVersion($reverse):null);
    $primary['relatedIds']=array((int)$primary['id'],(int)$secondary['id']);return $primary;
  });
}
