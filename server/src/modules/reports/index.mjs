import * as z from 'zod/v4';
import { defineModule } from '../runtime.mjs';
import { idempotencyKeySchema, registerCanonicalActionTool } from '../shared/action-tool.mjs';

const inputSchema = z.object({
  idReport: z.number().int().positive(),
  format: z.enum(['pdf', 'png', 'csv', 'json']).default('pdf'),
  parameters: z.record(z.string(), z.unknown()).default({}),
  idempotencyKey: idempotencyKeySchema
});

export default defineModule({
  id: 'reports', version: '2.0.0-beta.4', dependencies: ['core'],
  claims: { actions: ['reports.render'], jobs: ['reports.render'], tools: ['projeqtor_render_report'] },
  register(registrar, context) {
    registerCanonicalActionTool(registrar, context, {
      name: 'projeqtor_render_report', action: 'reports.render', inputSchema,
      description: 'Render a permitted native report as PDF, image, CSV, or structured JSON and return its durable job resource.',
      annotations: { readOnlyHint: true, destructiveHint: false, idempotentHint: true }
    });
  }
});
