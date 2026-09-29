export default {
  id: 'ticketing', locks: [], requiredTools: ['projeqtor_manage_ticket'],
  requiredActions: [
    'ticketing.ticket.manage', 'ticketing.dispatch', 'ticketing.transition',
    'ticketing.synchronize', 'ticketing.sla.evaluate', 'ticketing.escalate',
    'ticketing.synchronization.inspect', 'ticketing.synchronization.configure',
    'ticketing.synchronization.disable'
  ],
  workflowFamilies: ['dispatch', 'lifecycle', 'synchronization', 'sla', 'escalation']
};
