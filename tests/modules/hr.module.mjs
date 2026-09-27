export default {
  id: 'hr', locks: ['global-configuration'], requiredTools: [],
  requiredActions: ['hr.absence.submit', 'hr.absence.decide', 'hr.skill.assign'],
  workflowFamilies: ['employees', 'absence', 'leave', 'entitlements', 'skills']
};
