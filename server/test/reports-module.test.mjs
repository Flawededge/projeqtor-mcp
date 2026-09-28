import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import reports from '../src/modules/reports/index.mjs';
import { REPORTS_ACTIONS, REPORTS_HANDLERS, REPORTS_JOBS, REPORTS_WORKFLOW_FAMILIES } from '../src/modules/reports/contracts.mjs';
import { createProjeqtorServer } from '../src/tools.mjs';

const serverRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const root = path.dirname(serverRoot);
const registryPath = path.join(root, 'bridge/core/module-registry.php');
const modulePath = path.join(root, 'bridge/modules/reports/module.php');
const rendererPath = path.join(root, 'bridge/modules/reports/renderer.php');
const personalPath = path.join(root, 'bridge/modules/reports/personal.php');
const schedulingPath = path.join(root, 'bridge/modules/reports/scheduling.php');
const php = expression => execFileSync('php', ['-r', expression], { encoding: 'utf8' });
const descriptor = () => JSON.parse(php('require '+JSON.stringify(registryPath)+';$m=require '+JSON.stringify(modulePath)+';echo json_encode($m,JSON_THROW_ON_ERROR);'));

test('Reports owns its semantic surface with one convenience tool', () => {
  assert.equal(reports.id, 'reports');
  assert.deepEqual(reports.dependencies, ['core']);
  assert.deepEqual(reports.claims.actions, REPORTS_ACTIONS);
  assert.deepEqual(reports.claims.handlers, REPORTS_HANDLERS);
  assert.deepEqual(reports.claims.jobs, REPORTS_JOBS);
  assert.deepEqual(REPORTS_WORKFLOW_FAMILIES, ['native-rendering', 'pdf', 'charts', 'images', 'csv', 'structured-data', 'layouts', 'favorites', 'dashboards', 'scheduled-delivery']);
  const server = createProjeqtorServer({ username: 'tester', apiRequest: async () => ({ items: [] }) });
  assert.equal(Object.keys(server._registeredTools).length, 36);
});

test('Reports bridge replaces row export with native rendering and exact schemas', () => {
  const module = descriptor();
  assert.deepEqual(Object.keys(module.actions), REPORTS_ACTIONS);
  for (const [id, action] of Object.entries(module.actions)) {
    assert.equal(action.schema.type, 'object', id);
    assert.equal(action.schema.additionalProperties, false, id);
    assert.equal(action.resultSchema.type, 'object', id);
    assert.equal(action.resultSchema.additionalProperties, false, id);
    assert.ok(action.resultSchema.required.length > 0, id);
    assert.match(action.testContract, /^reports\./, id);
    assert.equal(action.idempotency.scope, 'actor', id);
    if (['destructive', 'administrative', 'external'].includes(action.risk)) assert.equal(action.preview, 'mcpReportsPreview', id);
  }
  assert.equal(module.actions['reports.render'].retryPolicy, 'safe');
  assert.equal(module.actions['reports.delivery.send'].retryPolicy, 'recovery_required');
  assert.equal(module.actions['reports.schedule'].risk, 'external');
  for (const id of ['reports.favorite.manage', 'reports.favorite.delete', 'reports.layout.manage', 'reports.layout.delete', 'reports.dashboard.pin', 'reports.dashboard.unpin', 'reports.dashboard.configure', 'reports.schedule']) {
    assert.deepEqual(module.actions[id].schema.properties.transactionMode.enum, ['atomic', 'best_effort'], id);
    assert.ok(module.actions[id].resultSchema.required.includes('rolledBack'), id);
    assert.ok(module.actions[id].resultSchema.required.includes('transactionMode'), id);
    assert.equal(module.actions[id].resultSchema.properties.items.items.additionalProperties, false, id);
    assert.equal(module.actions[id].resultSchema.properties.items.items.properties.error.additionalProperties, false, id);
  }
  assert.equal('path' in module.actions['reports.render'].resultSchema.properties, false);
});

test('render tool accepts only bounded native formats and closed input', () => {
  const server = createProjeqtorServer({ username: 'tester', apiRequest: async () => ({ ok: true }) });
  const schema = server._registeredTools.projeqtor_render_report.inputSchema;
  for (const format of ['pdf', 'png', 'jpeg', 'csv', 'json']) assert.equal(schema.safeParse({ idReport: 1, format, parameters: { idProject: 2 } }).success, true);
  assert.equal(schema.safeParse({ idReport: 1, format: 'html' }).success, false);
  assert.equal(schema.safeParse({ idReport: 1, parameters: { page: { unsafe: true } } }).success, false);
  assert.equal(schema.safeParse({ idReport: 1, unexpected: true }).success, false);
  assert.equal(schema.safeParse({ idReport: 1, parameters: Object.fromEntries(Array.from({ length: 101 }, (_, index) => ['p'+index, index])) }).success, false);
});

test('renderer enforces permissions, safe paths, cancellation, bounds, and atomic publication', () => {
  const source = readFileSync(rendererPath, 'utf8');
  const actions = readFileSync(path.join(root, 'bridge/modules/reports/actions.php'), 'utf8');
  assert.doesNotMatch(source, /workerExport/);
  assert.match(actions, /HabilitationReport/);
  assert.match(actions, /Module::isReportActive/);
  assert.match(actions, /realpath\(\$candidate\)/);
  assert.match(actions, /jsonPlanning\.php/);
  assert.match(source, /MCP_JOB_ARTIFACT_MAX_BYTES/);
  assert.match(source, /workerCancelled\(\$jobId\)/);
  assert.match(source, /rename\(\$temporary,\$path\)/);
  assert.match(source, /finally\{if\(\$copy&&str_contains\(\$copy,'\.render-'\)\)@unlink\(\$copy\);\}/);
  assert.doesNotMatch(source, /'path'=>\$path/);
  assert.match(source, /%PDF-/);
  assert.match(source, /\\x89PNG/);
  assert.match(source, /recipient_not_actor/);
});

