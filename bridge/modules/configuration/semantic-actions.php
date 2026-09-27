<?php
declare(strict_types=1);

const MCP_CONFIGURATION_SECRET_PATTERN='/password|passwd|api.?key|secret|token|oauth|smtp.*(?:pass|credential)|ldap.*pass|private.?key|cookie.?hash|crypto|salt/i';
const MCP_CONFIGURATION_INFRA_PATTERN='/plugin|install|updatechannel|updatetype|database|backup|restore|container|docker|hostadmin/i';

function mcpConfigurationRejectSensitive(mixed $value,string $path='$'): void {
  if(!is_array($value))return;
  foreach($value as $key=>$entry){$field=(string)$key;if(preg_match(MCP_CONFIGURATION_SECRET_PATTERN,$field))mcpJsonError(400,'secret_field_forbidden',"Secret-valued field '$path.$field' is not accepted");mcpConfigurationRejectSensitive($entry,$path.'.'.$field);}
}
function mcpConfigurationRedact(mixed $value): mixed {
  if(!is_array($value))return $value;$redacted=array();foreach($value as $key=>$entry){if(is_string($key)&&preg_match(MCP_CONFIGURATION_SECRET_PATTERN,$key))continue;$redacted[$key]=mcpConfigurationRedact($entry);}return $redacted;
}

function mcpConfigurationSafeParameterCode(string $code): string {
  $code=trim($code);
  if(!preg_match('/^[A-Za-z][A-Za-z0-9_.:-]{0,119}$/D',$code))mcpJsonError(400,'invalid_parameter_code','Parameter code must be a safe identifier');
  if(preg_match(MCP_CONFIGURATION_SECRET_PATTERN,$code))mcpJsonError(400,'secret_parameter_forbidden','Secret-valued parameters cannot be changed');
  if(preg_match(MCP_CONFIGURATION_INFRA_PATTERN,$code))mcpJsonError(400,'infrastructure_parameter_forbidden','Installation, update, database, backup, container, and host parameters are excluded');
  return $code;
}
function mcpConfigurationVersion(array $request,string $label): string {$version=(string)($request['expectedVersion']??'');if($version==='')mcpJsonError(409,'expected_version_required',"$label requires expectedVersion");return $version;}
function mcpConfigurationBool(mixed $value): int {return $value?1:0;}
function mcpConfigurationExecute(array $operations,array $arguments): array {
  mcpConfigurationRejectSensitive($arguments);$result=mcpExecuteOperationsArray($operations,(string)($arguments['transactionMode']??'atomic'),true);$effects=array();
  foreach($result['items']??array() as $item)if(in_array($item['status']??'',array('created','updated','existing','deleted'),true))$effects[]=array('action'=>$item['status']==='deleted'?'delete':($item['status']==='created'?'create':'update'),'objectClass'=>$item['objectClass']??null,'id'=>$item['id']??null);
  $result['effects']=$effects;return mcpConfigurationRedact($result);
}
function mcpConfigurationExisting(string $class,array $criteria): ?object {$object=SqlElement::getSingleSqlElementFromCriteria($class,$criteria);return $object&&$object->id?$object:null;}
function mcpConfigurationUpsert(string $class,array $criteria,array $data,array $request,string $key): array {
  $existing=mcpConfigurationExisting($class,$criteria);
  if($existing)return array('action'=>'update','objectClass'=>$class,'id'=>(int)$existing->id,'expectedVersion'=>mcpConfigurationVersion($request,$class.' update'),'data'=>$data,'localKey'=>$key);
  if(!empty($request['expectedVersion']))mcpJsonError(409,'version_conflict',"$class does not exist for the supplied expectedVersion");
  return array('action'=>'create','objectClass'=>$class,'data'=>array_merge($criteria,$data),'localKey'=>$key);
}
function mcpConfigurationFields(array $data,array $allowed,string $class): array {
  foreach(array_keys($data) as $field)if(!in_array($field,$allowed,true))mcpJsonError(400,'configuration_field_forbidden',"$class field '$field' is not writable through MCP");return $data;
}

