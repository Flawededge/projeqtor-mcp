<?php
declare(strict_types=1);

if (ob_get_level() === 0) ob_start();

function denyMcpRequest(int $status, string $message): never {
  if (ob_get_level() > 0) ob_clean();
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  echo json_encode(array('error' => $message));
  exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, array('GET', 'PUT', 'POST', 'DELETE'), true)) {
  header('Allow: GET, PUT, POST, DELETE');
  denyMcpRequest(405, 'Method not allowed');
}

$trustedAddress = getenv('PROJEQTOR_MCP_TRUSTED_IP');
if (!is_string($trustedAddress) || $trustedAddress === '') {
  denyMcpRequest(500, 'Trusted MCP address is not configured');
}
if (($_SERVER['REMOTE_ADDR'] ?? '') !== $trustedAddress) {
  denyMcpRequest(403, 'Forbidden');
}

$body = file_get_contents('php://input');
if ($body === false) {
  denyMcpRequest(400, 'Unable to read request body');
}
if (strlen($body) > 4194304) {
  denyMcpRequest(413, 'Request body is too large');
}

$username = $_SERVER['HTTP_X_PROJEQTOR_USER'] ?? '';
$timestamp = $_SERVER['HTTP_X_PROJEQTOR_TIMESTAMP'] ?? '';
$signature = $_SERVER['HTTP_X_PROJEQTOR_SIGNATURE'] ?? '';

if (!preg_match('/^[A-Za-z0-9_.@-]{1,100}$/D', $username) ||
    !preg_match('/^[0-9]{10}$/D', $timestamp) ||
    !preg_match('/^[a-f0-9]{64}$/D', $signature) ||
    abs(time() - (int)$timestamp) > 60) {
  denyMcpRequest(401, 'Invalid internal authentication');
}

$keyPath = getenv('PROJEQTOR_MCP_SIGNING_KEY_FILE');
if (!is_string($keyPath) || $keyPath === '') {
  $keyPath = '/run/secrets/projeqtor-mcp-signing-key';
}
$key = @file_get_contents($keyPath);
if ($key === false || trim($key) === '') {
  denyMcpRequest(500, 'Internal authentication is unavailable');
}
$key = trim($key);
$bridgeUri = $_REQUEST['uri'] ?? '';
$bodyDigest = hash('sha256', $body);
$expected = hash_hmac('sha256', $timestamp . "\n" . $username . "\n" . $method . "\n" . $bridgeUri . "\n" . $bodyDigest, $key);
if (!hash_equals($expected, $signature)) {
  denyMcpRequest(401, 'Invalid internal authentication');
}
if ($method === 'POST' && $bridgeUri === '__mcp/v2/oauth/provision') {
  $decoded = json_decode($body, true);
  if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) denyMcpRequest(400, 'A JSON object is required');
  $requestedUsername = $decoded['username'] ?? '';
  $displayName = trim((string)($decoded['displayName'] ?? ''));
  $email = strtolower(trim((string)($decoded['email'] ?? '')));
  $provider = $decoded['provider'] ?? '';
  if ($requestedUsername !== $username ||
      !preg_match('/^auth0-[0-9a-f]{64}$/D', $username) ||
      $provider !== 'auth0' || $displayName === '' || strlen($displayName) > 100 ||
      !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
    denyMcpRequest(400, 'Invalid OAuth provisioning request');
  }

  $transactionStarted = false;
  $oauthErrorHandlerInstalled = false;
  $provisionStage = 'bootstrap';
  try {
    $batchMode = true;
    $apiMode = true;
    $contextForAttributes = 'global';
    chdir('/var/www/html/mcp-api');
    require_once '/var/www/html/tool/projeqtor.php';
    $provisionStage = 'error-boundary';
    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
      if (!(error_reporting() & $severity)) return false;
      throw new ErrorException($message, 0, $severity, $file, $line);
    });
    $oauthErrorHandlerInstalled = true;
    $provisionStage = 'admin-lookup';
    $adminProfile = SqlElement::getSingleSqlElementFromCriteria('Profile', array('profileCode' => 'ADM'));
    $adminProbe = new User();
    $provisioners = $adminProfile->id ? $adminProbe->getSqlElementsFromCriteria(
      array('idProfile' => (int)$adminProfile->id, 'idle' => '0', 'locked' => '0'),
      false, null, 'id asc'
    ) : array();
    if (!$provisioners) throw new RuntimeException('OAuth provisioning is unavailable');
    $provisionStage = 'admin-session';
    $provisioner = $provisioners[0];
    $provisioner->_API = true;
    setSessionUser($provisioner);
    $batchMode = false;
    $provisionStage = 'transaction';
    $connection = Sql::getConnection();
    if (!$connection || !$connection->beginTransaction()) throw new RuntimeException('Could not start OAuth provisioning transaction');
    $transactionStarted = true;
    $provisionStage = 'advisory-lock';
    $lockResult = Sql::query('SELECT pg_advisory_xact_lock(hashtext('.Sql::str($username).'))');
    if (!$lockResult) throw new RuntimeException('Could not lock OAuth provisioning identity');
    $provisionStage = 'user-lookup';
    $oauthUser = SqlElement::getSingleSqlElementFromCriteria('User', array('name' => $username));
    $created = false;
    if (!$oauthUser->id) {
      $teamMemberProfile = SqlElement::getSingleSqlElementFromCriteria('Profile', array('profileCode' => 'TM'));
      if (!$teamMemberProfile->id) throw new RuntimeException('Team Member profile is unavailable');
      $oauthUser = new User();
      $oauthUser->name = $username;
      $oauthUser->resourceName = $displayName;
      $oauthUser->email = $email;
      $oauthUser->idProfile = (int)$teamMemberProfile->id;
      $oauthUser->locked = 0;
      $oauthUser->idle = 0;
      $oauthUser->isResource = 1;
      $oauthUser->isEmployee = 1;
      $oauthUser->description = 'Provisioned by Auth0 email OAuth';
      $provisionStage = 'native-save';
      $result = $oauthUser->save();
      if (!in_array(getLastOperationStatus($result), array('OK', 'NO_CHANGE'), true)) throw new RuntimeException('OAuth user save failed');
      $oauthUser = new User((int)$oauthUser->id);
      $created = true;
    }
    if (!$oauthUser->id || $oauthUser->idle || $oauthUser->locked) {
      throw new RuntimeException('oauth_user_unavailable');
    }
    if (!$created && ($oauthUser->email !== $email || $oauthUser->resourceName !== $displayName)) {
      $provisionStage = 'native-update';
      $oauthUser->email = $email;
      $oauthUser->resourceName = $displayName;
      $result = $oauthUser->save();
      if (!in_array(getLastOperationStatus($result), array('OK', 'NO_CHANGE'), true)) throw new RuntimeException('OAuth user update failed');
    }
    $provisionStage = 'commit';
    if (!$connection->commit()) throw new RuntimeException('Could not commit OAuth provisioning transaction');
    $transactionStarted = false;
    if ($oauthErrorHandlerInstalled) restore_error_handler();
  } catch (Throwable $error) {
    $unavailable = $error instanceof RuntimeException && $error->getMessage() === 'oauth_user_unavailable';
    if ($oauthErrorHandlerInstalled) restore_error_handler();
    if (!$unavailable) error_log('ProjeQtOr MCP OAuth provisioning failed at '.$provisionStage.' ('.get_class($error).')');
    if ($transactionStarted && class_exists('Sql', false)) {
      $connection = Sql::getConnection();
      if ($connection && $connection->inTransaction()) $connection->rollBack();
    }
    if ($unavailable) {
      if (ob_get_level() > 0) ob_clean();
      http_response_code(403);
      header('Content-Type: application/json; charset=UTF-8');
      header('Cache-Control: no-store');
      echo json_encode(array('error'=>array('code'=>'oauth_user_unavailable','message'=>'Mapped ProjeQtOr user is unavailable')));
      exit;
    }
    denyMcpRequest(500, 'Could not provision OAuth user');
  }
  if (ob_get_level() > 0) ob_clean();
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  echo json_encode(array('ok'=>true,'created'=>$created,'username'=>$username,'id'=>(int)$oauthUser->id));
  exit;
}

