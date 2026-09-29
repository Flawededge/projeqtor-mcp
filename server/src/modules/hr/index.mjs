import { defineModule } from '../runtime.mjs';

export const HR_ACTIONS = Object.freeze([
  'hr.employee.manage',
  'hr.employee.manager.assign',
  'hr.employee.manager.remove',
  'hr.employment.contract.manage',
  'hr.employment.contract.close',
  'hr.absence.record',
  'hr.absence.remove',
  'hr.leave.submit',
  'hr.leave.decide',
  'hr.leave.delete',
  'hr.leave.entitlement.adjust',
  'hr.skill.assign',
  'hr.skill.remove',
  'hr.skill.hierarchy.move',
  'hr.leave.calendar.export',
  'hr.leave.permissions.configure'
]);

export const HR_HANDLERS = Object.freeze([
  'tool:addEmployeesManagedBulk',
  'tool:deleteLeaveOfCalendar',
  'tool:exportLeaveCalendarOfDashboardEmployeeManager',
  'tool:moveSkillFromHierarchicalView',
  'tool:removeCustomEarnedRulesOfEmpContractType',
  'tool:removeEmployeesManaged',
  'tool:removeLvTypeOfEmpContractType',
  'tool:saveAbsence',
  'tool:saveActivitySkill',
  'tool:saveCustomEarnedRulesOfEmpContractType',
  'tool:saveEmployeesManaged',
  'tool:saveLeaveOfCalendar',
  'tool:saveLeavesSystemHabilitation',
  'tool:saveLvTypeOfEmpContractType',
  'tool:saveResourceSkill',
  'tool:saveValidOrCancelStatusLeave'
]);

export const HR_CLASSES = Object.freeze([
  'Employee', 'EmployeeManager', 'EmployeesManaged', 'EmploymentContract',
  'EmployeeLeaveEarned', 'Leave', 'ResourceSkill', 'Skill', 'SkillLevel',
  'LanguageSkillLevel'
]);

export default defineModule({
  id: 'hr',
  version: '2.0.0',
  dependencies: ['core', 'configuration', 'environment'],
  enabledStateRequirements: ['employee-or-skill-class-installed'],
  claims: { classes: HR_CLASSES, actions: HR_ACTIONS, handlers: HR_HANDLERS, jobs: ['hr.leave.calendar.export'] },
  register() {
    // HR remains on the canonical action tools. No sixth convenience tool is added.
  }
});
