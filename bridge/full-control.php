<?php
declare(strict_types=1);

require_once __DIR__ . '/policy.php';
require_once __DIR__ . '/field-access.php';
require_once __DIR__ . '/relation-access.php';

const MCP_V2_PAGE_MAX = 200;
const MCP_V2_BATCH_MAX = 200;
const MCP_V2_CONFIRM_SECONDS = 300;

final class McpBridgeException extends RuntimeException {
  public function __construct(
    public readonly int $httpStatus,
    public readonly string $errorCode,
    string $message,
    public readonly array $details=array()
  ) { parent::__construct($message); }
}

function mcpJsonResponse(array $payload, int $status=200): never {
  if (ob_get_level() > 0) ob_clean();
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
  exit;
}

function mcpJsonError(int $status, string $code, string $message, array $details=array()): never {
  if(!empty($GLOBALS['mcpCaptureErrors'])){
    throw new McpBridgeException($status,$code,$message,$details);
  }
  mcpJsonResponse(array('ok'=>false, 'error'=>array_merge(array('code'=>$code, 'message'=>$message), $details)), $status);
}

function mcpDecodeBody(string $body): array {
  if ($body === '') return array();
  $decoded = json_decode($body, true);
  if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
    mcpJsonError(400, 'invalid_json', 'A JSON object is required');
  }
  return $decoded;
}

function mcpRequireKeys(array $value, array $keys): void {
  foreach ($keys as $key) {
    if (!array_key_exists($key, $value)) mcpJsonError(400, 'missing_field', "Missing required field '$key'", array('field'=>$key));
  }
}

function mcpObjectVersion(object $object): string {
  $class = get_class($object);
  $id = (int)($object->id ?? 0);
  if (!$id) return 'new';
  $history = new History();
  $criteria = 'refType=' . Sql::str($class) . ' and refId=' . Sql::fmtId($id);
  $items = $history->getSqlElementsFromCriteria(null, false, $criteria, 'id desc', false, true, 1);
  $historyId = count($items) ? (int)$items[0]->id : 0;
  $stamp = $object->lastUpdateDateTime ?? $object->updateDateTime ?? $object->creationDateTime ?? $object->creationDate ?? '';
  return 'v2:' . $historyId . ':' . substr(hash('sha256', $class . ':' . $id . ':' . $stamp), 0, 16);
}

function mcpLegacyObjectArray(object $object, ?array $fields=null): array {
  $result = array();
  foreach (get_object_vars($object) as $field=>$value) {
    if (str_starts_with($field, '_') || mcpSensitiveField($field) || is_resource($value)) continue;
    if ($fields !== null && $field !== 'id' && !in_array($field, $fields, true)) continue;
    if (is_object($value)) continue;
    $result[$field] = $value;
  }
  $result['_version'] = mcpObjectVersion($object);
  return mcpRedactObject($result);
}

function mcpLegacyValidateField(object $object, string $field, bool $writable=false): void {
  if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $field) || !property_exists($object, $field) || str_starts_with($field, '_') || mcpSensitiveField($field)) {
    mcpJsonError(400, 'invalid_field', "Field '$field' is not available", array('field'=>$field));
  }
  if ($writable) {
    if ($field === 'id') mcpJsonError(400, 'invalid_field', 'The id field is not writable');
    if (method_exists($object, 'setAttributes')) $object->setAttributes();
    $attributes = method_exists($object, 'getFieldAttributes') ? (string)$object->getFieldAttributes($field) : '';
    foreach (array('readonly','calculated','noImport','hiddenforce') as $flag) {
      if (str_contains($attributes, $flag)) mcpJsonError(400, 'readonly_field', "Field '$field' is not writable", array('field'=>$field));
    }
  }
}

function mcpSqlLiteral(object $object, string $field, mixed $value): string {
  if ($value === null) return 'NULL';
  $type = method_exists($object, 'getDataType') ? $object->getDataType($field) : null;
  if (($type === 'int' || $type === 'decimal') && is_numeric($value)) return (string)(0 + $value);
  if (is_bool($value)) return $value ? '1' : '0';
  return Sql::str((string)$value);
}

