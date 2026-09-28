import assert from 'node:assert/strict';
import { TASK_COUNT, call, callError, queryAll, tag } from './import-identity-support.mjs';

export async function removeAllocations(admin, allocations) {
  const prepared = await call(admin, 'projeqtor_prepare_action', {
    action: 'planning.allocation.remove',
    arguments: { transactionMode: 'atomic', items: allocations.items }
  });
  const result = await call(admin, 'projeqtor_commit_action', {
    confirmationToken: prepared.confirmationToken
  });
  assert.equal(result.ok, true);
  assert.ok(result.items?.every(item => item.status === 'deleted'));
}

export async function cleanupActivities(admin, otherActor, runId) {
  const importRunId = tag(runId, 'import');
  const prepared = await call(admin, 'projeqtor_prepare_action', {
    action: 'import.cleanup', arguments: { importRunId, force: false }
  });
  assert.equal(prepared.preview?.counts?.total, TASK_COUNT);
  assert.equal(prepared.preview?.counts?.modified, 2);
  await callError(otherActor, 'projeqtor_commit_action', {
    confirmationToken: prepared.confirmationToken
  }, 'expired_confirmation');
  await callError(admin, 'projeqtor_commit_action', {
    confirmationToken: prepared.confirmationToken
  }, 'cleanup_modified');

  const forced = await call(admin, 'projeqtor_prepare_action', {
    action: 'import.cleanup', arguments: { importRunId, force: true }
  });
  assert.equal(forced.preview?.counts?.total, TASK_COUNT);
  assert.equal(forced.preview?.counts?.modified, 2);
  const result = await call(admin, 'projeqtor_commit_action', {
    confirmationToken: forced.confirmationToken
  });
  assert.equal(result.ok, true);
  assert.equal(result.counts?.deleted, TASK_COUNT);
}

export async function deleteProject(admin, project) {
  const current = await call(admin, 'projeqtor_get_item', {
    objectClass: 'Project', id: project.id,
    fields: ['id', 'name', 'externalReference']
  });
  const version = current.items?.[0]?._version;
  assert.ok(version);
  const prepared = await call(admin, 'projeqtor_prepare_change', {
    operations: [{
      action: 'delete', objectClass: 'Project', id: project.id,
      expectedVersion: version, data: {}
    }]
  });
  const result = await call(admin, 'projeqtor_commit_change', {
    confirmationToken: prepared.confirmationToken
  });
  assert.equal(result.ok, true);
  assert.equal(result.items?.[0]?.status, 'deleted');
}

export async function assertZeroResidual(admin, runId, idProject) {
  const probes = [
    ['Activity', { field: 'externalReference', operator: 'starts_with', value: tag(runId, 'activity') }],
    ['Project', { field: 'externalReference', operator: 'eq', value: tag(runId, 'project') }],
    ['Affectation', { field: 'idProject', operator: 'eq', value: idProject }]
  ];
  for (const [objectClass, filter] of probes) {
    const result = await queryAll(admin, {
      objectClass, fields: ['id'], filter,
      orderBy: [{ field: 'id', direction: 'asc' }]
    });
    assert.equal(result.items.length, 0, `${objectClass} fixtures remain after cleanup`);
  }
}
