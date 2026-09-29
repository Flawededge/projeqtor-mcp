<?php
declare(strict_types=1);

function initializationFail(string $message, int $code = 1): never {
  fwrite(STDERR, "ProjeQtOr initialization failed: $message\n");
  exit($code);
}

function requiredEnvironment(string $name): string {
  $value = getenv($name);
  if (!is_string($value) || $value === '') initializationFail("$name is required", 64);
  return $value;
}

$dsn = sprintf(
  'pgsql:host=%s;port=%s;dbname=%s',
  requiredEnvironment('PROJEQTOR_DB_HOST'),
  requiredEnvironment('PROJEQTOR_DB_PORT'),
  requiredEnvironment('PROJEQTOR_DB_NAME')
);
$pdo = new PDO(
  $dsn,
  requiredEnvironment('PROJEQTOR_DB_USER'),
  requiredEnvironment('PROJEQTOR_DB_PASSWORD'),
  array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC)
);

$tables = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname='public' ORDER BY tablename")->fetchAll(PDO::FETCH_COLUMN);
$createdCompatibilityTable = false;
if (!in_array('parameter', $tables, true)) {
  $unexpected = array_values(array_diff($tables, array('mcpoperation')));
  if ($unexpected) initializationFail('refusing to initialize a non-empty, non-ProjeQtOr database', 65);
  $pdo->exec('CREATE TABLE parameter (id serial PRIMARY KEY, idUser integer NULL, idProject integer NULL, parameterCode varchar(100) NULL, parameterValue varchar(4000) NULL)');
  $createdCompatibilityTable = true;
}

$_SERVER['SCRIPT_NAME'] = '/tool/container-initialize.php';
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
$initializationStatePath = '/var/lib/projeqtor/config/initialization-state';
$initializationState = is_file($initializationStatePath)
  ? trim((string)file_get_contents($initializationStatePath))
  : '';
$freshDatabase = $currentVersion === '' || $initializationState === 'fresh-pending';
if ($currentVersion === '') {
  $written = file_put_contents($initializationStatePath, "fresh-pending\n", LOCK_EX);
  if ($written === false) initializationFail('could not persist fresh initialization state', 76);
  chmod($initializationStatePath, 0600);
}
if ($createdCompatibilityTable) {
  $count = Sql::fetchLine(Sql::query('SELECT COUNT(*) AS count FROM parameter'));
  if ($currentVersion !== '' || !$count || (int)$count['count'] !== 0) initializationFail('compatibility table is not empty', 66);
  if (!Sql::query('DROP TABLE parameter')) initializationFail('could not remove compatibility table', 67);
}

if ($currentVersion !== 'V13.1.0') {
  if (is_file('/var/www/html/files/cron/MIGRATION')) initializationFail('stale migration marker exists', 68);
  chdir('/var/www/html/db');
  ob_start();
  require '/var/www/html/db/maintenance.php';
  $maintenanceOutput = (string)ob_get_clean();
  if (($nbErrors ?? 1) !== 0) initializationFail('official maintenance runner reported migration errors', 69);
  if (!str_contains($maintenanceOutput, 'DATABASE UPDATE COMPLETED')) initializationFail('official maintenance runner did not report completion', 70);
}

$versionStatement = $pdo->query("SELECT parameterValue FROM parameter WHERE idUser IS NULL AND idProject IS NULL AND parameterCode='dbVersion'");
if ($versionStatement->fetchColumn() !== 'V13.1.0') initializationFail('database version is not V13.1.0', 71);
if (is_file('/var/www/html/files/cron/MIGRATION')) initializationFail('migration marker remains after completion', 72);

$admin = SqlElement::getSingleSqlElementFromCriteria('User', array('name' => 'admin'));
if (!$admin->id) initializationFail('official administrator is missing', 73);
setSessionUser($admin);

if ($freshDatabase) {
  $passwordPath = requiredEnvironment('PROJEQTOR_BOOTSTRAP_ADMIN_PASSWORD_FILE');
  $password = trim((string)@file_get_contents($passwordPath));
  if (strlen($password) < 20) initializationFail('bootstrap administrator password must be at least 20 characters', 74);
  $admin->salt = bin2hex(random_bytes(32));
  $admin->password = hash('sha256', $password . $admin->salt);
  $admin->crypto = 'sha256';
  $admin->passwordChangeDate = date('Y-m-d');
  $admin->mustChangePassword = 0;
  $admin->locked = 0;
  $admin->idle = 0;
  $admin->apiKey = bin2hex(random_bytes(32));
  $result = $admin->save();
  if (!in_array(getLastOperationStatus($result), array('OK', 'NO_CHANGE'), true)) initializationFail('could not secure the administrator', 75);
}

chdir('/var/www/html/mcp-api');
require_once '/var/www/html/mcp-api/router.php';
mcpAssertPolicyComplete();

$temporaryStatePath = $initializationStatePath . '.new';
if (file_put_contents($temporaryStatePath, "initialized\n", LOCK_EX) === false) initializationFail('could not finalize initialization state', 77);
chmod($temporaryStatePath, 0600);
if (!rename($temporaryStatePath, $initializationStatePath)) initializationFail('could not publish initialization state', 78);

fwrite(STDOUT, "ProjeQtOr V13.1.0 initialization complete\n");