function mcpFilterSql(object $object, mixed $node): string {
  if (!is_array($node)) mcpJsonError(400, 'invalid_filter', 'Filter nodes must be objects');
  foreach (array('all','any') as $group) {
    if (array_key_exists($group, $node)) {
      if (!is_array($node[$group]) || count($node[$group]) < 1 || count($node[$group]) > 40) mcpJsonError(400, 'invalid_filter', "'$group' must contain 1 to 40 filters");
      $parts = array_map(fn($entry)=>mcpFilterSql($object, $entry), $node[$group]);
      return '(' . implode($group === 'all' ? ' AND ' : ' OR ', $parts) . ')';
    }
  }
  if (array_key_exists('not', $node)) return '(NOT ' . mcpFilterSql($object, $node['not']) . ')';
  mcpRequireKeys($node, array('field','operator'));
  $field = (string)$node['field'];
  $operator = (string)$node['operator'];
  mcpValidateField($object, $field);
  if(mcpFieldOwner($object,$field)!==$object)mcpJsonError(400,'nonqueryable_field',"Field '$field' belongs to a composed object and cannot be used in database filters");
  $column = $object->getDatabaseTableName() . '.' . $object->getDatabaseColumnName($field);
  $value = $node['value'] ?? null;
  $map = array('eq'=>'=', 'ne'=>'<>', 'lt'=>'<', 'lte'=>'<=', 'gt'=>'>', 'gte'=>'>=');
  if (isset($map[$operator])) {
    if ($value === null) return $column . ($operator === 'ne' ? ' IS NOT NULL' : ' IS NULL');
    return '(' . $column . ' ' . $map[$operator] . ' ' . mcpSqlLiteral($object, $field, $value) . ')';
  }
  if ($operator === 'is_null' || $operator === 'is_not_null') return '(' . $column . ($operator === 'is_null' ? ' IS NULL' : ' IS NOT NULL') . ')';
  if ($operator === 'in' || $operator === 'not_in') {
    if (!is_array($value) || count($value) > 500) mcpJsonError(400, 'invalid_filter', "'$operator' needs an array of at most 500 values");
    if (count($value) === 0) return $operator === 'in' ? '(1=0)' : '(1=1)';
    $list = implode(',', array_map(fn($entry)=>mcpSqlLiteral($object, $field, $entry), $value));
    return '(' . $column . ($operator === 'in' ? ' IN (' : ' NOT IN (') . $list . '))';
  }
  if (in_array($operator, array('contains','starts_with','ends_with'), true)) {
    if (!is_string($value)) mcpJsonError(400, 'invalid_filter', "'$operator' needs a string value");
    $escaped = str_replace(array('!','%','_'), array('!!','!%','!_'), $value);
    if ($operator !== 'starts_with') $escaped = '%' . $escaped;
    if ($operator !== 'ends_with') $escaped .= '%';
    return '(' . $column . ' ILIKE ' . Sql::str($escaped) . " ESCAPE '!')";
  }
  mcpJsonError(400, 'invalid_filter_operator', "Unsupported filter operator '$operator'");
}

function mcpCursorEncode(array $payload): string {
  return rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
}

function mcpCursorDecode(?string $cursor): ?array {
  if (!$cursor) return null;
  $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
  $value = $raw === false ? null : json_decode($raw, true);
  if (!is_array($value)) mcpJsonError(400, 'invalid_cursor', 'The pagination cursor is malformed');
  return $value;
}

function mcpSignedCursorEncode(array $payload): string {
  global $key;
  $encoded = mcpCursorEncode($payload);
  return $encoded . '.' . hash_hmac('sha256', $encoded, $key);
}

function mcpSignedCursorDecode(?string $cursor): ?array {
  global $key;
  if (!$cursor) return null;
  $parts = explode('.', $cursor, 2);
  if (count($parts) !== 2 || !hash_equals(hash_hmac('sha256', $parts[0], $key), $parts[1])) {
    mcpJsonError(400, 'invalid_cursor', 'The pagination cursor signature is invalid');
  }
  return mcpCursorDecode($parts[0]);
}