function mcpConfigurationManageUsers(array $arguments,string $username,string $action): array {
  $native=array();$allowed=array('name','resourceName','initials','email','idProfile','idClient','idLanguage','locked','loginTry','isContact','isEmployee','isResource','startDate','idRole','idCalendarDefinition','idle','description','dontReceiveTeamMails','idTeam','idOrganization');
  foreach($arguments['operations']??array() as $index=>$request){$operation=(string)$request['operation'];$id=(int)($request['idUser']??0);$data=mcpConfigurationFields($request['data']??array(),$allowed,'User');
    if($operation==='create'){foreach(array('name','email','idProfile') as $required)if(!array_key_exists($required,$data))mcpJsonError(400,'missing_field',"User create requires data.$required");$data['locked']=1;$native[]=array('action'=>'create','objectClass'=>'User','data'=>$data,'localKey'=>'user:'.$index);continue;}
    if($id<1)mcpJsonError(400,'id_required',"User $operation requires idUser");$version=mcpConfigurationVersion($request,'User update');
    if($operation==='lock')$data=array('locked'=>1);elseif($operation==='unlock')$data=array('locked'=>0,'loginTry'=>0);elseif($operation==='assign_profile')$data=array('idProfile'=>(int)($request['idProfile']??0));elseif($operation==='retire')$data=array('idle'=>1,'locked'=>1);elseif($operation==='reactivate')$data=array('idle'=>0);elseif($operation!=='update')mcpJsonError(400,'invalid_user_operation',"Unsupported user operation '$operation'");
    if($operation==='assign_profile'&&$data['idProfile']<1)mcpJsonError(400,'profile_required','assign_profile requires idProfile');$native[]=array('action'=>'update','objectClass'=>'User','id'=>$id,'expectedVersion'=>$version,'data'=>$data,'localKey'=>'user:'.$index);
  }return mcpConfigurationExecute($native,$arguments);
}
function mcpConfigurationManageProfiles(array $arguments,string $username,string $action): array {
  $native=array();foreach($arguments['operations']??array() as $index=>$request){$class=($request['profileType']??'profile')==='access_profile'?'AccessProfile':'Profile';$allowed=$class==='Profile'?array('name','profileCode','sortOrder','idle','description'):array('name','idAccessScopeRead','idAccessScopeCreate','idAccessScopeUpdate','idAccessScopeDelete','sortOrder','idle','description','isNonProject');$operation=(string)$request['operation'];$id=(int)($request['id']??0);$data=mcpConfigurationFields($request['data']??array(),$allowed,$class);
    if($operation==='create')$native[]=array('action'=>'create','objectClass'=>$class,'data'=>$data,'localKey'=>'profile:'.$index);else{if($id<1)mcpJsonError(400,'id_required',"$class $operation requires id");if($operation==='retire')$data=array('idle'=>1);elseif($operation==='reactivate')$data=array('idle'=>0);elseif($operation!=='update')mcpJsonError(400,'invalid_profile_operation',"Unsupported profile operation '$operation'");$native[]=array('action'=>'update','objectClass'=>$class,'id'=>$id,'expectedVersion'=>mcpConfigurationVersion($request,$class.' update'),'data'=>$data,'localKey'=>'profile:'.$index);}
  }return mcpConfigurationExecute($native,$arguments);
}
function mcpConfigurationManageAccess(array $arguments,string $username,string $action): array {
  $native=array();foreach($arguments['operations']??array() as $index=>$request){$operation=(string)$request['operation'];$profile=(int)$request['idProfile'];$target=(int)($request['targetId']??0);$value=$request['value'];
    if($operation==='menu_access'){$class='AccessRight';$criteria=array('idProfile'=>$profile,'idMenu'=>$target);$data=array('idAccessProfile'=>(int)$value);}elseif($operation==='menu_visibility'){$class='Habilitation';$criteria=array('idProfile'=>$profile,'idMenu'=>$target);$data=array('allowAccess'=>mcpConfigurationBool($value));}elseif($operation==='report_access'){$class='HabilitationReport';$criteria=array('idProfile'=>$profile,'idReport'=>$target);$data=array('allowAccess'=>mcpConfigurationBool($value));}elseif($operation==='other_right'){$class='HabilitationOther';$scope=(string)($request['scope']??'');if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,79}$/D',$scope))mcpJsonError(400,'invalid_scope','other_right requires a safe scope');$criteria=array('idProfile'=>$profile,'scope'=>$scope);$data=array('rightAccess'=>(string)$value);}else mcpJsonError(400,'invalid_access_operation',"Unsupported access operation '$operation'");
    if($profile<1||($operation!=='other_right'&&$target<1))mcpJsonError(400,'invalid_reference','Access operations require positive profile and target IDs');$native[]=mcpConfigurationUpsert($class,$criteria,$data,$request,'access:'.$index);
  }return mcpConfigurationExecute($native,$arguments);
}
function mcpConfigurationManageWorkflows(array $arguments,string $username,string $action): array {
  $native=array();foreach($arguments['operations']??array() as $index=>$request){$operation=(string)$request['operation'];
    if(in_array($operation,array('create','update','retire','reactivate'),true)){$data=mcpConfigurationFields($request['data']??array(),array('name','sortOrder','idle','workflowUpdate','description','isLeaveWorkflow'),'Workflow');if($operation==='create')$native[]=array('action'=>'create','objectClass'=>'Workflow','data'=>$data,'localKey'=>'workflow:'.$index);else{$id=(int)($request['idWorkflow']??0);if($id<1)mcpJsonError(400,'id_required',"Workflow $operation requires idWorkflow");if($operation==='retire')$data=array('idle'=>1);if($operation==='reactivate')$data=array('idle'=>0);$native[]=array('action'=>'update','objectClass'=>'Workflow','id'=>$id,'expectedVersion'=>mcpConfigurationVersion($request,'Workflow update'),'data'=>$data,'localKey'=>'workflow:'.$index);}continue;}
    $workflow=(int)($request['idWorkflow']??0);$profile=(int)($request['idProfile']??0);if($workflow<1||$profile<1)mcpJsonError(400,'invalid_reference',"Workflow $operation requires positive workflow and profile IDs");
    if($operation==='set_profile'){$class='WorkflowProfile';$criteria=array('idWorkflow'=>$workflow,'idProfile'=>$profile);$data=array('checked'=>mcpConfigurationBool($request['value']));}elseif($operation==='set_transition'){$from=(int)($request['idStatusFrom']??0);$to=(int)($request['idStatusTo']??0);if($from<1||$to<1)mcpJsonError(400,'invalid_reference','set_transition requires positive status IDs');$class='WorkflowStatus';$criteria=array('idWorkflow'=>$workflow,'idProfile'=>$profile,'idStatusFrom'=>$from,'idStatusTo'=>$to);$data=array('allowed'=>mcpConfigurationBool($request['value']));}else mcpJsonError(400,'invalid_workflow_operation',"Unsupported workflow operation '$operation'");$native[]=mcpConfigurationUpsert($class,$criteria,$data,$request,'workflow:'.$index);
  }return mcpConfigurationExecute($native,$arguments);
}
function mcpConfigurationSetModules(array $arguments,string $username,string $action): array {$native=array();foreach($arguments['modules']??array() as $index=>$request){$id=(int)$request['idModule'];if($id<1)mcpJsonError(400,'invalid_module','idModule must be positive');$native[]=array('action'=>'update','objectClass'=>'Module','id'=>$id,'expectedVersion'=>mcpConfigurationVersion($request,'Module update'),'data'=>array('active'=>mcpConfigurationBool($request['active'])),'localKey'=>'module:'.$index);}return mcpConfigurationExecute($native,$arguments);}
function mcpConfigurationSetParameters(array $arguments,string $username,string $action): array {
  $native=array();$actor=(int)getSessionUser()->id;foreach($arguments['parameters']??array() as $index=>$request){$code=mcpConfigurationSafeParameterCode((string)$request['code']);$scope=(string)($request['scope']??'user');$criteria=array('parameterCode'=>$code,'idUser'=>null,'idProject'=>null);if($scope==='user')$criteria['idUser']=$actor;elseif($scope==='project'){$criteria['idProject']=(int)($request['idProject']??0);if($criteria['idProject']<1)mcpJsonError(400,'project_required','Project parameters require idProject');}elseif($scope!=='global')mcpJsonError(400,'invalid_parameter_scope','Parameter scope must be user, project, or global');$native[]=mcpConfigurationUpsert('Parameter',$criteria,array('parameterValue'=>(string)$request['value']),$request,'parameter:'.$index);}return mcpConfigurationExecute($native,$arguments);
}
function mcpConfigurationManageViews(array $arguments,string $username,string $action): array {
  $native=array();$actor=(int)getSessionUser()->id;foreach($arguments['operations']??array() as $index=>$request){$operation=(string)$request['operation'];$entity=(string)$request['entity'];$id=(int)($request['id']??0);$data=$request['data']??array();
    if($entity==='filter'){$class='Filter';$allowed=array('name','refType','isShared','isDynamic','isCommon','isFavoriteProject','sortOrder','idLayout');$data['idUser']=$actor;}elseif($entity==='layout'){$class='Layout';$allowed=array('scope','objectClass','isShared','isDefault','sortOrder','comment');$data['idUser']=$actor;}elseif($entity==='layout_column'){$class='LayoutColumnSelector';$allowed=array('idLayout','scope','objectClass','field','attribute','hidden','sortOrder','widthPct','name','subItem','formatter','isReportList');$data['idUser']=$actor;}elseif($entity==='forced_layout'){$class='LayoutForced';$allowed=array('idUser','idLayout','objectClass');$data['idCreator']=$actor;}else mcpJsonError(400,'invalid_view_entity',"Unsupported view entity '$entity'");
    $data=mcpConfigurationFields($data,array_merge($allowed,array('idUser','idCreator')),$class);if($class==='Filter'&&isset($data['refType']))Security::checkValidClass((string)$data['refType']);if(in_array($class,array('Layout','LayoutColumnSelector','LayoutForced'),true)&&isset($data['objectClass']))Security::checkValidClass((string)$data['objectClass']);
    if($operation==='create')$native[]=array('action'=>'create','objectClass'=>$class,'data'=>$data,'localKey'=>'view:'.$index);elseif($operation==='update')$native[]=array('action'=>'update','objectClass'=>$class,'id'=>$id,'expectedVersion'=>mcpConfigurationVersion($request,$class.' update'),'data'=>$data,'localKey'=>'view:'.$index);elseif($operation==='delete')$native[]=array('action'=>'delete','objectClass'=>$class,'id'=>$id,'expectedVersion'=>mcpConfigurationVersion($request,$class.' delete'),'data'=>array(),'localKey'=>'view:'.$index);else mcpJsonError(400,'invalid_view_operation',"Unsupported view operation '$operation'");
  }return mcpConfigurationExecute($native,$arguments);
}
