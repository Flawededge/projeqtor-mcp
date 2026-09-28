import assert from 'node:assert/strict';
import { pathToFileURL } from 'node:url';
import { McpTestClient } from '../support/mcp-client.mjs';
import { verifyWhoami } from '../support/tool-results.mjs';
import { call, fixtureSchema, queryAll, requiredData, tag } from './import-identity-support.mjs';
import { decodeResource, safeKey, waitForJob } from './resource-verification.mjs';

function dateOffset(days) {
  const date = new Date();
  date.setUTCDate(date.getUTCDate() + days);
  return date.toISOString().slice(0, 10);
}

async function completedJobResult(client, queued, timeoutMs = 300_000) {
  assert.equal(queued.queued, true);
  assert.ok(Number.isSafeInteger(queued.job?.id));
  const job = await waitForJob(client, queued.job.id, { timeoutMs });
  assert.equal(job.status, 'succeeded', `Planning job #${job.id} ended as ${job.status}`);
  assert.equal(job.resultResource, `projeqtor://jobs/${job.id}/result`);
  const resource = decodeResource(await client.readResource(job.resultResource), job.resultResource);
  assert.match(resource.mimeType, /json/i, 'Planning job result is not JSON');
  const result = JSON.parse(resource.bytes.toString('utf8'));
  assert.equal(typeof result, 'object');
  assert.notEqual(result, null);
  return { job, result };
}

async function createFixture(client, runId, schema) {
  const importRunId = tag(runId, 'planning-import');
  const projectData = requiredData(schema.project, {
    name: `Beta 4 planning acceptance ${runId}`, idProjectType: schema.refs.projectType
  }, schema.refs);
  const projectOperation = {
    action: 'create', objectClass: 'Project', idempotencyKey: tag(runId, 'planning-project'),
    data: projectData
  };
  const projectValidation = await call(client, 'projeqtor_validate_operations', { operations: [projectOperation] });
  assert.equal(projectValidation.ok, true);
  const projectResult = await call(client, 'projeqtor_execute_operations', {
    transactionMode: 'atomic', importRunId,
    requestIdempotencyKey: tag(runId, 'planning-project-request'), operations: [projectOperation]
  });
  assert.equal(projectResult.ok, true);
  const idProject = Number(projectResult.items?.[0]?.id);
  assert.ok(idProject > 0);
  const base = requiredData(schema.activity, {
    idProject, idActivityType: schema.refs.activityType
  }, schema.refs);
  const operations = [1, 2].map(number => ({
    action: 'create', objectClass: 'Activity',
    idempotencyKey: tag(runId, `planning-activity-${number}`),
    data: { ...base, name: `Beta 4 leveling ${runId} #${number}` }
  }));
  const validation = await call(client, 'projeqtor_validate_operations', { operations });
  assert.equal(validation.ok, true);
  assert.ok(validation.items?.every(item => item.valid === true));
  const activityResult = await call(client, 'projeqtor_execute_operations', {
    transactionMode: 'atomic', importRunId,
    requestIdempotencyKey: tag(runId, 'planning-activities-request'), operations
  });
  assert.equal(activityResult.ok, true);
  const activityIds = activityResult.items.map(item => Number(item.id));
  assert.equal(activityIds.length, 2);
  assert.ok(activityIds.every(id => id > 0));
  return { importRunId, idProject, activityIds };
}

async function fixtureResource(client) {
  const result = await queryAll(client, {
    objectClass: 'User', fields: ['id', 'name', 'idRole', 'idCalendarDefinition'],
    filter: { field: 'name', operator: 'eq', value: 'beta4-member' },
    orderBy: [{ field: 'id', direction: 'asc' }]
  });
  assert.equal(result.items.length, 1, 'Disposable planning resource is missing');
  assert.ok(Number(result.items[0].idRole) > 0);
  assert.ok(Number(result.items[0].idCalendarDefinition) > 0);
  return result.items[0];
}

async function planningMode(client, code) {
  const result = await call(client, 'projeqtor_list_reference_values', {
    kind: 'activityPlanningMode', activeOnly: true, pageSize: 50
  });
  const mode = result.items?.find(item => item.code === code);
  assert.ok(Number(mode?.id) > 0, `Active planning mode ${code} is missing`);
  return Number(mode.id);
}

async function planningElements(client, activityIds) {
  const result = await queryAll(client, {
    objectClass: 'ActivityPlanningElement',
    fields: [
      'id', 'refType', 'refId', 'idProject', 'idPlanningMode', 'validatedStartDate',
      'validatedWork', 'assignedWork', 'leftWork', 'plannedStartDate', 'plannedEndDate',
      'notPlannedWork', 'surbooked'
    ],
    filter: { all: [
      { field: 'refType', operator: 'eq', value: 'Activity' },
      { field: 'refId', operator: 'in', value: activityIds }
    ] },
    orderBy: [{ field: 'refId', direction: 'asc' }]
  });
  assert.equal(result.items.length, activityIds.length);
  return result.items;
}

