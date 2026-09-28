import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import configuration from '../src/modules/configuration/index.mjs';

const root = new URL('../../', import.meta.url).pathname;
const presentationActions = [
  'configuration.user.manage', 'user.trigger_password_reset',
  'configuration.profile.manage', 'configuration.access.manage',
  'configuration.workflow.manage', 'configuration.module.set_state',
  'configuration.parameter.set', 'configuration.view.manage',
  'cron.check', 'cron.start', 'cron.stop', 'cron.restart'
];
const expectedActions = [
  'configuration.admin.execute', ...presentationActions.slice(0, 8),
  'configuration.maintenance.run', 'configuration.consistency.check',
  'configuration.consistency.repair', 'configuration.deferred_updates.execute',
  'configuration.plugin_update.notify', 'cron.configure', ...presentationActions.slice(8)
];

test('Configuration pack owns its semantic actions, native handlers, classes, and jobs', () => {
  assert.equal(configuration.id, 'configuration');
  assert.equal(configuration.version, '2.0.0-beta.4');
  assert.deepEqual(configuration.dependencies, ['core']);
  assert.deepEqual(configuration.enabledStateRequirements, ['moduleConfiguration']);
  assert.deepEqual(configuration.claims.actions, presentationActions);
  assert.deepEqual(configuration.claims.jobs, ['configuration.module.set_state', 'cron.start', 'cron.restart']);
  assert.ok(configuration.claims.classes.includes('User'));
  assert.ok(configuration.claims.classes.includes('WorkflowStatus'));
  assert.ok(configuration.claims.handlers.includes('tool:saveModuleStatus'));
  assert.ok(configuration.claims.handlers.includes('tool:saveFilter'));
  assert.equal(configuration.claims.tools.length, 0);
});

test('Configuration PHP descriptors are exact, guarded, bounded, and worker-complete', () => {
  const php = `
    require '${root}bridge/core/module-graph.php';
    require '${root}bridge/core/action-domains.php';
    require '${root}bridge/core/module-registry.php';
    $actions=mcpModuleCatalog()['configuration']['actions'];
    echo json_encode($actions, JSON_THROW_ON_ERROR);
  `;
  const actions = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
  assert.deepEqual(Object.keys(actions), expectedActions);
  for (const [id, action] of Object.entries(actions)) {
    assert.equal(action.module, 'configuration');
    assert.equal(action.actionVersion, '4.0.0');
    assert.equal(action.schema.additionalProperties, false, id);
    assert.equal(action.resultSchema.additionalProperties, false, id);
    assert.equal(typeof action.testContract, 'string');
    assert.ok(action.testContract.startsWith('configuration.'));
    if (action.risk !== 'read') assert.equal(action.confirmationRequired, true, id);
  }
  assert.equal(actions['configuration.user.manage'].schema.properties.operations.maxItems, 200);
  assert.equal(actions['configuration.user.manage'].resultSchema.properties.items.items.additionalProperties, false);
  assert.equal(actions['configuration.module.set_state'].async, true);
  assert.equal(actions['configuration.module.set_state'].worker, 'mcpConfigurationModuleWorker');
  assert.equal(actions['configuration.module.set_state'].retryPolicy, 'recovery_required');
  assert.equal(actions['cron.start'].retryPolicy, 'safe');
  assert.equal(actions['cron.restart'].retryPolicy, 'safe');
  const admin = actions['configuration.admin.execute'];
  assert.equal(admin.schema.oneOf.length, 9);
  assert.equal(admin.resultSchema.oneOf.length, 9);
  assert.deepEqual(admin.schema.oneOf.map(variant => variant.properties.branch.const), [
    'send_alert', 'maintenance', 'rebuild_reference', 'terminate_sessions', 'set_parameters',
    'check_consistency', 'repair_consistency', 'renumber_wbs', 'execute_deferred_updates'
  ]);
  assert.ok(admin.schema.oneOf.every(variant => variant.additionalProperties === false && variant.properties.arguments.additionalProperties === false));
  assert.ok(admin.resultSchema.oneOf.every(variant => variant.additionalProperties === false));
  assert.deepEqual(admin.delegates, {
    send_alert: 'tools.alert.send',
    maintenance: 'configuration.maintenance.run',
    rebuild_reference: 'core.reference.rebuild',
    terminate_sessions: 'user.session.terminate',
    set_parameters: 'configuration.parameter.set',
    check_consistency: 'configuration.consistency.check',
    repair_consistency: 'configuration.consistency.repair',
    renumber_wbs: 'planning.wbs.renumber',
    execute_deferred_updates: 'configuration.deferred_updates.execute'
  });
  assert.deepEqual(actions['cron.configure'].mappedHandlers, ['tool:cronExecutionStandard']);
  assert.deepEqual(actions['configuration.plugin_update.notify'].mappedHandlers, ['view:menuNewGuiLeft']);
});

