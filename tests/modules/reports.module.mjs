export default {
  id: 'reports', locks: ['mail-delivery'], requiredTools: ['projeqtor_render_report'],
  requiredActions: [
    'report.start', 'reports.render', 'reports.catalog.read', 'reports.dashboard.read',
    'reports.favorite.manage', 'reports.favorite.delete', 'reports.layout.manage',
    'reports.layout.delete', 'reports.dashboard.pin', 'reports.dashboard.unpin',
    'reports.schedule', 'reports.delivery.send'
  ],
  workflowFamilies: [
    'native-rendering', 'pdf', 'charts', 'images', 'csv', 'structured-data',
    'layouts', 'favorites', 'dashboards', 'scheduled-delivery'
  ],
  executionPolicy: {
    liveSafe: ['native-rendering', 'pdf', 'charts', 'images', 'csv', 'structured-data', 'favorites', 'dashboards'],
    disposableOnly: ['scheduled-delivery'],
    mailSinkRequired: true,
    cleanupRequired: true
  }
};
