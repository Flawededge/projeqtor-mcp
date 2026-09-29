import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';

const bridgeActions = new URL('../../bridge/actions.php', import.meta.url).pathname;
const coreActions = readFileSync(new URL('../../bridge/modules/core/actions.php', import.meta.url), 'utf8');
const discovery = readFileSync(new URL('../../bridge/core/action-discovery.php', import.meta.url), 'utf8');

test('session termination requires administration or Audit update access', () => {
  assert.match(coreActions, /securityGetAccessRightYesNo\('menuAdmin','read'\)/);
  assert.match(coreActions, /securityGetAccessRightYesNo\('menuAudit','update',\$audit\)/);
  assert.doesNotMatch(coreActions, /securityGetAccessRightYesNo\('menuAudit','read'/);
  assert.doesNotMatch(coreActions, /\$self\s*=/);
});

test('guarded result persistence records returned and thrown failures', () => {
  const result = JSON.parse(execFileSync('php', ['-r', `
    class Sql { public static $queries=[]; public static function str($v){return "'".str_replace("'","''",(string)$v)."'";} public static function fmtId($v){return (string)(int)$v;} public static function query($q){self::$queries[]=$q;} }
    class McpBridgeException extends RuntimeException { public function __construct(public readonly int $httpStatus,public readonly string $errorCode,string $message,public readonly array $details=[]){parent::__construct($message);} }
    function cleanApiMessage($value){return (string)$value;}
    require ${JSON.stringify(bridgeActions)};
    $returned=mcpPersistGuardedActionResult(17,['ok'=>false,'error'=>['code'=>'validation_failed','message'=>'bad']]);
    $thrown=mcpPersistGuardedActionFailure(18,new McpBridgeException(409,'version_conflict','changed'));
    echo json_encode(['returned'=>$returned,'thrown'=>$thrown,'queries'=>Sql::$queries]);
  `], { encoding: 'utf8' }));
  assert.equal(result.returned, 'failed');
  assert.equal(result.thrown.error.code, 'version_conflict');
  assert.match(result.queries[0], /status='failed'.*error_code='validation_failed'/);
  assert.match(result.queries[1], /status='failed'.*error_code='version_conflict'/);
});

test('public metadata does not claim idempotency for confirmation-token-only actions', () => {
  const metadataPath = new URL('../../bridge/core/action-metadata.php', import.meta.url).pathname;
  const result = JSON.parse(execFileSync('php', ['-r', `
    function mcpActionAvailable($id){return true;}
    function mcpPublicActionResultSchema($schema){return $schema;}
    require ${JSON.stringify(metadataPath)};
    $base=['idempotency'=>['supported'=>true,'scope'=>'actor','sameBodyReturnsOriginal'=>true,'conflictOnDifferentBody'=>true],'risk'=>'write','async'=>false];
    echo json_encode(['write'=>mcpPublicActionMetadata('safe',$base)['idempotency'],'guarded'=>mcpPublicActionMetadata('guarded',array_merge($base,['risk'=>'destructive']))['idempotency']]);
  `], { encoding: 'utf8' }));
  assert.equal(result.write.supported, true);
  assert.deepEqual(result.guarded, { supported: false, scope: null, sameBodyReturnsOriginal: false,
    conflictOnDifferentBody: false, reason: 'guarded_confirmation_token_only' });
  assert.match(discovery, /mcpPublicActionIdempotency\(\$action\)/);
});
