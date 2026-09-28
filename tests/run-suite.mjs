#!/usr/bin/env node
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { writeSanitizedArtifact } from './support/artifacts.mjs';
import { FixtureLedger, createRunId } from './support/ledger.mjs';
import { withResourceLock } from './support/locks.mjs';
import { actionsFromListResult } from './support/action-discovery.mjs';
import { runImportIdentityScenario } from './scenarios/import-identity.mjs';
import { McpTestClient } from './support/mcp-client.mjs';
import { discoverModules } from './support/module-discovery.mjs';
import { structuredToolResult, verifyWhoami } from './support/tool-results.mjs';

function argument(name) {
  const index = process.argv.indexOf(name);
  return index >= 0 ? process.argv[index + 1] : undefined;
}


async function preflight(client) {
  const healthUrl = new URL('/health', process.env.PROJEQTOR_MCP_URL);
  const healthResponse = await fetch(healthUrl, { signal: AbortSignal.timeout(10_000) });
  if (!healthResponse.ok) throw new Error(`MCP health returned HTTP ${healthResponse.status}`);
  const health = await healthResponse.json();
  const initialized = await client.initialize();
  const listed = await client.tools();
  const toolNames = (listed?.tools ?? []).map(tool => tool.name).sort();
  const whoami = await client.callTool('projeqtor_whoami');
  verifyWhoami(whoami, process.env.PROJEQTOR_TEST_ACTOR ?? 'admin');
  if (toolNames.length !== 36) throw new Error(`Expected 36 MCP tools, received ${toolNames.length}`);
  return {
    health, server: initialized?.serverInfo, toolCount: toolNames.length, toolNames,
    actorVerified: true
  };
}

async function actionNames(client, moduleId) {
  const result = await client.callTool('projeqtor_list_actions', { module: moduleId, pageSize: 200, includeTotal: true });
  return actionsFromListResult(result);
}

async function runModule(client, descriptor, { required }) {
  const listed = await client.tools();
  const tools = new Set((listed?.tools ?? []).map(tool => tool.name));
  const actionItems = await actionNames(client, descriptor.id);
  const actions = new Map(actionItems.map(item => [item.action, item]));
  const missingTools = descriptor.requiredTools.filter(name => !tools.has(name));
  const missingActions = descriptor.requiredActions.filter(name => !actions.has(name));
  const unavailableActions = descriptor.requiredActions.filter(name => actions.has(name) && actions.get(name).available !== true);
  const status = missingTools.length || missingActions.length || unavailableActions.length ? 'pending' : 'ready';
  const result = {
    module: descriptor.id, status, workflowFamilies: descriptor.workflowFamilies,
    missingTools, missingActions, unavailableActions, locks: descriptor.locks
  };
  if (required && status !== 'ready') throw new Error(`${descriptor.id} module coverage is incomplete: ${[...missingTools, ...missingActions, ...unavailableActions.map(name => `${name} (unavailable)`)].join(', ')}`);
  return result;
}

async function withLocks(names, callback, index = 0) {
  if (index >= names.length) return callback();
  return withResourceLock(names[index], () => withLocks(names, callback, index + 1));
}