async function configureFixture(client, runId, identity, fixture, startDate) {
  const resource = await fixtureResource(client);
  const allocation = await call(client, 'projeqtor_execute_action', {
    action: 'planning.allocation.upsert', idempotencyKey: tag(runId, 'leveling-allocation'),
    arguments: { transactionMode: 'atomic', items: [{
      projectId: fixture.idProject, resourceId: Number(resource.id),
      profileId: Number(identity.idProfile), rate: 100,
      description: tag(runId, 'leveling-allocation')
    }] }
  });
  assert.equal(allocation.ok, true);
  assert.equal(allocation.items?.[0]?.status, 'created');
  const modeId = await planningMode(client, 'ASAP');
  const before = await planningElements(client, fixture.activityIds);
  for (const item of before) {
    const phased = await call(client, 'projeqtor_execute_action', {
      action: 'planning.element.phase',
      idempotencyKey: tag(runId, `phase-${item.refId}`),
      arguments: {
        refType: 'Activity', refId: Number(item.refId), planningModeId: modeId,
        validatedStartDate: startDate, validatedWork: 2, expectedVersion: item._version
      }
    });
    assert.equal(phased.ok, true);
    assert.equal(phased.status, 'updated');
  }
  const assignments = await call(client, 'projeqtor_execute_action', {
    action: 'planning.assignment.upsert', idempotencyKey: tag(runId, 'leveling-assignments'),
    arguments: { transactionMode: 'atomic', items: fixture.activityIds.map(id => ({
      refType: 'Activity', refId: id, resourceId: Number(resource.id),
      roleId: Number(resource.idRole), rate: 100, assignedWork: 2, leftWork: 2,
      comment: tag(runId, 'competing-assignment')
    })) }
  });
  assert.equal(assignments.ok, true);
  assert.equal(assignments.items?.length, 2);
  assert.ok(assignments.items.every(item => item.status === 'created'));
  return {
    resourceId: Number(resource.id),
    allocationId: Number(allocation.items[0].id),
    assignmentIds: assignments.items.map(item => Number(item.id))
  };
}

export function assertCapacityConstrained(elements) {
  assert.equal(elements.length, 2);
  const ranges = elements.map(item => ({
    start: String(item.plannedStartDate ?? ''), end: String(item.plannedEndDate ?? '')
  })).sort((left, right) => left.start.localeCompare(right.start));
  assert.ok(ranges.every(range => /^\d{4}-\d{2}-\d{2}$/.test(range.start) && /^\d{4}-\d{2}-\d{2}$/.test(range.end)),
    'Capacity-constrained plan did not assign bounded dates');
  assert.ok(ranges[0].end < ranges[1].start,
    `Competing activities overlap despite capacity constraints (${ranges[0].end} / ${ranges[1].start})`);
  assert.ok(elements.every(item => Number(item.notPlannedWork ?? 0) === 0));
  return ranges;
}

function sumSurbooked(items) {
  return items.reduce((total, item) => total + Number(item.surbookedWork ?? 0), 0);
}

async function calculate(client, fixture, runId, label, allowOverbooking) {
  return completedJobResult(client, await call(client, 'projeqtor_plan_projects', {
    projectIds: [fixture.idProject], startDate: fixture.startDate,
    criticalPath: true, criticalResourceMode: true, allowOverbooking,
    includeDiagnostics: true, idempotencyKey: safeKey('b4', runId, 'planning', label)
  }));
}

async function evaluateOverbooking(client, fixture, runId) {
  const queued = await call(client, 'projeqtor_execute_action', {
    action: 'planning.critical_resources.evaluate',
    idempotencyKey: safeKey('b4', runId, 'critical-resources'),
    arguments: {
      projectIds: [fixture.idProject], startDate: fixture.startDate,
      endDate: dateOffset(45), allowOverbooking: true, maxResources: 20
    }
  });
  const completed = await completedJobResult(client, queued);
  const resource = completed.result.resources?.find(item => Number(item.idResource) === fixture.resourceId);
  assert.ok(resource, 'Critical-resource result omitted the competing resource');
  assert.ok(Number(resource.surbookedWork) > 0, 'Overbooking mode reported no surbooked work');
  assert.ok(Number(completed.result.counts?.overloaded) >= 1);
  const diagnostics = await call(client, 'projeqtor_execute_action', {
    action: 'planning.diagnostics', arguments: { idProject: fixture.idProject }
  });
  assert.ok(Array.isArray(diagnostics.overloads));
  const exact = sumSurbooked(diagnostics.overloads);
  assert.ok(exact > 0);
  assert.ok(Math.abs(exact - Number(resource.surbookedWork)) < 0.000001,
    `Critical-resource surbookedWork ${resource.surbookedWork} differs from diagnostics ${exact}`);
  return { jobId: completed.job.id, overloadRows: diagnostics.overloads.length, surbookedWork: exact };
}

