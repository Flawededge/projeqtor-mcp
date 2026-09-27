import * as z from 'zod/v4';
import { defineModule } from '../runtime.mjs';
import { idempotencyKeySchema, registerCanonicalActionTool } from '../shared/action-tool.mjs';

const inputSchema = z.object({
  projectIds: z.array(z.number().int().positive()).min(1).max(200),
  startDate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/).optional(),
  criticalPath: z.boolean().default(false),
  allowOverbooking: z.boolean().default(false),
  criticalResourceMode: z.boolean().default(false),
  includeDiagnostics: z.boolean().default(true),
  idempotencyKey: idempotencyKeySchema
});

export default defineModule({
  id: 'planning', version: '2.0.0-beta.4', dependencies: ['core'],
  claims: { actions: ['planning.calculate'], jobs: ['planning.calculate'], tools: ['projeqtor_plan_projects'] },
  register(registrar, context) {
    registerCanonicalActionTool(registrar, context, {
      name: 'projeqtor_plan_projects', action: 'planning.calculate', inputSchema,
      description: 'Calculate or recalculate up to 200 projects with optional critical-path, resource, overbooking, and diagnostic output. Long calculations return a durable job.',
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
    });
  }
});
