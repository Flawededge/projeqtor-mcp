import { McpServer } from '@modelcontextprotocol/server';
import * as z from 'zod/v4';
import {
  DEPENDENCY_RELATIONSHIPS,
  DEPENDENCY_TARGET_CLASSES,
  DomainError,
  READ_CLASSES,
  REFERENCE_KINDS,
  WRITE_CLASSES,
  cleanApiMessage,
  dependencyFromApi,
  filterAndPaginate,
  relationshipToApi,
  resolveLocalReferences,
  validateData
} from './domain.mjs';

const SERVER_VERSION = '2.0.0-beta.1';
const fieldNameSchema = z.string().regex(/^[A-Za-z][A-Za-z0-9_]*$/);
const readClassSchema = z.enum(READ_CLASSES);
const writeClassSchema = z.enum(WRITE_CLASSES);
const referenceKindSchema = z.enum(Object.keys(REFERENCE_KINDS));
const fieldsSchema = z.array(fieldNameSchema).max(80).optional();
const filterValueSchema = z.union([z.string().max(500), z.number(), z.boolean(), z.null()]);
const filtersSchema = z.record(fieldNameSchema, filterValueSchema)
  .refine(value => Object.keys(value).length <= 12, 'At most 12 filters may be supplied');
const objectDataSchema = z.record(fieldNameSchema, z.unknown())
  .refine(value => Object.keys(value).length <= 200, 'At most 200 fields may be supplied');
const dependencyRefSchema = z.object({
  objectClass: z.enum(DEPENDENCY_TARGET_CLASSES),
  id: z.number().int().positive()
});
const relationshipSchema = z.enum(Object.keys(DEPENDENCY_RELATIONSHIPS));

function apiPath(objectClass, selector, fields) {
  const encoded = [objectClass, selector].map(encodeURIComponent).join('/');
  if (!fields?.length) return encoded;
  return `${encoded}/select=${[...new Set(fields)].join(',')}`;
}

function result(value) {
  return {
    content: [{ type: 'text', text: JSON.stringify(value, null, 2) }],
    structuredContent: value
  };
}

function errorDetails(error) {
  if (error instanceof DomainError) {
    return { code: error.code, message: error.message, ...error.details };
  }
  return {
    code: typeof error?.code === 'string' ? error.code : 'projeqtor_error',
    message: error instanceof Error ? error.message : 'Unexpected ProjeQtOr bridge error',
    ...(error?.details && typeof error.details === 'object' ? error.details : {})
  };
}

function failure(error) {
  const payload = { ok: false, error: errorDetails(error) };
  return {
    isError: true,
    content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }],
    structuredContent: payload
  };
}

function writeResult(response, requestedData) {
  const saved = Array.isArray(response?.items) ? response.items[0] : null;
  if (!saved) throw new DomainError('invalid_write_response', 'ProjeQtOr returned no saved object');
  if (saved.apiResult && saved.apiResult !== 'OK') {
    throw new DomainError('validation_failed', cleanApiMessage(saved.apiResultMessage), {
      missingFields: [],
      invalidFields: [],
      referenceErrors: []
    });
  }

  const requestedFields = Object.keys(requestedData).filter(field => field !== 'id');
  const presentFields = requestedFields.filter(field => Object.hasOwn(saved, field));
  const equivalent = field => {
    const requested = requestedData[field];
    const actual = saved[field];
    if (requested === null || actual === null) return requested === actual;
    if (typeof requested === 'object' || typeof actual === 'object') {
      return JSON.stringify(requested) === JSON.stringify(actual);
    }
    return String(requested) === String(actual);
  };

  return {
    ok: true,
    id: Number(saved.id ?? requestedData.id),
    apiResult: saved.apiResult ?? 'OK',
    requestedFields,
    appliedFields: presentFields.filter(equivalent),
    recalculatedFields: presentFields.filter(field => !equivalent(field)),
    ignoredFields: requestedFields.filter(field => !Object.hasOwn(saved, field)),
    rejectedFields: [],
    saved
  };
}

