import * as z from 'zod/v4';
import { DomainError, cleanApiMessage } from '../../domain.mjs';

export const canonicalActionResultSchema = z.object({
  ok: z.boolean(),
  queued: z.boolean().optional(),
  idempotencyReplay: z.boolean().optional(),
  operationId: z.number().int().positive().optional(),
  job: z.object({
    id: z.number().int().positive(),
    type: z.string(),
    status: z.string(),
    progress: z.number(),
    resultResource: z.string().optional()
  }).loose().optional(),
  items: z.array(z.unknown()).optional()
}).loose();

export const idempotencyKeySchema = z.string().regex(/^[A-Za-z0-9_.:-]{1,255}$/).optional();

function toolResult(value) {
  return {
    content: [{ type: 'text', text: JSON.stringify(value, null, 2) }],
    structuredContent: value
  };
}

function toolFailure(error) {
  const payload = {
    ok: false,
    error: {
      code: error instanceof DomainError
        ? error.code
        : (typeof error?.code === 'string' ? error.code : 'projeqtor_error'),
      message: cleanApiMessage(error instanceof Error
        ? error.message
        : 'Unexpected ProjeQtOr bridge error'),
      ...(error?.details && typeof error.details === 'object' ? error.details : {})
    }
  };
  return { isError: true, content: [{ type: 'text', text: JSON.stringify(payload, null, 2) }], structuredContent: payload };
}

export function canonicalActionTool({ action, selectArguments = ({ idempotencyKey: _key, ...arguments_ }) => arguments_ }) {
  return async (input, { username, apiRequest }) => {
    try {
      const arguments_ = selectArguments(input);
      const body = {
        action,
        arguments: arguments_,
        ...(input.idempotencyKey === undefined ? {} : { idempotencyKey: input.idempotencyKey })
      };
      return toolResult(await apiRequest('__mcp/v2/actions/execute', username, 'POST', body, { allowItemErrors: true }));
    } catch (error) {
      return toolFailure(error);
    }
  };
}

export function registerCanonicalActionTool(registrar, context, specification) {
  const handler = canonicalActionTool(specification);
  registrar.registerTool(specification.name, {
    description: specification.description,
    inputSchema: specification.inputSchema,
    outputSchema: specification.outputSchema ?? canonicalActionResultSchema,
    annotations: specification.annotations
  }, input => handler(input, context));
}
