<?php
declare(strict_types=1);

function mcpValidateModuleGraph(array $catalog): void {
  $state=array();$visit=function(string $id,array $trail) use (&$visit,&$state,$catalog): void {
    if(($state[$id]??0)===2)return;
    if(($state[$id]??0)===1)throw new RuntimeException('MCP module dependency cycle: '.implode(' -> ',array_merge($trail,array($id))));
    $state[$id]=1;
    foreach($catalog[$id]['dependencies']??array() as $dependency)$visit((string)$dependency,array_merge($trail,array($id)));
    $state[$id]=2;
  };
  foreach(array_keys($catalog) as $id)$visit((string)$id,array());
}
