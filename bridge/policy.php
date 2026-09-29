<?php
declare(strict_types=1);

const MCP_POLICY_VERSION = 2;

function mcpPolicyManifest(): array {
  static $manifest = null;
  if ($manifest !== null) return $manifest;
  $path = __DIR__ . '/class-policy-v2.json';
  $decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : null;
  if (!is_array($decoded) || (int)($decoded['policyVersion'] ?? 0) !== MCP_POLICY_VERSION || !is_array($decoded['knownClasses'] ?? null)) {
    throw new RuntimeException('The MCP class policy manifest is missing or invalid');
  }
  $manifest = $decoded;
  return $manifest;
}

function mcpAssertPolicyComplete(): array {
  $manifest = mcpPolicyManifest();
  $known = array_fill_keys($manifest['knownClasses'], true);
  $installed = mcpInstalledClasses();
  $unknown = array_values(array_filter($installed, fn($class)=>!isset($known[$class])));
  if (count($unknown)) {
    throw new RuntimeException('Unclassified installed ProjeQtOr classes: ' . implode(', ', $unknown));
  }
  return array(
    'policyVersion'=>MCP_POLICY_VERSION,
    'expectedInstalledClassCount'=>(int)($manifest['expectedInstalledClassCount'] ?? count($manifest['knownClasses'])),
    'installedClassCount'=>count($installed),
    'unknownClasses'=>$unknown
  );
}

function mcpSensitiveField(string $field): bool {
  return (bool)preg_match('/(^password$|apiKey|salt$|token$|secret|credential|privateKey|smtp.*pass|ldap.*pass)/i', $field);
}

function mcpExplicitRelationClasses(): array {
  return array(
    'Affectation', 'Assignment', 'AssignmentRecurring', 'Attachment', 'Approver',
    'BusinessFeature', 'Calendar', 'Checklist', 'ChecklistLine', 'Dependency',
    'DocumentRight', 'DocumentVersion', 'ExpenseDetail', 'Link', 'Note', 'Origin',
    'OtherVersion', 'ProductAsset', 'ProductContext', 'ProductLanguage',
    'ProductProject', 'ProductStructure', 'ProductVersionStructure', 'RaciAssignment',
    'Recipient', 'ResourceCapacity', 'ResourceCost', 'ResourceIncompatible',
    'ResourceSkill', 'ResourceSupport', 'ResourceSurbooking', 'ResourceTeamAffectation',
    'StatusPeriod', 'Subscription', 'TenderEvaluationCriteria', 'TestCaseRun',
    'VersionCompatibility', 'VersionContext', 'VersionLanguage', 'VersionProject',
    'Work', 'WorkPeriod'
  );
}

function mcpExplicitReferenceClasses(): array {
  return array(
    'CalendarDefinition', 'Role', 'Status', 'Profile', 'PlanningMode', 'Priority',
    'Urgency', 'Severity', 'Quality', 'Health', 'Trend', 'Likelihood',
    'Criticality', 'Resolution', 'Language', 'Skill', 'SkillLevel', 'Location',
    'Context', 'Context1', 'Context2', 'Context3', 'Workflow', 'Module',
    'MeasureUnit', 'PaymentMode', 'PaymentType', 'PaymentDelay', 'DeliveryMode',
    'RunStatus', 'ApprovalStatus', 'ProgressMode', 'RevenueMode', 'WeightMode'
  );
}

function mcpExplicitDerivedClasses(): array {
  return array(
    'Audit', 'Baseline', 'History', 'HistoryArchive', 'PlanningElement',
    'PlanningHistory', 'PlannedWork', 'ProjectHistory'
  );
}

function mcpSensitiveClasses(): array {
  return array(
    'OAuthClient', 'OtpRequest', 'PasswordResetRequest', 'SSO', 'UserOld'
  );
}

function mcpActiveMenuClasses(): array {
  static $cache = null;
  if ($cache !== null) return $cache;
  $cache = array();
  $menu = new Menu();
  foreach ($menu->getSqlElementsFromCriteria(array('idle'=>'0', 'type'=>'object')) as $item) {
    if (!str_starts_with((string)$item->name, 'menu')) continue;
    $class = substr((string)$item->name, 4);
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $class) || !SqlElement::class_exists($class)) continue;
    $cache[$class] = array('administrative'=>(bool)$item->isAdminMenu, 'menu'=>(string)$item->name);
  }
  return $cache;
}

function mcpInstalledClasses(): array {
  static $cache = null;
  if ($cache !== null) return array_keys($cache);
  $cache = array();
  $paths = array_merge(
    glob('/var/www/html/model/*.php') ?: array(),
    glob('/var/www/html/model/custom/*.php') ?: array()
  );
  foreach ($paths as $path) {
    $class = basename($path, '.php');
    if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $class)) continue;
    try {
      if (SqlElement::class_exists($class) && is_subclass_of($class, 'SqlElement')) $cache[$class] = true;
    } catch (Throwable $error) {
      // A broken optional/plugin model is classified as unavailable rather than loaded.
    }
  }
  ksort($cache);
  return array_keys($cache);
}

