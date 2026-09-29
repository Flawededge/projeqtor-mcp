<?php
declare(strict_types=1);

function mcpResolveReferences(mixed $value, array $localIds): mixed {
  if (!is_array($value)) return $value;
  if (count($value) === 1 && array_key_exists('$ref', $value)) {
    $key = (string)$value['$ref'];
    if (!array_key_exists($key, $localIds)) mcpJsonError(400, 'unresolved_local_reference', "Local reference '$key' is unavailable");
    return $localIds[$key];
  }
  foreach ($value as $key=>$entry) $value[$key] = mcpResolveReferences($entry, $localIds);
  return $value;
}

function mcpFillObject(object $object, array $data): array {
  $applied = array();
  foreach ($data as $field=>$value) {
    mcpValidateField($object, (string)$field, true);
    $owner=mcpFieldOwner($object,(string)$field);
    $owner->$field=$value;
    $applied[] = (string)$field;
  }
  return $applied;
}

function mcpValuesEquivalent(mixed $requested,mixed $saved): bool {
  if($requested===null||$saved===null)return $requested===$saved;
  if(is_bool($requested))return (int)$requested===(int)$saved;
  if(is_numeric($requested)&&is_numeric($saved))return abs((float)$requested-(float)$saved)<0.000001;
  if(is_array($requested)||is_object($requested)||is_array($saved)||is_object($saved))return json_encode($requested)===json_encode($saved);
  return (string)$requested===(string)$saved;
}

function mcpOperationResult(string $status, string $class, object $object, array $requested, array $extra=array()): array {
  $saved = $object->id ? new $class($object->id) : $object;
  return array_merge(array(
    'status'=>$status,
    'objectClass'=>$class,
    'id'=>$saved->id ? (int)$saved->id : null,
    'requestedFields'=>array_keys($requested),
    'appliedFields'=>array_values(array_filter(array_keys($requested), fn($field)=>mcpFieldValue($saved,$field)[0] && mcpValuesEquivalent($requested[$field],mcpFieldValue($saved,$field)[1]))),
    'recalculatedFields'=>array_values(array_filter(array_keys($requested), fn($field)=>mcpFieldValue($saved,$field)[0] && !mcpValuesEquivalent($requested[$field],mcpFieldValue($saved,$field)[1]))),
    'ignoredFields'=>array_values(array_filter(array_keys($requested), fn($field)=>!mcpFieldValue($saved,$field)[0])),
    'rejectedFields'=>array(),
    'saved'=>mcpObjectArray($saved)
  ), $extra);
}

function mcpCheckOperation(array $operation, bool $forExecution=false, bool $allowGuarded=false): array {
  mcpRequireKeys($operation, array('action','objectClass'));
  $action = (string)$operation['action'];
  $class = (string)$operation['objectClass'];
  if (!in_array($action, array('create','update','delete'), true)) mcpJsonError(400, 'invalid_operation', "Unsupported operation '$action'");
  $policy = mcpRequireClassOperation($class, $action);
  if ($policy['guarded'] && !$allowGuarded && $forExecution) mcpJsonError(409, 'guarded_change_required', "Changes to '$class' require prepare_change and commit_change");
  $id = isset($operation['id']) ? (int)$operation['id'] : null;
  if (($action === 'update' || $action === 'delete') && (!$id || $id < 1)) mcpJsonError(400, 'id_required', "$action requires a positive id");
  if ($action === 'create' && $id) mcpJsonError(400, 'id_not_allowed', 'Create operations must not include id');
  $object = $id ? new $class($id) : new $class();
  if ($id && !$object->id) mcpJsonError(404, 'not_found', "$class #$id was not found");
  if (!Security::checkValidAccessForUser($object, $action, null, null, false)) mcpJsonError(403, 'forbidden', "$action access is denied for '$class'");
  if ($id && !empty($operation['expectedVersion'])) {
    $actual = mcpObjectVersion($object);
    if (!hash_equals($actual, (string)$operation['expectedVersion'])) mcpJsonError(409, 'version_conflict', "$class #$id has changed", array('expectedVersion'=>$operation['expectedVersion'],'actualVersion'=>$actual));
  }
  $data = isset($operation['data']) && is_array($operation['data']) ? $operation['data'] : array();
  if ($action !== 'delete') mcpFillObject($object, $data);
  $control = method_exists($object, 'control') ? cleanApiMessage($object->control()) : 'OK';
  $valid = ($control === '' || strtoupper($control) === 'OK');
  return array('valid'=>$valid,'message'=>$valid?null:$control,'policy'=>$policy,'object'=>$object,'data'=>$data);
}

