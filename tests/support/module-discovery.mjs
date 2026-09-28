import { readdir } from 'node:fs/promises';
import { basename, resolve } from 'node:path';
import { pathToFileURL } from 'node:url';

const VALID_MODULES = new Set([
  'planning', 'ticketing', 'scrum', 'follow_up', 'steering', 'financial',
  'products', 'hr', 'environment', 'tools', 'reports', 'configuration'
]);

export async function discoverModules(directory = new URL('../modules/', import.meta.url)) {
  const root = resolve(directory.pathname ?? directory);
  const files = (await readdir(root)).filter(file => file.endsWith('.module.mjs')).sort();
  const modules = [];
  const ids = new Set();
  for (const file of files) {
    const imported = await import(`${pathToFileURL(resolve(root, file)).href}?v=${Date.now()}`);
    const descriptor = imported.default;
    if (!descriptor || typeof descriptor !== 'object') throw new Error(`${file} must export a module descriptor`);
    if (!VALID_MODULES.has(descriptor.id)) throw new Error(`${file} declares unsupported module ${descriptor.id}`);
    if (basename(file, '.module.mjs') !== descriptor.id) throw new Error(`${file} must match module ID ${descriptor.id}`);
    if (ids.has(descriptor.id)) throw new Error(`Duplicate module test descriptor ${descriptor.id}`);
    if (!Array.isArray(descriptor.workflowFamilies) || descriptor.workflowFamilies.length === 0) throw new Error(`${file} has no workflow families`);
    if (!Array.isArray(descriptor.requiredActions) || !Array.isArray(descriptor.requiredTools) || !Array.isArray(descriptor.locks)) {
      throw new Error(`${file} must declare requiredActions, requiredTools, and locks arrays`);
    }
    ids.add(descriptor.id);
    modules.push(Object.freeze({ ...descriptor }));
  }
  const missing = [...VALID_MODULES].filter(id => !ids.has(id));
  if (missing.length) throw new Error(`Missing module test descriptors: ${missing.join(', ')}`);
  return modules;
}
