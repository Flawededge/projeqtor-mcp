export default {
  id: 'products', locks: [], requiredTools: [],
  requiredActions: [
    'products.hierarchy.manage',
    'products.version.transition',
    'products.composition.update',
    'products.version_composition.update',
    'products.version_composition.upgrade',
    'products.compatibility.update',
    'products.context.update',
    'products.language.update',
    'products.asset.update',
    'products.asset.composition',
    'products.project_links.update',
    'products.business_feature.update',
    'products.other_version.update',
    'products.restriction.update',
    'products.component_filter.apply'
  ],
  workflowFamilies: [
    'hierarchy', 'composition', 'compatibility', 'contexts', 'languages',
    'assets', 'project-links', 'business-features', 'alternate-versions'
  ]
};