test('Configuration rejects secret and infrastructure parameters without echoing values', () => {
  const semantic = `${root}bridge/modules/configuration/semantic-actions.php`;
  const run = expression => execFileSync('php', ['-r', `
    class TestMcpError extends Exception { public string $errorCode; function __construct($code){parent::__construct($code);$this->errorCode=$code;} }
    function mcpJsonError(int $status,string $code,string $message,array $details=array()): never { throw new TestMcpError($code); }
    require '${semantic}';
    try { ${expression}; echo 'accepted'; } catch(TestMcpError $error) { echo $error->errorCode; }
  `], { encoding: 'utf8' });
  assert.equal(run("mcpConfigurationSafeParameterCode('smtpPassword')"), 'secret_parameter_forbidden');
  assert.equal(run("mcpConfigurationSafeParameterCode('pluginUpdateChannel')"), 'infrastructure_parameter_forbidden');
  assert.equal(run("mcpConfigurationRejectSensitive(array('apiKey'=>'must-not-be-echoed'))"), 'secret_field_forbidden');
  assert.equal(run("mcpConfigurationSafeParameterCode('displayTheme')"), 'accepted');
});

test('Configuration user schema never accepts direct credentials', () => {
  const moduleSource = readFileSync(`${root}bridge/modules/configuration/descriptor.php`, 'utf8');
  const semanticSource = readFileSync(`${root}bridge/modules/configuration/semantic-actions.php`, 'utf8');
  assert.doesNotMatch(moduleSource, /['"](?:password|apiKey|salt|crypto|cookieHash)['"]\s*=>/);
  assert.match(semanticSource, /secret_field_forbidden/);
  assert.match(semanticSource, /data\['locked'\]=1/);
  assert.match(moduleSource, /tool:sendRequestResetPassword/);
  assert.doesNotMatch(moduleSource, /tool:sendChangeResetPassword/);
});

test('Configuration dispatches every admin branch through late-bound public actions', () => {
  const php = `
    class TestMcpError extends Exception { public string $errorCode; function __construct($code){parent::__construct($code);$this->errorCode=$code;} }
    function mcpJsonError(int $status,string $code,string $message,array $details=array()): never { throw new TestMcpError($code); }
    function securityGetAccessRightYesNo($menu,$right){return 'YES';}
    require '${root}bridge/core/module-graph.php';
    require '${root}bridge/core/action-domains.php';
    require '${root}bridge/core/module-registry.php';
    $module=require '${root}bridge/modules/configuration/module.php';
    $calls=array();
    $GLOBALS['mcpConfigurationPublicActionInvoker']=function($action,$arguments,$username)use(&$calls){$calls[]=array('action'=>$action,'arguments'=>$arguments);return array('ok'=>true);};
    $cases=array(
      'send_alert'=>array('audience'=>'users','userIds'=>array(2),'alertType'=>'INFO','scheduledAt'=>'2026-09-27 12:00','title'=>'Title','message'=>'Message','notificationTypeId'=>1),
      'maintenance'=>array('operation'=>'delete','item'=>'Mail','olderThanDays'=>30),
      'rebuild_reference'=>array(),'terminate_sessions'=>array('scope'=>'all'),'set_parameters'=>array('parameters'=>array(array('code'=>'displayTheme','value'=>'dark','scope'=>'global'))),
      'check_consistency'=>array(),'repair_consistency'=>array(),'renumber_wbs'=>array(),'execute_deferred_updates'=>array()
    );
    $results=array();foreach($cases as $branch=>$arguments){$result=mcpConfigurationAdminExecute(array('branch'=>$branch,'arguments'=>$arguments),'admin','configuration.admin.execute');$results[]=mcpValidateActionResult('configuration.admin.execute',$result);}
    try{mcpConfigurationValidateAdminBranch(array('branch'=>'renumber_wbs','arguments'=>array('unexpected'=>true)));$invalid='accepted';}catch(TestMcpError $error){$invalid=$error->errorCode;}
    echo json_encode(array('calls'=>$calls,'results'=>$results,'invalid'=>$invalid),JSON_THROW_ON_ERROR);
  `;
  const value = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
  assert.deepEqual(value.calls.map(call => call.action), [
    'tools.alert.send', 'configuration.maintenance.run', 'core.reference.rebuild', 'user.session.terminate', 'configuration.parameter.set',
    'configuration.consistency.check', 'configuration.consistency.repair', 'planning.wbs.renumber', 'configuration.deferred_updates.execute'
  ]);
  assert.ok(value.results.every(result => result.status === 'completed' && result.queued === false));
  assert.equal(value.invalid, 'action_validation_failed');
});

test('Cron activation reuses start and stop, and plugin updates only enqueue notifications', () => {
  const php = `
    function mcpJsonError(int $status,string $code,string $message,array $details=array()): never { throw new Exception($code); }
    function securityGetAccessRightYesNo($menu,$right){return 'YES';}
    require '${root}bridge/core/module-graph.php';
    require '${root}bridge/core/action-domains.php';
    require '${root}bridge/core/module-registry.php';
    $module=require '${root}bridge/modules/configuration/module.php';
    $calls=array();
    $GLOBALS['mcpConfigurationPublicActionInvoker']=function($action,$arguments,$username)use(&$calls){$calls[]=array('action'=>$action,'arguments'=>$arguments);return array('ok'=>true,'queued'=>true,'job'=>array('id'=>41,'resultResource'=>'projeqtor://jobs/41/result'));};
    $start=mcpValidateActionResult('cron.configure',mcpConfigurationCronConfigure(array('operation'=>'activate','active'=>true),'admin','cron.configure'));
    $stop=mcpValidateActionResult('cron.configure',mcpConfigurationCronConfigure(array('operation'=>'activate','active'=>false),'admin','cron.configure'));
    $plugin=mcpValidateActionResult('configuration.plugin_update.notify',mcpConfigurationPluginUpdateNotify(array('pluginId'=>'sample','pluginName'=>'Sample','installedVersion'=>'1.0.0','availableVersion'=>'1.1.0','recipientUserId'=>2,'notificationTypeId'=>1),'admin','configuration.plugin_update.notify'));
    echo json_encode(array('calls'=>$calls,'start'=>$start,'stop'=>$stop,'plugin'=>$plugin),JSON_THROW_ON_ERROR);
  `;
  const value = JSON.parse(execFileSync('php', ['-r', php], { encoding: 'utf8' }));
  assert.deepEqual(value.calls.map(call => call.action), ['cron.start', 'cron.stop', 'tools.notification.send']);
  assert.equal(value.start.status, 'starting');
  assert.equal(value.stop.status, 'stopping');
  assert.equal(value.plugin.notificationAction, 'tools.notification.send');
  assert.equal(value.plugin.status, 'queued');
  const source = readFileSync(`${root}bridge/modules/configuration/administrative-actions.php`, 'utf8');
  assert.doesNotMatch(source, /installRevisionUpdate|downloadRevisionFiles|uploadPlugin|uninstallPlugin|file_get_contents\s*\(/i);
});
