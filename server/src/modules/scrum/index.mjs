import * as z from 'zod/v4';
import { defineModule } from '../runtime.mjs';
import { idempotencyKeySchema, registerCanonicalActionTool } from '../shared/action-tool.mjs';

const operation = z.object({
  operation: z.enum(['rank_backlog', 'start_sprint', 'close_sprint', 'move_kanban_card']),
  sprintId: z.number().int().positive().optional(),
  itemId: z.number().int().positive().optional(),
  boardId: z.number().int().positive().optional(),
  columnId: z.number().int().positive().optional(),
  beforeItemId: z.number().int().positive().optional(),
  afterItemId: z.number().int().positive().optional(),
  expectedVersion: z.string().max(200).optional()
});

const inputSchema = z.object({
  operations: z.array(operation).min(1).max(200),
  transactionMode: z.enum(['atomic', 'best_effort']).default('atomic'),
  idempotencyKey: idempotencyKeySchema
});

export default defineModule({
  id: 'scrum', version: '2.0.0-beta.4', dependencies: ['core', 'planning'],
  claims: { actions: ['scrum.sprint.manage'], tools: ['projeqtor_manage_sprint'] },
  register(registrar, context) {
    registerCanonicalActionTool(registrar, context, {
      name: 'projeqtor_manage_sprint', action: 'scrum.sprint.manage', inputSchema,
      description: 'Rank backlog items, start or close sprints, and move Kanban cards with bounded atomic or best-effort batches.',
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
    });
  }
});
