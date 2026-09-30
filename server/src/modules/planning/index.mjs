import * as z from 'zod/v4';
import { defineModule } from '../runtime.mjs';
import { idempotencyKeySchema, registerCanonicalActionTool } from '../shared/action-tool.mjs';

export const PLANNING_ACTIONS = Object.freeze([
  'project.snapshot',
  'planning.assignment.upsert',
  'planning.assignment.remove',
  'planning.assignment.automatic',
  'planning.allocation.upsert',
  'planning.allocation.remove',
  'planning.dependency.upsert',
  'planning.dependency.remove',
  'planning.selection.delete',
  'planning.integrity.repair',
  'planning.grid.inline_edit',
  'planning.element.resize',
  'planning.element.phase',
  'planning.activity.split',
  'planning.scenario.configure',
  'planning.critical_resources.evaluate',
  'planning.calculate',
  'planning.wbs.renumber',
  'planning.diagnostics',
  'planning.baseline.create',
  'planning.baseline.delete'
]);

export const PLANNING_JOBS = Object.freeze([
  'project.snapshot',
  'planning.critical_resources.evaluate',
  'planning.calculate',
  'planning.wbs.renumber',
  'planning.baseline.create'
]);

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
  id: 'planning', version: '2.1.0', dependencies: ['core'],
  claims: { actions: PLANNING_ACTIONS, jobs: PLANNING_JOBS, tools: ['projeqtor_plan_projects'] },
  register(registrar, context) {
    registerCanonicalActionTool(registrar, context, {
      name: 'projeqtor_plan_projects', action: 'planning.calculate', inputSchema,
      description: 'Calculate or recalculate up to 200 projects with optional critical-path, resource, overbooking, and diagnostic output. Long calculations return a durable job.',
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
    });
  }
});
