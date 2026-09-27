<?php
declare(strict_types=1);

function mcpProductsUpgradeWorker(int $jobId,array $arguments,string $username): array {
  mcpProductsVersionTarget((int)$arguments['productVersionId']);$changes=mcpProductsUpgradePlan($arguments);$items=array();$total=max(1,count($changes));Sql::beginTransaction();
  try{
    foreach($changes as $index=>$change){
      if(workerCancelled($jobId))throw new RuntimeException('cancelled');$object=new ProductVersionStructure((int)$change['structureId']);
      if(!$object->id)throw new RuntimeException('version_structure_missing');if(!hash_equals((string)$change['expectedVersion'],mcpObjectVersion($object)))throw new RuntimeException('version_structure_changed');
      if($change['changed']){
        $parent=new ProductOrComponent((int)$object->idProductVersion,true);global $doNotUpdateAllVersionProject;
        $doNotUpdateAllVersionProject=(($parent->scope??'')!=='Product');$object->idComponentVersion=(int)$change['toVersionId'];
        try{$raw=$object->save();}finally{$doNotUpdateAllVersionProject=false;}
        if(getLastOperationStatus($raw)!=='OK')throw new RuntimeException(cleanApiMessage($raw));$status='updated';
      }else{$status='existing';}
      $items[]=array('index'=>$index,'status'=>$status,'objectClass'=>'ProductVersionStructure','id'=>(int)$object->id,'requestedFields'=>array('idComponentVersion'),'appliedFields'=>$change['changed']?array('idComponentVersion'):array(),'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>array(),'saved'=>mcpObjectArray(new ProductVersionStructure($object->id)),'concurrencyUnchecked'=>false);
      if($index%10===0)workerUpdate($jobId,'running',min(95,5+(int)(90*($index+1)/$total)));
    }
    Sql::commitTransaction();
  }catch(Throwable $error){Sql::rollbackTransaction();throw $error;}
  $result=array('ok'=>true,'rolledBack'=>false,'transactionMode'=>'atomic','items'=>$items);$result['effects']=mcpProductsEffects($result);return $result;
}
