import { createHash } from 'node:crypto';

export class DomainError extends Error {
  constructor(code, message, details = {}) {
    super(message);
    this.name = 'DomainError';
    this.code = code;
    this.details = details;
  }
}

export const READ_CLASSES = Object.freeze([
  'Action', 'Activity', 'ActivityType', 'Affectation', 'Assignment',
  'CalendarDefinition', 'Dependency', 'Document', 'Issue', 'Meeting',
  'Milestone', 'MilestoneType', 'Opportunity', 'PlanningMode', 'Profile',
  'Project', 'ProjectType', 'Requirement', 'Resource', 'Risk', 'Role',
  'Status', 'Team', 'TestCase', 'TestSession', 'Ticket', 'TicketType',
  'Version', 'Work'
]);

export const WRITE_CLASSES = Object.freeze([
  'Action', 'Activity', 'Affectation', 'Assignment', 'Dependency', 'Document',
  'Issue', 'Meeting', 'Milestone', 'Opportunity', 'Project', 'Requirement',
  'Resource', 'Risk', 'Team', 'TestCase', 'TestSession', 'Ticket', 'Version', 'Work'
]);

export const REFERENCE_KINDS = Object.freeze({
  activityPlanningMode: 'ActivityPlanningMode',
  activityType: 'ActivityType',
  calendar: 'CalendarDefinition',
  mainFunction: 'Role',
  milestoneType: 'MilestoneType',
  planningMode: 'PlanningMode',
  profile: 'Profile',
  projectType: 'ProjectType',
  status: 'Status',
  team: 'Team',
  ticketType: 'TicketType'
});

export const DEPENDENCY_TARGET_CLASSES = Object.freeze(['Activity', 'Milestone']);
export const DEPENDENCY_RELATIONSHIPS = Object.freeze({
  finish_to_start: 'E-S',
  finish_to_finish: 'E-E',
  start_to_start: 'S-S'
});

const fieldNamePattern = /^[A-Za-z][A-Za-z0-9_]*$/;

function stableObject(value) {
  if (Array.isArray(value)) return value.map(stableObject);
  if (!value || typeof value !== 'object') return value;
  return Object.fromEntries(Object.keys(value).sort().map(key => [key, stableObject(value[key])]));
}

function fingerprint(objectClass, filters, modifiedSince) {
  return createHash('sha256')
    .update(JSON.stringify(stableObject({ objectClass, filters, modifiedSince })))
    .digest('hex')
    .slice(0, 20);
}

export function encodeCursor(objectClass, filters, modifiedSince, afterId) {
  return Buffer.from(JSON.stringify({
    version: 1,
    afterId,
    fingerprint: fingerprint(objectClass, filters, modifiedSince)
  }), 'utf8').toString('base64url');
}

export function decodeCursor(cursor, objectClass, filters, modifiedSince) {
  if (!cursor) return 0;
  let parsed;
  try {
    parsed = JSON.parse(Buffer.from(cursor, 'base64url').toString('utf8'));
  } catch {
    throw new DomainError('invalid_cursor', 'The pagination cursor is malformed');
  }
  if (parsed?.version !== 1 || !Number.isSafeInteger(parsed.afterId) || parsed.afterId < 0 ||
      parsed.fingerprint !== fingerprint(objectClass, filters, modifiedSince)) {
    throw new DomainError('invalid_cursor', 'The pagination cursor does not match this query');
  }
  return parsed.afterId;
}

function valuesEqual(actual, expected) {
  if (expected === null) return actual === null || actual === undefined || actual === '';
  if (typeof expected === 'boolean') return Boolean(Number(actual ?? 0)) === expected;
  if (typeof expected === 'number') return Number(actual) === expected;
  return String(actual ?? '') === String(expected);
}

function modifiedValue(item) {
  return item.lastUpdateDateTime ?? item.updateDateTime ?? item.creationDateTime ?? item.creationDate;
}

function projectItem(item, fields) {
  if (!fields?.length) return item;
  const projected = {};
  for (const field of ['id', ...fields]) {
    if (Object.hasOwn(item, field)) projected[field] = item[field];
  }
  return projected;
}