function mcpApplyOperation(array $operation, bool $allowGuarded=false): array {
  $check = mcpCheckOperation($operation, true, $allowGuarded);
  if (!$check['valid']) return array('status'=>'invalid','objectClass'=>$operation['objectClass'],'id'=>$operation['id']??null,'error'=>array('code'=>'validation_failed','message'=>$check['message']));
  $object = $check['object'];
  $class = (string)$operation['objectClass'];
  $action = (string)$operation['action'];
  if ($action === 'delete') {
    SqlElement::setDeleteConfirmed();
    $raw = $object->delete();
    $status = getLastOperationStatus($raw);
    if ($status !== 'OK') return array('status'=>'error','objectClass'=>$class,'id'=>(int)$object->id,'error'=>array('code'=>'delete_failed','message'=>cleanApiMessage($raw)));
    return array('status'=>'deleted','objectClass'=>$class,'id'=>(int)($operation['id']??0));
  }
  if ($action === 'create' && !empty($operation['idempotencyKey']) && property_exists($object, 'externalReference')) {
    $criteria = array('externalReference'=>(string)$operation['idempotencyKey']);
    if (isset($check['data']['idProject'])) $criteria['idProject'] = $check['data']['idProject'];
    $existing = SqlElement::getSingleSqlElementFromCriteria($class, $criteria);
    if ($existing->id) return mcpOperationResult('existing',$class,$existing,$check['data'],array('idempotencyKey'=>$operation['idempotencyKey']));
    $object->externalReference = (string)$operation['idempotencyKey'];
    $check['data']['externalReference'] = (string)$operation['idempotencyKey'];
  }
  $raw = $class === 'Work' && method_exists($object,'saveWork') ? $object->saveWork() : $object->save();
  $status = getLastOperationStatus($raw);
  if ($status !== 'OK') return array('status'=>'error','objectClass'=>$class,'id'=>$object->id?:null,'error'=>array('code'=>'save_failed','message'=>cleanApiMessage($raw)));
  return mcpOperationResult($action === 'create'?'created':'updated',$class,$object,$check['data'],array('concurrencyUnchecked'=>$action==='update' && empty($operation['expectedVersion'])));
}

function mcpHandleValidateOperations(array $input): never {
  $operations = $input['operations'] ?? null;
  if (!is_array($operations) || count($operations) < 1 || count($operations) > MCP_V2_BATCH_MAX) mcpJsonError(400,'invalid_batch','operations must contain 1 to 200 items');
  $localIds = array();
  $results = array();
  foreach ($operations as $index=>$operation) {
    $GLOBALS['mcpCaptureErrors']=true;
    try {
      if (!is_array($operation)) mcpJsonError(400,'invalid_operation',"Operation $index must be an object");
      $operation['data'] = mcpResolveReferences($operation['data']??array(), $localIds);
      $check = mcpCheckOperation($operation, false, true);$virtualId = -($index+1);
      if (!empty($operation['localKey'])) $localIds[(string)$operation['localKey']] = $operation['id']??$virtualId;
      $results[] = array('index'=>$index,'localKey'=>$operation['localKey']??null,'objectClass'=>$operation['objectClass'],'action'=>$operation['action'],'valid'=>$check['valid'],'message'=>$check['message'],'virtualId'=>$operation['action']==='create'?$virtualId:null,'policy'=>$check['policy']);
    } catch(Throwable $error) {
      $details=$error instanceof McpBridgeException?array_merge(array('code'=>$error->errorCode,'message'=>$error->getMessage()),$error->details):array('code'=>'operation_failed','message'=>cleanApiMessage($error->getMessage()));
      $results[]=array('index'=>$index,'localKey'=>$operation['localKey']??null,'objectClass'=>$operation['objectClass']??null,'action'=>$operation['action']??null,'valid'=>false,'message'=>$details['message'],'error'=>$details);
    } finally { $GLOBALS['mcpCaptureErrors']=false; }
  }
  mcpJsonResponse(array('ok'=>!in_array(false,array_column($results,'valid'),true),'validationOnly'=>true,'items'=>$results));
}

