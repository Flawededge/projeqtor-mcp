import { defineModule } from '../runtime.mjs';

export const PRODUCTS_ACTIONS = Object.freeze([
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
]);

export const PRODUCTS_HANDLERS = Object.freeze([
  'tool:dynamicDialogProductVersionStructure',
  'tool:dynamicDialogRestrictProductList',
  'tool:filterComponentType',
  'tool:removeAssetComposition',
  'tool:removeBusinessFeature',
  'tool:removeOtherVersion',
  'tool:removeProductContext',
  'tool:removeProductLanguage',
  'tool:removeProductProject',
  'tool:removeProductStructure',
  'tool:removeProductVersionStructure',
  'tool:removeProductVersionStructureAsset',
  'tool:removeVersionCompatibility',
  'tool:removeVersionProject',
  'tool:saveAssetComposition',
  'tool:saveBusinessFeature',
  'tool:saveOtherVersion',
  'tool:saveProductAsset',
  'tool:saveProductContext',
  'tool:saveProductLanguage',
  'tool:saveProductProject',
  'tool:saveProductStructure',
  'tool:saveProductVersionStructure',
  'tool:saveRestrictProductList',
  'tool:saveVersionCompatibility',
  'tool:saveVersionProject',
  'tool:switchOtherVersion',
  'tool:upgradeProductVersionStructure'
]);

export default defineModule({
  id: 'products',
  version: '2.0.1',
  dependencies: ['core', 'configuration'],
  enabledStateRequirements: [],
  claims: {
    classes: [
      'Product', 'Component', 'ProductVersion', 'ComponentVersion',
      'ProductStructure', 'ProductVersionStructure', 'VersionCompatibility',
      'ProductContext', 'ProductLanguage', 'ProductAsset', 'BusinessFeature', 'OtherVersion'
    ],
    actions: PRODUCTS_ACTIONS,
    handlers: PRODUCTS_HANDLERS
  },
  register() {
    // Products workflows use the canonical action engine; no alternate tool path.
  }
});
