<?php
declare(strict_types=1);

function denyMcpRequest(int $status, string $message): never {
  http_response_code($status);
  header('Content-Type: application/json; charset=UTF-8');
  header('Cache-Control: no-store');
  echo json_encode(array('error' => $message));
  exit;
}

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? '');
if (!in_array($method, array('GET', 'PUT', 'POST'), true)) {
  header('Allow: GET, PUT, POST');
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
if (strlen($body) > 1048576) {
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
$bodyDigest = hash('sha256', $body);
$expected = hash_hmac('sha256', $timestamp . "\n" . $username . "\n" . $method . "\n" . $bodyDigest, $key);
if (!hash_equals($expected, $signature)) {
  denyMcpRequest(401, 'Invalid internal authentication');
}

$_SERVER['PHP_AUTH_USER'] = $username;
$_SERVER['REMOTE_USER'] = $username;

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
