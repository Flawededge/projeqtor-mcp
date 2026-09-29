<?php
declare(strict_types=1);

function mcpSteeringTestRunWorker(int $jobId,array $arguments,string $username): array {
  if(workerCancelled($jobId))throw new RuntimeException('cancelled');
  return mcpSteeringTestRunBatch($arguments,$jobId);
}

function mcpSteeringVotingWorker(int $jobId,array $arguments,string $username): array {
  $items=$arguments['votes'];$mode=(string)($arguments['transactionMode']??'atomic');
  return mcpSteeringBatch($items,$mode,function(array $item):array {
    $parent=mcpSteeringTarget((string)$item['refType'],(int)$item['refId'],'read');$actor=(int)getSessionUser()->id;$party=(string)$item['partyType'];$criteria=array('refType'=>(string)$item['refType'],'refId'=>(int)$item['refId'],'idVoter'=>$actor);
    if($party==='user')$criteria['idUser']=$actor;else{$criteria['idClient']=(int)$item['partyId'];$contact=new Contact($actor);if(!$contact->id||(int)$contact->idClient!==(int)$item['partyId'])mcpJsonError(403,'forbidden','The actor does not represent this client');}
    $attribution=new VotingAttribution((int)$item['attributionId']);if(!$attribution->id)mcpJsonError(404,'steering_target_not_found','Voting attribution was not found');mcpSteeringVersion($attribution,array('expectedVersion'=>$item['attributionExpectedVersion']),'update');
    if($party==='user'&&(int)$attribution->idUser!==$actor)mcpJsonError(403,'forbidden','Voting attribution does not belong to the actor');if($party==='client'&&(int)$attribution->idClient!==(int)$item['partyId'])mcpJsonError(403,'forbidden','Voting attribution does not belong to the client');if($attribution->refType&&$attribution->refType!==$item['refType'])mcpJsonError(403,'forbidden','Voting attribution does not cover this object type');if($attribution->idProject&&(int)$attribution->idProject!==(int)($parent->idProject??0))mcpJsonError(403,'forbidden','Voting attribution does not cover this project');
    $vote=mcpSteeringNatural('Voting',$criteria);$requested=(string)$item['operation'];$oldValue=$vote->id?abs((float)$vote->value):0.0;$newValue=$requested==='cast'?abs((float)$item['value']):0.0;$operation=$requested==='retract'?'delete':($vote->id?'update':'create');
    if($operation==='delete'&&!$vote->id)return array('status'=>'existing','objectClass'=>'Voting','id'=>null,'requestedFields'=>array(),'appliedFields'=>array(),'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>array(),'concurrencyUnchecked'=>false);
    $used=max(0,(float)$attribution->usedValue+$newValue-$oldValue);if($used>(float)$attribution->totalValue)mcpJsonError(400,'voting_budget_exceeded','The vote exceeds the remaining attribution');
    $noteId=$vote->id?(int)$vote->idNote:null;if(!empty($item['note'])){$note=$noteId?new Note($noteId):new Note();$noteData=array('idUser'=>$actor,'refType'=>(string)$item['refType'],'refId'=>(int)$item['refId'],'creationDate'=>$note->creationDate?:date('Y-m-d H:i:s'),'note'=>(string)$item['note'],'idPrivacy'=>(int)($item['privacyId']??1));$noteResult=mcpSteeringSave($note,$note->id?'update':'create',$note->id?array('expectedVersion'=>$item['noteExpectedVersion']??''):array(),$noteData,true);$noteId=(int)$noteResult['id'];if(!empty($item['notify'])&&method_exists($parent,'sendMailIfMailable'))$parent->sendMailIfMailable(false,false,false,false,!$vote->id,false,false,false,false,false,false,true);}
    $data=$operation==='delete'?array():$criteria+array('value'=>(float)$item['value'],'idNote'=>$noteId);$result=mcpSteeringSave($vote,$operation,$item,$data,true);mcpSteeringSave($attribution,'update',array('expectedVersion'=>$item['attributionExpectedVersion']),array('usedValue'=>$used,'leftValue'=>(float)$attribution->totalValue-$used),true);
    $votingItem=mcpSteeringNatural('VotingItem',array('refType'=>(string)$item['refType'],'refId'=>(int)$item['refId']));if(method_exists($votingItem,'save'))$votingItem->save();$result['recalculatedFields']=array('VotingItem','VotingAttribution');return $result;
  },function(int $index)use($jobId,$items):void{if(workerCancelled($jobId))throw new RuntimeException('cancelled');if($index%10===0)workerUpdate($jobId,'running',min(95,5+(int)(90*($index+1)/max(1,count($items)))));});
}
