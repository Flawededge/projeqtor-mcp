export default {
  id: 'steering',
  locks: ['planning-engine', 'mail'],
  requiredTools: [],
  requiredActions: [
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
  ],
  workflowFamilies: [
    'approvals', 'checklists', 'raci', 'meetings', 'kpi-thresholds',
    'risks-issues-decisions', 'test-runs', 'voting', 'consolidation'
  ],
  fixtures: {
    creates: ['Project', 'Meeting', 'Risk', 'Issue', 'Decision', 'TestCase', 'TestSession', 'VotingAttributionRule'],
    cleanup: 'steering-fixture-run-id'
  }
};
