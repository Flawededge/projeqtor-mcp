import { defineModule } from '../runtime.mjs';

const actions = [
  'configuration.user.manage',
  'user.trigger_password_reset',
  'configuration.profile.manage',
  'configuration.access.manage',
  'configuration.workflow.manage',
  'configuration.module.set_state',
  'configuration.parameter.set',
  'configuration.view.manage',
  'cron.check', 'cron.start', 'cron.stop', 'cron.restart'
];

const handlers = [
  'tool:sendRequestResetPassword', 'tool:saveParameter', 'tool:saveWorkflowParameter',
  'tool:saveWorkflowProfileParameter', 'tool:saveModuleStatus', 'tool:resetModuleTablesInSession',
  'tool:saveUserParameter', 'tool:saveGlobalParameter', 'tool:saveFilter',
  'tool:saveFilterShared', 'tool:shareFilter', 'tool:removeFilter', 'tool:saveLayout',
  'tool:shareLayout', 'tool:removeLayout', 'tool:moveLayoutColumn',
  'tool:saveSelectedLayoutColumn', 'tool:saveValidateLayoutForOthers',
  'tool:cronCheck', 'tool:cronActivation', 'tool:cronStop', 'tool:cronRelaunch', 'tool:cronRun'
];

export default defineModule({
  id: 'configuration', version: '2.0.0-beta.4', dependencies: ['core'],
  enabledStateRequirements: ['moduleConfiguration'],
  claims: {
    classes: [
      'User', 'Profile', 'AccessProfile', 'AccessRight', 'Habilitation',
      'HabilitationOther', 'HabilitationReport', 'Workflow', 'WorkflowProfile',
      'WorkflowStatus', 'Module', 'Parameter', 'Filter', 'Layout',
      'LayoutColumnSelector', 'LayoutForced', 'Cron', 'CronExecution'
    ],
    actions,
    handlers,
    jobs: ['configuration.module.set_state', 'cron.start', 'cron.restart']
  },
  register() {
    // Configuration actions are intentionally exposed through the canonical action tools.
  }
});
