import assert from 'node:assert/strict';
import { ACTORS, BATCH_SIZES, TASK_COUNT, createActors, fixtureSchema } from './import-identity-support.mjs';
import { assertContracts, createProject, importActivities, readback } from './import-operations.mjs';
import {
  actorWrite, allocate, assertAttribution, assertCrossActorJobIsolation, assertDenied
} from './identity-actions.mjs';
import { assertZeroResidual, cleanupActivities, deleteProject, removeAllocations } from './import-cleanup.mjs';

export async function runImportIdentityScenario({ runId }) {
  assert.match(runId, /^b4-[A-Za-z0-9-]{8,64}$/);
  const actors = await createActors();
  const admin = actors['beta4-admin'].client;
  await assertContracts(admin);
  const schema = await fixtureSchema(admin);
  const project = await createProject(admin, runId, schema);
  const imported = await importActivities(admin, runId, project.id, schema);
  const activities = await readback(admin, runId, project.id);
  const allocations = await allocate(admin, actors, runId, project.id);

  await assertCrossActorJobIsolation(
    admin, actors['beta4-manager'].client, imported.results[0].operationId
  );
  const managerOperation = await actorWrite(actors['beta4-manager'], activities[0], runId);
  const memberOperation = await actorWrite(actors['beta4-member'], activities[1], runId);
  if (managerOperation) await assertCrossActorJobIsolation(actors['beta4-manager'].client, admin, managerOperation);
  if (memberOperation) await assertCrossActorJobIsolation(actors['beta4-member'].client, admin, memberOperation);
  await assertAttribution(admin, Number(activities[0].id), actors['beta4-manager'].identity.id);
  await assertAttribution(admin, Number(activities[1].id), actors['beta4-member'].identity.id);
  await assertDenied(actors['beta4-denied'].client, admin, runId, project.data);

  await removeAllocations(admin, allocations);
  await cleanupActivities(admin, actors['beta4-manager'].client, runId);
  await deleteProject(admin, project);
  await assertZeroResidual(admin, runId, project.id);

  return {
    status: 'passed',
    actors: ACTORS.map(([username, profileCode]) => ({ username, profileCode, verified: true })),
    import: {
      tasks: TASK_COUNT, batchSizes: [...BATCH_SIZES], validationPassed: true,
      requestReplayPassed: true, requestConflictPassed: true,
      uniqueReadback: TASK_COUNT, pages: 3
    },
    permissions: {
      actorAttributionPassed: true, deniedMutationPassed: true,
      crossActorOperationIsolationPassed: true, actorBoundConfirmationPassed: true
    },
    cleanup: { deletedActivities: TASK_COUNT, residualFixtures: 0 }
  };
}
