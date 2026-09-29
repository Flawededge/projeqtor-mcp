export default {
  id: 'hr',
  locks: ['global-configuration', 'hr-leave-engine'],
  requiredTools: [],
  requiredActions: [
    'hr.employee.manage', 'hr.employee.manager.assign', 'hr.employee.manager.remove',
    'hr.employment.contract.manage', 'hr.employment.contract.close', 'hr.absence.record', 'hr.absence.remove',
    'hr.leave.submit', 'hr.leave.decide', 'hr.leave.delete', 'hr.leave.entitlement.adjust',
    'hr.skill.assign', 'hr.skill.remove', 'hr.skill.hierarchy.move',
    'hr.leave.calendar.export', 'hr.leave.permissions.configure'
  ],
  workflowFamilies: [
    'employees', 'employee-managers', 'employment-contracts', 'careers', 'absence',
    'leave-submission', 'leave-approval', 'entitlements', 'skills', 'competencies'
  ],
  executionEnvironment: 'disposable_only',
  identities: ['admin', 'manager', 'member', 'denied'],
  requiredAssertions: [
    'success-and-independent-readback', 'structured-validation-failure',
    'least-privilege-denial', 'actor-attribution', 'idempotent-replay',
    'idempotency-body-conflict', 'optimistic-concurrency-conflict',
    'guarded-confirmation-replay-rejection', 'job-cancellation-and-atomic-artifact', 'zero-residual-fixtures'
  ],
  fixtureContract: {
    uniqueRunId: true,
    enableModules: ['Absence', 'SkillManagement'],
    outboundMail: 'private-mail-sink-only',
    cleanup: ['Leave', 'EmployeeLeaveEarned', 'EmploymentContract', 'EmployeesManaged', 'ResourceSkill', 'Skill', 'Employee']
  }
};
