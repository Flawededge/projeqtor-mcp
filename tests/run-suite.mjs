#!/usr/bin/env node
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { writeSanitizedArtifact } from './support/artifacts.mjs';
import { FixtureLedger, createRunId } from './support/ledger.mjs';
import { withResourceLock } from './support/locks.mjs';
import { actionIdsFromListResult } from './support/action-discovery.mjs';
import { McpTestClient } from './support/mcp-client.mjs';
import { discoverModules } from './support/module-discovery.mjs';
import { verifyWhoami } from './support/tool-results.mjs';

function argument(name) {
  const index = process.argv.indexOf(name);
  return index >= 0 ? process.argv[index + 1] : undefined;
}

function structured(result) {
  return result?.structuredContent ?? result?.content?.find(item => item.type === 'text' && item.text)?.text;
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
  return {
    health, server: initialized?.serverInfo, toolCount: toolNames.length, toolNames,
    actorVerified: true
  };
}

async function actionNames(client, moduleId) {
  const result = await client.callTool('projeqtor_list_actions', { module: moduleId, pageSize: 200, includeTotal: true });
  return actionIdsFromListResult(result);
}

async function runModule(client, descriptor, { required }) {
  const listed = await client.tools();
  const tools = new Set((listed?.tools ?? []).map(tool => tool.name));
  const actions = new Set(await actionNames(client, descriptor.id));
  const missingTools = descriptor.requiredTools.filter(name => !tools.has(name));
  const missingActions = descriptor.requiredActions.filter(name => !actions.has(name));
  const status = missingTools.length || missingActions.length ? 'pending' : 'ready';
  const result = {
    module: descriptor.id, status, workflowFamilies: descriptor.workflowFamilies,
    missingTools, missingActions, locks: descriptor.locks
  };
  if (required && status !== 'ready') throw new Error(`${descriptor.id} module coverage is incomplete: ${[...missingTools, ...missingActions].join(', ')}`);
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
    const value = structured(capabilitiesResult);
    const capabilities = typeof value === 'string' ? JSON.parse(value) : value;
    const unknown = capabilities?.coverage?.unknown ?? capabilities?.unknownCount ?? 0;
    const deferred = capabilities?.coverage?.deferred ?? capabilities?.deferredCount ?? 0;
    if (unknown !== 0 || deferred !== 0) throw new Error(`Coverage closure failed: unknown=${unknown} deferred=${deferred}`);
    summary.coverage = { unknown, deferred };
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
