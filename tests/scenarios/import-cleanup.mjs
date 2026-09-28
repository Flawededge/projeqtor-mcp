import assert from 'node:assert/strict';
import { TASK_COUNT, call, callError, queryAll, tag } from './import-identity-support.mjs';

async function waitForJob(client, id) {
  const deadline = Date.now() + 900_000;
  let job;
  do {
    job = await call(client, 'projeqtor_get_job', { id: Number(id) });
    if (['succeeded', 'failed', 'cancelled', 'recovery_required'].includes(job.status)) return job;
    await new Promise(resolve => setTimeout(resolve, 2_000));
  } while (Date.now() < deadline);
  throw new Error(`Job ${id} did not reach a terminal state`);
}

async function readJobResult(client, job) {
  assert.match(job.resultResource ?? '', /^projeqtor:\/\/jobs\/[0-9]+\/result$/);
  const resource = await client.request('resources/read', { uri: job.resultResource });
  const blob = resource?.contents?.[0]?.blob;
  assert.equal(typeof blob, 'string');
  return JSON.parse(Buffer.from(blob, 'base64').toString('utf8'));
}

export async function removeAllocations(admin, allocations) {
  const ids = allocations.items.map(item => Number(item.id));
  const current = await queryAll(admin, {
    objectClass: 'Affectation', fields: ['id'],
    filter: { field: 'id', operator: 'in', value: ids },
    orderBy: [{ field: 'id', direction: 'asc' }]
  });
  if (current.items.length === 0) return;
  const items = current.items.map(item => ({ id: Number(item.id), expectedVersion: item._version }));
  const prepared = await call(admin, 'projeqtor_prepare_action', {
    action: 'planning.allocation.remove',
    arguments: { transactionMode: 'atomic', items }
  });
  const result = await call(admin, 'projeqtor_commit_action', {
    confirmationToken: prepared.confirmationToken
  });
  assert.equal(result.ok, true);
  assert.equal(result.items?.length, items.length);
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
  const rejectedRequest = await call(admin, 'projeqtor_commit_action', {
    confirmationToken: prepared.confirmationToken
  });
  assert.equal(rejectedRequest.queued, true);
  const rejected = await waitForJob(admin, rejectedRequest.job?.id);
  assert.equal(rejected.status, 'failed');
  assert.equal(rejected.errorCode, 'cleanup_modified');

  const forced = await call(admin, 'projeqtor_prepare_action', {
    action: 'import.cleanup', arguments: { importRunId, force: true }
  });
  assert.equal(forced.preview?.counts?.total, TASK_COUNT);
  assert.equal(forced.preview?.counts?.modified, 2);
  const result = await call(admin, 'projeqtor_commit_action', {
    confirmationToken: forced.confirmationToken
  });
  assert.equal(result.ok, true);
  assert.equal(result.queued, true);
  assert.ok(Number(result.job?.id) > 0);
  const cleanup = await waitForJob(admin, result.job.id);
  assert.equal(cleanup.status, 'succeeded');
  const cleanupResult = await readJobResult(admin, cleanup);
  assert.equal(cleanupResult.counts?.deleted, TASK_COUNT);
}

export async function deleteProject(admin, project) {
  const current = await call(admin, 'projeqtor_get_item', {
    objectClass: 'Project', id: project.id,
    fields: ['id', 'name']
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
    ['Activity', { field: 'name', operator: 'starts_with', value: 'Beta 4 acceptance activity ' }],
    ['Project', { field: 'name', operator: 'eq', value: `Beta 4 import acceptance ${runId}` }],
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
