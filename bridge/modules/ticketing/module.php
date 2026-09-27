<?php
declare(strict_types=1);
require_once __DIR__.'/actions.php';

$id=array('type'=>'integer','minimum'=>1);$nullableId=array('type'=>array('integer','null'),'minimum'=>1);$version=array('type'=>'string','minLength'=>1,'maxLength'=>200);$nullableVersion=array('type'=>array('string','null'),'maxLength'=>200);$transaction=array('type'=>'string','enum'=>array('atomic','best_effort'));
$effect=mcpObjectSchema(array('action'=>array('type'=>'string'),'objectClass'=>array('type'=>'string'),'id'=>$id),array('action','objectClass','id'),false);
$saved=mcpObjectSchema(array('id'=>$id,'_version'=>$version),array('id','_version'),true);
$error=mcpObjectSchema(array('code'=>array('type'=>'string'),'message'=>array('type'=>'string')),array('code','message'),true);
$itemResult=mcpObjectSchema(array(
  'index'=>array('type'=>'integer','minimum'=>0),'status'=>array('type'=>'string','enum'=>array('dispatched','transitioned','escalated','synchronized','unchanged','rolled_back','error')),
  'objectClass'=>array('type'=>'string','enum'=>array('Ticket')),'id'=>$nullableId,'saved'=>$saved,
  'appliedFields'=>array('type'=>'array','items'=>array('type'=>'string')),'recalculatedFields'=>array('type'=>'array','items'=>array('type'=>'string')),
  'rejectedFields'=>array('type'=>'array','items'=>array('type'=>'string')),'ignoredFields'=>array('type'=>'array','items'=>array('type'=>'string')),
  'resolvedResourceId'=>$id,'noteId'=>$id,'synchronizedActivityId'=>$id,'error'=>$error
),array('index','status','appliedFields','recalculatedFields','rejectedFields','ignoredFields'),false);
$batchResult=mcpObjectSchema(array('ok'=>array('type'=>'boolean'),'rolledBack'=>array('type'=>'boolean'),'transactionMode'=>$transaction,'items'=>array('type'=>'array','maxItems'=>200,'items'=>$itemResult),'effects'=>array('type'=>'array','items'=>$effect)),array('ok','rolledBack','transactionMode','items','effects'),false);

$base=mcpObjectSchema(array('ticketId'=>$id,'expectedVersion'=>$version),array('ticketId','expectedVersion'),false);
$dispatch=mcpObjectSchema(array('ticketId'=>$id,'resourceId'=>$id,'teamId'=>$id,'expectedVersion'=>$version),array('ticketId','expectedVersion'),false);
$transition=mcpObjectSchema(array('ticketId'=>$id,'statusId'=>$id,'expectedVersion'=>$version),array('ticketId','statusId','expectedVersion'),false);
$escalation=mcpObjectSchema(array('ticketId'=>$id,'priorityId'=>$id,'urgencyId'=>$id,'criticalityId'=>$id,'statusId'=>$id,'resourceId'=>$id,'teamId'=>$id,'reason'=>array('type'=>'string','minLength'=>1,'maxLength'=>4000),'expectedVersion'=>$version),array('ticketId','reason','expectedVersion'),false);
$manage=mcpObjectSchema(array('ticketId'=>$id,'operation'=>array('type'=>'string','enum'=>array('dispatch','transition','escalate','synchronize')),'resourceId'=>$id,'teamId'=>$id,'statusId'=>$id,'priorityId'=>$id,'urgencyId'=>$id,'criticalityId'=>$id,'reason'=>array('type'=>'string','maxLength'=>4000),'expectedVersion'=>$version),array('ticketId','operation','expectedVersion'),false);
$batch=function(string $field,array $item) use($transaction): array{return mcpObjectSchema(array($field=>array('type'=>'array','minItems'=>1,'maxItems'=>200,'items'=>$item),'transactionMode'=>$transaction),array($field),false);};
$options=array('availability'=>'mcpTicketingActionAvailable','transaction'=>'atomic','batchLimit'=>200,'permissionClasses'=>array('Ticket'));

$slaRule=mcpObjectSchema(array('id'=>$id,'projectId'=>$nullableId,'macroStatusId'=>$id,'value'=>array('type'=>'number','minimum'=>0),'delayUnitId'=>$id,'_version'=>$version),array('id','projectId','macroStatusId','value','delayUnitId','_version'),false);
$slaItem=mcpObjectSchema(array('ticketId'=>$id,'_version'=>$version,'statusId'=>$id,'dueAt'=>array('type'=>array('string','null')),'closed'=>array('type'=>'boolean'),'breached'=>array('type'=>'boolean'),'overdueSeconds'=>array('type'=>'integer','minimum'=>0),'rules'=>array('type'=>'array','items'=>$slaRule)),array('ticketId','_version','statusId','dueAt','closed','breached','overdueSeconds','rules'),false);
$slaResult=mcpObjectSchema(array('ok'=>array('type'=>'boolean'),'evaluatedAt'=>array('type'=>'string'),'items'=>array('type'=>'array','maxItems'=>200,'items'=>$slaItem)),array('ok','evaluatedAt','items'),false);

