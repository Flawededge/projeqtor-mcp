<?php
declare(strict_types=1);

const MCP_FINANCIAL_INTERNAL_CLASSES=array('BillLine','BudgetElement','ExpenseDetail','TenderEvaluationCriteria','WorkCommandAccepted','WorkCommandBilled','WorkUnit','WorkUnitCatalogPhase','ComplexityValues','AbacusDefinition','AbacusLine','AbacusValue','AbacusProject','Abacusable','Phase');

function mcpFinancialActionAvailable(array $action): bool {
  foreach($action['permissionClasses']??array() as $class){
    if(!SqlElement::class_exists((string)$class))return false;
    $policy=mcpClassPolicy((string)$class);if(!($policy['supported']??false))return false;
    try{
      if(Security::checkValidAccessForUser(null,'read',(string)$class,null,false))continue;
      if(in_array('create',$policy['operations']??array(),true)&&Security::checkValidAccessForUser(new $class(),'create',null,null,false))continue;
    }catch(Throwable $error){}
    return false;
  }
  return true;
}

function mcpFinancialFields(array $item,array $allowed): array {
  $out=array();foreach($allowed as $field)if(array_key_exists($field,$item))$out[$field]=$item[$field];return $out;
}

function mcpFinancialDirectAccess(object $object,string $operation): bool {
  try{return (bool)Security::checkValidAccessForUser($object,$operation,null,null,false);}catch(Throwable $error){return false;}
}

function mcpFinancialTarget(string $class,int $id,string $operation='update',bool $semanticInternal=false): object {
  Security::checkValidClass($class);
  if(!$semanticInternal)mcpRequireClassOperation($class,$operation==='delete'?'delete':($operation==='read'?'read':'update'));
  elseif(!in_array($class,MCP_FINANCIAL_INTERNAL_CLASSES,true))mcpJsonError(403,'unsupported_financial_class',"$class is not a Financial semantic child class");
  $object=new $class($id);if(!$object->id)mcpJsonError(404,'financial_target_not_found',"$class #$id was not found");
  if(!$semanticInternal&&!mcpFinancialDirectAccess($object,$operation))mcpJsonError(403,'forbidden',"$operation access is denied for $class #$id");
  return $object;
}

function mcpFinancialRequireVersion(object $object,array $entry,string $operation): void {
  if(!$object->id||!in_array($operation,array('update','delete'),true))return;
  $expected=(string)($entry['expectedVersion']??'');if($expected==='')mcpJsonError(409,'expected_version_required',get_class($object).' #'.$object->id.' requires expectedVersion');
  $actual=mcpObjectVersion($object);if(!hash_equals($actual,$expected))mcpJsonError(409,'version_conflict',get_class($object).' #'.$object->id.' has changed',array('expectedVersion'=>$expected,'actualVersion'=>$actual));
}

function mcpFinancialItem(string $status,string $class,?int $id,array $requested=array(),array $recalculated=array(),array $related=array()): array {
  $saved=null;if($id&&$status!=='deleted'){$object=new $class($id);if($object->id)$saved=array('id'=>(int)$object->id,'_version'=>mcpObjectVersion($object));}
  return array('status'=>$status,'objectClass'=>$class,'id'=>$id,'relatedIds'=>array_values(array_filter(array_map('intval',$related))),
    'requestedFields'=>array_values($requested),'appliedFields'=>array_values($requested),'recalculatedFields'=>array_values($recalculated),'ignoredFields'=>array(),'rejectedFields'=>array(),
    'saved'=>$saved,'concurrencyUnchecked'=>false);
}

