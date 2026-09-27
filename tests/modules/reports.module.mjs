export default {
  id: 'reports', locks: ['mail-delivery'], requiredTools: ['projeqtor_render_report'],
  requiredActions: ['reports.render', 'reports.schedule', 'reports.dashboard.read'],
  workflowFamilies: ['pdf', 'charts', 'csv', 'structured-data', 'layouts', 'favorites', 'dashboards', 'scheduled-delivery']
};
