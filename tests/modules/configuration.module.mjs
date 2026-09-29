export default {
  id: 'configuration', locks: ['global-configuration', 'cron-control'], requiredTools: [],
  requiredActions: [
    'configuration.admin.execute', 'configuration.maintenance.run',
    'configuration.consistency.check', 'configuration.consistency.repair',
    'configuration.deferred_updates.execute', 'configuration.plugin_update.notify',
    'configuration.parameter.set', 'cron.configure', 'cron.start', 'cron.stop'
  ],
  workflowFamilies: [
    'users', 'profiles', 'access-rules', 'workflows', 'modules', 'parameters', 'filters', 'layouts',
    'administration', 'maintenance', 'consistency', 'deferred-updates', 'plugin-update-notifications', 'cron'
  ]
};