function mcpFinancialSave(object $object,string $operation,array $entry,array $fields,bool $contextual=false,array $recalculated=array()): array {
  $class=get_class($object);$internal=in_array($class,MCP_FINANCIAL_INTERNAL_CLASSES,true);
  if(!$internal)mcpRequireClassOperation($class,$operation==='create'?'create':$operation);
  mcpFinancialRequireVersion($object,$entry,$operation);
  $id=(int)($object->id??0);
  if($operation==='delete'){
    if(!$contextual&&!mcpFinancialDirectAccess($object,'delete'))mcpJsonError(403,'forbidden',"delete access is denied for $class");
    SqlElement::setDeleteConfirmed();$raw=$object->delete();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'delete_failed',cleanApiMessage($raw));
    return mcpFinancialItem('deleted',$class,$id,array(),$recalculated);
  }
  if(!$contextual&&$operation==='update'&&!mcpFinancialDirectAccess($object,'update'))mcpJsonError(403,'forbidden',"update access is denied for $class");
  foreach($fields as $field=>$value){if(!property_exists($object,$field))mcpJsonError(400,'invalid_financial_field',"$field is not writable on $class",array('invalidFields'=>array($field)));$object->$field=$value;}
  if(!$contextual&&$operation==='create'&&!mcpFinancialDirectAccess($object,'create'))mcpJsonError(403,'forbidden',"create access is denied for $class");
  $control=method_exists($object,'control')?cleanApiMessage($object->control()):'OK';if($control!==''&&strtoupper($control)!=='OK')mcpJsonError(400,'validation_failed',$control);
  $new=!$object->id;$raw=$object->save();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'save_failed',cleanApiMessage($raw));
  return mcpFinancialItem($new?'created':'updated',$class,(int)$object->id,array_keys($fields),$recalculated);
}

function mcpFinancialResolve(string $class,array $entry,array $criteria=array(),bool $internal=false): array {
  $id=(int)($entry['id']??0);$requested=(string)($entry['operation']??'upsert');$accessOperation=$requested==='delete'?'delete':'update';
  $object=$id?mcpFinancialTarget($class,$id,$accessOperation,$internal):new $class();
  if(!$id&&$criteria){$candidate=SqlElement::getSingleSqlElementFromCriteria($class,$criteria);if($candidate->id)$object=$candidate;}
  $operation=(string)($entry['operation']??'upsert');if($operation==='upsert')$operation=$object->id?'update':'create';
  if(in_array($operation,array('update','delete'),true)&&!$object->id)mcpJsonError(404,'financial_target_not_found',"$class target was not found");
  if($operation==='create'&&$object->id)mcpJsonError(409,'financial_duplicate',"$class target already exists",array('id'=>(int)$object->id));
  if($operation==='create')$object=new $class();return array($object,$operation);
}

function mcpFinancialBatch(array $entries,string $mode,callable $executor): array {
  if(count($entries)<1||count($entries)>200)mcpJsonError(400,'invalid_batch','Financial actions require 1 to 200 items');
  if(!in_array($mode,array('atomic','best_effort'),true))mcpJsonError(400,'invalid_transaction_mode','transactionMode must be atomic or best_effort');
  $items=array();if($mode==='atomic')Sql::beginTransaction();
  foreach($entries as $index=>$entry){if($mode==='best_effort')Sql::beginTransaction();$GLOBALS['mcpCaptureErrors']=true;
    try{$item=$executor($entry,$index);$item['index']=$index;$items[]=$item;if($mode==='best_effort')Sql::commitTransaction();}
    catch(Throwable $error){if($mode==='best_effort')Sql::rollbackTransaction();$details=$error instanceof McpBridgeException?array_merge(array('code'=>$error->errorCode,'message'=>$error->getMessage()),$error->details):array('code'=>'financial_operation_failed','message'=>cleanApiMessage($error->getMessage()));$items[]=array('index'=>$index,'status'=>'error','objectClass'=>(string)($entry['objectClass']??'FinancialItem'),'id'=>isset($entry['id'])?(int)$entry['id']:null,'relatedIds'=>array(),'requestedFields'=>array(),'appliedFields'=>array(),'recalculatedFields'=>array(),'ignoredFields'=>array(),'rejectedFields'=>array(),'saved'=>null,'error'=>$details,'concurrencyUnchecked'=>false);if($mode==='atomic'){Sql::rollbackTransaction();$GLOBALS['mcpCaptureErrors']=false;return array('ok'=>false,'rolledBack'=>true,'transactionMode'=>$mode,'items'=>$items,'effects'=>array());}}
    finally{$GLOBALS['mcpCaptureErrors']=false;}
  }
  if($mode==='atomic')Sql::commitTransaction();$effects=array();foreach($items as $item)if(in_array($item['status']??'',array('created','updated','deleted'),true)&&!empty($item['id']))$effects[]=array('action'=>$item['status']==='deleted'?'delete':($item['status']==='created'?'create':'update'),'objectClass'=>$item['objectClass'],'id'=>(int)$item['id']);
  return array('ok'=>!count(array_filter($items,fn($item)=>($item['status']??'')==='error')),'rolledBack'=>false,'transactionMode'=>$mode,'items'=>$items,'effects'=>$effects);
}

