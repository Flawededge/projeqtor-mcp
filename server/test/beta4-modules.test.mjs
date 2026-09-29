import test from 'node:test';
import assert from 'node:assert/strict';
import { createProjeqtorServer } from '../src/tools.mjs';
import { MODULE_IDS, LEGACY_MODULE_ALIASES, MODULE_PACKS } from '../src/modules/index.mjs';
import { defineModule, ModuleRegistry } from '../src/modules/runtime.mjs';

function handler(server, name) {
  return server._registeredTools[name].handler;
}

test('Beta 4 loads core plus all twelve independently owned business modules', () => {
  assert.equal(MODULE_IDS.length, 13);
  assert.deepEqual(MODULE_IDS, [
    'core', 'planning', 'ticketing', 'scrum', 'follow_up', 'steering', 'financial',
    'products', 'hr', 'environment', 'tools', 'reports', 'configuration'
  ]);
  assert.deepEqual(Object.keys(LEGACY_MODULE_ALIASES), [
    'planning_followup_environment', 'ticketing_scrum', 'steering_reports',
    'financial_products', 'hr_tools_configuration'
  ]);
  assert.equal(MODULE_PACKS.length, 13);
  assert.deepEqual(new Set(MODULE_PACKS.map(module => module.id)), new Set(MODULE_IDS));
});

test('Beta 4 preserves 31 tools and adds exactly five convenience tools', () => {
  const server = createProjeqtorServer({ username: 'tester', apiRequest: async () => ({ items: [] }) });
  const names = Object.keys(server._registeredTools);
  assert.equal(names.length, 36);
  assert.deepEqual(names.slice(-5), [
    'projeqtor_plan_projects',
    'projeqtor_render_report',
    'projeqtor_manage_sprint',
    'projeqtor_manage_ticket',
    'projeqtor_record_work'
  ]);
  assert.deepEqual(Object.keys(server._registeredResourceTemplates), [
    'projeqtor-attachment', 'projeqtor-document-version', 'projeqtor-job-result'
  ]);
});

test('convenience tools only delegate typed inputs to canonical action execution', async () => {
  const calls = [];
  const server = createProjeqtorServer({
    username: 'ChrisD',
    apiRequest: async (...args) => {
      calls.push(args);
      return { ok: true, queued: true, job: { id: calls.length } };
    }
  });

  await handler(server, 'projeqtor_plan_projects')({
    projectIds: [2], criticalPath: true, allowOverbooking: false,
    criticalResourceMode: true, includeDiagnostics: true, idempotencyKey: 'plan:2'
  });
  await handler(server, 'projeqtor_record_work')({
    entries: [{ resourceId: 4, workDate: '2026-09-27', refType: 'Activity', refId: 8, work: 0.5 }],
    transactionMode: 'atomic', submitPeriod: false, idempotencyKey: 'work:1'
  });
  await handler(server, 'projeqtor_manage_ticket')({
    operations: [{ ticketId: 9, operation: 'transition', statusId: 3, expectedVersion: 'v1' }],
    transactionMode: 'atomic', idempotencyKey: 'ticket:9'
  });
  await handler(server, 'projeqtor_manage_sprint')({
    operations: [{ operation: 'start_sprint', sprintId: 11, expectedVersion: 'v2' }],
    transactionMode: 'atomic', idempotencyKey: 'sprint:11'
  });
  await handler(server, 'projeqtor_render_report')({
    idReport: 12, format: 'pdf', parameters: { idProject: 2 }, idempotencyKey: 'report:12'
  });

  assert.deepEqual(calls.map(call => call.slice(0, 3)), Array(5).fill(['__mcp/v2/actions/execute', 'ChrisD', 'POST']));
  assert.deepEqual(calls.map(call => call[3].action), [
    'planning.calculate', 'follow_up.work.record', 'ticketing.ticket.manage',
    'scrum.sprint.manage', 'reports.render'
  ]);
  for (const call of calls) {
    assert.deepEqual(call[4], { allowItemErrors: true });
    assert.equal(Object.hasOwn(call[3].arguments, 'idempotencyKey'), false);
    assert.equal(typeof call[3].idempotencyKey, 'string');
  }
});

test('convenience tool schemas enforce bounded typed requests', () => {
  const server = createProjeqtorServer({ username: 'tester', apiRequest: async () => ({ ok: true }) });
  const planSchema = server._registeredTools.projeqtor_plan_projects.inputSchema;
  assert.equal(planSchema.safeParse({ projectIds: [1], includeDiagnostics: true }).success, true);
  assert.equal(planSchema.safeParse({ projectIds: [] }).success, false);
  assert.equal(planSchema.safeParse({ projectIds: Array.from({ length: 201 }, (_, index) => index + 1) }).success, false);
  const workSchema = server._registeredTools.projeqtor_record_work.inputSchema;
  assert.equal(workSchema.safeParse({ entries: [{
    resourceId: 1, workDate: '2026-09-27', refType: 'Activity', refId: 2, work: 0.5
  }] }).success, true);
  assert.equal(workSchema.safeParse({ entries: [{
    resourceId: 1, workDate: 'bad-date', refType: 'Activity', refId: 2, work: 0.5
  }] }).success, false);
});

