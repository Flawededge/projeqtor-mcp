import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { createProjeqtorServer } from '../src/tools.mjs';
import { REFERENCE_KINDS } from '../src/domain.mjs';

function handler(server, name) {
  return server._registeredTools[name].handler;
}

test('Beta 3 policy manifests classify every pinned class and UI handler', async () => {
  const classPolicy = JSON.parse(await readFile(new URL('../../bridge/class-policy-v3.json', import.meta.url)));
  const handlerPolicy = JSON.parse(await readFile(new URL('../../bridge/ui-handler-policy-v3.json', import.meta.url)));
  const menus = JSON.parse(await readFile(new URL('../../bridge/active-menu-classes-v3.json', import.meta.url)));
  assert.equal(Object.keys(classPolicy.classes).length, 640);
  assert.equal(menus.activeUserFacingCount, 229);
  assert.equal(handlerPolicy.entrypointCount, 797);
  assert.equal(classPolicy.classes.ActivityPlanningMode.supported, true);
  assert.equal(REFERENCE_KINDS.activityPlanningMode, 'ActivityPlanningMode');
  assert.equal(classPolicy.classes.Activity.module, 'planning_followup_environment');
  assert.equal(classPolicy.classes.Project.module, 'planning_followup_environment');
  assert.equal(classPolicy.classes.Milestone.module, 'planning_followup_environment');
  assert.equal(classPolicy.classes.Dependency.module, 'planning_followup_environment');
  assert.equal(handlerPolicy.mutationCandidateCount, 331);
  assert.equal(handlerPolicy.handlers.length, 797);
  const allowed = new Set(['generic_crud', 'registered_action', 'read_only', 'intentional_exclusion', 'deferred_beta4']);
  const exclusionReasons = new Set(['raw_sql', 'secrets_or_credentials', 'plugin_installation', 'host_container_database_or_backup_administration']);
  for (const entry of handlerPolicy.handlers) {
    assert.ok(allowed.has(entry.classification), entry.path);
    assert.match(entry.sourceHash, /^[a-f0-9]{64}$/);
    if (entry.classification === 'deferred_beta4') assert.match(entry.beta4Issue, /\/issues\/[2-6]$/);
    if (entry.classification === 'intentional_exclusion') assert.ok(exclusionReasons.has(entry.exclusionReason), entry.path);
  }
});

test('UI handler and job retry tools call their actor-scoped bridge routes', async () => {
  const calls = [];
  const server = createProjeqtorServer({
    username: 'ChrisD',
    apiRequest: async (...args) => {
      calls.push(args);
      return { ok: true, items: [] };
    }
  });
  await handler(server, 'projeqtor_list_ui_handlers')({
    module: 'planning_followup_environment',
    classification: 'deferred_beta4',
    pageSize: 20
  });
  await handler(server, 'projeqtor_retry_job')({ id: 42 });
  assert.equal(calls[0][0], '__mcp/v2/ui-handlers');
  assert.equal(calls[0][1], 'ChrisD');
  assert.equal(calls[1][0], '__mcp/v2/jobs/retry');
  assert.deepEqual(calls[1][3], { id: 42 });
});

test('canonical operation batches pass the top-level idempotency key unchanged', async () => {
  const calls = [];
  const server = createProjeqtorServer({
    username: 'Peet',
    apiRequest: async (...args) => {
      calls.push(args);
      return { ok: true, items: [] };
    }
  });
  await handler(server, 'projeqtor_execute_operations')({
    transactionMode: 'atomic',
    requestIdempotencyKey: 'migration:562:retry-1',
    operations: [{ action: 'create', objectClass: 'Activity', data: { name: 'A' } }]
  });
  assert.equal(calls[0][3].requestIdempotencyKey, 'migration:562:retry-1');
});