function mcpFinancialPreview(array $arguments,string $username,string $action): array {
  $groups=array('expenses','details','documents','lines','terms','tenders','criteria','budgets','periods','moves','commands','acceptances','billings','units','phases','items');$targets=array();
  foreach($groups as $group)foreach($arguments[$group]??array() as $index=>$entry)$targets[]=array('index'=>$index,'objectClass'=>$entry['objectClass']??$entry['expenseClass']??$group,'id'=>$entry['id']??null,'operation'=>$entry['operation']??'upsert');
  return array('action'=>$action,'count'=>count($targets),'targets'=>$targets,'transactionMode'=>$arguments['transactionMode']??'atomic');
}

function mcpFinancialFacturXPreview(array $arguments,string $username,string $action): array {
  $meta=mcpReadUpload((string)$arguments['uploadId'],$username);$project=mcpFinancialTarget('Project',(int)$arguments['idProject'],'read');
  return array('action'=>$action,'fileName'=>$meta['fileName']??null,'expectedBytes'=>(int)($meta['expectedBytes']??0),'idProject'=>(int)$project->id,'effects'=>array('create or match Provider','create ProviderBill','create up to 200 BillLine records'));
}

function mcpFinancialExpenseAction(array $arguments,string $username,string $action): array {
  $fields=array('name','idProject','idResource','idActivity','idStatus','plannedAmount','realAmount','expensePlannedDate','expenseRealDate','description','externalReference','idle');
  return mcpFinancialBatch($arguments['expenses'],$arguments['transactionMode']??'atomic',function(array $entry)use($fields):array{$class=(string)$entry['objectClass'];list($object,$verb)=mcpFinancialResolve($class,$entry);return mcpFinancialSave($object,$verb,$entry,mcpFinancialFields($entry,$fields),false,array('plannedAmount','realAmount'));});
}

function mcpFinancialExpenseDetailAction(array $arguments,string $username,string $action): array {
  $fields=array('idExpenseDetailType','name','externalReference','expenseDate','amount','amountLocal','value01','value02','value03','unit01','unit02','unit03','idle');
  return mcpFinancialBatch($arguments['details'],$arguments['transactionMode']??'atomic',function(array $entry)use($fields):array{$parent=mcpFinancialTarget((string)$entry['expenseClass'],(int)$entry['idExpense'],'update');list($detail,$verb)=mcpFinancialResolve('ExpenseDetail',$entry,array(),true);$data=mcpFinancialFields($entry,$fields);$data['idExpense']=(int)$parent->id;$data['idProject']=$parent->idProject;if(property_exists($detail,'idActivity'))$data['idActivity']=$parent->idActivity??null;$data['idle']=$parent->idle??0;return mcpFinancialSave($detail,$verb,$entry,$verb==='delete'?array():$data,true,array('expense totals'));});
}

function mcpFinancialDocumentAction(array $arguments,string $username,string $action): array {
  $fields=array('name','idProject','idClient','idProvider','idContact','idResource','idStatus','date','paymentDueDate','untaxedAmount','taxPct','taxAmount','fullAmount','description','externalReference','idle');
  return mcpFinancialBatch($arguments['documents'],$arguments['transactionMode']??'atomic',function(array $entry)use($fields):array{$class=(string)$entry['objectClass'];list($object,$verb)=mcpFinancialResolve($class,$entry);$data=mcpFinancialFields($entry,$fields);if(array_key_exists('idType',$entry)){$typeField='id'.$class.'Type';if(property_exists($object,$typeField))$data[$typeField]=$entry['idType'];}return mcpFinancialSave($object,$verb,$entry,$data,false,array('untaxedAmount','taxAmount','fullAmount'));});
}

function mcpFinancialBillLineAction(array $arguments,string $username,string $action): array {
  $fields=array('line','quantity','numberDays','idTerm','idResource','idActivityPrice','idCatalog','idMeasureUnit','startDate','endDate','description','detail','price','priceLocal','extra','billingType','idle');
  return mcpFinancialBatch($arguments['lines'],$arguments['transactionMode']??'atomic',function(array $entry)use($fields):array{$parent=mcpFinancialTarget((string)$entry['refType'],(int)$entry['refId'],'update');list($line,$verb)=mcpFinancialResolve('BillLine',$entry,array(),true);$data=mcpFinancialFields($entry,$fields);$data['refType']=get_class($parent);$data['refId']=(int)$parent->id;return mcpFinancialSave($line,$verb,$entry,$verb==='delete'?array():$data,true,array('parent totals'));});
}

