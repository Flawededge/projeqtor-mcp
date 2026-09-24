import test from 'node:test';
import assert from 'node:assert/strict';
import {
  DomainError,
  dependencyFromApi,
  encodeCursor,
  filterAndPaginate,
  relationshipToApi,
  resolveLocalReferences,
  validateData
} from '../src/domain.mjs';

test('filtered pagination is stable and cursor-bound to the query', () => {
  const items = [
    { id: 3, idProject: 2, name: 'C' },
    { id: 1, idProject: 2, name: 'A' },
    { id: 2, idProject: 9, name: 'B' },
    { id: 4, idProject: 2, name: 'D' }
  ];
  const first = filterAndPaginate({ items, objectClass: 'Activity', filters: { idProject: 2 }, pageSize: 2, fields: ['name'] });
  assert.equal(first.total, 3);
  assert.deepEqual(first.items, [{ id: 1, name: 'A' }, { id: 3, name: 'C' }]);
  assert.equal(first.hasMore, true);
  const second = filterAndPaginate({ items, objectClass: 'Activity', filters: { idProject: 2 }, cursor: first.nextCursor, pageSize: 2, fields: ['name'] });
  assert.deepEqual(second.items, [{ id: 4, name: 'D' }]);
  assert.equal(second.nextCursor, null);
  assert.throws(() => filterAndPaginate({ items, objectClass: 'Activity', filters: { idProject: 9 }, cursor: first.nextCursor, pageSize: 2 }), DomainError);
});

test('cursor encoding accepts the matching query', () => {
  const cursor = encodeCursor('Project', {}, undefined, 12);
  const page = filterAndPaginate({ items: [{ id: 13 }], objectClass: 'Project', cursor, pageSize: 1 });
  assert.equal(page.items[0].id, 13);
});

test('local batch references resolve recursively and fail closed', () => {
  const ids = new Map([['parent', 42]]);
  assert.deepEqual(resolveLocalReferences({ idActivity: { $ref: 'parent' }, nested: [{ $ref: 'parent' }] }, ids), { idActivity: 42, nested: [42] });
  assert.throws(() => resolveLocalReferences({ $ref: 'missing' }, ids), /not available/);
});

test('schema validation reports missing, unknown, readonly, type, and reference fields', () => {
  const schema = { fields: [
    { name: 'id', type: 'integer', required: false, writable: false },
    { name: 'name', type: 'string', required: true, writable: true },
    { name: 'idProject', type: 'integer', required: true, writable: true, referenceClass: 'Project' },
    { name: 'reference', type: 'string', required: false, writable: false }
  ] };
  const outcome = validateData(schema, { idProject: '2', reference: 'x', unexpected: 1 });
  assert.deepEqual(outcome.missingFields, ['name']);
  assert.deepEqual(outcome.invalidFields, [
    { field: 'reference', reason: 'not_writable' },
    { field: 'unexpected', reason: 'unknown_field' }
  ]);
  assert.deepEqual(outcome.referenceChecks, [{ field: 'idProject', objectClass: 'Project', id: 2 }]);
});

test('dependency relationship names map to ProjeQtOr codes and back', () => {
  assert.equal(relationshipToApi('finish_to_start'), 'E-S');
  assert.equal(dependencyFromApi({
    id: 7, predecessorRefType: 'Activity', predecessorRefId: 1,
    successorRefType: 'Milestone', successorRefId: 2, dependencyType: 'S-S', dependencyDelay: '-2'
  }).relationship, 'start_to_start');
});