function mcpHandleClassCatalog(array $input): never {
  $inventory = mcpAssertPolicyComplete();
  $classification = $input['classification'] ?? null;
  $supportedOnly = (bool)($input['supportedOnly'] ?? false);
  $search = mb_strtolower((string)($input['search'] ?? ''));
  $fingerprint=substr(hash('sha256',json_encode(array('classification'=>$classification,'supportedOnly'=>$supportedOnly,'search'=>$search))),0,24);
  $pageSize = max(1, min(MCP_V2_PAGE_MAX, (int)($input['pageSize'] ?? 100)));
  $cursor = mcpSignedCursorDecode($input['cursor'] ?? null);
  if($cursor&&(($cursor['kind']??'')!=='classes'||($cursor['fingerprint']??'')!==$fingerprint))mcpJsonError(400,'cursor_query_mismatch','Cursor does not belong to this class query');
  $after = (string)($cursor['after'] ?? '');
  $items = array();
  foreach (mcpInstalledClasses() as $class) {
    $policy = mcpPolicyForCurrentUser(mcpClassPolicy($class));
    if ($classification && $policy['classification'] !== $classification) continue;
    if ($supportedOnly && !$policy['supported']) continue;
    if ($search && !str_contains(mb_strtolower($class), $search)) continue;
    if ($after && strcmp($class, $after) <= 0) continue;
    $items[] = $policy;
    if (count($items) > $pageSize) break;
  }
  $hasMore = count($items) > $pageSize;
  if ($hasMore) array_pop($items);
  mcpJsonResponse(array(
    'policyVersion'=>MCP_POLICY_VERSION,
    'installedCount'=>count(mcpInstalledClasses()),
    'inventory'=>$inventory,
    'worker'=>function_exists('mcpWorkerCompatibility')?mcpWorkerCompatibility():array('compatible'=>false),
    'modules'=>mcpModuleSummary(),
    'returned'=>count($items),
    'hasMore'=>$hasMore,
    'nextCursor'=>$hasMore ? mcpSignedCursorEncode(array('kind'=>'classes','fingerprint'=>$fingerprint,'after'=>end($items)['objectClass'])) : null,
    'items'=>$items
  ));
}

function mcpHandleGetItem(array $input): never {
  mcpRequireKeys($input, array('objectClass', 'id'));
  $class = (string)$input['objectClass'];
  $id = (int)$input['id'];
  if ($id < 1) mcpJsonError(400, 'invalid_id', 'A positive object id is required');
  $policy=mcpRequireClassOperation($class, 'read');
  $object = new $class($id, true);
  $nativeRead=$object->id&&Security::checkValidAccessForUser($object,'read',null,null,false);
  $contextual=in_array($policy['classification'],array('relation','derived'),true)&&$object->id&&mcpContextualReadAllowed($object);
  if (!$object->id || (!$nativeRead && !$contextual)) {
    mcpJsonError(404, 'not_found', "$class #$id was not found or is inaccessible");
  }
  $fields = isset($input['fields']) && is_array($input['fields']) ? array_values(array_unique($input['fields'])) : null;
  if ($fields !== null) foreach ($fields as $field) mcpValidateField($object, (string)$field);
  mcpJsonResponse(array('objectClass'=>$class, 'item'=>mcpObjectArray($object, $fields)));
}
function mcpSavedFilterSql(object $object, int $filterId): string {
  $user = getSessionUser();
  $filter = new Filter($filterId);
  if (!$filter->id || ((int)$filter->idUser !== (int)$user->id && !(int)$filter->isShared)) {
    mcpJsonError(404, 'filter_not_found', 'Saved filter is unavailable');
  }
  $parts = array();
  $criteria = new FilterCriteria();
  foreach ($criteria->getSqlElementsFromCriteria(array('idFilter'=>$filterId, 'isReportList'=>'0')) as $criterion) {
    if ($criterion->isDynamic || $criterion->sqlOperator === 'SORT') continue;
    $field = (string)$criterion->sqlAttribute;
    if (str_contains($field, '_')) continue;
    mcpValidateField($object, $field);
  if(mcpFieldOwner($object,$field)!==$object)mcpJsonError(400,'nonqueryable_field',"Field '$field' belongs to a composed object and cannot be used in database filters");
    $operator = strtoupper(trim((string)$criterion->sqlOperator));
    if (!in_array($operator, array('=','<>','<','<=','>','>=','LIKE','IN','NOT IN'), true)) continue;
    $parts[] = '(' . $object->getDatabaseTableName() . '.' . $object->getDatabaseColumnName($field) . ' ' . $operator . ' ' . (string)$criterion->sqlValue . ')';
  }
  return count($parts) ? '(' . implode(' AND ', $parts) . ')' : '(1=1)';
}

