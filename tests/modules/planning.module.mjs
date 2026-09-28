export default {
  id: 'planning', locks: ['planning-engine'], requiredTools: ['projeqtor_plan_projects'],
  requiredActions: ['planning.selection.delete', 'planning.integrity.repair', 'planning.grid.inline_edit',
    'planning.calculate', 'planning.diagnostics', 'planning.baseline.create'],
  workflowFamilies: ['assignments', 'allocations', 'dependencies', 'selection-deletion', 'integrity-repair', 'inline-editing', 'phasing', 'splitting', 'scenarios', 'critical-resources', 'leveling', 'baselines']
};