test('discovery forwards Beta 4 filters and legacy module aliases unchanged', async () => {
  const calls = [];
  const server = createProjeqtorServer({
    username: 'Peet',
    apiRequest: async (...args) => {
      calls.push(args);
      return { ok: true, items: [] };
    }
  });
  await handler(server, 'projeqtor_list_actions')({
    module: 'reports', search: 'render', risk: 'read', availability: 'all',
    cursor: 'signed', pageSize: 25, includeTotal: true, availableOnly: false
  });
  await handler(server, 'projeqtor_list_ui_handlers')({
    module: 'planning_followup_environment', classification: 'registered_action',
    cursor: 'signed-handler', pageSize: 30, includeTotal: true
  });
  assert.equal(calls[0][0], '__mcp/v2/actions');
  assert.equal(calls[0][3].module, 'reports');
  assert.equal(calls[0][3].includeTotal, true);
  assert.equal(calls[1][0], '__mcp/v2/ui-handlers');
  assert.equal(calls[1][3].module, 'planning_followup_environment');
});

test('capabilities expose deterministic module versions and claims', async () => {
  const server = createProjeqtorServer({
    username: 'tester',
    apiRequest: async path => {
      if (path === '__mcp/v2/whoami') return { username: 'tester' };
      if (path === '__mcp/v2/classes') return { policyVersion: 4, inventory: {} };
      if (path === '__mcp/v2/actions') return { items: [] };
      throw new Error('Unexpected call ' + path);
    }
  });
  const response = await handler(server, 'projeqtor_get_capabilities')({});
  assert.equal(response.structuredContent.schemaVersion, 4);
  assert.equal(response.structuredContent.license.spdx, 'AGPL-3.0-or-later');
  assert.equal(
    response.structuredContent.license.correspondingSourceUrl,
    'https://github.com/Flawededge/projeqtor-mcp'
  );
  assert.equal(response.structuredContent.license.upstream.name, 'ProjeQtOr');
  assert.equal(response.structuredContent.license.upstream.license, 'AGPL-3.0-or-later');
  assert.equal(response.structuredContent.modules.length, 13);
  assert.deepEqual(new Set(response.structuredContent.modules.map(module => module.id)), new Set(MODULE_IDS));
  assert.equal(response.structuredContent.modules.reduce((total, module) => total + module.actionCount, 0), 212);
  const planning = response.structuredContent.modules.find(module => module.id === 'planning');
  assert.equal(planning.version, '2.0.0-beta.4');
  assert.equal(planning.enabled, true);
  assert.equal(planning.actionCount, 21);
  assert.match(planning.coverageHash, /^[a-f0-9]{64}$/);
  assert.deepEqual(planning.enabledStateRequirements, []);
});

test('module registry rejects duplicate ownership before executing any registrar', () => {
  const server = {
    _registeredTools: {}, _registeredResources: {},
    registerTool() {}, registerResource() {}
  };
  const first = defineModule({
    id: 'alpha', version: '1', dependencies: [], claims: { actions: ['shared.action'] }, register() {}
  });
  const second = defineModule({
    id: 'beta', version: '1', dependencies: [], claims: { actions: ['shared.action'] }, register() {}
  });
  assert.throws(() => new ModuleRegistry(server).registerModules([first, second], {}), /Duplicate action/);
  assert.throws(() => new ModuleRegistry(server).registerModules([first, first], {}), /Duplicate module/);
});

test('module registry rejects missing dependencies, cycles, and legacy tool collisions', () => {
  const server = {
    _registeredTools: { existing: {} }, _registeredResources: {},
    registerTool() {}, registerResource() {}
  };
  const missing = defineModule({ id: 'missing', version: '1', dependencies: ['absent'], claims: {}, register() {} });
  assert.throws(() => new ModuleRegistry(server).registerModules([missing], {}), /missing module/);
  const left = defineModule({ id: 'left', version: '1', dependencies: ['right'], claims: {}, register() {} });
  const right = defineModule({ id: 'right', version: '1', dependencies: ['left'], claims: {}, register() {} });
  assert.throws(() => new ModuleRegistry(server).registerModules([left, right], {}), /dependency cycle/);
  const collision = defineModule({
    id: 'collision', version: '1', dependencies: [], claims: { tools: ['existing'] }, register() {}
  });
  assert.throws(() => new ModuleRegistry(server).registerModules([collision], {}), /Duplicate tool/);
});

test('module registrars cannot register undeclared tools', () => {
  const server = {
    _registeredTools: {}, _registeredResources: {},
    registerTool() {}, registerResource() {}
  };
  const invalid = defineModule({
    id: 'invalid', version: '1', dependencies: [], claims: {},
    register(registrar) { registrar.registerTool('surprise', {}, () => {}); }
  });
  assert.throws(() => new ModuleRegistry(server).registerModules([invalid], {}), /unclaimed tool/);
});
