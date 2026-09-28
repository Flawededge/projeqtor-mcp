<?php
declare(strict_types=1);

function mcpHandleListActions(array $input): never {
  $module=(string)($input['module']??'');$domain=(string)($input['domain']??'');$risk=(string)($input['risk']??'');$search=mb_strtolower((string)($input['search']??''));$availableOnly=(bool)($input['availableOnly']??true);
  $pageSize=max(1,min(200,(int)($input['pageSize']??100)));
  $aliases=array('planning_followup_environment'=>array('planning','follow_up','environment'),'ticketing_scrum'=>array('ticketing','scrum'),'steering_reports'=>array('steering','reports'),'financial_products'=>array('financial','products'),'hr_tools_configuration'=>array('hr','tools','configuration'));
  $modules=$module?($aliases[$module]??array($module)):array();$fingerprint=hash('sha256',json_encode(array($modules,$domain,$risk,$search,$availableOnly)));$cursor=mcpSignedCursorDecode($input['cursor']??null);
  if($cursor&&(($cursor['kind']??'')!=='actions'||($cursor['fingerprint']??'')!==$fingerprint))mcpJsonError(400,'cursor_query_mismatch','Cursor does not belong to this action query');
  $after=(string)($cursor['after']??'');$filtered=array();
  foreach(mcpActionRegistry() as $id=>$action){
    if($modules&&!in_array($action['module'],$modules,true))continue;
    if($domain&&($action['domain']??'')!==$domain)continue;
    if($risk&&$action['risk']!==$risk)continue;
    if($search&&!str_contains(mb_strtolower($id.' '.implode(' ',$action['mappedHandlers'])),$search))continue;
    $available=mcpActionAvailable($id);if($availableOnly&&!$available)continue;
    $resultSchema=!empty($action['async'])?mcpPublicActionResultSchema($action['resultSchema']):$action['resultSchema'];
    $filtered[$id]=array('action'=>$id,'domain'=>$action['domain']??$action['module'],'module'=>$action['module'],'actionVersion'=>$action['actionVersion'],'risk'=>$action['risk'],'sideEffectClassification'=>$action['sideEffectClassification'],'async'=>$action['async'],'available'=>$available,'requiresConfirmation'=>in_array($action['risk'],array('destructive','administrative','external'),true),'mappedHandlers'=>$action['mappedHandlers'],'resultSchema'=>$resultSchema,'retryPolicy'=>$action['retryPolicy'],'maxAttempts'=>$action['maxAttempts'],'idempotency'=>mcpPublicActionIdempotency($action),'transaction'=>$action['transaction'],'testContract'=>$action['testContract']);
  }
  ksort($filtered,SORT_STRING);$total=count($filtered);$items=array();
  foreach($filtered as $id=>$item){if($after&&strcmp($id,$after)<=0)continue;$items[]=$item;if(count($items)>$pageSize)break;}
  $hasMore=count($items)>$pageSize;if($hasMore)array_pop($items);$next=$hasMore?mcpSignedCursorEncode(array('kind'=>'actions','fingerprint'=>$fingerprint,'after'=>end($items)['action'])):null;
  mcpJsonResponse(array('returned'=>count($items),'total'=>!empty($input['includeTotal'])?$total:null,'hasMore'=>$hasMore,'nextCursor'=>$next,'items'=>$items));
}
