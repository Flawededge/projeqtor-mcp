<?php
declare(strict_types=1);

function mcpPublicActionMetadata(string $actionId,array $action): array {
  foreach(array('executor','worker','availability','preview') as $internal)unset($action[$internal]);
  return array_merge(array('action'=>$actionId),$action,array('available'=>mcpActionAvailable($actionId)));
}
