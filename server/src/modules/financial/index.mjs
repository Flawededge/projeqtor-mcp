import { defineModule } from '../runtime.mjs';

export const FINANCIAL_ACTIONS = Object.freeze([
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
  'financial.work_token_contract.manage',
  'financial.work_token_markup.manage',
  'financial.abacus.manage',
  'financial.abacus.apply_to_project',
  'financial.facturx.import'
]);

export const FINANCIAL_HANDLERS = Object.freeze([
  'tool:closeUncloseOrganizationBudgetElement',
  'tool:importFacturX',
  'tool:moveBudgetFromHierarchicalView',
  'tool:removeAcceptedWorkCommand',
  'tool:removeBillLine',
  'tool:removeBilledWorkCommand',
  'tool:removeExpenseDetail',
  'tool:removeOrganizationBudgetElement',
  'tool:removeProviderTerm',
  'tool:removeTenderEvaluationCriteria',
  'tool:removeTenderSubmission',
  'tool:removeWorkCommand',
  'tool:removeWorkUnit',
  'tool:removeWorkUnitCatalogPhase',
  'tool:saveAbacusAffectations',
  'tool:saveAbacusAssignments',
  'tool:saveAbacusDefinition',
  'tool:saveAbacusLine',
  'tool:saveAbacusPhase',
  'tool:saveAbacusProjectLine',
  'tool:saveAbacusable',
  'tool:saveAcceptedWorkCommand',
  'tool:saveBillLine',
  'tool:saveBilledWorkCommand',
  'tool:saveExpenseDetail',
  'tool:saveOrganizationBudgetElement',
  'tool:saveParentWorkCommand',
  'tool:saveProviderTerm',
  'tool:saveProviderTermFromProviderBill',
  'tool:saveTenderEvaluationCriteria',
  'tool:saveTenderSubmission',
  'tool:saveWorkCommand',
  'tool:saveWorkUnit',
  'tool:saveWorkUnitCatalogPhase',
  'tool:saveWorkTokenClientContract',
  'tool:saveWorkTokenMarkup'
]);

export default defineModule({
  id: 'financial', version: '2.0.0',
  dependencies: ['core', 'configuration', 'environment', 'products', 'follow_up', 'tools'],
  enabledStateRequirements: [],
  claims: {
    classes: [
      'IndividualExpense', 'ProjectExpense', 'ActivityExpense', 'ExpenseDetail',
      'Bill', 'ProviderBill', 'ProviderOrder', 'ProviderPayment', 'Payment',
      'Quotation', 'ClientContract', 'SupplierContract', 'BillLine', 'ProviderTerm',
      'CallForTender', 'Tender', 'TenderEvaluationCriteria', 'Budget', 'BudgetElement',
      'WorkCommand', 'WorkCommandAccepted', 'WorkCommandBilled', 'WorkUnit',
      'WorkUnitCatalogPhase', 'WorkTokenClientContract', 'WorkTokenMarkup', 'AbacusDefinition', 'AbacusLine', 'AbacusValue',
      'AbacusProject', 'Abacusable', 'Phase'
    ],
    actions: FINANCIAL_ACTIONS,
    handlers: FINANCIAL_HANDLERS
  },
  register() {
    // Financial workflows use the canonical action engine and guarded workers.
  }
});