function mcpExecuteOperationsArray(array $operations, string $mode='atomic', bool $allowGuarded=false): array {
  if (count($operations) < 1 || count($operations) > MCP_V2_BATCH_MAX) mcpJsonError(400,'invalid_batch','operations must contain 1 to 200 items');
  if (!in_array($mode,array('atomic','best_effort'),true)) mcpJsonError(400,'invalid_transaction_mode','transactionMode must be atomic or best_effort');
  $localIds = array();
  $results = array();
  if ($mode === 'atomic') Sql::beginTransaction();
  foreach ($operations as $index=>$operation) {
    if ($mode === 'best_effort') Sql::beginTransaction();
    $GLOBALS['mcpCaptureErrors']=true;
    try {
      $operation['data'] = mcpResolveReferences($operation['data']??array(), $localIds);
      $result = mcpApplyOperation($operation, $allowGuarded);
      $result['index'] = $index;
      $result['localKey'] = $operation['localKey']??null;
      $failed = in_array($result['status'],array('invalid','error'),true);
      if (!$failed && !empty($operation['localKey']) && !empty($result['id'])) $localIds[(string)$operation['localKey']] = (int)$result['id'];
      if ($mode === 'best_effort') $failed ? Sql::rollbackTransaction() : Sql::commitTransaction();
      $results[] = $result;
      if ($failed && $mode === 'atomic') {
        Sql::rollbackTransaction();
        return array('ok'=>false,'rolledBack'=>true,'transactionMode'=>$mode,'items'=>$results);
      }
    } catch (Throwable $error) {
      if ($mode === 'best_effort') Sql::rollbackTransaction();
      $details=$error instanceof McpBridgeException?array_merge(array('code'=>$error->errorCode,'message'=>$error->getMessage()),$error->details):array('code'=>'operation_failed','message'=>cleanApiMessage($error->getMessage()));
      $results[] = array('index'=>$index,'localKey'=>$operation['localKey']??null,'objectClass'=>$operation['objectClass']??null,'status'=>'error','error'=>$details);
      if ($mode === 'atomic') {
        Sql::rollbackTransaction();
        return array('ok'=>false,'rolledBack'=>true,'transactionMode'=>$mode,'items'=>$results);
      }
    } finally {
      $GLOBALS['mcpCaptureErrors']=false;
    }
  }
  if ($mode === 'atomic') Sql::commitTransaction();
  return array('ok'=>!count(array_filter($results,fn($item)=>in_array($item['status'],array('invalid','error'),true))),'rolledBack'=>false,'transactionMode'=>$mode,'items'=>$results);
}