function mcpFinancialProviderTermAction(array $arguments,string $username,string $action): array {
  $fields=array('idProject','date','name','taxPct','untaxedAmount','taxAmount','fullAmount','untaxedAmountLocal','taxAmountLocal','fullAmountLocal','idResource','idle');
  return mcpFinancialBatch($arguments['terms'],$arguments['transactionMode']??'atomic',function(array $entry)use($fields):array{$parent=mcpFinancialTarget((string)$entry['parentType'],(int)$entry['parentId'],'update');list($term,$verb)=mcpFinancialResolve('ProviderTerm',$entry);if(!empty($entry['detachFromBill'])){if(!$term->id)mcpJsonError(400,'id_required','detachFromBill requires an existing ProviderTerm id');$verb='update';return mcpFinancialSave($term,$verb,$entry,array('idProviderBill'=>null),false,array('provider bill terms'));}$data=mcpFinancialFields($entry,$fields);$data[$entry['parentType']==='ProviderBill'?'idProviderBill':'idProviderOrder']=(int)$parent->id;if(!isset($data['idProject'])&&property_exists($parent,'idProject'))$data['idProject']=$parent->idProject;return mcpFinancialSave($term,$verb,$entry,$verb==='delete'?array():$data,false,array('parent totals'));});
}

function mcpFinancialTenderAction(array $arguments,string $username,string $action): array {
  $fields=array('name','idProject','idCallForTender','idProvider','idContact','idTenderStatus','idStatus','requestDateTime','expectedTenderDateTime','description','idle');
  return mcpFinancialBatch($arguments['tenders'],$arguments['transactionMode']??'atomic',function(array $entry)use($fields):array{$class=(string)$entry['objectClass'];list($object,$verb)=mcpFinancialResolve($class,$entry);$data=mcpFinancialFields($entry,$fields);if($class==='Tender'&&!$object->id&&!isset($data['creationDate']))$data['creationDate']=date('Y-m-d');return mcpFinancialSave($object,$verb,$entry,$data);});
}

function mcpFinancialTenderCriteriaAction(array $arguments,string $username,string $action): array {
  return mcpFinancialBatch($arguments['criteria'],$arguments['transactionMode']??'atomic',function(array $entry):array{$parent=mcpFinancialTarget('CallForTender',(int)$entry['idCallForTender'],'update');list($criterion,$verb)=mcpFinancialResolve('TenderEvaluationCriteria',$entry,array(),true);$data=mcpFinancialFields($entry,array('criteriaName','criteriaMaxValue','criteriaCoef','idle'));$data['idCallForTender']=(int)$parent->id;return mcpFinancialSave($criterion,$verb,$entry,$verb==='delete'?array():$data,true);});
}

function mcpFinancialBudgetAction(array $arguments,string $username,string $action): array {
  $fields=array('name','idBudget','idBudgetType','idBudgetOrientation','idOrganization','idProject','budgetStartDate','budgetEndDate','amount','description','idle');
  return mcpFinancialBatch($arguments['budgets'],$arguments['transactionMode']??'atomic',function(array $entry)use($fields):array{list($budget,$verb)=mcpFinancialResolve('Budget',$entry);return mcpFinancialSave($budget,$verb,$entry,mcpFinancialFields($entry,$fields),false,array('bbs','bbsSortable'));});
}

function mcpFinancialOrganizationBudgetAction(array $arguments,string $username,string $action): array {
  return mcpFinancialBatch($arguments['periods'],$arguments['transactionMode']??'atomic',function(array $entry):array{$organization=mcpFinancialTarget('Organization',(int)$entry['idOrganization'],'update');$criteria=array('refType'=>'Organization','refId'=>(int)$organization->id,'year'=>(int)$entry['year']);list($element,$verb)=mcpFinancialResolve('BudgetElement',$entry,$criteria,true);$data=array('refType'=>'Organization','refId'=>(int)$organization->id,'year'=>(int)$entry['year']);foreach(array('budgetWork','budgetCost','expenseBudgetAmount') as $field)if(array_key_exists($field,$entry))$data[$field]=$entry[$field];if(array_key_exists('closed',$entry)){$data['idle']=$entry['closed']?1:0;$data['idleDateTime']=$entry['closed']?date('Y-m-d H:i:s'):null;}if(isset($data['budgetCost'])||isset($data['expenseBudgetAmount']))$data['totalBudgetCost']=(float)($data['budgetCost']??$element->budgetCost??0)+(float)($data['expenseBudgetAmount']??$element->expenseBudgetAmount??0);$result=mcpFinancialSave($element,$verb,$entry,$verb==='delete'?array():$data,true,array('organization budget synthesis'));if($verb!=='delete'&&method_exists($organization,'updateBudgetElementSynthesis'))$organization->updateBudgetElementSynthesis(new BudgetElement($result['id']));return $result;});
}