async function guardedRemove(client, action, objectClass, ids) {
  if (!ids.length) return;
  const current = await queryAll(client, {
    objectClass, fields: ['id'], filter: { field: 'id', operator: 'in', value: ids },
    orderBy: [{ field: 'id', direction: 'asc' }]
  });
  if (!current.items.length) return;
  const prepared = await call(client, 'projeqtor_prepare_action', {
    action, arguments: {
      transactionMode: 'atomic',
      items: current.items.map(item => ({ id: Number(item.id), expectedVersion: item._version }))
    }
  });
  const removed = await call(client, 'projeqtor_commit_action', {
    confirmationToken: prepared.confirmationToken
  });
  assert.equal(removed.ok, true);
  assert.ok(removed.items?.every(item => item.status === 'deleted'));
}

async function cleanupImport(client, importRunId) {
  const prepared = await call(client, 'projeqtor_prepare_action', {
    action: 'import.cleanup', arguments: { importRunId, force: true }
  });
  const queued = await call(client, 'projeqtor_commit_action', {
    confirmationToken: prepared.confirmationToken
  });
  const completed = await completedJobResult(client, queued, 180_000);
  assert.ok(Number(completed.result.counts?.deleted) >= 3);
}

async function assertNoResidual(client, runId, idProject) {
  const probes = [
    ['Project', { field: 'name', operator: 'eq', value: `Beta 4 planning acceptance ${runId}` }],
    ['Activity', { field: 'name', operator: 'starts_with', value: `Beta 4 leveling ${runId}` }],
    ['Assignment', { field: 'idProject', operator: 'eq', value: idProject }],
    ['Affectation', { field: 'idProject', operator: 'eq', value: idProject }],
    ['PlannedWork', { field: 'idProject', operator: 'eq', value: idProject }]
  ];
  for (const [objectClass, filter] of probes) {
    const result = await queryAll(client, {
      objectClass, fields: ['id'], filter, orderBy: [{ field: 'id', direction: 'asc' }]
    });
    assert.equal(result.items.length, 0, `${objectClass} planning fixtures remain after cleanup`);
  }
}

export async function runPlanningAcceptance({ client, identity, runId }) {
  assert.match(runId, /^b4-[A-Za-z0-9-]{8,64}$/);
  const schema = await fixtureSchema(client);
  const fixture = await createFixture(client, runId, schema);
  fixture.startDate = dateOffset(7);
  let configured = null;
  try {
    configured = await configureFixture(client, runId, identity, fixture, fixture.startDate);
    Object.assign(fixture, configured);
    const constrained = await calculate(client, fixture, runId, 'constrained', false);
    assert.equal(constrained.result.ok, true);
    assert.equal(constrained.result.status, 'complete');
    const constrainedRanges = assertCapacityConstrained(await planningElements(client, fixture.activityIds));
    const constrainedDiagnostics = await call(client, 'projeqtor_execute_action', {
      action: 'planning.diagnostics', arguments: { idProject: fixture.idProject }
    });
    assert.equal(constrainedDiagnostics.counts?.overloads, 0);
    const overbooking = await evaluateOverbooking(client, fixture, runId);
    const restored = await calculate(client, fixture, runId, 'restore-constrained', false);
    assert.equal(restored.result.ok, true);
    assertCapacityConstrained(await planningElements(client, fixture.activityIds));
    const finalDiagnostics = await call(client, 'projeqtor_execute_action', {
      action: 'planning.diagnostics', arguments: { idProject: fixture.idProject }
    });
    assert.equal(finalDiagnostics.counts?.overloads, 0);
    return {
      projectId: fixture.idProject, activityCount: fixture.activityIds.length,
      resourceId: fixture.resourceId, constrainedJobId: constrained.job.id,
      constrainedRanges, overbooking, restoredJobId: restored.job.id,
      cleanupVerified: true
    };
  } finally {
    if (configured?.assignmentIds) {
      await guardedRemove(client, 'planning.assignment.remove', 'Assignment', configured.assignmentIds);
    }
    if (configured?.allocationId) {
      await guardedRemove(client, 'planning.allocation.remove', 'Affectation', [configured.allocationId]);
    }
    await cleanupImport(client, fixture.importRunId);
    await assertNoResidual(client, runId, fixture.idProject);
  }
}

async function main() {
  const client = await McpTestClient.fromEnvironment();
  await client.initialize();
  const identity = verifyWhoami(
    await client.callTool('projeqtor_whoami'),
    process.env.PROJEQTOR_TEST_ACTOR ?? 'beta4-admin'
  );
  const result = await runPlanningAcceptance({
    client, identity, runId: process.env.PROJEQTOR_TEST_RUN_ID
  });
  process.stdout.write(`${JSON.stringify({ ok: true, ...result })}\n`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch(error => {
    process.stderr.write(`Planning acceptance failed: ${error.message}\n`);
    process.exitCode = 1;
  });
}
