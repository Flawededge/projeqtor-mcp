import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = dirname(dirname(dirname(fileURLToPath(import.meta.url))));
const registryPath = join(root, 'bridge/core/module-registry.php');
const graphPath = join(root, 'bridge/core/module-graph.php');
const domainsPath = join(root, 'bridge/core/action-domains.php');
const channelPath = join(root, 'bridge/core/worker-artifact-channel.php');
const workerPath = join(root, 'worker/mcp-worker.php');
const metadataPath = join(root, 'bridge/core/action-metadata.php');
const discoveryPath = join(root, 'bridge/core/action-discovery.php');
const php = source => execFileSync('php', ['-r', source], { encoding: 'utf8' });
const requirePhp = path => `require ${JSON.stringify(path)};`;

function reservedSchemaFields(schema, found = []) {
  if (!schema || typeof schema !== 'object') return found;
  if (schema.properties && typeof schema.properties === 'object') {
    for (const field of Object.keys(schema.properties)) {
      if (['path', 'resultPath', 'result_path', 'artifactPath', 'artifact_path', 'filePath', 'file_path', 'filesystemPath', 'filesystem_path', 'absolutePath', 'absolute_path'].includes(field)) found.push(field);
    }
  }
  for (const value of Object.values(schema)) reservedSchemaFields(value, found);
  return found;
}

test('every async module publishes a result schema without worker filesystem fields', () => {
  const script = [
    requirePhp(graphPath), requirePhp(domainsPath), requirePhp(registryPath),
    '$items=array();foreach(mcpActionRegistry() as $id=>$action)if(!empty($action["async"]))$items[$id]=array("internal"=>$action["resultSchema"],"public"=>mcpPublicActionResultSchema($action["resultSchema"]));',
    'echo json_encode($items,JSON_THROW_ON_ERROR);'
  ].join('');
  const actions = JSON.parse(php(script));
  assert.equal(Object.keys(actions).length, 28);
  assert.deepEqual(Object.entries(actions).filter(([, schemas]) => reservedSchemaFields(schemas.internal).length).map(([id]) => id), [
    'tools.document.extract', 'export.start', 'hr.leave.calendar.export'
  ]);
  for (const [id, schemas] of Object.entries(actions)) {
    assert.deepEqual(reservedSchemaFields(schemas.public), [], id);
  }

  assert.match(readFileSync(metadataPath, 'utf8'), /mcpPublicActionResultSchema/);
  assert.match(readFileSync(discoveryPath, 'utf8'), /mcpPublicActionResultSchema/);
});

test('public schema and result sanitizers remove reserved fields recursively only', () => {
  const script = [
    requirePhp(registryPath),
    '$schema=mcpObjectSchema(array("path"=>array("type"=>"string"),"criticalPath"=>array("type"=>"boolean"),"items"=>array("type"=>"array","items"=>mcpObjectSchema(array("result_path"=>array("type"=>"string"),"name"=>array("type"=>"string")),array("result_path","name"),false))),array("path","criticalPath","items"),false);',
    '$result=array("path"=>"/private/root","criticalPath"=>true,"items"=>array(array("resultPath"=>"/private/nested","name"=>"kept")));',
    'echo json_encode(array("schema"=>mcpPublicActionResultSchema($schema),"result"=>mcpPublicAsyncActionResult($result)),JSON_THROW_ON_ERROR);'
  ].join('');
  const value = JSON.parse(php(script));
  assert.deepEqual(value.schema.required, ['criticalPath', 'items']);
  assert.deepEqual(value.schema.properties.items.items.required, ['name']);
  assert.equal(value.schema.properties.criticalPath.type, 'boolean');
  assert.deepEqual(value.result, { criticalPath: true, items: [{ name: 'kept' }] });
});

