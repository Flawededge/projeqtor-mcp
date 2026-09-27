import * as z from 'zod/v4';
import { defineModule } from '../runtime.mjs';
import { idempotencyKeySchema, registerCanonicalActionTool } from '../shared/action-tool.mjs';

const operation = z.object({
  ticketId: z.number().int().positive(),
  operation: z.enum(['dispatch', 'transition', 'escalate', 'synchronize']),
  resourceId: z.number().int().positive().optional(),
  teamId: z.number().int().positive().optional(),
  statusId: z.number().int().positive().optional(),
  priorityId: z.number().int().positive().optional(),
  targetTicketId: z.number().int().positive().optional(),
  reason: z.string().max(4000).optional(),
  expectedVersion: z.string().max(200).optional()
});

const inputSchema = z.object({
  operations: z.array(operation).min(1).max(200),
  transactionMode: z.enum(['atomic', 'best_effort']).default('atomic'),
  idempotencyKey: idempotencyKeySchema
});

export default defineModule({
  id: 'ticketing', version: '2.0.0-beta.4', dependencies: ['core'],
  claims: { actions: ['ticketing.ticket.manage'], tools: ['projeqtor_manage_ticket'] },
  register(registrar, context) {
    registerCanonicalActionTool(registrar, context, {
      name: 'projeqtor_manage_ticket', action: 'ticketing.ticket.manage', inputSchema,
      description: 'Dispatch, transition, escalate, or synchronize up to 200 tickets with optimistic concurrency protection.',
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
    });
  }
});