function mcpClassPolicy(string $class): array {
  if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/D', $class) || !SqlElement::class_exists($class) || !is_subclass_of($class, 'SqlElement')) {
    return array('classification'=>'internal', 'supported'=>false, 'reason'=>'not_an_installed_sql_element', 'operations'=>array());
  }

  $menus = mcpActiveMenuClasses();
  $relations = array_flip(mcpExplicitRelationClasses());
  $references = array_flip(mcpExplicitReferenceClasses());
  $derived = array_flip(mcpExplicitDerivedClasses());
  $sensitive = array_flip(mcpSensitiveClasses());
  $reason = null;

  if (isset($sensitive[$class])) {
    $classification = 'sensitive';
    $supported = false;
    $reason = 'credential_or_authentication_state';
  } else if (isset($derived[$class])) {
    $classification = 'derived';
    $supported = true;
  } else if (isset($relations[$class])) {
    $classification = 'relation';
    $supported = true;
  } else if (isset($references[$class])) {
    $classification = 'reference';
    $supported = true;
  } else if (isset($menus[$class])) {
    $classification = $menus[$class]['administrative'] ? 'administrative' :
      ((preg_match('/Type$/D', $class) || in_array($class, array('Status','Role','Priority','Urgency','Severity','Quality','Health','Trend','Likelihood','Criticality','Resolution','PlanningMode'), true)) ? 'reference' : 'business');
    $supported = true;
  } else if (preg_match('/(Main|Select|Selection|All|Current|Summary|SimpleMain|Full)$/D', $class) ||
             preg_match('/^(Menu|AccessScope|List|Favorite|Layout|ColumnSelector|Extra|ImportProgress|Mutex|Locker)/D', $class)) {
    $classification = 'internal';
    $supported = false;
    $reason = 'ui_projection_or_internal_state';
  } else if (preg_match('/Type$/D', $class)) {
    $classification = 'reference';
    $supported = true;
  } else {
    $classification = 'internal';
    $supported = false;
    $reason = 'unpublished_support_model';
  }

  $operations = array();
  if ($supported) {
    $operations[] = 'read';
    if ($classification !== 'derived') {
      try {
        $object = new $class();
        if (!property_exists($class, '_readOnly') && !property_exists($class, '_noCreate')) $operations[] = 'create';
        if (!property_exists($class, '_readOnly') && !property_exists($class, '_noUpdate')) $operations[] = 'update';
        if (!property_exists($class, '_readOnly') && !property_exists($class, '_noDelete')) $operations[] = 'delete';
      } catch (Throwable $error) {
        $supported = false;
        $reason = 'model_initialization_failed';
        $operations = array();
      }
    }
  }

  return array(
    'objectClass'=>$class,
    'classification'=>$classification,
    'supported'=>$supported,
    'reason'=>$reason,
    'operations'=>$operations,
    'guarded'=>($classification === 'administrative'),
    'policyVersion'=>MCP_POLICY_VERSION
  );
}

function mcpRequireClassOperation(string $class, string $operation): array {
  $policy = mcpClassPolicy($class);
  if (!$policy['supported'] || !in_array($operation, $policy['operations'], true)) {
    mcpJsonError(403, 'unsupported_class_operation', "Operation '$operation' is not exposed for '$class'", array('policy'=>$policy));
  }
  Security::checkValidClass($class);
  return $policy;
}

function mcpPolicyForCurrentUser(array $policy): array {
  $class=(string)($policy['objectClass']??'');
  $permission=array('read'=>'denied','create'=>'denied','update'=>'denied','delete'=>'denied');
  if(!$policy['supported'])return array_merge($policy,array('effectiveOperations'=>array(),'permission'=>$permission));
  try {
    if(Security::checkValidAccessForUser(null,'read',$class,null,false))$permission['read']='class';
    else if(in_array($policy['classification'],array('relation','derived'),true))$permission['read']='parent-scoped';
  } catch(Throwable $error) {}
  if(in_array('create',$policy['operations'],true)){
    try { if(Security::checkValidAccessForUser(new $class(),'create',null,null,false))$permission['create']='class'; } catch(Throwable $error) {}
  }
  foreach(array('update','delete') as $operation){
    if(in_array($operation,$policy['operations'],true))$permission[$operation]='object-scoped';
  }
  $effective=array();
  foreach($permission as $operation=>$mode)if($mode!=='denied')$effective[]=$operation;
  return array_merge($policy,array(
    'effectiveOperations'=>$effective,
    'permission'=>$permission,
    'permissionEvaluatedFor'=>(string)(getSessionUser()->name??'')
  ));
}

function mcpRedactObject(array $value): array {
  foreach (array_keys($value) as $field) {
    if (mcpSensitiveField((string)$field)) unset($value[$field]);
  }
  return $value;
}
