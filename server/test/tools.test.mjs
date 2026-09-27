import test from 'node:test';
import assert from 'node:assert/strict';
import { createProjeqtorServer } from '../src/tools.mjs';

function tool(server, name) {
  return server._registeredTools[name].handler;
}

test('registers the compatibility and full-control v2 tool surface', () => {
  const server = createProjeqtorServer({ username: 'tester', apiRequest: async () => ({ items: [] }) });
  assert.deepEqual(Object.keys(server._registeredTools), [
    'projeqtor_get_capabilities',
    'projeqtor_get_object_schema',
    'projeqtor_get_item',
    'projeqtor_list_items',
    'projeqtor_list_reference_values',
    'projeqtor_list_resource_choices',
    'projeqtor_list_dependencies',
    'projeqtor_create_dependency',
    'projeqtor_update_dependency',
    'projeqtor_delete_dependency',
    'projeqtor_create_item',
    'projeqtor_update_item',
    'projeqtor_batch_upsert',
    'projeqtor_whoami',
    'projeqtor_list_object_classes',
    'projeqtor_list_ui_handlers',
    'projeqtor_query_items',
    'projeqtor_get_changes',
    'projeqtor_validate_operations',
    'projeqtor_execute_operations',
    'projeqtor_prepare_change',
    'projeqtor_commit_change',
    'projeqtor_list_actions',
    'projeqtor_get_action_schema',
    'projeqtor_execute_action',
    'projeqtor_prepare_action',
    'projeqtor_commit_action',
    'projeqtor_list_jobs',
    'projeqtor_retry_job',
    'projeqtor_get_job',
    'projeqtor_cancel_job',
    'projeqtor_plan_projects',
    'projeqtor_render_report',
    'projeqtor_manage_sprint',
    'projeqtor_manage_ticket',
    'projeqtor_record_work'
  ]);
});

test('list_items applies exact filters and cursor pagination while retaining requested fields', async () => {
  const calls = [];
  const apiRequest = async (...args) => {
    calls.push(args);
    return args[3].cursor
      ? { total: 2, returned: 1, pageSize: 1, hasMore: false, nextCursor: null, items: [{ id: 3, name: 'Third' }] }
      : { total: 2, returned: 1, pageSize: 1, hasMore: true, nextCursor: 'signed-page-2', items: [{ id: 1, name: 'First' }] };
  };
  const server = createProjeqtorServer({ username: 'tester', apiRequest });
  const first = await tool(server, 'projeqtor_list_items')({
    objectClass: 'Activity', fields: ['name'], filters: { idProject: 2 }, pageSize: 1
  });
  assert.equal(first.structuredContent.total, 2);
  assert.deepEqual(first.structuredContent.items, [{ id: 1, name: 'First' }]);
  assert.equal(calls[0][0], '__mcp/v2/query');
  assert.equal(calls[0][2], 'POST');
  assert.deepEqual(calls[0][3].filter, { field: 'idProject', operator: 'eq', value: 2 });
  const second = await tool(server, 'projeqtor_list_items')({
    objectClass: 'Activity', fields: ['name'], filters: { idProject: 2 },
    pageSize: 1, cursor: first.structuredContent.nextCursor
  });
  assert.deepEqual(second.structuredContent.items, [{ id: 3, name: 'Third' }]);
});

test('reference lookup filters inactive values and searches names', async () => {
  const calls = [];
  const server = createProjeqtorServer({
    username: 'tester',
    apiRequest: async (...args) => {
      calls.push(args);
      if (args[0] === '__mcp/schema/CalendarDefinition') return { fields: [
        { name: 'id' }, { name: 'name' }, { name: 'idle' }
      ] };
      return { total: 1, returned: 1, hasMore: false, items: [{ id: 1, name: 'Default', idle: 0 }] };
    }
  });
  const response = await tool(server, 'projeqtor_list_reference_values')({
    kind: 'calendar', activeOnly: true, search: 'default', filters: {}, pageSize: 20
  });
  assert.equal(response.structuredContent.objectClass, 'CalendarDefinition');
  assert.deepEqual(response.structuredContent.items, [{ id: 1, name: 'Default', idle: 0 }]);
  assert.equal(calls[1][0], '__mcp/v2/query');
  assert.deepEqual(calls[1][3].fields, ['id', 'name', 'idle']);
  assert.deepEqual(calls[1][3].filter.all.map(node => node.operator), ['eq', 'contains']);
});

test('reference lookup selects only fields installed on the requested class', async () => {
  const calls = [];
  const server = createProjeqtorServer({
    username: 'tester',
    apiRequest: async (...args) => {
      calls.push(args);
      if (args[0] === '__mcp/schema/ActivityType') return { fields: [
        { name: 'id' }, { name: 'name' }, { name: 'idle' }
      ] };
      return { total: 1, returned: 1, hasMore: false, items: [{ id: 26, name: 'Task', idle: 0 }] };
    }
  });
  const response = await tool(server, 'projeqtor_list_reference_values')({
    kind: 'activityType', activeOnly: true, filters: {}, pageSize: 20
  });
  assert.deepEqual(response.structuredContent.items, [{ id: 26, name: 'Task', idle: 0 }]);
  assert.deepEqual(calls[1][3].fields, ['id', 'name', 'idle']);
  assert.equal(JSON.stringify(calls[1][3]).includes('idProject'), false);
});

