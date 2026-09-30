import * as z from 'zod/v4';
import { defineModule } from '../runtime.mjs';
import { idempotencyKeySchema, registerCanonicalActionTool } from '../shared/action-tool.mjs';

export const TICKETING_ACTIONS = Object.freeze([
  'ticketing.ticket.manage',
  'ticketing.dispatch',
  'ticketing.transition',
  'ticketing.escalate',
  'ticketing.synchronize',
  'ticketing.sla.evaluate',
  'ticketing.synchronization.inspect',
  'ticketing.synchronization.configure',
  'ticketing.synchronization.disable'
]);

export const TICKETING_HANDLERS = Object.freeze([
  'tool:saveDisableSynchronizationDefinition',
  'tool:saveSynchronizationDefinition'
]);

export const TICKETING_CLASSES = Object.freeze([
  'InputMailboxTicket', 'MacroTicketStatus', 'Synchronization', 'SynchronizedItems',
  'Ticket', 'TicketDelay', 'TicketDelayPerProject', 'TicketMain', 'TicketSimple',
  'TicketSimpleMain', 'TicketType'
]);

const operation = z.object({
  ticketId: z.number().int().positive(),
  operation: z.enum(['dispatch', 'transition', 'escalate', 'synchronize']),
  resourceId: z.number().int().positive().optional(),
  teamId: z.number().int().positive().optional(),
  statusId: z.number().int().positive().optional(),
  priorityId: z.number().int().positive().optional(),
  urgencyId: z.number().int().positive().optional(),
  criticalityId: z.number().int().positive().optional(),
  reason: z.string().max(4000).optional(),
  expectedVersion: z.string().min(1).max(200)
});

const inputSchema = z.object({
  operations: z.array(operation).min(1).max(200),
  transactionMode: z.enum(['atomic', 'best_effort']).default('atomic'),
  idempotencyKey: idempotencyKeySchema
});

export default defineModule({
  id: 'ticketing', version: '2.1.0',
  dependencies: ['core'],
  enabledStateRequirements: ['ticket-class-installed'],
  claims: {
    classes: TICKETING_CLASSES,
    actions: TICKETING_ACTIONS,
    handlers: TICKETING_HANDLERS,
    tools: ['projeqtor_manage_ticket']
  },
  register(registrar, context) {
    registerCanonicalActionTool(registrar, context, {
      name: 'projeqtor_manage_ticket', action: 'ticketing.ticket.manage', inputSchema,
      description: 'Dispatch, transition, escalate, or synchronize up to 200 tickets with required optimistic concurrency versions.',
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
    });
  }
});