function mcpFinancialBudgetMoveAction(array $arguments,string $username,string $action): array {
  return mcpFinancialBatch($arguments['moves'],$arguments['transactionMode']??'atomic',function(array $entry):array{if((int)$entry['id']===(int)$entry['targetId'])mcpJsonError(400,'validation_failed','A budget cannot be moved relative to itself');$source=mcpFinancialTarget('Budget',(int)$entry['id'],'update');$target=mcpFinancialTarget('Budget',(int)$entry['targetId'],'read');mcpFinancialRequireVersion($source,$entry,'update');if(!hash_equals(mcpObjectVersion($target),(string)$entry['expectedTargetVersion']))mcpJsonError(409,'version_conflict','Target budget has changed');$oldParent=(int)($source->idBudget??0);$source->idBudget=$target->idBudget;if($entry['position']==='after')$bbs=(string)$target->bbs.'.1';else{$parts=explode('.',(string)$target->bbs);$last=(int)array_pop($parts)-1;$bbs=$parts?implode('.',$parts).'.'.$last.'.1':$last.'.1';}$sortable=formatSortableWbs($bbs);$result=mcpFinancialSave($source,'update',$entry,array('idBudget'=>$source->idBudget,'bbs'=>$bbs,'bbsSortable'=>$sortable),false,array('budget hierarchy'));foreach(array_unique(array_filter(array($oldParent,(int)$target->idBudget))) as $parentId){$parent=new Budget($parentId);if(method_exists($parent,'regenerateBbsLevel'))$parent->regenerateBbsLevel();}return $result;});
}

function mcpFinancialWorkCommandAction(array $arguments,string $username,string $action): array {
  return mcpFinancialBatch($arguments['commands'],$arguments['transactionMode']??'atomic',function(array $entry):array{$command=mcpFinancialTarget('Command',(int)$entry['idCommand'],'update');list($work,$verb)=mcpFinancialResolve('WorkCommand',$entry);$data=mcpFinancialFields($entry,array('idWorkUnit','idComplexity','idWorkCommand','name','unitAmount','commandQuantity','commandAmount','idle'));$data['idCommand']=(int)$command->id;if(isset($data['idWorkCommand']))$data['elementary']=$data['idWorkCommand']?1:0;if($verb==='update'&&isset($data['commandQuantity'])&&!isset($data['commandAmount']))$data['commandAmount']=(float)$data['commandQuantity']*(float)$work->unitAmount;return mcpFinancialSave($work,$verb,$entry,$verb==='delete'?array():$data,false,array('commandAmount'));});
}

function mcpFinancialWorkAcceptedAction(array $arguments,string $username,string $action): array {
  return mcpFinancialBatch($arguments['acceptances'],$arguments['transactionMode']??'atomic',function(array $entry):array{$work=mcpFinancialTarget('WorkCommand',(int)$entry['idWorkCommand'],'update');$acceptance=mcpFinancialTarget('Acceptance',(int)$entry['idAcceptance'],'read');$criteria=array('idWorkCommand'=>(int)$work->id,'idAcceptance'=>(int)$acceptance->id);list($record,$verb)=mcpFinancialResolve('WorkCommandAccepted',$entry,$criteria,true);$data=array('idCommand'=>(int)$work->idCommand,'idWorkCommand'=>(int)$work->id,'idAcceptance'=>(int)$acceptance->id,'acceptedQuantity'=>$entry['acceptedQuantity']??0,'acceptedDate'=>$acceptance->acceptanceDate);$result=mcpFinancialSave($record,$verb,$entry,$verb==='delete'?array():$data,true,array('work command accepted totals'));if($verb==='delete'&&method_exists($work,'save'))$work->save();return $result;});
}

function mcpFinancialRecalculateBilled(WorkCommand $work): void {$total=0;$record=new WorkCommandBilled();foreach($record->getSqlElementsFromCriteria(array('idWorkCommand'=>(int)$work->id)) as $billed){$bill=new Bill((int)$billed->idBill);if($bill->done)$total+=(float)$billed->billedQuantity;}$work->billedQuantity=$total;$work->billedAmount=(float)$work->unitAmount*$total;$work->save();}

