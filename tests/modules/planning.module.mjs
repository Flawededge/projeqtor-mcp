export default {
  id: 'planning', locks: ['planning-engine'], requiredTools: ['projeqtor_plan_projects'],
  requiredActions: ['planning.calculate', 'planning.diagnostics', 'planning.baseline.create'],
  workflowFamilies: ['assignments', 'allocations', 'dependencies', 'phasing', 'splitting', 'scenarios', 'critical-resources', 'leveling', 'baselines']
};
