export default {
  id: 'financial', locks: ['global-configuration'], requiredTools: [],
  requiredActions: ['financial.expense.submit', 'financial.billing.generate', 'financial.budget.update'],
  workflowFamilies: ['expenses', 'billing', 'provider-terms', 'procurement', 'tenders', 'budgets', 'work-commands', 'abacus', 'factur-x']
};