$_SERVER['PHP_AUTH_USER'] = $username;
$_SERVER['REMOTE_USER'] = $username;

if (str_starts_with($bridgeUri, '__mcp/v2/')) {
  $batchMode = true;
  $apiMode = true;
  $contextForAttributes = 'global';
  chdir('/var/www/html/mcp-api');
  require_once '/var/www/html/tool/projeqtor.php';

  $mcpUser = SqlElement::getSingleSqlElementFromCriteria('User', array('name' => $username));
  if (!$mcpUser->id || $mcpUser->idle || $mcpUser->locked) {
    denyMcpRequest(403, 'Mapped ProjeQtOr user is unavailable');
  }
  $mcpUser->_API = true;
  setSessionUser($mcpUser);
  $batchMode = false;

  require_once __DIR__ . '/router.php';
  mcpHandleV2($bridgeUri, $method, $body, $username);
}

if ($method === 'GET' && preg_match('#^__mcp/schema/([A-Za-z][A-Za-z0-9_]*)$#D', $bridgeUri, $matches)) {
  $batchMode = true;
  $apiMode = true;
  $contextForAttributes = 'global';
  chdir('/var/www/html/mcp-api');
  require_once '/var/www/html/tool/projeqtor.php';

  $mcpUser = SqlElement::getSingleSqlElementFromCriteria('User', array('name' => $username));
  if (!$mcpUser->id || $mcpUser->idle || $mcpUser->locked) {
    denyMcpRequest(403, 'Mapped ProjeQtOr user is unavailable');
  }
  $mcpUser->_API = true;
  setSessionUser($mcpUser);
  $batchMode = false;

  require_once __DIR__ . '/schema-v2.php';
  emitMcpSchemaV2($matches[1]);
}

if ($method !== 'GET') {
  $decoded = json_decode($body, true);
  if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
    denyMcpRequest(400, 'A JSON object is required');
  }

  $batchMode = true;
  $apiMode = true;
  $contextForAttributes = 'global';
  chdir('/var/www/html/mcp-api');
  require_once '/var/www/html/tool/projeqtor.php';
  require_once '/var/www/html/external/phpAES/aes.class.php';
  require_once '/var/www/html/external/phpAES/aesctr.class.php';

  $mcpUser = SqlElement::getSingleSqlElementFromCriteria('User', array('name' => $username));
  if (!$mcpUser->id || $mcpUser->idle || $mcpUser->locked || !$mcpUser->apiKey) {
    denyMcpRequest(403, 'Mapped ProjeQtOr user is unavailable for API writes');
  }

  $_REQUEST['data'] = AesCtr::encrypt(
    $body,
    $mcpUser->apiKey,
    Parameter::getGlobalParameter('aesKeyLength')
  );
}

chdir('/var/www/html/api');
require '/var/www/html/api/index.php';
