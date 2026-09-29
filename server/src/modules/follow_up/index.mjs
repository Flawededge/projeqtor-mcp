import * as z from 'zod/v4';
import { defineModule } from '../runtime.mjs';
import { idempotencyKeySchema, registerCanonicalActionTool } from '../shared/action-tool.mjs';

export const FOLLOW_UP_ACTIONS = Object.freeze([
  'follow_up.work.record',
  'follow_up.work.delete',
  'follow_up.dispatch_work.apply',
  'follow_up.timer.control',
  'follow_up.remaining_work.update',
  'follow_up.period.submit',
  'follow_up.period.validate',
  'follow_up.imputation_alert.generate',
  'follow_up.comment.add',
  'follow_up.timesheet.result'
]);

export const FOLLOW_UP_HANDLERS = Object.freeze([
  'tool:dynamicDialogCommentImputation',
  'tool:dynamicDialogDispatchWork',
  'tool:generateImputationAlert',
  'tool:saveDispatchWork',
  'tool:saveImputation',
  'tool:saveImputationValidation',
  'tool:saveLeftWork',
  'tool:saveWorkDetailImputation',
  'tool:startStopWork',
  'tool:submitWorkPeriod',
  'tool:timesheetAddResult'
]);


const detail = z.object({
  id: z.number().int().positive().optional(),
  operation: z.enum(['create', 'update', 'upsert']).optional(),
  expectedVersion: z.string().min(1).max(200).optional(),
  work: z.number().nonnegative(),
  idWorkCategory: z.number().int().positive().nullable().optional(),
  uncertainties: z.string().max(4000).nullable().optional(),
  progress: z.string().max(4000).nullable().optional()
});

const entry = z.object({
  id: z.number().int().positive().optional(),
  operation: z.enum(['create', 'update', 'upsert']).optional(),
  resourceId: z.number().int().positive(),
  workDate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  assignmentId: z.number().int().positive().nullable().optional(),
  workElementId: z.number().int().positive().nullable().optional(),
  refType: z.string().regex(/^[A-Za-z][A-Za-z0-9_]{0,99}$/).optional(),
  refId: z.number().int().positive().nullable().optional(),
  work: z.number().nonnegative(),
  leftWork: z.number().nonnegative().optional(),
  comment: z.string().max(4000).nullable().optional(),
  expectedVersion: z.string().min(1).max(200).optional(),
  assignmentExpectedVersion: z.string().min(1).max(200).optional(),
  details: z.array(detail).max(200).optional()
});

const inputSchema = z.object({
  entries: z.array(entry).min(1).max(200),
  transactionMode: z.enum(['atomic', 'best_effort']).default('atomic'),
  idempotencyKey: idempotencyKeySchema
});

export default defineModule({
  id: 'follow_up',
  version: '2.0.1',
  dependencies: ['core', 'planning', 'environment'],
  enabledStateRequirements: [],
  claims: {
    classes: ['Work', 'WorkDetail', 'WorkPeriod', 'WorkElement', 'LockedImputation'],
    actions: FOLLOW_UP_ACTIONS,
    handlers: FOLLOW_UP_HANDLERS,
    tools: ['projeqtor_record_work']
  },
  register(registrar, context) {
    registerCanonicalActionTool(registrar, context, {
      name: 'projeqtor_record_work', action: 'follow_up.work.record', inputSchema,
      description: 'Validate and atomically record up to 200 work entries as the authenticated user.',
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
    });
  }
});
