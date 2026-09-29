import * as z from 'zod/v4';
import { defineModule } from '../runtime.mjs';
import { idempotencyKeySchema, registerCanonicalActionTool } from '../shared/action-tool.mjs';

export const SCRUM_ACTIONS = Object.freeze([
  'scrum.sprint.manage', 'scrum.story.manage', 'scrum.backlog.prioritize',
  'scrum.sprint.lifecycle', 'scrum.kanban.board.get', 'scrum.kanban.board.manage',
  'scrum.kanban.columns.replace', 'scrum.kanban.card.move', 'scrum.kanban.preferences',
  'scrum.poker.state.get', 'scrum.poker.session.lifecycle', 'scrum.poker.item.manage',
  'scrum.poker.item.state', 'scrum.poker.vote.cast', 'scrum.poker.vote.visibility'
]);

export const SCRUM_HANDLERS = Object.freeze([
  'tool:backlogCheckRequiredField', 'tool:dynamicDialogKanbanUpdate', 'tool:flipPokerVote',
  'tool:kanbanAdd', 'tool:kanbanColumnAdd', 'tool:kanbanColumnEdit', 'tool:kanbanCopy',
  'tool:kanbanDel', 'tool:kanbanEditName', 'tool:kanbanFunction',
  'tool:kanbanScrumSimplifiedService', 'tool:kanbanShare', 'tool:kanbanUpdate',
  'tool:openPokerItemVote', 'tool:removePokerItem', 'tool:saveKanbanColumnManagment',
  'tool:savePokerItem', 'tool:saveUserStory', 'tool:startPausePokerSession',
  'tool:startPokerSession', 'tool:voteToPokerItem', 'view:kanbanView', 'view:kanbanViewMain'
]);

export const SCRUM_OWNERSHIP_CORRECTIONS = Object.freeze({
  'tool:saveNoteStreamBacklog': 'tools',
  'tool:saveNoteStreamKanban': 'tools'
});

const operation = z.object({
  operation: z.enum([
    'rank_backlog', 'start_sprint', 'pause_sprint', 'resume_sprint',
    'close_sprint', 'reopen_sprint', 'move_kanban_card'
  ]),
  sprintId: z.number().int().positive().optional(),
  itemId: z.number().int().positive().optional(),
  objectClass: z.enum([
    'Ticket', 'Activity', 'Action', 'Requirement', 'UserStory',
    'Epic', 'Risk', 'Issue', 'Question', 'Decision'
  ]).optional(),
  boardId: z.number().int().positive().optional(),
  columnType: z.enum(['Status', 'Sprint']).optional(),
  columnId: z.number().int().positive().nullable().optional(),
  priorityId: z.number().int().positive().optional(),
  beforeItemId: z.number().int().positive().optional(),
  afterItemId: z.number().int().positive().optional(),
  expectedVersion: z.string().min(1).max(200)
});

const inputSchema = z.object({
  operations: z.array(operation).min(1).max(200),
  transactionMode: z.enum(['atomic', 'best_effort']).default('atomic'),
  idempotencyKey: idempotencyKeySchema
});

export default defineModule({
  id: 'scrum',
  version: '2.0.0-beta.4',
  dependencies: ['core', 'planning'],
  enabledStateRequirements: [],
  claims: {
    classes: [
      'Sprint', 'UserStory', 'Epic', 'Kanban', 'PokerSession', 'PokerItem',
      'PokerVote', 'PokerResource', 'PokerComplexity', 'ScrumPriority'
    ],
    actions: SCRUM_ACTIONS,
    handlers: SCRUM_HANDLERS,
    tools: ['projeqtor_manage_sprint']
  },
  register(registrar, context) {
    registerCanonicalActionTool(registrar, context, {
      name: 'projeqtor_manage_sprint',
      action: 'scrum.sprint.manage',
      inputSchema,
      description: 'Prioritize backlog stories, control sprint lifecycle, and move Kanban cards using version-checked batches.',
      annotations: { readOnlyHint: false, destructiveHint: false, idempotentHint: true }
    });
  }
});
