import { ResourceTemplate } from '@modelcontextprotocol/server';
import * as z from 'zod/v4';
import { DomainError, cleanApiMessage } from './domain.mjs';
import { FILTER_OPERATORS, LIMITS, TRANSACTION_MODES } from './contracts.mjs';

const className = z.string().regex(/^[A-Za-z][A-Za-z0-9_]{0,99}$/);
const fieldName = z.string().regex(/^[A-Za-z][A-Za-z0-9_]*$/);
const scalar = z.union([z.string().max(10000), z.number(), z.boolean(), z.null()]);
const jsonObject = z.record(z.string(), z.unknown());
const fields = z.array(fieldName).max(LIMITS.fields).optional();

const predicate = z.object({
  field: fieldName,
  operator: z.enum(FILTER_OPERATORS),
  value: z.union([scalar, z.array(scalar).max(500)]).optional()
});
const filterNode = z.lazy(() => z.union([
  predicate,
  z.object({ all: z.array(filterNode).min(1).max(LIMITS.filters) }),
  z.object({ any: z.array(filterNode).min(1).max(LIMITS.filters) }),
  z.object({ not: filterNode })
]));

const operation = z.object({
  localKey: z.string().regex(/^[A-Za-z0-9_.:-]{1,100}$/).optional(),
  action: z.enum(['create', 'update', 'delete']),
  objectClass: className,
  id: z.number().int().positive().optional(),
  data: jsonObject.optional().default({}),
  expectedVersion: z.string().max(200).optional(),
  idempotencyKey: z.string().min(1).max(255).optional()
});

function result(value) {
  return {
    content: [{ type: 'text', text: JSON.stringify(value, null, 2) }],
    structuredContent: value
  };
}

function failure(error) {
  const details = error instanceof DomainError
    ? { code: error.code, message: error.message, ...error.details }
    : {
        code: typeof error?.code === 'string' ? error.code : 'projeqtor_error',
        message: cleanApiMessage(error instanceof Error ? error.message : 'Unexpected ProjeQtOr bridge error'),
        ...(error?.details && typeof error.details === 'object' ? error.details : {})
      };
  const payload = { ok: false, error: details };
  return {
    isError: true,
    content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }],
    structuredContent: payload
  };
}

function guarded(handler) {
  return async args => {
    try {
      return result(await handler(args));
    } catch (error) {
      return failure(error);
    }
  };
}

function post(apiRequest, path, username, body) {
  return apiRequest(path, username, 'POST', body, { allowItemErrors: true });
}

