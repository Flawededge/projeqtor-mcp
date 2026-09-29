import * as z from 'zod/v4';
import { defineModule } from '../runtime.mjs';
import { idempotencyKeySchema, registerCanonicalActionTool } from '../shared/action-tool.mjs';
import { REPORTS_ACTIONS, REPORTS_HANDLERS, REPORTS_JOBS } from './contracts.mjs';

const parameterScalar = z.union([z.string().max(4000), z.number(), z.boolean(), z.null()]);
const parametersSchema = z.record(
  z.string().regex(/^[A-Za-z][A-Za-z0-9_]{0,79}$/),
  z.union([parameterScalar, z.array(parameterScalar).max(200)])
).superRefine((parameters, context) => {
  if (Object.keys(parameters).length > 100) context.addIssue({ code: 'custom', message: 'At most 100 parameters are allowed' });
  if (Buffer.byteLength(JSON.stringify(parameters), 'utf8') > 65_536) {
    context.addIssue({ code: 'custom', message: 'Parameters may not exceed 64 KiB' });
  }
});

const inputSchema = z.object({
  idReport: z.number().int().positive(),
  format: z.enum(['pdf', 'png', 'jpeg', 'csv', 'json']).default('pdf'),
  parameters: parametersSchema.default({}),
  idempotencyKey: idempotencyKeySchema
}).strict();

export default defineModule({
  id: 'reports', version: '2.0.0-beta.4', dependencies: ['core'],
  claims: { actions: REPORTS_ACTIONS, handlers: REPORTS_HANDLERS, jobs: REPORTS_JOBS, tools: ['projeqtor_render_report'] },
  register(registrar, context) {
    registerCanonicalActionTool(registrar, context, {
      name: 'projeqtor_render_report', action: 'reports.render', inputSchema,
      description: 'Render a permitted native report as PDF, image, CSV, or structured JSON and return its durable job resource.',
      annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true }
    });
  }
});
