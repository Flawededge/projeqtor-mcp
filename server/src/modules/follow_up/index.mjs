import * as z from 'zod/v4';
import { defineModule } from '../runtime.mjs';
import { idempotencyKeySchema, registerCanonicalActionTool } from '../shared/action-tool.mjs';

const entry = z.object({
  resourceId: z.number().int().positive(),
  workDate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  refType: z.enum(['Activity', 'Ticket', 'Meeting', 'TestSession']),
  refId: z.number().int().positive(),
  work: z.number().nonnegative(),
  leftWork: z.number().nonnegative().optional(),
  comment: z.string().max(4000).optional(),
  expectedVersion: z.string().max(200).optional()
});

const inputSchema = z.object({
  entries: z.array(entry).min(1).max(200),
  transactionMode: z.enum(['atomic', 'best_effort']).default('atomic'),
  submitPeriod: z.boolean().default(false),
  idempotencyKey: idempotencyKeySchema
});

export default defineModule({
  id: 'follow_up', version: '2.0.0-beta.4', dependencies: ['core', 'planning'],
  claims: { actions: ['follow_up.work.record'], tools: ['projeqtor_record_work'] },
  register(registrar, context) {
    registerCanonicalActionTool(registrar, context, {
      name: 'projeqtor_record_work', action: 'follow_up.work.record', inputSchema,
      description: 'Validate and record up to 200 work entries as the authenticated user, optionally submitting the affected work period.',
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
    });
  }
});
