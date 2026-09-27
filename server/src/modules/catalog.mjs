export const MODULE_IDS = Object.freeze([
  'core', 'planning', 'ticketing', 'scrum',
  'follow_up', 'steering', 'financial', 'products',
  'hr', 'environment', 'tools', 'reports', 'configuration'
]);

export const LEGACY_MODULE_ALIASES = Object.freeze({
  planning_followup_environment: Object.freeze(['planning', 'follow_up', 'environment']),
  ticketing_scrum: Object.freeze(['ticketing', 'scrum']),
  steering_reports: Object.freeze(['steering', 'reports']),
  financial_products: Object.freeze(['financial', 'products']),
  hr_tools_configuration: Object.freeze(['hr', 'tools', 'configuration'])
});
