export default {
  id: 'configuration', locks: ['global-configuration', 'cron-control'], requiredTools: [],
  requiredActions: ['configuration.user.lock', 'configuration.workflow.update', 'configuration.cron.control'],
  workflowFamilies: ['users', 'profiles', 'access-rules', 'workflows', 'modules', 'parameters', 'filters', 'layouts', 'cron']
};
