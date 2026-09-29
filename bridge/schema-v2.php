<?php
declare(strict_types=1);

require_once __DIR__ . '/policy.php';

function mcpSchemaV2ReferenceClass(string $field): ?string {
  $overrides=array('idResourceSelect'=>'Resource','idAffectable'=>'Affectable','idUser'=>'User');
  if(isset($overrides[$field]))return $overrides[$field];
  if(!preg_match('/^id([A-Z][A-Za-z0-9_]*)$/D',$field,$matches))return null;
  return SqlElement::class_exists($matches[1])?$matches[1]:null;
}

function mcpSchemaV2Type(object $object,string $field,mixed $value,?string $reference): array {
  $databaseType=method_exists($object,'getDataType')?$object->getDataType($field):null;
  if($reference||$field==='id'||preg_match('/Id$/D',$field))$type='integer';
  else if(in_array($databaseType,array('int','integer'),true))$type='integer';
  else if(in_array($databaseType,array('decimal','numeric','float'),true))$type='number';
  else if(in_array($databaseType,array('date','datetime','time'),true))$type='string';
  else if(preg_match('/^(is|has|idle$|done$|handled$|cancelled$|paused$|optional$|fix)/i',$field))$type='boolean';
  else $type=is_int($value)?'integer':(is_float($value)?'number':(is_bool($value)?'boolean':'string'));
  $format=$databaseType==='date'?'date':($databaseType==='datetime'?'date-time':($type==='boolean'?'zero-or-one':null));
  return array($type,$format,$databaseType);
}

function mcpSchemaV2Column(object $object,string $field): ?array {
  static $cache=array();
  try {
    $table=(string)$object->getDatabaseTableName();
    $column=(string)$object->getDatabaseColumnName($field);
  } catch(Throwable $error) { return null; }
  $key=$table.'.'.$column;
  if(array_key_exists($key,$cache))return $cache[$key];
  $sql='SELECT data_type,udt_name,is_nullable,column_default,character_maximum_length,numeric_precision,numeric_scale FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='.Sql::str($table).' AND column_name='.Sql::str($column);
  $result=Sql::query($sql);$row=Sql::fetchLine($result);
  $cache[$key]=$row?:null;
  return $cache[$key];
}

function mcpSchemaV2Collect(object $object,array &$fields,array &$seen,int $depth=0): void {
  if(method_exists($object,'setAttributes'))$object->setAttributes();
  foreach(get_object_vars($object) as $name=>$value){
    if(is_object($value)&&$depth<2&&!str_starts_with($name,'_')){mcpSchemaV2Collect($value,$fields,$seen,$depth+1);continue;}
    if(!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D',$name)||str_starts_with($name,'_')||mcpSensitiveField($name)||is_array($value)||is_object($value)||isset($seen[$name]))continue;
    $attributeText=method_exists($object,'getFieldAttributes')?(string)$object->getFieldAttributes($name):'';
    $attributes=array_values(array_filter(array_map('trim',explode(',',$attributeText))));
    $hidden=in_array('hidden',$attributes,true)||in_array('hiddenforce',$attributes,true)||in_array('noExport',$attributes,true);
    $readonly=in_array('readonly',$attributes,true)||in_array('calculated',$attributes,true)||in_array('noImport',$attributes,true);
    $includedReadOnly=$depth>0&&in_array($name,array('id','refType','refId','refName','handled','done','idle','cancelled'),true);
    $reference=mcpSchemaV2ReferenceClass($name); [$type,$format,$databaseType]=mcpSchemaV2Type($object,$name,$value,$reference);
    $column=mcpSchemaV2Column($object,$name);
    if($column)$databaseType=(string)($column['data_type']?:$column['udt_name']);
    $unit=preg_match('/Work$/D',$name)?'deployment_work_unit':(preg_match('/Duration|Delay$/D',$name)?'working_days':(preg_match('/Rate|Pct|Progress$/D',$name)?'percent':null));
    $required=in_array('required',$attributes,true)||in_array('mandatory',$attributes,true)||($column&&$column['is_nullable']==='NO'&&$column['column_default']===null&&$name!=='id');
    $fields[]=array('name'=>$name,'type'=>$type,'format'=>$format,'databaseType'=>$databaseType,'required'=>$required,'nullable'=>$column?$column['is_nullable']==='YES':!$required,'readable'=>!$hidden,'writable'=>$name!=='id'&&!$hidden&&!$readonly&&!$includedReadOnly,'referenceClass'=>$reference,'default'=>$column?$column['column_default']:((is_scalar($value)||$value===null)?$value:null),'maxLength'=>$column&&$column['character_maximum_length']!==null?(int)$column['character_maximum_length']:null,'precision'=>$column&&$column['numeric_precision']!==null?array('digits'=>(int)$column['numeric_precision'],'scale'=>(int)$column['numeric_scale']):null,'unit'=>$unit,'sensitive'=>false,'attributes'=>$attributes,'sourceObject'=>get_class($object));
    $seen[$name]=true;
  }
}

function emitMcpSchemaV2(string $class): never {
  $policy=mcpPolicyForCurrentUser(mcpClassPolicy($class));
  if(!$policy['supported']||!in_array('read',$policy['operations'],true))denyMcpRequest(404,'Unsupported object class');
  Security::checkValidClass($class); $object=new $class(); $fields=array();$seen=array();mcpSchemaV2Collect($object,$fields,$seen);
  echo json_encode(array('schemaVersion'=>2,'policyVersion'=>MCP_POLICY_VERSION,'objectClass'=>$class,'classification'=>$policy['classification'],'operations'=>array('read'=>in_array('read',$policy['effectiveOperations'],true),'create'=>in_array('create',$policy['effectiveOperations'],true),'update'=>in_array('update',$policy['effectiveOperations'],true),'delete'=>in_array('delete',$policy['effectiveOperations'],true),'guarded'=>$policy['guarded'],'permission'=>$policy['permission']),'fields'=>$fields),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);exit;
}
