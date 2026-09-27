export default {
  id: 'steering', locks: [], requiredTools: [],
  requiredActions: ['steering.approval.decide', 'steering.checklist.update', 'steering.test_run.execute'],
  workflowFamilies: ['approvals', 'checklists', 'raci', 'meetings', 'kpi', 'risks', 'issues', 'decisions', 'test-runs', 'voting']
};
