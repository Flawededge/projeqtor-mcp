import { defineModule } from '../runtime.mjs';
import { registerFullControlTools } from '../../full-control.mjs';

export const CORE_ACTIONS = Object.freeze([
  'object.copy',
  'workflow.transition',
  'core.reference.rebuild',
  'user.session.terminate',
  'user.session.login',
  'core.joblist.update',
  'user.legal_notice.accept',
  'core.object.bulk_update',
  'core.subtask.manage',
  'user.legal_notice.view'
]);

export const CORE_HANDLERS = Object.freeze([
  'tool:copyObject', 'tool:copyObjectTo', 'tool:copyProjectTo',
  'tool:changeObjectStatus',
  'tool:disconnectSession', 'tool:hackMessage', 'tool:loginCheck',
  'tool:saveJoblist', 'tool:saveMessageLegalFollowup', 'tool:saveObjectMultiple',
  'tool:saveSubTask', 'tool:saveSubTaskOrder', 'view:main'
]);

const tools = [
  'projeqtor_whoami', 'projeqtor_list_object_classes', 'projeqtor_list_ui_handlers',
  'projeqtor_query_items', 'projeqtor_get_changes', 'projeqtor_validate_operations',
  'projeqtor_execute_operations', 'projeqtor_prepare_change', 'projeqtor_commit_change',
  'projeqtor_list_actions', 'projeqtor_get_action_schema', 'projeqtor_execute_action',
  'projeqtor_prepare_action', 'projeqtor_commit_action', 'projeqtor_list_jobs',
  'projeqtor_retry_job', 'projeqtor_get_job', 'projeqtor_cancel_job'
];

export default defineModule({
  id: 'core', version: '2.0.1', dependencies: [],
  claims: {
    classes: ['Audit', 'Job', 'JobDefinition', 'JoblistDefinition', 'MessageLegal', 'MessageLegalFollowup', 'SubTask'],
    actions: CORE_ACTIONS,
    handlers: CORE_HANDLERS,
    tools,
    resources: ['projeqtor-attachment', 'projeqtor-document-version', 'projeqtor-job-result']
  },
  register(registrar, context) {
    registerFullControlTools(registrar, context);
  }
});