export function filterAndPaginate({ items, objectClass, filters = {}, modifiedSince, cursor, pageSize, fields }) {
  const afterId = decodeCursor(cursor, objectClass, filters, modifiedSince);
  const sinceTime = modifiedSince ? Date.parse(modifiedSince) : null;
  if (modifiedSince && Number.isNaN(sinceTime)) {
    throw new DomainError('invalid_modified_since', 'modifiedSince must be an ISO-8601 date-time');
  }

  const filtered = items
    .filter(item => Object.entries(filters).every(([field, value]) => valuesEqual(item[field], value)))
    .filter(item => {
      if (sinceTime === null) return true;
      const candidate = Date.parse(modifiedValue(item) ?? '');
      return !Number.isNaN(candidate) && candidate >= sinceTime;
    })
    .sort((left, right) => Number(left.id) - Number(right.id));

  const remaining = filtered.filter(item => Number(item.id) > afterId);
  const page = remaining.slice(0, pageSize);
  const hasMore = remaining.length > page.length;
  const lastId = page.length ? Number(page.at(-1).id) : afterId;

  return {
    identifier: 'id',
    total: filtered.length,
    returned: page.length,
    pageSize,
    hasMore,
    truncated: hasMore,
    nextCursor: hasMore ? encodeCursor(objectClass, filters, modifiedSince, lastId) : null,
    items: page.map(item => projectItem(item, fields))
  };
}

export function resolveLocalReferences(value, localIds) {
  if (Array.isArray(value)) return value.map(entry => resolveLocalReferences(entry, localIds));
  if (!value || typeof value !== 'object') return value;
  const keys = Object.keys(value);
  if (keys.length === 1 && keys[0] === '$ref') {
    const key = value.$ref;
    if (typeof key !== 'string' || !localIds.has(key)) {
      throw new DomainError('unresolved_batch_reference', `Batch reference '${String(key)}' is not available`, { reference: key });
    }
    return localIds.get(key);
  }
  return Object.fromEntries(Object.entries(value).map(([key, entry]) => [key, resolveLocalReferences(entry, localIds)]));
}

function missingValue(value) {
  return value === undefined || value === null || value === '';
}

function typeMatches(type, value) {
  if (missingValue(value)) return true;
  if (type === 'integer') return Number.isInteger(Number(value));
  if (type === 'number') return Number.isFinite(Number(value));
  if (type === 'boolean') return typeof value === 'boolean' || value === 0 || value === 1 || value === '0' || value === '1';
  if (type === 'string') return typeof value === 'string';
  return true;
}

export function validateData(schema, data, { creating = true } = {}) {
  const schemaFields = new Map((schema?.fields ?? []).map(field => [field.name, field]));
  const missingFields = [];
  const invalidFields = [];
  const referenceChecks = [];

  if (creating) {
    for (const field of schemaFields.values()) {
      if (field.required && field.writable && missingValue(data[field.name]) && missingValue(field.default)) {
        missingFields.push(field.name);
      }
    }
  }

  for (const [name, value] of Object.entries(data)) {
    if (!fieldNamePattern.test(name)) {
      invalidFields.push({ field: name, reason: 'invalid_name' });
      continue;
    }
    const field = schemaFields.get(name);
    if (!field) {
      invalidFields.push({ field: name, reason: 'unknown_field' });
      continue;
    }
    if (!field.writable && name !== 'id') {
      invalidFields.push({ field: name, reason: 'not_writable' });
    } else if (!typeMatches(field.type, value)) {
      invalidFields.push({ field: name, reason: `expected_${field.type}` });
    }
    if (field.referenceClass && !missingValue(value)) {
      referenceChecks.push({ field: name, objectClass: field.referenceClass, id: Number(value) });
    }
  }

  return {
    valid: missingFields.length === 0 && invalidFields.length === 0,
    missingFields,
    invalidFields,
    referenceChecks
  };
}

export function relationshipToApi(relationship) {
  const value = DEPENDENCY_RELATIONSHIPS[relationship];
  if (!value) throw new DomainError('invalid_dependency_type', `Unsupported dependency relationship '${relationship}'`);
  return value;
}

export function dependencyFromApi(item) {
  const relationship = Object.entries(DEPENDENCY_RELATIONSHIPS).find(([, value]) => value === item.dependencyType)?.[0] ?? item.dependencyType;
  return {
    id: item.id,
    predecessor: { objectClass: item.predecessorRefType, id: item.predecessorRefId },
    successor: { objectClass: item.successorRefType, id: item.successorRefId },
    relationship,
    lagDays: Number(item.dependencyDelay ?? 0),
    comment: item.comment ?? '',
    idle: Number(item.idle ?? 0)
  };
}

export function cleanApiMessage(value) {
  let message = String(value ?? 'Unknown ProjeQtOr validation error');
  try {
    const decoded = JSON.parse(message);
    if (typeof decoded === 'string') message = decoded;
  } catch {}
  return message
    .replace(/<br\s*\/?\s*>/gi, ' ')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&quot;/gi, '"')
    .replace(/&#039;|&apos;/gi, "'")
    .replace(/&amp;/gi, '&')
    .replace(/\s+/g, ' ')
    .trim();
}
