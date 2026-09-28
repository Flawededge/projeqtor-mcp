import assert from 'node:assert/strict';
import { McpTestClient } from '../support/mcp-client.mjs';
import { structuredToolError, structuredToolResult, verifyWhoami } from '../support/tool-results.mjs';

export const TASK_COUNT = 562;
export const BATCH_SIZES = Object.freeze([200, 200, 162]);
export const ACTORS = Object.freeze([
  ['beta4-admin', 'ADM'],
  ['beta4-manager', 'PL'],
  ['beta4-member', 'TM'],
  ['beta4-denied', 'G']
]);

export function tag(runId, suffix) { return `b4:${runId}:${suffix}`; }

export async function call(client, name, args = {}) {
  return structuredToolResult(await client.callTool(name, args), name);
}

export async function callError(client, name, args, code) {
  return structuredToolError(await client.callTool(name, args), code, name);
}

export async function batchOutcome(client, name, args) {
  const result = await client.callTool(name, args);
  const value = result?.structuredContent ??
    result?.content?.find(item => item.type === 'text' && item.text)?.text;
  let document;
  try {
    document = typeof value === 'string' ? JSON.parse(value) : value;
  } catch {
    throw new Error(`${name} returned malformed JSON`);
  }
  if (!document || typeof document !== 'object' || Array.isArray(document) || result?.isError || document.error) {
    throw new Error(`${name} returned an MCP-level error instead of a batch outcome`);
  }
  return document;
}

export async function createActors() {
  const actors = {};
  for (const [name, profileCode] of ACTORS) {
    const client = await McpTestClient.forActor(name, { timeoutMs: 900_000 });
    await client.initialize();
    const identity = verifyWhoami(await client.callTool('projeqtor_whoami'), name);
    assert.equal(identity.profileCode, profileCode);
    assert.equal(identity.credentialsExposed, false);
    actors[name] = { client, identity };
  }
  return actors;
}

export async function queryAll(client, args) {
  const items = [];
  let cursor;
  let pages = 0;
  let total = null;
  do {
    const page = await call(client, 'projeqtor_query_items', {
      ...args, cursor, pageSize: 200, includeTotal: cursor === undefined
    });
    assert.ok(Array.isArray(page.items), 'Query page has no items');
    if (cursor === undefined) total = page.total;
    items.push(...page.items);
    pages += 1;
    assert.ok(pages <= 10, 'Query pagination did not terminate');
    if (page.hasMore) assert.equal(typeof page.nextCursor, 'string');
    cursor = page.hasMore ? page.nextCursor : undefined;
  } while (cursor);
  return { items, pages, total };
}

export function buildActivities({ runId, idProject, baseData }) {
  const prefix = tag(runId, 'activity');
  return Array.from({ length: TASK_COUNT }, (_, index) => ({
    localKey: `activity-${index + 1}`,
    action: 'create',
    objectClass: 'Activity',
    idempotencyKey: `${prefix}:${String(index + 1).padStart(4, '0')}`,
    data: {
      ...baseData, idProject,
      name: `Beta 4 acceptance activity ${String(index + 1).padStart(4, '0')}`
    }
  }));
}

export function splitBatches(operations) {
  assert.equal(operations.length, TASK_COUNT);
  let offset = 0;
  const batches = BATCH_SIZES.map(size => {
    const batch = operations.slice(offset, offset + size);
    offset += size;
    return batch;
  });
  assert.equal(offset, TASK_COUNT);
  assert.deepEqual(batches.map(batch => batch.length), BATCH_SIZES);
  return batches;
}

async function firstReference(client, kind) {
  const result = await call(client, 'projeqtor_list_reference_values', {
    kind, activeOnly: true, pageSize: 50
  });
  assert.ok(result.items?.length > 0, `No active ${kind} reference exists`);
  return Number(result.items[0].id);
}

function fieldMap(schema) {
  assert.ok(Array.isArray(schema.fields));
  return new Map(schema.fields.map(field => [field.name, field]));
}

export async function fixtureSchema(client) {
  const project = fieldMap(await call(client, 'projeqtor_get_object_schema', { objectClass: 'Project' }));
  const activity = fieldMap(await call(client, 'projeqtor_get_object_schema', { objectClass: 'Activity' }));
  for (const [fields, names] of [[project, ['name', 'idProjectType']], [activity, ['name', 'idProject', 'idActivityType']]]) {
    for (const name of names) assert.equal(fields.get(name)?.writable, true, `${name} is not writable`);
  }
  return {
    project, activity,
    refs: {
      projectType: await firstReference(client, 'projectType'),
      activityType: await firstReference(client, 'activityType'),
      activityPlanningMode: await firstReference(client, 'activityPlanningMode'),
      status: await firstReference(client, 'status')
    }
  };
}

export function requiredData(fields, data, refs) {
  const known = { idProjectType: refs.projectType, idActivityType: refs.activityType,
    idActivityPlanningMode: refs.activityPlanningMode, idStatus: refs.status };
  for (const field of fields.values()) {
    if (!field.required || !field.writable || Object.hasOwn(data, field.name)) continue;
    if (Object.hasOwn(known, field.name)) data[field.name] = known[field.name];
    else if (field.default !== null && field.default !== '') data[field.name] = field.default;
    else if (field.type === 'boolean') data[field.name] = false;
    else if (field.format === 'date') data[field.name] = new Date().toISOString().slice(0, 10);
    else throw new Error(`No fixture value for required field ${field.name}`);
  }
  if (fields.get('idStatus')?.writable && !Object.hasOwn(data, 'idStatus')) data.idStatus = refs.status;
  return data;
}
