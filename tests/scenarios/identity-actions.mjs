import assert from 'node:assert/strict';
import { batchOutcome, call, callError, queryAll, tag } from './import-identity-support.mjs';

async function user(client, name) {
  const result = await queryAll(client, {
    objectClass: 'User', fields: ['id', 'name', 'idProfile'],
    filter: { field: 'name', operator: 'eq', value: name },
    orderBy: [{ field: 'id', direction: 'asc' }]
  });
  assert.equal(result.items.length, 1, `${name} identity is missing`);
  return result.items[0];
}

export async function allocate(admin, actors, runId, idProject) {
  const resources = await Promise.all([
    user(admin, 'beta4-manager'), user(admin, 'beta4-member')
  ]);
  const result = await call(admin, 'projeqtor_execute_action', {
    action: 'planning.allocation.upsert',
    idempotencyKey: tag(runId, 'actor-allocations'),
    arguments: {
      transactionMode: 'atomic',
      items: resources.map(resource => ({
        projectId: idProject,
        resourceId: Number(resource.id),
        profileId: Number(actors['beta4-manager'].identity.idProfile),
        rate: 100,
        description: tag(runId, 'identity-allocation')
      }))
    }
  });
  assert.equal(result.ok, true);
  assert.equal(result.items?.length, 2);
  assert.ok(result.items.every(item => item.status === 'created' && item.saved?._version));
  return {
    operationId: result.operationId,
    items: result.items.map(item => ({ id: Number(item.id), expectedVersion: item.saved._version }))
  };
}

export async function actorWrite(actor, item, runId) {
  const description = tag(runId, `updated-by-${actor.identity.username}`);
  if (item.description === description) return null;
  const operation = {
    action: 'update', objectClass: 'Activity', id: Number(item.id),
    expectedVersion: item._version,
    data: { description }
  };
  const validation = await call(actor.client, 'projeqtor_validate_operations', {
    operations: [operation]
  });
  assert.equal(validation.ok, true, `${actor.identity.username} validation failed`);
  const result = await call(actor.client, 'projeqtor_execute_operations', {
    transactionMode: 'atomic',
    requestIdempotencyKey: tag(runId, `write-${actor.identity.username}`),
    operations: [operation]
  });
  assert.equal(result.ok, true, `${actor.identity.username} update failed`);
  assert.equal(result.items?.[0]?.status, 'updated');
  return result.operationId;
}

export async function assertAttribution(admin, idActivity, idActor) {
  const history = await queryAll(admin, {
    objectClass: 'History',
    filter: { all: [
      { field: 'refType', operator: 'eq', value: 'Activity' },
      { field: 'refId', operator: 'eq', value: idActivity }
    ] },
    orderBy: [{ field: 'id', direction: 'asc' }]
  });
  assert.ok(history.items.some(item => Number(item.idUser) === Number(idActor)),
    'History does not attribute the update to the expected actor');
}

export async function assertDenied(denied, admin, runId, projectData) {
  const operation = {
    action: 'create', objectClass: 'Project',
    idempotencyKey: tag(runId, 'denied-project'),
    data: { ...projectData, name: `Denied project ${runId}` }
  };
  const validation = await batchOutcome(denied, 'projeqtor_validate_operations', {
    operations: [operation]
  });
  assert.equal(validation.ok, false);
  assert.equal(validation.items?.[0]?.error?.code, 'forbidden');
  const execution = await batchOutcome(denied, 'projeqtor_execute_operations', {
    transactionMode: 'atomic',
    requestIdempotencyKey: tag(runId, 'denied-project-request'),
    operations: [operation]
  });
  assert.equal(execution.ok, false);
  assert.equal(execution.rolledBack, true);
  assert.equal(execution.items?.[0]?.error?.code, 'forbidden');
  const residual = await queryAll(admin, {
    objectClass: 'Project', fields: ['id', 'name'],
    filter: { field: 'name', operator: 'eq', value: `Denied project ${runId}` },
    orderBy: [{ field: 'id', direction: 'asc' }]
  });
  assert.equal(residual.items.length, 0);
}

export async function assertCrossActorJobIsolation(owner, other, operationId) {
  assert.ok(Number(operationId) > 0);
  await callError(other, 'projeqtor_get_job', { id: Number(operationId) }, 'job_not_found');
  const job = await call(owner, 'projeqtor_get_job', { id: Number(operationId) });
  assert.equal(Number(job.id), Number(operationId));
}
