<?php
declare(strict_types=1);

function bootstrapFail(string $message, int $code = 1): never {
  fwrite(STDERR, "Beta 4 database bootstrap: $message\n");
  exit($code);
}

function requiredEnvironment(string $name): string {
  $value = getenv($name);
  if (!is_string($value) || $value === '') bootstrapFail("$name is required", 64);
  return $value;
}

$dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', requiredEnvironment('PROJEQTOR_DB_HOST'), requiredEnvironment('PROJEQTOR_DB_PORT'), requiredEnvironment('PROJEQTOR_DB_NAME'));
$pdo = new PDO($dsn, requiredEnvironment('PROJEQTOR_DB_USER'), requiredEnvironment('PROJEQTOR_DB_PASSWORD'), array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC));
$tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
$createdCompatibilityTable = false;
if (!in_array('parameter', $tables, true)) {
  $unexpected = array_values(array_diff($tables, array('mcpoperation')));
  if ($unexpected) bootstrapFail('refusing to initialize a non-empty database without a parameter table', 65);
  $pdo->exec('CREATE TABLE parameter (id serial PRIMARY KEY, idUser integer NULL, idProject integer NULL, parameterCode varchar(100) NULL, parameterValue varchar(4000) NULL)');
  $createdCompatibilityTable = true;
}

$_SERVER['SCRIPT_NAME'] = '/tool/bootstrap-maintenance.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_URI'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$batchMode = true;
$cronnedScript = true;
$noScriptLog = false;
chdir('/var/www/html/tool');
require '/var/www/html/tool/projeqtor.php';

$currentVersion = Sql::getDbVersion();
if ($createdCompatibilityTable) {
  $count = Sql::fetchLine(Sql::query('SELECT COUNT(*) AS count FROM parameter'));
  if ($currentVersion !== '' || !$count || (int)$count['count'] !== 0) bootstrapFail('compatibility table is not empty', 66);
  if (!Sql::query('DROP TABLE parameter')) bootstrapFail('could not remove the compatibility table', 67);
}
if ($currentVersion !== 'V13.1.0') {
  if (is_file('/var/www/html/files/cron/MIGRATION')) bootstrapFail('stale migration marker exists', 68);
  chdir('/var/www/html/db');
  ob_start();
  require '/var/www/html/db/maintenance.php';
  $maintenanceOutput = (string)ob_get_clean();
  if (($nbErrors ?? 1) !== 0) bootstrapFail('official maintenance runner reported migration errors', 69);
  if (!str_contains($maintenanceOutput, 'DATABASE UPDATE COMPLETED')) bootstrapFail('official maintenance runner did not report completion', 70);
}

$versionStatement = $pdo->query("SELECT parameterValue FROM parameter WHERE idUser IS NULL AND idProject IS NULL AND parameterCode='dbVersion'");
if ($versionStatement->fetchColumn() !== 'V13.1.0') bootstrapFail('database version is not V13.1.0', 71);
if (is_file('/var/www/html/files/cron/MIGRATION')) bootstrapFail('migration marker remains after completion', 72);
$admin = SqlElement::getSingleSqlElementFromCriteria('User', array('name' => 'admin'));
if (!$admin->id) bootstrapFail('official admin user is missing', 73);
setSessionUser($admin);

