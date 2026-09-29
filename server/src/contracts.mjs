export const SERVER_VERSION = '2.0.1';

export const FILTER_OPERATORS = Object.freeze([
  'eq', 'ne', 'lt', 'lte', 'gt', 'gte', 'in', 'not_in',
  'contains', 'starts_with', 'ends_with', 'is_null', 'is_not_null'
]);

export const TRANSACTION_MODES = Object.freeze(['atomic', 'best_effort']);

export const ACTION_RISK = Object.freeze({
  read: 'read',
  write: 'write',
  destructive: 'destructive',
  administrative: 'administrative',
  external: 'external_side_effect'
});

export const LIMITS = Object.freeze({
  pageSize: 200,
  batchItems: 200,
  fields: 120,
  filters: 40,
  uploadChunkBytes: 524288,
  requestBytes: 4 * 1024 * 1024
});
