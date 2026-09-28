<?php
declare(strict_types=1);

function mcpPublicActionIdempotency(array $action): array {
  if(in_array($action['risk']??'',array('destructive','administrative','external'),true))return array(
    'supported'=>false,'scope'=>null,'sameBodyReturnsOriginal'=>false,'conflictOnDifferentBody'=>false,
    'reason'=>'guarded_confirmation_token_only'
  );
  return $action['idempotency']??array('supported'=>false,'scope'=>null,'sameBodyReturnsOriginal'=>false,'conflictOnDifferentBody'=>false);
}

function mcpPublicActionMetadata(string $actionId,array $action): array {
  foreach(array('executor','worker','availability','preview') as $internal)unset($action[$internal]);
  if(!empty($action['async']))$action['resultSchema']=mcpPublicActionResultSchema($action['resultSchema']);
  $action['idempotency']=mcpPublicActionIdempotency($action);
  return array_merge(array('action'=>$actionId),$action,array('available'=>mcpActionAvailable($actionId)));
}