// Enable every optional family exercised by the disposable acceptance matrix.
// Capture the original switches once so restore-mode cleanup can put them back.
$acceptanceModuleNames = array(
  'moduleAbsence','moduleNotification','moduleDataCloning','moduleAssets',
  'moduleSituation','moduleGestionCA','moduleLocalization','modulePoker',
  'moduleTargetMilestone','moduleTechnicalProgress','moduleBudgetFunctionOfOrga',
  'moduleTodoList','moduleChecklist','moduleMail','moduleTokenManagement',
  'moduleHumanResource','moduleSkillManagement','moduleVoting',
  'moduleProjectAnalysis','moduleCrmProspect','moduleAbacus'
);
$moduleSnapshotPath='/var/lib/projeqtor/mcp-harness-module-state.json';
$acceptanceParameterValues=array(
  'paramMailerType'=>'phpmailer',
  'paramMailSmtpServer'=>'mail',
  'paramMailSmtpPort'=>'1025',
  'paramMailSender'=>'beta4@beta4.invalid',
  'paramMailReplyTo'=>'beta4@beta4.invalid',
  'paramMailReplyToName'=>'Beta 4 Disposable',
  'paramMailSmtpUseUnsecureSsl'=>'YES'
);
$acceptanceModules=array();
$parentModuleIds=array();
foreach($acceptanceModuleNames as $moduleName){
  $module=SqlElement::getSingleSqlElementFromCriteria('Module',array('name'=>$moduleName));
  if(!$module->id)bootstrapFail("required acceptance module $moduleName is missing",77);
  $acceptanceModules[$moduleName]=$module;
  if((int)$module->idModule>0)$parentModuleIds[(int)$module->idModule]=true;
}
foreach(array_keys($parentModuleIds) as $parentModuleId){
  $parent=new Module((int)$parentModuleId);
  if(!$parent->id)bootstrapFail("acceptance parent module $parentModuleId is missing",80);
  $acceptanceModules[$parent->name]=$parent;
}
$moduleSnapshot=null;
if(is_file($moduleSnapshotPath)){
  $decoded=json_decode((string)file_get_contents($moduleSnapshotPath),true);
  if(!is_array($decoded)||!isset($decoded['modules'])||!is_array($decoded['modules']))bootstrapFail('initial state snapshot is malformed',78);
  $moduleSnapshot=$decoded;
}
if($moduleSnapshot===null)$moduleSnapshot=array('version'=>2,'modules'=>array(),'parameters'=>array());
foreach($acceptanceModules as $moduleName=>$module){
  if(!array_key_exists($moduleName,$moduleSnapshot['modules']))$moduleSnapshot['modules'][$moduleName]=(int)$module->active;
}
if(!isset($moduleSnapshot['parameters'])||!is_array($moduleSnapshot['parameters']))$moduleSnapshot['parameters']=array();
$parameterLookup=$pdo->prepare('SELECT parameterValue FROM parameter WHERE idUser IS NULL AND idProject IS NULL AND parameterCode=:code ORDER BY id LIMIT 1');
foreach(array_keys($acceptanceParameterValues) as $parameterCode){
  if(array_key_exists($parameterCode,$moduleSnapshot['parameters']))continue;
  $parameterLookup->execute(array(':code'=>$parameterCode));
  $parameterValue=$parameterLookup->fetchColumn();
  $moduleSnapshot['parameters'][$parameterCode]=array('exists'=>$parameterValue!==false,'value'=>$parameterValue===false?null:(string)$parameterValue);
}
$moduleSnapshot['version']=2;
$temporary=$moduleSnapshotPath.'.tmp';
if(file_put_contents($temporary,json_encode($moduleSnapshot,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n",LOCK_EX)===false||!chmod($temporary,0600)||!rename($temporary,$moduleSnapshotPath))bootstrapFail('could not capture initial disposable state',78);

foreach($acceptanceModules as $moduleName=>$module){
  $module->active=1;$result=$module->save();
  if(!in_array(getLastOperationStatus($result),array('OK','NO_CHANGE'),true))bootstrapFail("could not enable acceptance module $moduleName",79);
}
foreach(array_keys($parentModuleIds) as $parentModuleId){
  $parent=new Module((int)$parentModuleId);
  if(!$parent->id)bootstrapFail("acceptance parent module $parentModuleId is missing",80);
  $parent->active=1;$result=$parent->save();
  if(!in_array(getLastOperationStatus($result),array('OK','NO_CHANGE'),true))bootstrapFail("could not refresh acceptance parent module $parentModuleId",81);
}
foreach($acceptanceParameterValues as $parameterCode=>$parameterValue){
  Parameter::storeGlobalParameter($parameterCode,$parameterValue);
  if((string)Parameter::getGlobalParameter($parameterCode)!==$parameterValue)bootstrapFail("could not isolate disposable mail setting $parameterCode",82);
}

$identityProfiles = array('beta4-admin' => 'ADM', 'beta4-manager' => 'PL', 'beta4-member' => 'TM', 'beta4-denied' => 'G');
foreach ($identityProfiles as $name => $profileCode) {
  $profile = SqlElement::getSingleSqlElementFromCriteria('Profile', array('profileCode' => $profileCode));
  if (!$profile->id) bootstrapFail("profile $profileCode is missing", 74);
  $user = SqlElement::getSingleSqlElementFromCriteria('User', array('name' => $name));
  $expectedResource = $name === 'beta4-denied' ? 0 : 1;
  if ($user->id) {
    if ($user->email !== $name.'@beta4.invalid' || (int)$user->idProfile !== (int)$profile->id ||
        (int)$user->locked !== 0 || (int)$user->idle !== 0 ||
        (int)$user->isResource !== $expectedResource || (int)$user->isEmployee !== $expectedResource) {
      bootstrapFail("reserved identity $name already exists with unexpected attributes", 75);
    }
    continue;
  }
  $user = new User();
  $user->name = $name;
  $user->resourceName = 'Beta 4 '.ucfirst($name);
  $user->email = $name.'@beta4.invalid';
  $user->idProfile = (int)$profile->id;
  $user->locked = 0;
  $user->idle = 0;
  $user->isResource = $expectedResource;
  $user->isEmployee = $expectedResource;
  $result = $user->save();
  if (!in_array(getLastOperationStatus($result), array('OK', 'NO_CHANGE'), true)) bootstrapFail("could not provision $name", 76);
}
fwrite(STDOUT, "Beta 4 database bootstrap: V13.1.0 ready; disposable identities provisioned\n");
