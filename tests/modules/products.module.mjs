export default {
  id: 'products', locks: [], requiredTools: [],
  requiredActions: ['products.version.transition', 'products.composition.update', 'products.compatibility.update'],
  workflowFamilies: ['hierarchy', 'composition', 'compatibility', 'contexts', 'languages', 'assets', 'project-links']
};
