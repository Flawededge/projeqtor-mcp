import { createHash } from 'node:crypto';

const MODULE_ID_PATTERN = /^[a-z][a-z0-9_]{1,49}$/;
const CLAIM_TYPES = Object.freeze(['classes', 'actions', 'handlers', 'routes', 'jobs', 'tools', 'resources']);

function assertStringArray(value, label) {
  if (!Array.isArray(value) || value.some(item => typeof item !== 'string' || item.length === 0)) {
    throw new TypeError(`${label} must be an array of non-empty strings`);
  }
}

export function defineModule(definition) {
  if (!definition || typeof definition !== 'object') throw new TypeError('Module definition must be an object');
  if (!MODULE_ID_PATTERN.test(definition.id ?? '')) throw new TypeError(`Invalid module id '${definition.id ?? ''}'`);
  if (typeof definition.version !== 'string' || definition.version.length === 0) {
    throw new TypeError(`Module '${definition.id}' must declare a version`);
  }
  const enabledStateRequirements = definition.enabledStateRequirements ?? [];
  assertStringArray(enabledStateRequirements, `Module '${definition.id}' enabledStateRequirements`);
  const dependencies = definition.dependencies ?? [];
  assertStringArray(dependencies, `Module '${definition.id}' dependencies`);
  const claims = {};
  for (const type of CLAIM_TYPES) {
    claims[type] = Object.freeze([...(definition.claims?.[type] ?? [])]);
    assertStringArray(claims[type], `Module '${definition.id}' ${type}`);
  }
  if (typeof definition.register !== 'function') throw new TypeError(`Module '${definition.id}' must export register()`);
  return Object.freeze({
    ...definition,
    dependencies: Object.freeze([...dependencies]),
    enabledStateRequirements: Object.freeze([...enabledStateRequirements]),
    claims: Object.freeze(claims)
  });
}

export class ModuleRegistry {
  #server;
  #modules = new Map();
  #claims = new Map(CLAIM_TYPES.map(type => [type, new Map()]));

  constructor(server) {
    this.#server = server;
    for (const name of Object.keys(server._registeredTools ?? {})) this.#claims.get('tools').set(name, 'legacy');
    const existingResources = new Set([
      ...Object.keys(server._registeredResources ?? {}), ...Object.keys(server._registeredResourceTemplates ?? {})
    ]);
    for (const name of existingResources) this.#claims.get('resources').set(name, 'legacy');
  }

  registerModules(definitions, context) {
    const ordered = this.#sort(definitions);
    for (const definition of ordered) this.#reserve(definition);
    for (const definition of ordered) {
      const registrar = Object.freeze({
        registerTool: (name, config, handler) => this.#registerTool(definition.id, name, config, handler),
        registerResource: (name, template, config, handler) => this.#registerResource(definition.id, name, template, config, handler)
      });
      definition.register(registrar, Object.freeze({ ...context, module: definition }));
    }
    return ordered.map(definition => definition.id);
  }

  describe() {
    return [...this.#modules.values()].map(module => ({
      enabled: true,
      availabilityReason: null,
      enabledStateRequirements: [...module.enabledStateRequirements],
      actionCount: module.claims.actions.length,
      coverageHash: createHash('sha256').update(JSON.stringify({
        id: module.id, version: module.version, claims: module.claims
      })).digest('hex'),
      id: module.id,
      version: module.version,
      dependencies: [...module.dependencies],
      claims: Object.fromEntries(CLAIM_TYPES.map(type => [type, [...module.claims[type]]]))
    }));
  }

  #sort(definitions) {
    if (!Array.isArray(definitions)) throw new TypeError('Module definitions must be an array');
    const byId = new Map();
    for (const definition of definitions) {
      if (byId.has(definition.id)) throw new Error(`Duplicate module '${definition.id}'`);
      byId.set(definition.id, definition);
    }
    const pending = new Map(byId);
    const ordered = [];
    while (pending.size > 0) {
      let progressed = false;
      for (const [id, definition] of [...pending].sort(([left], [right]) => left.localeCompare(right))) {
        for (const dependency of definition.dependencies) {
          if (!byId.has(dependency)) throw new Error(`Module '${id}' depends on missing module '${dependency}'`);
        }
        if (definition.dependencies.every(dependency => ordered.some(item => item.id === dependency))) {
          ordered.push(definition);
          pending.delete(id);
          progressed = true;
        }
      }
      if (!progressed) throw new Error(`Module dependency cycle: ${[...pending.keys()].sort().join(', ')}`);
    }
    return ordered;
  }

  #reserve(definition) {
    if (this.#modules.has(definition.id)) throw new Error(`Duplicate module '${definition.id}'`);
    for (const type of CLAIM_TYPES) {
      for (const value of definition.claims[type]) {
        const owner = this.#claims.get(type).get(value);
        if (owner) throw new Error(`Duplicate ${type.slice(0, -1)} '${value}' claimed by '${owner}' and '${definition.id}'`);
        this.#claims.get(type).set(value, definition.id);
      }
    }
    this.#modules.set(definition.id, definition);
  }

  #assertOwned(type, moduleId, name) {
    const owner = this.#claims.get(type).get(name);
    if (owner !== moduleId) throw new Error(`Module '${moduleId}' attempted to register unclaimed ${type.slice(0, -1)} '${name}'`);
  }

  #registerTool(moduleId, name, config, handler) {
    this.#assertOwned('tools', moduleId, name);
    this.#server.registerTool(name, config, handler);
  }

  #registerResource(moduleId, name, template, config, handler) {
    this.#assertOwned('resources', moduleId, name);
    this.#server.registerResource(name, template, config, handler);
  }
}