function mcpHandleExecuteOperations(array $input,string $username): never {
  @set_time_limit(300);
  $operations = $input['operations'] ?? null;
  if (!is_array($operations) || count($operations) < 1 || count($operations) > MCP_V2_BATCH_MAX) mcpJsonError(400,'invalid_batch','operations must contain 1 to 200 items');
  foreach ($operations as $operation) if (($operation['action']??'') === 'delete') mcpJsonError(409,'guarded_change_required','Deletes require prepare_change and commit_change');
  $idempotencyKey=isset($input['requestIdempotencyKey'])?(string)$input['requestIdempotencyKey']:null;$operationId=null;
  if($idempotencyKey!==null){
    if(!preg_match('/^[A-Za-z0-9_.:-]{1,255}$/D',$idempotencyKey))mcpJsonError(400,'invalid_idempotency_key','requestIdempotencyKey must contain 1 to 255 safe characters');
    $requestHash=mcpRequestHash(array('transactionMode'=>$input['transactionMode']??'atomic','importRunId'=>$input['importRunId']??null,'operations'=>$operations));mcpEnsureOperationTable();
    $query=Sql::query('SELECT * FROM mcpoperation WHERE username='.Sql::str($username).' AND idempotency_key='.Sql::str($idempotencyKey));$existing=Sql::fetchLine($query);
    if($existing){if(!hash_equals((string)$existing['request_hash'],$requestHash))mcpJsonError(409,'idempotency_key_conflict','The idempotency key was already used with different arguments');$saved=json_decode((string)$existing['result_json'],true);if(!is_array($saved))$saved=array('ok'=>false,'status'=>$existing['status']);$saved['idempotencyReplay']=true;$saved['operationId']=(int)$existing['id'];mcpJsonResponse($saved);}
    $operationId=mcpOperationInsert($username,'operations.batch','running',array('operationCount'=>count($operations),'transactionMode'=>$input['transactionMode']??'atomic'),null,null,$idempotencyKey,$requestHash,'never',1);
  }
  $result=mcpExecuteOperationsArray($operations,(string)($input['transactionMode']??'atomic'),false);
  if(!empty($input['importRunId'])&&!$result['rolledBack'])$result['importJournal']=mcpRecordImportRun((string)getSessionUser()->name,(string)$input['importRunId'],$result);
  if($operationId){$result['operationId']=$operationId;Sql::query('UPDATE mcpoperation SET status='.Sql::str($result['ok']?'succeeded':'failed').',progress=100,result_json='.Sql::str(json_encode($result)).',completed_at=CURRENT_TIMESTAMP,updated_at=CURRENT_TIMESTAMP WHERE id='.Sql::fmtId($operationId));}
  mcpJsonResponse($result);
}

function mcpEnsureOperationTable(): void {
  static $ready = false;
  if ($ready) return;
  $tableResult=Sql::query("SELECT to_regclass('mcpoperation') AS table_name");$tableRow=Sql::fetchLine($tableResult);
  if(!$tableRow||empty($tableRow['table_name']))Sql::query("CREATE TABLE mcpoperation (
    id bigserial PRIMARY KEY,
    username varchar(100) NOT NULL,
    operation_type varchar(120) NOT NULL,
    status varchar(40) NOT NULL,
    payload text,
    result_json text,
    result_path text,
    progress integer NOT NULL DEFAULT 0,
    cancel_requested integer NOT NULL DEFAULT 0,
    nonce varchar(80) UNIQUE,
    request_hash varchar(64),
    idempotency_key varchar(255),
    attempts integer NOT NULL DEFAULT 0,
    max_attempts integer NOT NULL DEFAULT 1,
    retry_policy varchar(30) NOT NULL DEFAULT 'never',
    lease_owner varchar(120),
    lease_expires_at timestamp NULL,
    heartbeat_at timestamp NULL,
    error_code varchar(100),
    recovery_state varchar(40),
    module_id varchar(60),
    action_version varchar(30),
    effects_json text,
    created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    started_at timestamp NULL,
    completed_at timestamp NULL,
    expires_at timestamp NULL
  )");
  $columnResult=Sql::query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='mcpoperation'");
  $existingColumns=array();while($columnRow=Sql::fetchLine($columnResult))$existingColumns[$columnRow['column_name']]=true;
  foreach(array(
    'request_hash'=>'varchar(64)','idempotency_key'=>'varchar(255)','attempts'=>'integer NOT NULL DEFAULT 0','max_attempts'=>'integer NOT NULL DEFAULT 1',
    'retry_policy'=>"varchar(30) NOT NULL DEFAULT 'never'",'lease_owner'=>'varchar(120)','lease_expires_at'=>'timestamp NULL','heartbeat_at'=>'timestamp NULL','error_code'=>'varchar(100)','recovery_state'=>'varchar(40)','module_id'=>'varchar(60)','action_version'=>'varchar(30)','effects_json'=>'text'
  ) as $column=>$definition)if(!isset($existingColumns[$column]))Sql::query("ALTER TABLE mcpoperation ADD COLUMN $column $definition");
  $indexResult=Sql::query("SELECT 1 AS present FROM pg_indexes WHERE schemaname=current_schema() AND indexname='mcpoperation_actor_idempotency'");
  if(!Sql::fetchLine($indexResult))Sql::query('CREATE UNIQUE INDEX mcpoperation_actor_idempotency ON mcpoperation(username,idempotency_key) WHERE idempotency_key IS NOT NULL');
  $ready = true;
}

