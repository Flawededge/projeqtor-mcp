import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import test from 'node:test';
import { assertCapacityConstrained } from '../scenarios/planning-acceptance.mjs';

const root = new URL('../', import.meta.url);
const [runner, planning, mail] = await Promise.all([
  readFile(new URL('run-suite.mjs', root), 'utf8'),
  readFile(new URL('scenarios/planning-acceptance.mjs', root), 'utf8'),
  readFile(new URL('scenarios/mail-acceptance.mjs', root), 'utf8')
]);

test('capacity-constrained assertion requires two non-overlapping scheduled ranges', () => {
  const ranges = assertCapacityConstrained([
    { plannedStartDate: '2026-10-05', plannedEndDate: '2026-10-06', notPlannedWork: 0 },
    { plannedStartDate: '2026-10-07', plannedEndDate: '2026-10-08', notPlannedWork: 0 }
  ]);
  assert.equal(ranges.length, 2);
  assert.throws(() => assertCapacityConstrained([
    { plannedStartDate: '2026-10-05', plannedEndDate: '2026-10-07', notPlannedWork: 0 },
    { plannedStartDate: '2026-10-07', plannedEndDate: '2026-10-08', notPlannedWork: 0 }
  ]), /overlap despite capacity constraints/);
});

test('acceptance serializes planning engine and mail sink workflows', () => {
  assert.match(runner, /withLocks\(\['planning-engine'\]/);
  assert.match(runner, /runPlanningAcceptance/);
  assert.match(runner, /withLocks\(\['mail'\]/);
  assert.match(runner, /runMailAcceptance/);
});

test('planning scenario proves exact overload diagnostics and reversible cleanup', () => {
  assert.match(planning, /allowOverbooking: true/);
  assert.match(planning, /surbookedWork/);
  assert.match(planning, /Math\.abs\(exact - Number\(resource\.surbookedWork\)\)/);
  assert.match(planning, /planning\.assignment\.remove/);
  assert.match(planning, /planning\.allocation\.remove/);
  assert.match(planning, /import\.cleanup/);
  assert.match(planning, /PlannedWork/);
  assert.match(planning, /saved\?\.fixPlanning/);
  assert.match(planning, /fixtureResource\(client, identity\.username\)/);
  assert.match(planning, /action: 'project\.snapshot'/);
});

test('mail scenario is pinned to the internal sink and redacts retained payloads', () => {
  assert.match(mail, /http:\/\/mail:8025\/api\/v1/);
  assert.match(mail, /hostname !== 'mail'/);
  assert.match(mail, /tools\.mail\.send/);
  assert.match(mail, /payloadRedacted/);
  assert.match(mail, /serialized\.includes\(recipient\), false/);
  assert.match(mail, /method: 'DELETE'/);
  assert.match(mail, /IDs: created\.map/);
});
