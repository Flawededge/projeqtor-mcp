import core from './core/index.mjs';
import planning from './planning/index.mjs';
import ticketing from './ticketing/index.mjs';
import scrum from './scrum/index.mjs';
import followUp from './follow_up/index.mjs';
import steering from './steering/index.mjs';
import financial from './financial/index.mjs';
import products from './products/index.mjs';
import hr from './hr/index.mjs';
import environment from './environment/index.mjs';
import tools from './tools/index.mjs';
import reports from './reports/index.mjs';
import configuration from './configuration/index.mjs';
import { ModuleRegistry } from './runtime.mjs';
export { MODULE_IDS, LEGACY_MODULE_ALIASES } from './catalog.mjs';

export const MODULE_PACKS = Object.freeze([
  core, planning, ticketing, scrum, steering, financial, products,
  followUp, hr, environment, tools, reports, configuration
]);

export function registerModulePacks(server, context) {
  const registry = new ModuleRegistry(server);
  registry.registerModules(MODULE_PACKS, context);
  return registry.describe();
}