function mcpCanonicalValue(mixed $value): mixed {
  if(!is_array($value))return $value;if(array_is_list($value))return array_map('mcpCanonicalValue',$value);
  ksort($value,SORT_STRING);foreach($value as $key=>$entry)$value[$key]=mcpCanonicalValue($entry);return $value;
}
function mcpRequestHash(array $value): string { return hash('sha256',json_encode(mcpCanonicalValue($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)); }

function mcpOperationInsert(string $username,string $type,string $status,array $payload,?string $nonce=null,?string $expires=null,?string $idempotencyKey=null,?string $requestHash=null,string $retryPolicy='never',int $maxAttempts=1,?string $moduleId=null,?string $actionVersion=null): int {
  mcpEnsureOperationTable();
  $sql = 'INSERT INTO mcpoperation (username,operation_type,status,payload,nonce,expires_at,idempotency_key,request_hash,retry_policy,max_attempts,module_id,action_version) VALUES (' .
    Sql::str($username) . ',' . Sql::str($type) . ',' . Sql::str($status) . ',' . Sql::str(json_encode($payload)) . ',' .
    ($nonce?Sql::str($nonce):'NULL') . ',' . ($expires?Sql::str($expires):'NULL') . ',' . ($idempotencyKey?Sql::str($idempotencyKey):'NULL') . ',' . ($requestHash?Sql::str($requestHash):'NULL') . ',' . Sql::str($retryPolicy) . ',' . max(1,$maxAttempts) . ',' .
    ($moduleId?Sql::str($moduleId):'NULL') . ',' . ($actionVersion?Sql::str($actionVersion):'NULL') . ')';
  Sql::query($sql);
  return (int)Sql::$lastQueryNewid;
}

function mcpImportNonce(string $username,string $importRunId): string {
  if(!preg_match('/^[A-Za-z0-9_.:-]{1,150}$/D',$importRunId))mcpJsonError(400,'invalid_import_run','importRunId must contain 1 to 150 safe characters');
  return 'import:'.substr(hash('sha256',$username."\n".$importRunId),0,64);
}

function mcpImportJournal(string $username,string $importRunId): array {
  mcpEnsureOperationTable();$nonce=mcpImportNonce($username,$importRunId);
  $result=Sql::query('SELECT * FROM mcpoperation WHERE username='.Sql::str($username).' AND nonce='.Sql::str($nonce).' AND operation_type='.Sql::str('import.run'));
  $row=Sql::fetchLine($result);if(!$row)mcpJsonError(404,'import_run_not_found','Import run was not found');
  $document=json_decode((string)$row['result_json'],true);if(!is_array($document))$document=array('importRunId'=>$importRunId,'items'=>array());
  return array('row'=>$row,'document'=>$document);
}

function mcpRecordImportRun(string $username,string $importRunId,array $operationResult): array {
  mcpEnsureOperationTable();$nonce=mcpImportNonce($username,$importRunId);
  $created=array();
  foreach($operationResult['items']??array() as $item){
    if(($item['status']??'')!=='created')continue;
    $class=(string)($item['objectClass']??'');$id=(int)($item['id']??0);
    if(!$class||!$id)continue;
    $created[]=array('objectClass'=>$class,'id'=>$id,'version'=>$item['saved']['_version']??null);
  }
  $result=Sql::query('SELECT * FROM mcpoperation WHERE username='.Sql::str($username).' AND nonce='.Sql::str($nonce).' AND operation_type='.Sql::str('import.run'));
  $row=Sql::fetchLine($result);
  if($row){
    $document=json_decode((string)$row['result_json'],true);if(!is_array($document))$document=array('importRunId'=>$importRunId,'items'=>array());
    $map=array();foreach($document['items']??array() as $item)$map[$item['objectClass'].':'.$item['id']]=$item;
    foreach($created as $item)$map[$item['objectClass'].':'.$item['id']]=$item;
    $document['items']=array_values($map);
    Sql::query('UPDATE mcpoperation SET result_json='.Sql::str(json_encode($document)).',updated_at=CURRENT_TIMESTAMP,completed_at=CURRENT_TIMESTAMP WHERE id='.Sql::fmtId($row['id']));
    return array('id'=>(int)$row['id'],'importRunId'=>$importRunId,'recorded'=>count($created),'total'=>count($document['items']));
  }
  $document=array('importRunId'=>$importRunId,'items'=>$created);
  $id=mcpOperationInsert($username,'import.run','succeeded',array('importRunId'=>$importRunId),$nonce,null);
  Sql::query('UPDATE mcpoperation SET result_json='.Sql::str(json_encode($document)).',completed_at=CURRENT_TIMESTAMP,progress=100 WHERE id='.Sql::fmtId($id));
  return array('id'=>$id,'importRunId'=>$importRunId,'recorded'=>count($created),'total'=>count($created));
}

function mcpSignConfirmation(string $username, string $kind, array $payload, string $nonce, int $expires): string {
  global $key;
  $document = array('version'=>1,'username'=>$username,'kind'=>$kind,'payload'=>$payload,'nonce'=>$nonce,'expires'=>$expires);
  $encoded = mcpCursorEncode($document);
  $signature = hash_hmac('sha256',$encoded,$key);
  return $encoded . '.' . $signature;
}

function mcpVerifyConfirmation(string $token, string $username, string $kind): array {
  global $key;
  $parts = explode('.',$token,2);
  if (count($parts)!==2 || !hash_equals(hash_hmac('sha256',$parts[0],$key),$parts[1])) mcpJsonError(400,'invalid_confirmation','Confirmation token is invalid');
  $payload = mcpCursorDecode($parts[0]);
  if (($payload['username']??'')!==$username || ($payload['kind']??'')!==$kind || (int)($payload['expires']??0)<time()) mcpJsonError(400,'expired_confirmation','Confirmation token is expired or belongs to another actor');
  mcpEnsureOperationTable();
  $result = Sql::query('UPDATE mcpoperation SET nonce=NULL,status=' . Sql::str('running') . ',started_at=COALESCE(started_at,CURRENT_TIMESTAMP),updated_at=CURRENT_TIMESTAMP WHERE nonce=' . Sql::str((string)$payload['nonce']) . ' AND username=' . Sql::str($username) . " AND status='prepared' RETURNING id,status,expires_at");
  $row = Sql::fetchLine($result);
  if (!$row || $row['status']!=='running') mcpJsonError(409,'confirmation_replayed','Confirmation token was already used or revoked');
  return array('document'=>$payload,'operationId'=>(int)$row['id']);
}

function mcpHandlePrepareChange(array $input, string $username): never {
  $operations = $input['operations']??null;
  if (!is_array($operations) || count($operations)<1 || count($operations)>MCP_V2_BATCH_MAX) mcpJsonError(400,'invalid_batch','operations must contain 1 to 200 items');
  $preview = array();
  foreach ($operations as $index=>$operation) {
    $check = mcpCheckOperation($operation,false,true);
    $object = $check['object'];
    $preview[] = array('index'=>$index,'action'=>$operation['action'],'objectClass'=>$operation['objectClass'],'id'=>$operation['id']??null,'name'=>$object->name??null,'version'=>$object->id?mcpObjectVersion($object):'new','classification'=>$check['policy']['classification'],'valid'=>$check['valid'],'message'=>$check['message']);
  }
  $nonce = bin2hex(random_bytes(24));
  $expires = time()+MCP_V2_CONFIRM_SECONDS;
  $id = mcpOperationInsert($username,'guarded.change','prepared',array('operations'=>$operations),$nonce,date('Y-m-d H:i:s',$expires));
  $token = mcpSignConfirmation($username,'change',array('operationId'=>$id,'operations'=>$operations),$nonce,$expires);
  mcpJsonResponse(array('ok'=>true,'operationId'=>$id,'expiresAt'=>date(DATE_ATOM,$expires),'confirmationToken'=>$token,'preview'=>$preview));
}

function mcpHandleCommitChange(array $input, string $username): never {
  @set_time_limit(300);
  mcpRequireKeys($input,array('confirmationToken'));
  $verified = mcpVerifyConfirmation((string)$input['confirmationToken'],$username,'change');
  $operations = $verified['document']['payload']['operations']??array();
  $result = mcpExecuteOperationsArray($operations,'atomic',true);
  $status = $result['ok']?'succeeded':'failed';
  Sql::query('UPDATE mcpoperation SET status=' . Sql::str($status) . ', progress=100, result_json=' . Sql::str(json_encode($result)) . ', completed_at=CURRENT_TIMESTAMP, updated_at=CURRENT_TIMESTAMP WHERE id=' . $verified['operationId'] . " AND status='running'");
  mcpJsonResponse(array_merge($result,array('operationId'=>$verified['operationId'])));
}

function mcpJobRow(array $row): array {
  return array('id'=>(int)$row['id'],'username'=>$row['username'],'type'=>$row['operation_type'],'module'=>$row['module_id']??null,'actionVersion'=>$row['action_version']??null,'status'=>$row['status'],'progress'=>(int)$row['progress'],'cancelRequested'=>(bool)$row['cancel_requested'],'attempts'=>(int)($row['attempts']??0),'maxAttempts'=>(int)($row['max_attempts']??1),'retryPolicy'=>$row['retry_policy']??'never','heartbeatAt'=>$row['heartbeat_at']??null,'leaseExpiresAt'=>$row['lease_expires_at']??null,'recoveryState'=>$row['recovery_state']??null,'errorCode'=>$row['error_code']??null,'createdAt'=>$row['created_at'],'updatedAt'=>$row['updated_at'],'startedAt'=>$row['started_at'],'completedAt'=>$row['completed_at'],'expiresAt'=>$row['expires_at'],'resultResource'=>($row['result_path']||$row['result_json'])?'projeqtor://jobs/' . $row['id'] . '/result':null);
}

function mcpHandleListJobs(array $input, string $username): never {
  mcpEnsureOperationTable();
  $size=max(1,min(200,(int)($input['pageSize']??50)));
  $status=(string)($input['status']??'');$fingerprint=substr(hash('sha256',json_encode(array('status'=>$status,'sort'=>'id:desc'))),0,24);$cursor=mcpSignedCursorDecode($input['cursor']??null);
  if($cursor&&(($cursor['kind']??'')!=='jobs'||($cursor['fingerprint']??'')!==$fingerprint))mcpJsonError(400,'cursor_query_mismatch','Cursor does not belong to this job query');
  $where='username=' . Sql::str($username);
  if($status)$where.=' AND status='.Sql::str($status);if($cursor)$where.=' AND id<'.Sql::fmtId((int)$cursor['after']);
  $result=Sql::query("SELECT * FROM mcpoperation WHERE $where ORDER BY id DESC LIMIT ".($size+1));
  $items=array(); while($row=Sql::fetchLine($result)) $items[]=mcpJobRow($row);
  $hasMore=count($items)>$size;if($hasMore)array_pop($items);$next=$hasMore?mcpSignedCursorEncode(array('kind'=>'jobs','fingerprint'=>$fingerprint,'after'=>end($items)['id'])):null;
  $countWhere='username='.Sql::str($username).($status?' AND status='.Sql::str($status):'');$countResult=Sql::query("SELECT count(*) AS total FROM mcpoperation WHERE $countWhere");$countRow=Sql::fetchLine($countResult);
  mcpJsonResponse(array('returned'=>count($items),'total'=>(int)$countRow['total'],'hasMore'=>$hasMore,'nextCursor'=>$next,'items'=>$items));
}

function mcpHandleGetJob(int $id,string $username): never {
  mcpEnsureOperationTable();
  $result=Sql::query('SELECT * FROM mcpoperation WHERE id=' . Sql::fmtId($id) . ' AND username=' . Sql::str($username));
  $row=Sql::fetchLine($result); if(!$row)mcpJsonError(404,'job_not_found','Job was not found');
  mcpJsonResponse(mcpJobRow($row));
}

function mcpHandleCancelJob(array $input,string $username): never {
  $id=(int)($input['id']??0); if(!$id)mcpJsonError(400,'id_required','A positive job id is required');
  mcpEnsureOperationTable();
  Sql::query("UPDATE mcpoperation SET cancel_requested=1, status=CASE WHEN status='queued' THEN 'cancelled' ELSE 'cancel_requested' END, updated_at=CURRENT_TIMESTAMP, completed_at=CASE WHEN status='queued' THEN CURRENT_TIMESTAMP ELSE completed_at END WHERE id=" . Sql::fmtId($id) . ' AND username=' . Sql::str($username) . " AND status IN ('queued','running','cancel_requested')");
  mcpHandleGetJob($id,$username);
}

function mcpRevalidateRetryPermission(array $row): void {
  $payload=json_decode((string)($row['payload']??''),true);
  if(!is_array($payload))mcpJsonError(409,'job_not_retryable','The saved job payload is invalid');
  $action=(string)($payload['action']??$row['operation_type']??'');
  $arguments=is_array($payload['arguments']??null)?$payload['arguments']:array();
  if(!function_exists('mcpActionAvailable')||!mcpActionAvailable($action))mcpJsonError(403,'forbidden','The originating action is no longer available');
  mcpValidateActionArguments($action,$arguments);
  if($action==='project.snapshot'){
    $project=new Project((int)($arguments['idProject']??0));
    if(!$project->id||!Security::checkValidAccessForUser($project,'read',null,null,false))mcpJsonError(403,'forbidden','Project snapshot access is no longer available');
  }elseif($action==='export.start'){
    $class=(string)($arguments['objectClass']??'');
    mcpRequireClassOperation($class,'read');
    if(!Security::checkValidAccessForUser(null,'read',$class,null,false))mcpJsonError(403,'forbidden','Export access is no longer available');
  }elseif(in_array($action,array('report.start','reports.render'),true)){
    $report=new Report((int)($arguments['idReport']??0));
    if(!$report->id||!Security::checkValidAccessForUser($report,'read',null,null,false))mcpJsonError(403,'forbidden','Report access is no longer available');
  }
}

function mcpHandleRetryJob(array $input,string $username): never {
  $id=(int)($input['id']??0);if(!$id)mcpJsonError(400,'id_required','A positive job id is required');mcpEnsureOperationTable();
  $query=Sql::query('SELECT * FROM mcpoperation WHERE id='.Sql::fmtId($id).' AND username='.Sql::str($username).' FOR UPDATE');$row=Sql::fetchLine($query);if(!$row)mcpJsonError(404,'job_not_found','Job was not found');
  if(($row['retry_policy']??'never')!=='safe')mcpJsonError(409,'job_not_retryable','Only jobs with safe retry policy can be retried');
  if((int)$row['attempts']>=(int)$row['max_attempts'])mcpJsonError(409,'retry_limit_reached','The job attempt limit has been reached');
  if(!in_array($row['status'],array('failed','cancelled'),true))mcpJsonError(409,'job_not_retryable','Only failed or cancelled safe jobs can be retried');
  mcpRevalidateRetryPermission($row);
  Sql::query("UPDATE mcpoperation SET status='queued',progress=0,cancel_requested=0,result_json=NULL,result_path=NULL,error_code=NULL,recovery_state=NULL,lease_owner=NULL,lease_expires_at=NULL,heartbeat_at=NULL,started_at=NULL,completed_at=NULL,updated_at=CURRENT_TIMESTAMP WHERE id=".Sql::fmtId($id).' AND username='.Sql::str($username));
  mcpHandleGetJob($id,$username);
}

function mcpWorkerCompatibility(): array {
  mcpEnsureOperationTable();$required=array('request_hash','idempotency_key','attempts','max_attempts','retry_policy','lease_owner','lease_expires_at','heartbeat_at','error_code','recovery_state','module_id','action_version','effects_json');
  $query=Sql::query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='mcpoperation'");$present=array();while($row=Sql::fetchLine($query))$present[]=$row['column_name'];$missing=array_values(array_diff($required,$present));
  $path='/var/lib/projeqtor/mcp-worker-heartbeat';$stamp=is_file($path)?(int)trim((string)file_get_contents($path)):0;$age=$stamp?time()-$stamp:null;
  return array('schemaVersion'=>4,'workerVersion'=>'2.0.1','compatible'=>!count($missing),'missingColumns'=>$missing,'heartbeatAt'=>$stamp?date(DATE_ATOM,$stamp):null,'heartbeatAgeSeconds'=>$age,'heartbeatFresh'=>$age!==null&&$age>=0&&$age<=45);
}
