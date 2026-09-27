import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import configuration from '../src/modules/configuration/index.mjs';

const root = new URL('../../', import.meta.url).pathname;
const expectedActions = [
  'configuration.user.manage', 'user.trigger_password_reset',
  'configuration.profile.manage', 'configuration.access.manage',
  'configuration.workflow.manage', 'configuration.module.set_state',
  'configuration.parameter.set', 'configuration.view.manage',
  'cron.check', 'cron.start', 'cron.stop', 'cron.restart'
];

test('Configuration pack owns its semantic actions, native handlers, classes, and jobs', () => {
  assert.equal(configuration.id, 'configuration');
  assert.equal(configuration.version, '2.0.0-beta.4');
  assert.deepEqual(configuration.dependencies, ['core']);
  assert.deepEqual(configuration.enabledStateRequirements, ['moduleConfiguration']);
  assert.deepEqual(configuration.claims.actions, expectedActions);
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
  const moduleSource = readFileSync(`${root}bridge/modules/configuration/module.php`, 'utf8');
  const semanticSource = readFileSync(`${root}bridge/modules/configuration/semantic-actions.php`, 'utf8');
  assert.doesNotMatch(moduleSource, /['"](?:password|apiKey|salt|crypto|cookieHash)['"]\s*=>/);
  assert.match(semanticSource, /secret_field_forbidden/);
  assert.match(semanticSource, /data\['locked'\]=1/);
  assert.match(moduleSource, /tool:sendRequestResetPassword/);
  assert.doesNotMatch(moduleSource, /tool:sendChangeResetPassword/);
});