async function main() {
  const mode = process.argv[2] ?? process.env.PROJEQTOR_TEST_MODE ?? 'integration';
  const modules = await discoverModules();
  if (process.argv.includes('--dry-run')) {
    const selected = argument('--module');
    if (selected && !modules.some(module => module.id === selected)) throw new Error(`Unknown module ${selected}`);
    await writeSanitizedArtifact('module-discovery.json', { mode, modules });
    process.stdout.write(`${JSON.stringify({ ok: true, mode, discoveredModules: modules.length, selected: selected ?? null })}\n`);
    return;
  }
  if (mode === 'live' && process.env.PROJEQTOR_LIVE_TESTS !== '1') throw new Error('Live tests require PROJEQTOR_LIVE_TESTS=1');
  if (!['integration', 'module', 'live', 'acceptance'].includes(mode)) throw new Error(`Unsupported suite ${mode}`);

  const runId = process.env.PROJEQTOR_TEST_RUN_ID ?? createRunId();
  const ledger = await new FixtureLedger(resolve(process.env.PROJEQTOR_TEST_ARTIFACT_DIR ?? 'tests/.runtime/artifacts', `fixtures-${runId}.jsonl`), runId).initialize();
  const client = await McpTestClient.fromEnvironment();
  const summary = { runId, mode, startedAt: new Date().toISOString(), preflight: await preflight(client), modules: [] };

  if (mode === 'module' || mode === 'acceptance') {
    const selectedId = argument('--module');
    const selected = selectedId ? modules.filter(module => module.id === selectedId) : modules;
    if (selected.length === 0) throw new Error(`Unknown module ${selectedId}`);
    const required = mode === 'acceptance' || process.env.BETA4_REQUIRE_MODULES === '1';
    for (const descriptor of selected) {
      summary.modules.push(await withLocks(descriptor.locks, () => runModule(client, descriptor, { required })));
    }
  }

  if (mode === 'acceptance') {
    const capabilitiesResult = await client.callTool('projeqtor_get_capabilities');
    const capabilities = structuredToolResult(capabilitiesResult, 'projeqtor_get_capabilities');
    const expectedModules = ['configuration', 'core', 'environment', 'financial', 'follow_up', 'hr', 'planning', 'products', 'reports', 'scrum', 'steering', 'ticketing', 'tools'];
    const moduleIds = (capabilities.modules ?? []).map(module => module.id).sort();
    const actionCount = (capabilities.modules ?? []).reduce((total, module) => total + (module.actionCount ?? 0), 0);
    const classPolicy = capabilities.classPolicy ?? {};
    const handlerPolicy = capabilities.handlerPolicy ?? {};
    const worker = capabilities.workerCompatibility ?? {};
    const hashFields = [classPolicy.hash, classPolicy.manifestHash, handlerPolicy.hash, handlerPolicy.manifestHash, handlerPolicy.sourceInventoryHash, handlerPolicy.sourceTreeHash];
    if (capabilities.serverVersion !== '2.0.0-beta.4' || capabilities.schemaVersion !== 4) throw new Error('Beta 4 server/schema capability mismatch');
    if (JSON.stringify(moduleIds) !== JSON.stringify(expectedModules) || actionCount !== 212) throw new Error(`Module/action capability mismatch: modules=${moduleIds.length} actions=${actionCount}`);
    if (classPolicy.version !== 4 || classPolicy.installed !== 640 || classPolicy.unknown !== 0) throw new Error(`Class policy closure failed: version=${classPolicy.version} installed=${classPolicy.installed} unknown=${classPolicy.unknown}`);
    if (handlerPolicy.version !== 4 || handlerPolicy.installed !== 899 || handlerPolicy.installedSourceFiles !== 944 || handlerPolicy.includedLibraries !== 45 || handlerPolicy.mutationCandidates !== 337 || handlerPolicy.unknown !== 0 || handlerPolicy.deferred !== 0) throw new Error(`Handler policy closure failed: version=${handlerPolicy.version} installed=${handlerPolicy.installed} sources=${handlerPolicy.installedSourceFiles} unknown=${handlerPolicy.unknown} deferred=${handlerPolicy.deferred}`);
    if (hashFields.some(value => typeof value !== 'string' || !/^[a-f0-9]{64}$/.test(value))) throw new Error('Coverage capability hashes are missing or malformed');
    if (worker.compatible !== true || worker.schemaVersion !== 4 || worker.workerVersion !== '2.0.0-beta.4' || worker.heartbeatFresh !== true) throw new Error('Worker capability compatibility or heartbeat check failed');
    summary.coverage = { modules: moduleIds.length, actions: actionCount, classes: classPolicy.installed, handlers: handlerPolicy.installed, sourceFiles: handlerPolicy.installedSourceFiles, unknown: 0, deferred: 0 };
    summary.importIdentity = await runImportIdentityScenario({ runId });
  }

  summary.cleanupPlanCount = (await ledger.cleanupPlan()).length;
  summary.finishedAt = new Date().toISOString();
  await writeSanitizedArtifact(`suite-${mode}-${runId}.json`, summary);
  process.stdout.write(`${JSON.stringify({ ok: true, mode, runId, moduleCount: summary.modules.length })}\n`);
}

main().catch(async error => {
  try { await writeSanitizedArtifact('last-failure.json', { message: error.message, mode: process.argv[2] }); } catch {}
  process.stderr.write(`Beta 4 ${process.argv[2] ?? 'integration'} suite failed: ${error.message}\n`);
  process.exitCode = 1;
});