function mcpHandleQuery(array $input): never {
  mcpRequireKeys($input, array('objectClass'));
  $class = (string)$input['objectClass'];
  $policy=mcpRequireClassOperation($class, 'read');
  $nativeClassRead=Security::checkValidAccessForUser(null,'read',$class,null,false);
  $referenceClassRead=mcpReferenceParentReadAllowed($class,$policy);
  $contextualClass=in_array($policy['classification'],array('relation','derived'),true);
  if (!$nativeClassRead && !$referenceClassRead && !$contextualClass) mcpJsonError(403, 'forbidden', 'Read access is denied');
  $object = new $class();
  $fields = isset($input['fields']) && is_array($input['fields']) ? array_values(array_unique($input['fields'])) : null;
  if ($fields !== null) foreach ($fields as $field) mcpValidateField($object, (string)$field);
  $pageSize = max(1, min(MCP_V2_PAGE_MAX, (int)($input['pageSize'] ?? 50)));
  $orderBy = $input['orderBy'] ?? array(array('field'=>'id','direction'=>'asc'));
  if (!is_array($orderBy) || count($orderBy) !== 1) mcpJsonError(400, 'invalid_order', 'orderBy must contain exactly one field');
  $primary = $orderBy[0];
  $sortField = (string)($primary['field'] ?? 'id');
  $direction = strtolower((string)($primary['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
  mcpValidateField($object, $sortField);
  if(mcpFieldOwner($object,$sortField)!==$object)mcpJsonError(400,'nonqueryable_field',"Field '$sortField' belongs to a composed object and cannot be used for sorting");
  $table = $object->getDatabaseTableName();
  $sortColumn = $table . '.' . $object->getDatabaseColumnName($sortField);
  $idColumn = $table . '.' . $object->getDatabaseColumnName('id');
  $parts = array($nativeClassRead ? getAccesRestrictionClause($class, null, true) : '1=1');
  if (isset($input['filter'])) $parts[] = mcpFilterSql($object, $input['filter']);
  if (!empty($input['savedFilterId'])) $parts[] = mcpSavedFilterSql($object, (int)$input['savedFilterId']);
  $fingerprint = substr(hash('sha256', json_encode(array($class,$fields,$input['filter']??null,$input['savedFilterId']??null,$sortField,$direction))), 0, 24);
  $cursor = mcpSignedCursorDecode($input['cursor'] ?? null);
  if ($cursor) {
    if (($cursor['kind']??'')!=='items'||($cursor['fingerprint'] ?? '') !== $fingerprint) mcpJsonError(400, 'cursor_query_mismatch', 'The cursor does not match this query');
    $lastValue = $cursor['value'] ?? null;
    $lastId = (int)($cursor['id'] ?? 0);
    $compare = $direction === 'desc' ? '<' : '>';
    $parts[] = '((' . $sortColumn . ' ' . $compare . ' ' . mcpSqlLiteral($object, $sortField, $lastValue) . ') OR (' . $sortColumn . '=' . mcpSqlLiteral($object, $sortField, $lastValue) . ' AND ' . $idColumn . ' ' . $compare . ' ' . $lastId . '))';
  }
  $where = implode(' AND ', array_map(fn($part)=>'(' . $part . ')', $parts));
  $order = $sortColumn . ' ' . $direction . ($sortField === 'id' ? '' : ', ' . $idColumn . ' ' . $direction);
  $list = $object->getSqlElementsFromCriteria(null, false, $where, $order, false, true, $pageSize + 1);
  $hasMore = count($list) > $pageSize;
  if ($hasMore) array_pop($list);
  $lastScanned=count($list)?end($list):null;
  if (!$nativeClassRead && !$referenceClassRead) $list=array_values(array_filter($list,fn($entry)=>mcpContextualReadAllowed($entry)));
  $items = array_map(fn($entry)=>mcpObjectArray($entry, $fields), $list);
  $next = null;
  if ($hasMore && $lastScanned) {
    $last = $lastScanned;
    $next = mcpSignedCursorEncode(array('kind'=>'items','fingerprint'=>$fingerprint, 'value'=>$last->$sortField, 'id'=>(int)$last->id));
  }
  $total = null;
  if (!empty($input['includeTotal']) && ($nativeClassRead || $referenceClassRead)) {
    $countParts = $parts;
    if ($cursor) array_pop($countParts);
    foreach($object->getDatabaseCriteria() as $field=>$value){
      $countParts[]=$table.'.'.$object->getDatabaseColumnName((string)$field).'='.mcpSqlLiteral($object,(string)$field,$value);
    }
    if(property_exists($object,'isPrivate'))$countParts[]=SqlElement::getPrivacyClause($object);
    $total = $object->countSqlElementsFromCriteria(null, implode(' AND ', array_map(fn($part)=>'(' . $part . ')', $countParts)));
  }
  mcpJsonResponse(array('identifier'=>'id','total'=>$total,'returned'=>count($items),'pageSize'=>$pageSize,'hasMore'=>$hasMore,'nextCursor'=>$next,'items'=>$items));
}

function mcpHandleChanges(array $input): never {
  mcpRequireKeys($input,array('objectClass','since'));$class=(string)$input['objectClass'];mcpRequireClassOperation($class,'read');
  if(!Security::checkValidAccessForUser(null,'read',$class,null,false))mcpJsonError(403,'forbidden',"History access is denied for '$class'");
  $since=date_create_immutable((string)$input['since']);if(!$since)mcpJsonError(400,'invalid_time_window','since must be a valid ISO-8601 timestamp');
  $fields=isset($input['fields'])&&is_array($input['fields'])?array_values(array_unique($input['fields'])):null;$probe=new $class();if($fields!==null)foreach($fields as $field)mcpValidateField($probe,(string)$field);
  $pageSize=max(1,min(MCP_V2_PAGE_MAX,(int)($input['pageSize']??100)));$cursor=mcpSignedCursorDecode($input['cursor']??null);$requestedUntil=isset($input['until'])?date_create_immutable((string)$input['until']):null;
  if($cursor){if(($cursor['kind']??'')!=='changes')mcpJsonError(400,'cursor_query_mismatch','Cursor does not belong to a change query');$watermark=(string)($cursor['watermarkUntil']??'');if($requestedUntil&&$requestedUntil->format(DATE_ATOM)!==$watermark)mcpJsonError(400,'cursor_query_mismatch','until differs from the fixed cursor watermark');}
  else{$watermark=($requestedUntil?:new DateTimeImmutable('now'))->format(DATE_ATOM);}
  $until=date_create_immutable($watermark);if(!$until||$until<=$since)mcpJsonError(400,'invalid_time_window','since and until must define a valid ISO-8601 window');
  $fingerprint=substr(hash('sha256',json_encode(array($class,$fields,$since->format(DATE_ATOM),$watermark,'historyId','asc'))),0,24);if($cursor&&($cursor['fingerprint']??'')!==$fingerprint)mcpJsonError(400,'cursor_query_mismatch','Cursor does not match this change query');
  $afterHistory=(int)($cursor['historyId']??0);$history=new History();$where='refType='.Sql::str($class).' and operationDate>='.Sql::str($since->format('Y-m-d H:i:s')).' and operationDate<'.Sql::str($until->format('Y-m-d H:i:s')).' and id>'.$afterHistory;
  $rows=$history->getSqlElementsFromCriteria(null,false,$where,'id asc',false,true,$pageSize*10);$changes=array();$maxHistory=$afterHistory;
  foreach($rows as $row){$maxHistory=max($maxHistory,(int)$row->id);$id=(int)$row->refId;$changes[$id]=array('id'=>$id,'operation'=>(string)$row->operation,'changedAt'=>(string)$row->operationDate,'historyId'=>(int)$row->id);if(count($changes)>=$pageSize)break;}
  $items=array();foreach($changes as $change){$object=new $class($change['id'],true);$deleted=!$object->id||strtolower($change['operation'])==='delete';if(!$deleted&&!Security::checkValidAccessForUser($object,'read',null,null,false))continue;$items[]=$deleted?array_merge($change,array('deleted'=>true,'item'=>null)):array_merge($change,array('deleted'=>false,'item'=>mcpObjectArray($object,$fields)));}
  $lastRow=count($rows)?end($rows):null;$hasMore=$lastRow&&(int)$lastRow->id>$maxHistory;$next=$hasMore?mcpSignedCursorEncode(array('kind'=>'changes','fingerprint'=>$fingerprint,'watermarkUntil'=>$watermark,'historyId'=>$maxHistory)):null;
  mcpJsonResponse(array('since'=>$since->format(DATE_ATOM),'watermarkUntil'=>$watermark,'returned'=>count($items),'hasMore'=>$hasMore,'nextCursor'=>$next,'items'=>$items));
}