function mcpFinancialWorkBilledAction(array $arguments,string $username,string $action): array {
  return mcpFinancialBatch($arguments['billings'],$arguments['transactionMode']??'atomic',function(array $entry):array{$work=mcpFinancialTarget('WorkCommand',(int)$entry['idWorkCommand'],'update');$bill=mcpFinancialTarget('Bill',(int)$entry['idBill'],'read');$criteria=array('idWorkCommand'=>(int)$work->id,'idBill'=>(int)$bill->id);list($record,$verb)=mcpFinancialResolve('WorkCommandBilled',$entry,$criteria,true);$data=array('idBill'=>(int)$bill->id,'idCommand'=>(int)$work->idCommand,'idWorkCommand'=>(int)$work->id,'billedQuantity'=>$entry['billedQuantity']??0);$result=mcpFinancialSave($record,$verb,$entry,$verb==='delete'?array():$data,true,array('billedQuantity','billedAmount'));mcpFinancialRecalculateBilled($work);return $result;});
}

function mcpFinancialWorkUnitAction(array $arguments,string $username,string $action): array {
  return mcpFinancialBatch($arguments['units'],$arguments['transactionMode']??'atomic',function(array $entry):array{$catalog=mcpFinancialTarget('CatalogUO',(int)$entry['idCatalogUO'],'update');list($unit,$verb)=mcpFinancialResolve('WorkUnit',$entry,array(),true);$data=mcpFinancialFields($entry,array('reference','description','incoming','livrable','validityDate','idle'));$data['idCatalogUO']=(int)$catalog->id;$result=mcpFinancialSave($unit,$verb,$entry,$verb==='delete'?array():$data,true,array('complexity values'));$related=array();if($verb!=='delete')foreach($entry['complexities']??array() as $value){$criteria=array('idCatalogUO'=>(int)$catalog->id,'idComplexity'=>(int)$value['idComplexity'],'idWorkUnit'=>(int)$result['id']);$record=SqlElement::getSingleSqlElementFromCriteria('ComplexityValues',$criteria);if(!$record->id)$record=new ComplexityValues();foreach($criteria as $field=>$fieldValue)$record->$field=$fieldValue;foreach(array('charge','price','priceLocal','duration') as $field)if(array_key_exists($field,$value))$record->$field=$value[$field];$raw=$record->save();if(getLastOperationStatus($raw)!=='OK')mcpJsonError(400,'save_failed',cleanApiMessage($raw));$related[]=(int)$record->id;}$result['relatedIds']=$related;return $result;});
}

function mcpFinancialWorkUnitPhaseAction(array $arguments,string $username,string $action): array {
  return mcpFinancialBatch($arguments['phases'],$arguments['transactionMode']??'atomic',function(array $entry):array{$catalog=mcpFinancialTarget('CatalogUO',(int)$entry['idCatalogUO'],'update');list($phase,$verb)=mcpFinancialResolve('WorkUnitCatalogPhase',$entry,array(),true);$data=mcpFinancialFields($entry,array('reference','ratioPct','idle'));$data['idCatalogUO']=(int)$catalog->id;return mcpFinancialSave($phase,$verb,$entry,$verb==='delete'?array():$data,true);});
}

function mcpFinancialAbacusAction(array $arguments,string $username,string $action): array {
  $fields=array('name','idAbacusDefinition','idProject','idPhasing','className','idClassName1','idClassName2','idClassName3','idClassName4','idClassName5','quantity','capacity','assumption','example','valueField','inputField','sortOrder','comment','idle');
  return mcpFinancialBatch($arguments['items'],$arguments['transactionMode']??'atomic',function(array $entry)use($fields):array{$class=(string)$entry['objectClass'];if($class==='AbacusProject')mcpFinancialTarget('Project',(int)$entry['idProject'],'update');else{if(securityGetAccessRightYesNo('menuAdmin','update')!=='YES')mcpJsonError(403,'forbidden','Abacus definition administration requires administrative update access');if(!empty($entry['idProject']))mcpFinancialTarget('Project',(int)$entry['idProject'],'read');}list($object,$verb)=mcpFinancialResolve($class,$entry,array(),true);return mcpFinancialSave($object,$verb,$entry,$verb==='delete'?array():mcpFinancialFields($entry,$fields),true,array('abacus values'));});
}