test('dependency creation maps friendly relationship names and lag days', async () => {
  const calls = [];
  const apiRequest = async (...args) => {
    calls.push(args);
    return { items: [{ id: 9, apiResult: 'OK', dependencyType: 'E-S', dependencyDelay: 2 }] };
  };
  const server = createProjeqtorServer({ username: 'tester', apiRequest });
  const response = await tool(server, 'projeqtor_create_dependency')({
    predecessor: { objectClass: 'Activity', id: 10 },
    successor: { objectClass: 'Milestone', id: 11 },
    relationship: 'finish_to_start',
    lagDays: 2
  });
  assert.equal(response.structuredContent.id, 9);
  assert.equal(calls[0][2], 'PUT');
  assert.deepEqual(calls[0][3], {
    predecessorRefType: 'Activity', predecessorRefId: 10,
    successorRefType: 'Milestone', successorRefId: 11,
    dependencyType: 'E-S', dependencyDelay: 2
  });
  assert.deepEqual(calls[0][4], { allowItemErrors: true });
});

test('dependency write failures become structured validation errors without HTML', async () => {
  const server = createProjeqtorServer({
    username: 'tester',
    apiRequest: async () => ({ items: [{ apiResult: 'KO', apiResultMessage: '<b>Duplicate</b><br/>Dependency' }] })
  });
  const response = await tool(server, 'projeqtor_create_dependency')({
    predecessor: { objectClass: 'Activity', id: 1 },
    successor: { objectClass: 'Activity', id: 2 },
    relationship: 'finish_to_start', lagDays: 0
  });
  assert.equal(response.isError, true);
  assert.deepEqual(response.structuredContent.error, {
    code: 'validation_failed', message: 'Duplicate Dependency',
    missingFields: [], invalidFields: [], referenceErrors: []
  });
});

test('validation-only batch resolves earlier local keys and reports per-item results', async () => {
  const activitySchema = { fields: [
    { name: 'name', type: 'string', required: true, writable: true },
    { name: 'idProject', type: 'integer', required: true, writable: true, referenceClass: 'Project' },
    { name: 'idActivity', type: 'integer', required: false, writable: true, referenceClass: 'Activity' },
    { name: 'externalReference', type: 'string', required: false, writable: true }
  ] };
  const apiRequest = async path => {
    if (path === '__mcp/schema/Activity') return activitySchema;
    if (path === 'Project/2/select=id') return { items: [{ id: 2 }] };
    throw new Error(`Unexpected API call ${path}`);
  };
  const server = createProjeqtorServer({ username: 'tester', apiRequest });
  const response = await tool(server, 'projeqtor_batch_upsert')({
    validationOnly: true,
    items: [
      { localKey: 'parent', objectClass: 'Activity', migrationKey: 'xml:1', onExisting: 'return', data: { name: 'Parent', idProject: 2 } },
      { localKey: 'child', objectClass: 'Activity', migrationKey: 'xml:2', onExisting: 'return', data: { name: 'Child', idProject: 2, idActivity: { $ref: 'parent' } } }
    ]
  });
  assert.equal(response.structuredContent.ok, true);
  assert.deepEqual(response.structuredContent.counts, { valid: 2 });
  assert.equal(response.structuredContent.items[1].unverifiedReferences[0].id, -1);
});

test('batch upsert returns an existing migration-key match without writing', async () => {
  let writes = 0;
  const schema = { fields: [
    { name: 'name', type: 'string', required: true, writable: true },
    { name: 'idProject', type: 'integer', required: true, writable: true, referenceClass: 'Project' },
    { name: 'externalReference', type: 'string', required: false, writable: true }
  ] };
  const apiRequest = async (path, username, method = 'GET') => {
    if (method !== 'GET' && path !== '__mcp/v2/query') writes += 1;
    if (path === '__mcp/schema/Activity') return schema;
    if (path === 'Project/2/select=id') return { items: [{ id: 2 }] };
    if (path === '__mcp/v2/query') return { items: [{ id: 77, idProject: 2, externalReference: 'xml:existing' }] };
    throw new Error(`Unexpected API call ${path}`);
  };
  const server = createProjeqtorServer({ username: 'tester', apiRequest });
  const response = await tool(server, 'projeqtor_batch_upsert')({
    validationOnly: false,
    items: [{
      localKey: 'existing', objectClass: 'Activity', migrationKey: 'xml:existing', onExisting: 'return',
      data: { name: 'Existing', idProject: 2 }
    }]
  });
  assert.equal(response.structuredContent.items[0].status, 'existing');
  assert.equal(response.structuredContent.items[0].id, 77);
  assert.equal(writes, 0);
});

test('write results distinguish applied, recalculated, and ignored fields', async () => {
  const server = createProjeqtorServer({
    username: 'tester',
    apiRequest: async () => ({
      items: [{ id: 5, apiResult: 'OK', name: 'Requested', idProject: 3 }]
    })
  });
  const response = await tool(server, 'projeqtor_create_item')({
    objectClass: 'Activity',
    data: { name: 'Requested', idProject: 2, description: 'Not returned' }
  });
  assert.deepEqual(response.structuredContent.requestedFields, ['name', 'idProject', 'description']);
  assert.deepEqual(response.structuredContent.appliedFields, ['name']);
  assert.deepEqual(response.structuredContent.recalculatedFields, ['idProject']);
  assert.deepEqual(response.structuredContent.ignoredFields, ['description']);
  assert.deepEqual(response.structuredContent.rejectedFields, []);
});
