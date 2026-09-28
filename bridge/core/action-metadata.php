<?php
declare(strict_types=1);

function mcpPublicActionMetadata(string $actionId,array $action): array {
  foreach(array('executor','worker','availability','preview') as $internal)unset($action[$internal]);
  if(!empty($action['async']))$action['resultSchema']=mcpPublicActionResultSchema($action['resultSchema']);
  return array_merge(array('action'=>$actionId),$action,array('available'=>mcpActionAvailable($actionId)));
}
