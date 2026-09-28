import assert from 'node:assert/strict';
import {
  TASK_COUNT, buildActivities, call, callError, queryAll, requiredData, splitBatches, tag
} from './import-identity-support.mjs';

export async function assertContracts(client) {
  const listed = await client.tools();
  const execute = (listed.tools ?? []).find(tool => tool.name === 'projeqtor_execute_operations');
  assert.equal(execute?.inputSchema?.properties?.operations?.maxItems, 200);
  const allocation = await call(client, 'projeqtor_get_action_schema', {
    action: 'planning.allocation.upsert'
  });
  assert.equal(allocation.schema?.properties?.items?.maxItems, 200);
  assert.equal(allocation.available, true);
  const cleanup = await call(client, 'projeqtor_get_action_schema', { action: 'import.cleanup' });
  assert.equal(cleanup.risk, 'destructive');
  assert.equal(cleanup.available, true);
  assert.ok(cleanup.schema?.properties?.importRunId);
}

export async function createProject(client, runId, schema) {
  const data = requiredData(schema.project, {
    name: `Beta 4 import acceptance ${runId}`,
    idProjectType: schema.refs.projectType
  }, schema.refs);
  const operation = {
    action: 'create', objectClass: 'Project',
    idempotencyKey: tag(runId, 'project'), data
  };
  const validation = await call(client, 'projeqtor_validate_operations', { operations: [operation] });
  assert.equal(validation.ok, true);
  const result = await call(client, 'projeqtor_execute_operations', {
    transactionMode: 'atomic',
    requestIdempotencyKey: tag(runId, 'project-request'),
    operations: [operation]
  });
  assert.equal(result.ok, true);
  assert.equal(result.items?.[0]?.status, 'created');
  return { id: Number(result.items[0].id), data };
}

export async function importActivities(client, runId, idProject, schema) {
  const baseData = requiredData(schema.activity, {
    idProject, idActivityType: schema.refs.activityType
  }, schema.refs);
  const operations = buildActivities({ runId, idProject, baseData });
  const batches = splitBatches(operations);
  const results = [];
  for (const [index, batch] of batches.entries()) {
    const validation = await call(client, 'projeqtor_validate_operations', { operations: batch });
    assert.equal(validation.ok, true, `batch ${index + 1} validation failed`);
    assert.equal(validation.items?.length, batch.length);
    assert.ok(validation.items.every(item => item.valid === true));
    const result = await call(client, 'projeqtor_execute_operations', {
      transactionMode: 'atomic',
      importRunId: tag(runId, 'import'),
      requestIdempotencyKey: tag(runId, `import-request-${index + 1}`),
      operations: batch
    });
    assert.equal(result.ok, true, `batch ${index + 1} execution failed`);
    assert.equal(result.items?.length, batch.length);
    assert.ok(result.items.every(item => item.status === 'created'));
    results.push(result);
  }

  const replay = await call(client, 'projeqtor_execute_operations', {
    transactionMode: 'atomic',
    importRunId: tag(runId, 'import'),
    requestIdempotencyKey: tag(runId, 'import-request-1'),
    operations: batches[0]
  });
  assert.equal(replay.idempotencyReplay, true);
  assert.equal(replay.operationId, results[0].operationId);

  const changed = structuredClone(batches[0]);
  changed[0].data.name += ' changed';
  await callError(client, 'projeqtor_execute_operations', {
    transactionMode: 'atomic',
    importRunId: tag(runId, 'import'),
    requestIdempotencyKey: tag(runId, 'import-request-1'),
    operations: changed
  }, 'idempotency_key_conflict');
  return { results };
}

export async function readback(client, runId, idProject) {
  const prefix = tag(runId, 'activity');
  const result = await queryAll(client, {
    objectClass: 'Activity',
    fields: ['id', 'name', 'idProject', 'externalReference'],
    filter: { all: [
      { field: 'idProject', operator: 'eq', value: idProject },
      { field: 'externalReference', operator: 'starts_with', value: prefix }
    ] },
    orderBy: [{ field: 'id', direction: 'asc' }]
  });
  assert.equal(result.total, TASK_COUNT);
  assert.equal(result.items.length, TASK_COUNT);
  assert.equal(result.pages, 3);
  assert.equal(new Set(result.items.map(item => Number(item.id))).size, TASK_COUNT);
  assert.ok(result.items.every(item => Number(item.idProject) === idProject));
  assert.ok(result.items.every(item => String(item.externalReference).startsWith(prefix)));
  return result.items;
}
