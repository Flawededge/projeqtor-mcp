export const REPORTS_ACTIONS = Object.freeze([
  'report.start', 'reports.render', 'reports.catalog.read', 'reports.dashboard.read',
  'reports.favorite.manage', 'reports.favorite.delete', 'reports.layout.manage',
  'reports.layout.delete', 'reports.dashboard.pin', 'reports.dashboard.unpin',
  'reports.schedule', 'reports.delivery.send'
]);
export const REPORTS_HANDLERS = Object.freeze([
  'view:print', 'tool:getParamDashboard', 'tool:jsonProjectDashboard',
  'tool:jsonProjectDashboardDetail', 'tool:saveReportAsFavorite',
  'tool:saveReportFavoriteOrder', 'tool:removeFavoriteReport',
  'tool:backupReportLayout', 'tool:saveReportLayout', 'tool:moveReportLayoutColumn',
  'tool:shareReportLayout', 'tool:removeReportLayout', 'tool:saveReportInToday',
  'tool:saveTodayDeleteReport', 'tool:saveAutoSendReport'
]);
export const REPORTS_JOBS = Object.freeze(['report.start', 'reports.render', 'reports.delivery.send']);
export const REPORTS_WORKFLOW_FAMILIES = Object.freeze([
  'native-rendering', 'pdf', 'charts', 'images', 'csv', 'structured-data',
  'layouts', 'favorites', 'dashboards', 'scheduled-delivery'
]);
