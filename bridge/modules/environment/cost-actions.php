<?php
declare(strict_types=1);

function mcpEnvironmentRoleDefaultCostAction(array $arguments,string $username,string $action): array {
  $role=new Role((int)$arguments['idRole']);
  if (!$role->id||!Security::checkValidAccessForUser($role,'read',null,null,false)) mcpJsonError(403,'forbidden','Role cost defaults are unavailable');
  $mode=(string)$arguments['mode'];
  if ($mode==='from_date'&&empty($arguments['startDate'])) mcpJsonError(400,'missing_field','startDate is required for from_date mode',array('field'=>'startDate'));
  $operations=array();
  foreach ($arguments['resources'] as $resourceInput) {
    $resource=new Resource((int)$resourceInput['idResource']);
    if (!$resource->id||!Security::checkValidAccessForUser($resource,'read',null,null,false)) mcpJsonError(403,'forbidden','Resource is unavailable',array('idResource'=>$resourceInput['idResource']));
    $cost=(float)($resource->subcontractor?$role->defaultExternalCost:$role->defaultCost);
    if ($cost==0.0) continue;
    $current=SqlElement::getSingleSqlElementFromCriteria('ResourceCost',array('idRole'=>(int)$role->id,'idResource'=>(int)$resource->id,'idle'=>'0','endDate'=>null));
    if ($mode==='from_date') {
      $operations[]=array('action'=>'create','objectClass'=>'ResourceCost','data'=>array(
        'idResource'=>(int)$resource->id,'idRole'=>(int)$role->id,'cost'=>$cost,'startDate'=>(string)$arguments['startDate']
      ));
      continue;
    }
    if (!$current->id) mcpJsonError(404,'environment_target_not_found','Current ResourceCost was not found',array('idResource'=>(int)$resource->id,'idRole'=>(int)$role->id));
    $operations[]=array('action'=>'update','objectClass'=>'ResourceCost','id'=>(int)$current->id,
      'expectedVersion'=>$resourceInput['expectedVersion']??mcpObjectVersion($current),'data'=>array('startDate'=>null,'cost'=>$cost));
    if ($mode==='replace_current_and_assignments') {
      $assignment=new Assignment();
      foreach ($assignment->getSqlElementsFromCriteria(array('idRole'=>(int)$role->id,'idResource'=>(int)$resource->id),false,null,'id asc') as $entry) {
        $operations[]=array('action'=>'update','objectClass'=>'Assignment','id'=>(int)$entry->id,'expectedVersion'=>mcpObjectVersion($entry),'data'=>array('dailyCost'=>$cost));
      }
    }
  }
  if (!$operations) mcpJsonError(400,'no_applicable_resource_costs','No non-zero role defaults apply to the selected resources');
  return mcpEnvironmentExecuteBatch($operations,$arguments);
}
