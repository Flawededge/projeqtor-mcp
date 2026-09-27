export default {
  id: 'ticketing', locks: [], requiredTools: ['projeqtor_manage_ticket'],
  requiredActions: ['ticketing.dispatch', 'ticketing.transition', 'ticketing.sla.evaluate'],
  workflowFamilies: ['dispatch', 'lifecycle', 'synchronization', 'sla', 'escalation']
};