function compactReference(item) {
  const result = { id: item.id, name: item.name };
  for (const field of ['code', 'idProject', 'idProfile', 'scope', 'sortOrder', 'idle']) {
    if (Object.hasOwn(item, field)) result[field] = item[field];
  }
  return result;
}

export function createProjeqtorServer({ username, apiRequest }) {
  const server = new McpServer({ name: 'projeqtor', version: SERVER_VERSION });
  const schemaCache = new Map();
  const referenceCache = new Map();

  async function getSchema(objectClass) {
    if (!schemaCache.has(objectClass)) {
      schemaCache.set(objectClass, apiRequest(`__mcp/schema/${encodeURIComponent(objectClass)}`, username));
    }
    try {
      return await schemaCache.get(objectClass);
    } catch (error) {
      schemaCache.delete(objectClass);
      throw error;
    }
  }

  async function referenceExists(objectClass, id) {
    if (!READ_CLASSES.includes(objectClass) || !Number.isInteger(id) || id <= 0) return null;
    const key = `${objectClass}:${id}`;
    if (!referenceCache.has(key)) {
      referenceCache.set(key, apiRequest(apiPath(objectClass, String(id), ['id']), username)
        .then(data => Array.isArray(data.items) && data.items.some(item => Number(item.id) === id))
        .catch(() => false));
    }
    return referenceCache.get(key);
  }

  async function validateReferences(referenceChecks) {
    const referenceErrors = [];
    const unverifiedReferences = [];
    for (const check of referenceChecks) {
      if (!READ_CLASSES.includes(check.objectClass) || check.id <= 0) {
        unverifiedReferences.push(check);
        continue;
      }
      if (!await referenceExists(check.objectClass, check.id)) {
        referenceErrors.push({ ...check, reason: 'not_found_or_inaccessible' });
      }
    }
    return { referenceErrors, unverifiedReferences };
  }

  server.registerTool('projeqtor_get_capabilities', {
    description: 'Describe supported object classes, operations, limits, units, and installed MCP feature level.',
    inputSchema: z.object({}),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, async () => result({
    serverVersion: SERVER_VERSION,
    schemaVersion: 1,
    classes: READ_CLASSES.map(objectClass => ({
      objectClass,
      operations: [
        'read',
        ...(WRITE_CLASSES.includes(objectClass) ? ['create', 'update'] : []),
        ...(objectClass === 'Dependency' ? ['delete'] : [])
      ]
    })),
    referenceKinds: REFERENCE_KINDS,
    dependencyRelationships: DEPENDENCY_RELATIONSHIPS,
    limits: { pageSize: 200, batchItems: 50, fields: 80, filters: 12 },
    units: {
      dependencyLag: 'working days',
      allocationRate: 'percent',
      assignmentRate: 'percent',
      work: 'ProjeQtOr deployment-configured work unit'
    }
  }));

  server.registerTool('projeqtor_get_object_schema', {
    description: 'Return installed-version field metadata including types, required fields, defaults, writability, and reference classes.',
    inputSchema: z.object({ objectClass: readClassSchema }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, async ({ objectClass }) => {
    try {
      const schema = await getSchema(objectClass);
      return result({
        ...schema,
        operations: {
          read: true,
          create: WRITE_CLASSES.includes(objectClass),
          update: WRITE_CLASSES.includes(objectClass),
          delete: objectClass === 'Dependency'
        }
      });
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_get_item', {
    description: 'Get one accessible ProjeQtOr object by class and numeric ID.',
    inputSchema: z.object({
      objectClass: readClassSchema,
      id: z.number().int().positive(),
      fields: fieldsSchema
    }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, async ({ objectClass, id, fields }) => {
    try {
      return result(await apiRequest(apiPath(objectClass, String(id), fields), username));
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_list_items', {
    description: 'List accessible ProjeQtOr objects with exact filters, stable cursor pagination, total count, and optional modified-since filtering.',
    inputSchema: z.object({
      objectClass: readClassSchema,
      fields: fieldsSchema,
      filters: filtersSchema.optional().default({}),
      modifiedSince: z.string().max(50).optional(),
      cursor: z.string().max(1000).optional(),
      pageSize: z.number().int().min(1).max(200).optional(),
      maxItems: z.number().int().min(1).max(200).optional()
    }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, async ({ objectClass, fields, filters, modifiedSince, cursor, pageSize, maxItems }) => {
    try {
      const effectivePageSize = pageSize ?? maxItems ?? 50;
      const supportFields = fields ? ['id', ...fields, ...Object.keys(filters)] : undefined;
      const selectedFields = modifiedSince ? undefined : supportFields;
      const data = await apiRequest(apiPath(objectClass, 'all', selectedFields), username);
      return result(filterAndPaginate({
        items: Array.isArray(data.items) ? data.items : [],
        objectClass, filters, modifiedSince, cursor, pageSize: effectivePageSize, fields
      }));
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_list_reference_values', {
    description: 'List supported ProjeQtOr reference data such as calendars, functions, teams, profiles, types, statuses, and planning modes.',
    inputSchema: z.object({
      kind: referenceKindSchema,
      activeOnly: z.boolean().default(true),
      search: z.string().max(200).optional(),
      filters: filtersSchema.optional().default({}),
      cursor: z.string().max(1000).optional(),
      pageSize: z.number().int().min(1).max(200).default(100)
    }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, async ({ kind, activeOnly, search, filters, cursor, pageSize }) => {
    try {
      const objectClass = REFERENCE_KINDS[kind];
      const data = await apiRequest(apiPath(objectClass, 'all'), username);
      let items = (Array.isArray(data.items) ? data.items : []).map(compactReference);
      if (activeOnly) items = items.filter(item => Number(item.idle ?? 0) === 0);
      if (search) {
        const term = search.toLocaleLowerCase();
        items = items.filter(item => String(item.name ?? '').toLocaleLowerCase().includes(term));
      }
      const page = filterAndPaginate({ items, objectClass: `reference:${kind}:${activeOnly}:${search ?? ''}`, filters, cursor, pageSize });
      return result({ kind, objectClass, ...page });
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_list_resource_choices', {
    description: 'List active standard calendars and main functions required when creating or enabling a ProjeQtOr resource.',
    inputSchema: z.object({}),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, async () => {
    try {
      const [calendarData, roleData] = await Promise.all([
        apiRequest(apiPath('CalendarDefinition', 'all', ['id', 'name', 'idle']), username),
        apiRequest(apiPath('Role', 'all', ['id', 'name', 'idle']), username)
      ]);
      const active = data => (Array.isArray(data.items) ? data.items : [])
        .filter(item => Number(item.idle ?? 0) === 0)
        .map(item => ({ id: item.id, name: item.name }));
      return result({ calendars: active(calendarData), mainFunctions: active(roleData) });
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_list_dependencies', {
    description: 'List predecessor links with stable pagination. Optionally restrict to links touching one activity or milestone.',
    inputSchema: z.object({
      item: dependencyRefSchema.optional(),
      direction: z.enum(['predecessors', 'successors', 'both']).default('both'),
      cursor: z.string().max(1000).optional(),
      pageSize: z.number().int().min(1).max(200).default(100)
    }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, async ({ item, direction, cursor, pageSize }) => {
    try {
      const fields = ['id', 'predecessorRefType', 'predecessorRefId', 'successorRefType', 'successorRefId', 'dependencyType', 'dependencyDelay', 'comment', 'idle'];
      const data = await apiRequest(apiPath('Dependency', 'all', fields), username);
      let items = Array.isArray(data.items) ? data.items : [];
      if (item) {
        items = items.filter(dependency => {
          const predecessor = dependency.predecessorRefType === item.objectClass && Number(dependency.predecessorRefId) === item.id;
          const successor = dependency.successorRefType === item.objectClass && Number(dependency.successorRefId) === item.id;
          if (direction === 'predecessors') return successor;
          if (direction === 'successors') return predecessor;
          return predecessor || successor;
        });
      }
      const identity = `Dependency:${item?.objectClass ?? '*'}:${item?.id ?? '*'}:${direction}`;
      const page = filterAndPaginate({ items, objectClass: identity, cursor, pageSize });
      return result({ ...page, items: page.items.map(dependencyFromApi) });
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_create_dependency', {
    description: 'Create a predecessor relationship between an activity or milestone. Lag is in working days.',
    inputSchema: z.object({
      predecessor: dependencyRefSchema,
      successor: dependencyRefSchema,
      relationship: relationshipSchema.default('finish_to_start'),
      lagDays: z.number().int().min(-999).max(999).default(0),
      comment: z.string().max(4000).optional()
    }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false }
  }, async ({ predecessor, successor, relationship, lagDays, comment }) => {
    try {
      const data = {
        predecessorRefType: predecessor.objectClass,
        predecessorRefId: predecessor.id,
        successorRefType: successor.objectClass,
        successorRefId: successor.id,
        dependencyType: relationshipToApi(relationship),
        dependencyDelay: lagDays,
        ...(comment === undefined ? {} : { comment })
      };
      const response = await apiRequest('Dependency', username, 'PUT', data, { allowItemErrors: true });
      return result(writeResult(response, data));
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_update_dependency', {
    description: 'Update relationship type, lag, or comment for one dependency.',
    inputSchema: z.object({
      id: z.number().int().positive(),
      relationship: relationshipSchema.optional(),
      lagDays: z.number().int().min(-999).max(999).optional(),
      comment: z.string().max(4000).optional()
    }).refine(value => value.relationship !== undefined || value.lagDays !== undefined || value.comment !== undefined, 'At least one change is required'),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
  }, async ({ id, relationship, lagDays, comment }) => {
    try {
      const changes = {
        id,
        ...(relationship === undefined ? {} : { dependencyType: relationshipToApi(relationship) }),
        ...(lagDays === undefined ? {} : { dependencyDelay: lagDays }),
        ...(comment === undefined ? {} : { comment })
      };
      const response = await apiRequest('Dependency', username, 'POST', changes, { allowItemErrors: true });
      return result(writeResult(response, changes));
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_delete_dependency', {
    description: 'Permanently remove one dependency link. This does not delete either linked activity or milestone.',
    inputSchema: z.object({ id: z.number().int().positive() }),
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false }
  }, async ({ id }) => {
    try {
      const response = await apiRequest('Dependency', username, 'DELETE', { id }, { allowItemErrors: true });
      return result(writeResult(response, { id }));
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_create_item', {
    description: 'Create an allow-listed ProjeQtOr project-management object as the authenticated user. Do not include an id.',
    inputSchema: z.object({ objectClass: writeClassSchema, data: objectDataSchema }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: false }
  }, async ({ objectClass, data }) => {
    try {
      if (Object.hasOwn(data, 'id')) throw new DomainError('id_not_allowed', 'Create data must not contain id; use projeqtor_update_item');
      const response = await apiRequest(encodeURIComponent(objectClass), username, 'PUT', data, { allowItemErrors: true });
      return result(writeResult(response, data));
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_update_item', {
    description: 'Update an allow-listed ProjeQtOr project-management object as the authenticated user. Only supplied writable fields are changed.',
    inputSchema: z.object({
      objectClass: writeClassSchema,
      id: z.number().int().positive(),
      changes: objectDataSchema
    }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
  }, async ({ objectClass, id, changes }) => {
    try {
      const response = await apiRequest(encodeURIComponent(objectClass), username, 'POST', { ...changes, id }, { allowItemErrors: true });
      return result(writeResult(response, changes));
    } catch (error) {
      return failure(error);
    }
  });

  server.registerTool('projeqtor_batch_upsert', {
    description: 'Validate or idempotently create/update up to 50 objects with per-item results and references to earlier local keys.',
    inputSchema: z.object({
      validationOnly: z.boolean().default(false),
      items: z.array(z.object({
        localKey: z.string().regex(/^[A-Za-z0-9_.:-]{1,100}$/),
        objectClass: writeClassSchema,
        data: objectDataSchema,
        migrationKey: z.string().min(1).max(255).optional(),
        match: filtersSchema.optional(),
        onExisting: z.enum(['return', 'update']).default('return')
      })).min(1).max(50)
    }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
  }, async ({ validationOnly, items }) => {
    const localIds = new Map();
    const seenKeys = new Set();
    const classLists = new Map();
    const outcomes = [];

    async function listClass(objectClass) {
      if (!classLists.has(objectClass)) {
        classLists.set(objectClass, apiRequest(apiPath(objectClass, 'all'), username)
          .then(data => Array.isArray(data.items) ? data.items : []));
      }
      return classLists.get(objectClass);
    }

    for (let index = 0; index < items.length; index += 1) {
      const item = items[index];
      try {
        if (seenKeys.has(item.localKey)) {
          throw new DomainError('duplicate_local_key', `Duplicate localKey '${item.localKey}'`);
        }
        seenKeys.add(item.localKey);

        const data = resolveLocalReferences(item.data, localIds);
        let match = item.match ? resolveLocalReferences(item.match, localIds) : null;
        if (item.migrationKey) {
          if (Object.hasOwn(data, 'externalReference') && data.externalReference !== item.migrationKey) {
            throw new DomainError('migration_key_conflict', 'migrationKey and data.externalReference must match');
          }
          data.externalReference = item.migrationKey;
          match = { ...(match ?? {}), externalReference: item.migrationKey };
        }
        if (Object.hasOwn(data, 'idProject')) match = { ...(match ?? {}), idProject: data.idProject };
        if (!match || Object.keys(match).length === 0) {
          throw new DomainError('idempotency_key_required', 'Supply migrationKey or match fields for every batch item');
        }

        const schema = await getSchema(item.objectClass);
        const validation = validateData(schema, data);
        const references = await validateReferences(validation.referenceChecks);
        const completeValidation = {
          ...validation,
          referenceErrors: references.referenceErrors,
          unverifiedReferences: references.unverifiedReferences
        };
        completeValidation.valid = completeValidation.valid && completeValidation.referenceErrors.length === 0;

        if (!completeValidation.valid) {
          outcomes.push({ localKey: item.localKey, objectClass: item.objectClass, status: 'invalid', ...completeValidation });
          continue;
        }
        if (validationOnly) {
          const virtualId = -(index + 1);
          localIds.set(item.localKey, virtualId);
          outcomes.push({ localKey: item.localKey, objectClass: item.objectClass, status: 'valid', virtualId, ...completeValidation });
          continue;
        }

        const existing = (await listClass(item.objectClass)).find(candidate =>
          Object.entries(match).every(([field, value]) => String(candidate[field] ?? '') === String(value ?? ''))
        );
        if (existing && item.onExisting === 'return') {
          const id = Number(existing.id);
          localIds.set(item.localKey, id);
          outcomes.push({ localKey: item.localKey, objectClass: item.objectClass, status: 'existing', id, match });
          continue;
        }

        const method = existing ? 'POST' : 'PUT';
        const payload = existing ? { ...data, id: Number(existing.id) } : data;
        const response = await apiRequest(encodeURIComponent(item.objectClass), username, method, payload, { allowItemErrors: true });
        const written = writeResult(response, data);
        localIds.set(item.localKey, written.id);
        if (!existing) (await listClass(item.objectClass)).push(written.saved);
        outcomes.push({
          localKey: item.localKey,
          objectClass: item.objectClass,
          status: existing ? 'updated' : 'created',
          ...written
        });
      } catch (error) {
        outcomes.push({ localKey: item.localKey, objectClass: item.objectClass, status: 'error', error: errorDetails(error) });
      }
    }

    const counts = outcomes.reduce((accumulator, item) => {
      accumulator[item.status] = (accumulator[item.status] ?? 0) + 1;
      return accumulator;
    }, {});
    return result({
      ok: outcomes.every(item => !['invalid', 'error'].includes(item.status)),
      validationOnly,
      counts,
      items: outcomes
    });
  });

  return server;
}