test('Reports batches isolate best-effort failures and report atomic rollback', () => {
  const actions = readFileSync(path.join(root, 'bridge/modules/reports/actions.php'), 'utf8');
  const personal = readFileSync(personalPath, 'utf8');
  const scheduling = readFileSync(schedulingPath, 'utf8');
  const mutations = personal + scheduling;
  assert.match(actions, /function mcpReportsBestEffort/);
  assert.match(actions, /\['status'\]='rolled_back'/);
  assert.match(mutations, /Sql::beginTransaction\(\)/);
  assert.match(mutations, /Sql::rollbackTransaction\(\)/);
  assert.match(personal, /mcpReportsBestEffort\(\$arguments,\$username,\$actionId,__FUNCTION__\)/);
  assert.match(personal, /function mcpReportsDashboardConfigureAction/);
  assert.match(personal, /mcpReportsRequireOwned/);
  assert.match(personal, /mcpRequireClassOperation/);
  assert.match(personal, /periodDays.*periodNotSet.*todayRefreshDelay.*todayScrollDelay/s);
  assert.match(scheduling, /mcpReportsBestEffort\(\$arguments,\$username,\$actionId,__FUNCTION__\)/);
});

test('saved report layouts are runnable only by the actor profile and clean up their native records', () => {
  const source = readFileSync(personalPath, 'utf8');
  assert.match(source, /function mcpReportsEnsureLayoutReport/);
  assert.match(source, /function mcpReportsDeleteLayoutRelations/);
  assert.match(source, /new HabilitationReport\(\)/);
  assert.match(source, /\$right->idProfile=\(int\)\$user->idProfile/);
  assert.match(source, /reportObjectList\.php\?reportLayoutId=/);
  assert.doesNotMatch(source, /getSqlElementsFromCriteria\(array\('idle'=>'0'\)\)/);
  assert.doesNotMatch(source, /_skipRightControl/);
});

test('artifact signatures and structured content are executable contracts', () => {
  const script = [
    'class Report { public $id=7; public $name="Capacity"; }',
    'function mcpReportsError($code,$message): never { throw new RuntimeException($code); }',
    'require '+JSON.stringify(rendererPath)+';',
    '$dir=sys_get_temp_dir()."/mcp-report-test-".bin2hex(random_bytes(4));mkdir($dir);',
    '$files=array("pdf"=>"%PDF-1.7\\n","png"=>"\\x89PNG\\r\\n\\x1a\\nabc","jpeg"=>"\\xff\\xd8\\xffabc","csv"=>"a,b\\n1,2\\n","json"=>"{\\"ok\\":true}");',
    '$valid=array();foreach($files as $format=>$bytes){$path=$dir."/".$format;file_put_contents($path,$bytes);mcpReportsValidateSignature($format,$path);$valid[]=$format;}',
    '$invalid=false;$bad=$dir."/bad";file_put_contents($bad,"not-pdf");try{mcpReportsValidateSignature("pdf",$bad);}catch(Throwable $e){$invalid=true;}',
    '$structured=json_decode(mcpReportsStructured("<table><tr><th>A</th></tr><tr><td>1</td></tr></table>",new Report(),array("idProject"=>2)),true);',
    'foreach(array_keys($files) as $name)unlink($dir."/".$name);unlink($bad);rmdir($dir);',
    'echo json_encode(array("valid"=>$valid,"invalid"=>$invalid,"structured"=>$structured),JSON_THROW_ON_ERROR);'
  ].join('');
  const result = JSON.parse(php(script));
  assert.deepEqual(result.valid, ['pdf', 'png', 'jpeg', 'csv', 'json']);
  assert.equal(result.invalid, true);
  assert.equal(result.structured.report.id, 7);
  assert.match(result.structured.parametersHash, /^[a-f0-9]{64}$/);
  assert.ok(result.structured.text.includes('A'));
});

test('Reports policy resolves known ownership collisions explicitly', () => {
  const policy = JSON.parse(readFileSync(path.join(root, 'policy/modules/reports.json'), 'utf8'));
  assert.ok(policy.ownedClasses.includes('Favorite'));
  assert.ok(policy.ownedClasses.includes('TodayParameter'));
  assert.equal(policy.handlerMappings['tool/saveReportFavoriteOrder.php'].action, 'reports.favorite.manage');
  assert.equal(policy.handlerMappings['tool/saveCustomTodayMenuOrder.php'].action, 'reports.dashboard.configure');
  assert.equal(policy.handlerMappings['tool/saveTodayParameters.php'].action, 'reports.dashboard.configure');
  assert.equal(policy.handlerMappings['tool/saveTodayParametersSwitch.php'].action, 'reports.dashboard.configure');
  assert.equal(policy.handlerHandoffs['view/dashboardEmployeeManager.php'], 'hr');
  assert.equal(policy.handlerHandoffs['view/dashboardTicketMain.php'], 'configuration');
});