export function registerFullControlTools(server, { username, apiRequest }) {
  server.registerTool('projeqtor_whoami', {
    description: 'Return the authenticated ProjeQtOr identity, profile and effective MCP scopes without exposing credentials.',
    inputSchema: z.object({}),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(() => apiRequest('__mcp/v2/whoami', username)));

  server.registerTool('projeqtor_list_object_classes', {
    description: 'List every installed ProjeQtOr model classification and the operations permitted to this user. Internal and sensitive models include an exclusion reason.',
    inputSchema: z.object({
      classification: z.enum(['business', 'reference', 'relation', 'administrative', 'derived', 'sensitive', 'internal']).optional(),
      supportedOnly: z.boolean().default(false),
      search: z.string().max(200).optional(),
      cursor: z.string().max(1000).optional(),
      pageSize: z.number().int().min(1).max(LIMITS.pageSize).default(100)
    }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/classes', username, args)));

  server.registerTool('projeqtor_list_ui_handlers', {
    description: 'List every installed ProjeQtOr tool/view entrypoint with module, mutation kinds, source hash, coverage classification, mappings, availability, risk, and Beta 4 issue.',
    inputSchema: z.object({
      module: z.enum(['planning_followup_environment', 'ticketing_scrum', 'steering_reports', 'financial_products', 'hr_tools_configuration']).optional(),
      classification: z.enum(['generic_crud', 'registered_action', 'read_only', 'intentional_exclusion', 'deferred_beta4']).optional(),
      mutationType: z.string().max(100).optional(),
      search: z.string().max(200).optional(),
      cursor: z.string().max(2000).optional(),
      pageSize: z.number().int().min(1).max(LIMITS.pageSize).default(100)
    }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/ui-handlers', username, args)));

  server.registerTool('projeqtor_query_items', {
    description: 'Query a permitted ProjeQtOr class with database-side structured filters, saved filters, selected fields, stable keyset pagination and optional total count.',
    inputSchema: z.object({
      objectClass: className,
      fields,
      filter: filterNode.optional(),
      savedFilterId: z.number().int().positive().optional(),
      orderBy: z.array(z.object({ field: fieldName, direction: z.enum(['asc', 'desc']).default('asc') })).length(1).default([{ field: 'id', direction: 'asc' }]),
      cursor: z.string().max(2000).optional(),
      pageSize: z.number().int().min(1).max(LIMITS.pageSize).default(50),
      includeTotal: z.boolean().default(false)
    }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/query', username, args)));

  server.registerTool('projeqtor_get_changes', {
    description: 'Return History-aware changes and deletion tombstones for a permitted class over an ISO-8601 time window.',
    inputSchema: z.object({
      objectClass: className,
      since: z.string().max(50),
      until: z.string().max(50).optional(),
      fields,
      cursor: z.string().max(2000).optional(),
      pageSize: z.number().int().min(1).max(LIMITS.pageSize).default(100)
    }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/changes', username, args)));

  server.registerTool('projeqtor_validate_operations', {
    description: 'Validate a mixed create, update and delete batch without saving it. Resolves local references and returns field, reference, permission and concurrency errors.',
    inputSchema: z.object({ operations: z.array(operation).min(1).max(LIMITS.batchItems) }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/operations/validate', username, args)));

  server.registerTool('projeqtor_execute_operations', {
    description: 'Execute a validated mixed batch. Atomic mode rolls the complete batch back on any failure; best_effort returns per-item outcomes.',
    inputSchema: z.object({
      transactionMode: z.enum(TRANSACTION_MODES).default('atomic'),
      importRunId: z.string().regex(/^[A-Za-z0-9_.:-]{1,150}$/).optional(),
      requestIdempotencyKey: z.string().regex(/^[A-Za-z0-9_.:-]{1,255}$/).optional(),
      operations: z.array(operation.omit({ action: true }).extend({ action: z.enum(['create', 'update']) })).min(1).max(LIMITS.batchItems)
    }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
  }, guarded(args => post(apiRequest, '__mcp/v2/operations/execute', username, args)));

  server.registerTool('projeqtor_prepare_change', {
    description: 'Preview deletes or guarded administrative changes and return an expiring actor-bound confirmation token. No mutation is performed.',
    inputSchema: z.object({ operations: z.array(operation).min(1).max(LIMITS.batchItems) }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/changes/prepare', username, args)));

  server.registerTool('projeqtor_commit_change', {
    description: 'Commit a previously previewed destructive or administrative change after targets, versions, permissions and replay protection are rechecked.',
    inputSchema: z.object({ confirmationToken: z.string().min(20).max(10000) }),
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/changes/commit', username, args)));

  server.registerTool('projeqtor_list_actions', {
    description: 'List registered high-level ProjeQtOr actions, their risk, synchrony, domain and availability to this user.',
    inputSchema: z.object({ domain: z.string().max(100).optional(), availableOnly: z.boolean().default(true) }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/actions', username, args)));

  server.registerTool('projeqtor_get_action_schema', {
    description: 'Return the input and result schema for one registered high-level action.',
    inputSchema: z.object({ action: z.string().regex(/^[a-z][a-z0-9_.-]{2,100}$/) }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(({ action }) => apiRequest(`__mcp/v2/actions/${encodeURIComponent(action)}`, username)));

  server.registerTool('projeqtor_execute_action', {
    description: 'Execute a non-destructive high-level ProjeQtOr workflow action. Guarded actions must use prepare_action and commit_action.',
    inputSchema: z.object({
      action: z.string().regex(/^[a-z][a-z0-9_.-]{2,100}$/),
      arguments: jsonObject.default({}),
      idempotencyKey: z.string().regex(/^[A-Za-z0-9_.:-]{1,255}$/).optional()
    }),
    annotations: { readOnlyHint: false, destructiveHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/actions/execute', username, args)));

  server.registerTool('projeqtor_prepare_action', {
    description: 'Preview an administrative, destructive or external-side-effect action and return an expiring confirmation token.',
    inputSchema: z.object({ action: z.string().regex(/^[a-z][a-z0-9_.-]{2,100}$/), arguments: jsonObject.default({}) }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/actions/prepare', username, args)));

  server.registerTool('projeqtor_commit_action', {
    description: 'Commit a previously previewed guarded action after revalidating its actor, arguments, rights and expiry.',
    inputSchema: z.object({ confirmationToken: z.string().min(20).max(10000) }),
    annotations: { readOnlyHint: false, destructiveHint: true, idempotentHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/actions/commit', username, args)));

  server.registerTool('projeqtor_list_jobs', {
    description: 'List durable MCP operations owned by the authenticated user, with status and progress.',
    inputSchema: z.object({ status: z.enum(['queued', 'running', 'succeeded', 'failed', 'cancel_requested', 'cancelled', 'expired', 'recovery_required']).optional(), cursor: z.string().max(2000).optional(), pageSize: z.number().int().min(1).max(200).default(50) }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(args => post(apiRequest, '__mcp/v2/jobs', username, args)));

  server.registerTool('projeqtor_retry_job', {
    description: 'Retry a failed or cancelled job only when its registered retry policy is safe, the caller owns it, permissions still pass, and attempts remain.',
    inputSchema: z.object({ id: z.number().int().positive() }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
  }, guarded(args => post(apiRequest, '__mcp/v2/jobs/retry', username, args)));

  server.registerTool('projeqtor_get_job', {
    description: 'Get one durable MCP operation, progress, structured result metadata and result resource URI.',
    inputSchema: z.object({ id: z.number().int().positive() }),
    annotations: { readOnlyHint: true, destructiveHint: false }
  }, guarded(({ id }) => apiRequest(`__mcp/v2/jobs/${id}`, username)));

  server.registerTool('projeqtor_cancel_job', {
    description: 'Request cancellation of a queued or running MCP operation. Running calculations stop only at safe cooperative boundaries.',
    inputSchema: z.object({ id: z.number().int().positive() }),
    annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
  }, guarded(args => post(apiRequest, '__mcp/v2/jobs/cancel', username, args)));

  for (const [name, template, mimeType, endpoint] of [
    ['projeqtor-attachment', 'projeqtor://attachments/{id}', 'application/octet-stream', 'attachment'],
    ['projeqtor-document-version', 'projeqtor://document-versions/{id}', 'application/octet-stream', 'document-version'],
    ['projeqtor-job-result', 'projeqtor://jobs/{id}/result', 'application/json', 'job-result']
  ]) {
    server.registerResource(name, new ResourceTemplate(template, { list: undefined }), {
      title: name.replaceAll('-', ' '), mimeType
    }, async (uri, variables) => {
      const id = Number(variables.id);
      if (!Number.isInteger(id) || id <= 0) throw new Error('Invalid resource identifier');
      const data = await apiRequest(`__mcp/v2/resources/${endpoint}/${id}`, username);
      return {
        contents: [{ uri: uri.href, mimeType: data.mimeType ?? mimeType, blob: data.base64 }]
      };
    });
  }
}
