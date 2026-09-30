import { defineModule } from '../runtime.mjs';

export const STEERING_ACTIONS = Object.freeze([
  'steering.state.query',
  'steering.business.manage',
  'steering.approval.decide',
  'steering.approver.manage',
  'steering.checklist.update',
  'steering.checklist_definition.manage',
  'steering.raci.update',
  'steering.meeting.assign_team',
  'steering.meeting.link',
  'steering.meeting.live_update',
  'steering.kpi_threshold.manage',
  'steering.situation.manage',
  'steering.test_run.execute',
  'steering.voting.cast',
  'steering.voting.attribution_manage',
  'steering.consolidation.validate'
]);

export const STEERING_HANDLERS = Object.freeze([
  'tool:approveItem',
  'tool:assignTeamForMeeting',
  'tool:dynamicDialogChecklist',
  'tool:liveMeetingUpdate',
  'tool:removeApprover',
  'tool:removeChecklistDefinitionLine',
  'tool:removeKpiThreshold',
  'tool:removeRaciAssignment',
  'tool:removeTestCaseRun',
  'tool:saveAddVote',
  'tool:saveApprover',
  'tool:saveChecklist',
  'tool:saveChecklistDefinitionLine',
  'tool:saveConsolidationValidation',
  'tool:saveKpiThreshold',
  'tool:saveNoteStreamVote',
  'tool:saveRaciAssignment',
  'tool:saveSituation',
  'tool:saveTcrData',
  'tool:saveTestCaseRun',
  'tool:saveVotingAttributionVote',
  'view:liveMeetingView',
  'view:liveMeetingViewBottom'
]);

export default defineModule({
  id: 'steering',
  version: '2.1.0',
  dependencies: ['core', 'configuration', 'planning', 'tools'],
  enabledStateRequirements: [],
  claims: {
    classes: [
      'Approver', 'Checklist', 'ChecklistDefinition', 'RaciAssignment', 'RaciModel',
      'KpiDefinition', 'TestCase', 'TestCaseRun', 'TestSession', 'VotingAttributionRule',
      'Meeting', 'PeriodicMeeting', 'Risk', 'Issue', 'Opportunity', 'Decision', 'Question', 'Acceptance'
    ],
    actions: STEERING_ACTIONS,
    handlers: STEERING_HANDLERS
  },
  register() {
    // Steering workflows run exclusively through the canonical action engine.
  }
});