test('artifact channel supports legacy and schema-clean workers while persisting result_path', () => {
  const script = [
    '$root=sys_get_temp_dir()."/mcp-artifact-channel-".bin2hex(random_bytes(5));define("MCP_WORKER_ARTIFACT_ROOT",$root);',
    requirePhp(registryPath), requirePhp(channelPath),
    '$legacyPath=null;$reportPath=null;try{',
    'workerArtifactChannelBegin(101);$legacyPath=workerArtifactPath(101,"csv");file_put_contents($legacyPath,"a,b\\n1,2\\n");$legacy=workerArtifactChannelFinish(101,array("ok"=>true,"path"=>$legacyPath,"nested"=>array("result_path"=>"hidden")));',
    'workerArtifactChannelBegin(102);$reportPath=workerArtifactPath(102,"pdf");file_put_contents($reportPath,"%PDF-1.7\\n");$report=workerArtifactChannelFinish(102,array("ok"=>true,"resource"=>"projeqtor://jobs/102/result"));',
    'workerArtifactChannelBegin(103);$plain=workerArtifactChannelFinish(103,array("ok"=>true));',
    'echo json_encode(array("legacy"=>$legacy,"report"=>$report,"plain"=>$plain),JSON_THROW_ON_ERROR);',
    '}finally{if($legacyPath&&is_file($legacyPath))unlink($legacyPath);if($reportPath&&is_file($reportPath))unlink($reportPath);if(is_dir($root))rmdir($root);}'
  ].join('');
  const value = JSON.parse(php(script));
  assert.equal(value.legacy.resultPath.endsWith('/job-101.csv'), true);
  assert.deepEqual(value.legacy.result, { ok: true, nested: [] });
  assert.equal(value.report.resultPath.endsWith('/job-102.pdf'), true);
  assert.deepEqual(value.report.result, { ok: true, resource: 'projeqtor://jobs/102/result' });
  assert.equal(value.plain.resultPath, null);
});

test('durable worker clears ProjeQtOr authorization and reference caches before each actor job', () => {
  const worker = readFileSync(workerPath, 'utf8');
  assert.match(worker, /function workerResetRequestCaches\(\): void/);
  assert.match(worker, /SqlList::cleanAllLists\(\)/);
  assert.match(worker, /foreach\(array_keys\(SqlElement::\$_cachedQuery\) as \$class\)SqlElement::\$_cachedQuery\[\$class\]=array\(\)/);
  assert.match(worker, /workerResetRequestCaches\(\);\$user=SqlElement::getSingleSqlElementFromCriteria/);
});

test('durable worker removes all incomplete report staging files after failure and staleness', () => {
  const worker = readFileSync(workerPath, 'utf8');
  assert.match(worker, /function workerCleanupJobTemporary\(int \$jobId\): void/);
  assert.match(worker, /'job-'\.\$jobId\.'\.\*\.render-\*'/);
  assert.match(worker, /'job-'\.\$jobId\.'\.\*\.capture-\*'/);
  assert.match(worker, /workerCleanupStaleTemporary\(\)/);
  assert.match(worker, /workerCleanupJobTemporary\(\(int\)\$row\['id'\]\)/);
});

test('artifact channel fails closed on traversal, mismatches, missing files, and multiple artifacts', () => {
  const script = [
    '$root=sys_get_temp_dir()."/mcp-artifact-channel-".bin2hex(random_bytes(5));define("MCP_WORKER_ARTIFACT_ROOT",$root);',
    requirePhp(registryPath), requirePhp(channelPath),
    '$errors=array();$capture=function(string $name,callable $callback)use(&$errors):void{try{$callback();$errors[$name]=null;}catch(Throwable $error){$errors[$name]=$error->getMessage();}};',
    '$capture("unregistered",function(){workerArtifactChannelBegin(201);workerArtifactChannelFinish(201,array("ok"=>true,"path"=>"/etc/passwd"));});',
    '$capture("traversal",function(){workerArtifactChannelBegin(202);try{workerArtifactPath(202,"../csv");}finally{workerArtifactChannelAbort(202);}});',
    '$capture("missing",function(){workerArtifactChannelBegin(203);workerArtifactPath(203,"json");workerArtifactChannelFinish(203,array("ok"=>true));});',
    '$capture("multiple",function(){workerArtifactChannelBegin(204);try{workerArtifactPath(204,"csv");workerArtifactPath(204,"json");}finally{workerArtifactChannelAbort(204);}});',
    'if(is_dir($root))rmdir($root);echo json_encode($errors,JSON_THROW_ON_ERROR);'
  ].join('');
  const errors = JSON.parse(php(script));
  assert.match(errors.unregistered, /unregistered artifact path/);
  assert.match(errors.traversal, /extension is invalid/);
  assert.match(errors.missing, /was not published/);
  assert.match(errors.multiple, /only one artifact/);

  const worker = readFileSync(workerPath, 'utf8');
  assert.match(worker, /workerArtifactChannelFinish\(\$id,mcpValidateActionResult/);
  assert.match(worker, /workerUpdate\([^\n]+\$output\['result'\],\$output\['resultPath'\]/);
  assert.match(worker, /\$GLOBALS\['mcpCaptureErrors'\]=true/);
  assert.match(worker, /finally\{\$GLOBALS\['mcpCaptureErrors'\]=\$previousCapture;\}/);
  assert.match(worker, /\$error instanceof McpBridgeException\?\$error->errorCode/);
  assert.doesNotMatch(worker, /\$path=\$output\['path'\]/);
});