$expectedTicket=mcpObjectSchema(array('ticketId'=>$id,'expectedVersion'=>$version),array('ticketId','expectedVersion'),false);
$expectedItem=mcpObjectSchema(array('id'=>$id,'expectedVersion'=>$version),array('id','expectedVersion'),false);
$configureSchema=mcpObjectSchema(array('projectId'=>$id,'statusId'=>$id,'ticketTypeId'=>$id,'activityTypeId'=>$id,'setActivity'=>array('type'=>'boolean'),'includeExisting'=>array('type'=>'boolean'),'expectedVersion'=>$version,'expectedTicketVersions'=>array('type'=>'array','maxItems'=>200,'items'=>$expectedTicket)),array('projectId','statusId','activityTypeId'),false);
$configureResult=mcpObjectSchema(array('ok'=>array('type'=>'boolean'),'status'=>array('type'=>'string','enum'=>array('created','updated')),'definition'=>$saved,'synchronized'=>array('type'=>'array','maxItems'=>200,'items'=>mcpObjectSchema(array('ticketId'=>$id,'activityId'=>$id),array('ticketId','activityId'),false)),'effects'=>array('type'=>'array','items'=>$effect)),array('ok','status','definition','synchronized','effects'),false);
$inspectDefinition=mcpObjectSchema(array('id'=>$id,'_version'=>$version,'statusId'=>$id,'ticketTypeId'=>$nullableId,'activityTypeId'=>$id,'setActivity'=>array('type'=>'boolean')),array('id','_version','statusId','ticketTypeId','activityTypeId','setActivity'),false);
$inspectItem=mcpObjectSchema(array('ticketId'=>$id,'ticketVersion'=>$version,'linkId'=>$nullableId,'linkVersion'=>$nullableVersion,'targetType'=>array('type'=>array('string','null')),'targetId'=>$nullableId),array('ticketId','ticketVersion','linkId','linkVersion','targetType','targetId'),false);
$inspectResult=mcpObjectSchema(array('ok'=>array('type'=>'boolean'),'projectId'=>$id,'definition'=>array('type'=>array('object','null'),'properties'=>$inspectDefinition['properties'],'required'=>$inspectDefinition['required'],'additionalProperties'=>false),'items'=>array('type'=>'array','maxItems'=>200,'items'=>$inspectItem)),array('ok','projectId','definition','items'),false);
$disableResult=mcpObjectSchema(array('ok'=>array('type'=>'boolean'),'status'=>array('type'=>'string','enum'=>array('disabled')),'projectId'=>$id,'definitionId'=>$id,'removedItemIds'=>array('type'=>'array','maxItems'=>200,'items'=>$id),'effects'=>array('type'=>'array','items'=>$effect)),array('ok','status','projectId','definitionId','removedItemIds','effects'),false);

return array('id'=>'ticketing','version'=>'4.0.0','dependencies'=>array('core','configuration','environment','products'),'actions'=>array(
  'ticketing.ticket.manage'=>mcpActionSpec($batch('operations',$manage),$batchResult,'write',false,'mcpTicketingManage',array(),'ticketing.ticket.manage',$options),
  'ticketing.dispatch'=>mcpActionSpec($batch('items',$dispatch),$batchResult,'write',false,'mcpTicketingDispatch',array(),'ticketing.dispatch',$options),
  'ticketing.transition'=>mcpActionSpec($batch('items',$transition),$batchResult,'write',false,'mcpTicketingTransition',array(),'ticketing.transition',$options),
  'ticketing.escalate'=>mcpActionSpec($batch('items',$escalation),$batchResult,'write',false,'mcpTicketingEscalate',array(),'ticketing.escalate',$options),
  'ticketing.synchronize'=>mcpActionSpec($batch('items',$base),$batchResult,'write',false,'mcpTicketingSynchronize',array(),'ticketing.synchronize',$options),
  'ticketing.sla.evaluate'=>mcpActionSpec(mcpObjectSchema(array('ticketIds'=>array('type'=>'array','minItems'=>1,'maxItems'=>200,'items'=>$id)),array('ticketIds'),false),$slaResult,'read',false,'mcpTicketingSlaEvaluate',array(),'ticketing.sla.evaluate',array_merge($options,array('transaction'=>'none'))),
  'ticketing.synchronization.inspect'=>mcpActionSpec(mcpObjectSchema(array('projectId'=>$id),array('projectId'),false),$inspectResult,'read',false,'mcpTicketingSynchronizationInspect',array(),'ticketing.synchronization.inspect',array_merge($options,array('transaction'=>'none','batchLimit'=>1))),
  'ticketing.synchronization.configure'=>mcpActionSpec($configureSchema,$configureResult,'administrative',false,'mcpTicketingSynchronizationConfigure',array('tool:saveSynchronizationDefinition'),'ticketing.synchronization.configure',array_merge($options,array('preview'=>'mcpTicketingSynchronizationConfigurePreview'))),
  'ticketing.synchronization.disable'=>mcpActionSpec(mcpObjectSchema(array('projectId'=>$id,'expectedVersion'=>$version,'unlinkItems'=>array('type'=>'boolean'),'expectedItemVersions'=>array('type'=>'array','maxItems'=>200,'items'=>$expectedItem)),array('projectId','expectedVersion'),false),$disableResult,'destructive',false,'mcpTicketingSynchronizationDisable',array('tool:saveDisableSynchronizationDefinition'),'ticketing.synchronization.disable',array_merge($options,array('preview'=>'mcpTicketingSynchronizationDisablePreview')))
));
