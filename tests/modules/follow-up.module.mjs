export default {
  id: 'follow-up', locks: ['work-period'], requiredTools: ['projeqtor_record_work'],
  requiredActions: ['follow_up.work.record', 'follow_up.period.submit', 'follow_up.period.validate'],
  workflowFamilies: ['work-entry', 'timers', 'remaining-work', 'submission', 'validation', 'imputation-alerts']
};
