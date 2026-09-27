export default {
  id: 'financial', locks: ['global-configuration'], requiredTools: [],
  requiredActions: [
    'financial.expense.submit',
    'financial.expense_detail.manage',
    'financial.billing.generate',
    'financial.bill_line.manage',
    'financial.provider_term.manage',
    'financial.tender.manage',
    'financial.tender_criteria.manage',
    'financial.budget.update',
    'financial.organization_budget.manage',
    'financial.budget.move',
    'financial.work_command.manage',
    'financial.work_command.accept',
    'financial.work_command.bill',
    'financial.work_unit.manage',
    'financial.work_unit_phase.manage',
    'financial.abacus.manage',
    'financial.abacus.apply_to_project',
    'financial.facturx.import'
  ],
  workflowFamilies: [
    'expenses', 'billing', 'provider-terms', 'procurement', 'tenders', 'budgets',
    'work-commands', 'work-units', 'abacus', 'factur-x'
  ]
};
