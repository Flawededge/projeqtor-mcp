export default {
  id: 'follow-up',
  locks: ['work-period', 'imputation-alert-delivery'],
  requiredTools: ['projeqtor_record_work'],
  requiredActions: [
    'follow_up.work.record',
    'follow_up.work.delete',
    'follow_up.dispatch_work.apply',
    'follow_up.timer.control',
    'follow_up.remaining_work.update',
    'follow_up.period.submit',
    'follow_up.period.validate',
    'follow_up.imputation_alert.generate',
    'follow_up.comment.add',
    'follow_up.timesheet.result'
  ],
  workflowFamilies: ['work-entry', 'dispatch-work', 'timers', 'remaining-work', 'submission', 'validation', 'rejection', 'imputation-alerts'],
  safeDisposableHooks: ['record-readback-cleanup', 'period-state-restore', 'timer-stop-cleanup', 'mail-sink-only']
};
