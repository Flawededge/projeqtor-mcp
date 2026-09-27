#!/usr/bin/env node
import { createHash, randomBytes, randomUUID } from 'node:crypto';
import { chmod, chown, mkdir, readFile, realpath, rm, stat, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { resolve } from 'node:path';
import { spawnSync } from 'node:child_process';
import { writeSanitizedArtifact } from './artifacts.mjs';

const testRoot = resolve(new URL('..', import.meta.url).pathname);
const workspaceKey = createHash('sha256').update(testRoot).digest('hex').slice(0, 16);
const runtimeRoot = resolve(tmpdir(), 'projeqtor-beta4-harness', workspaceKey);
const currentFile = resolve(runtimeRoot, 'current');

function secret(bytes = 32) { return randomBytes(bytes).toString('base64url'); }
function sha256(value) { return createHash('sha256').update(value).digest('hex'); }
function safeRunId(value) {
  if (!/^b4-[a-z0-9-]{8,64}$/.test(value)) throw new Error('Invalid harness run ID');
  return value;
}

async function currentRunId() {
  try { return safeRunId((await readFile(currentFile, 'utf8')).trim()); }
  catch (error) { if (error.code === 'ENOENT') return null; throw error; }
}

async function prepare() {
  const runId = safeRunId(process.env.BETA4_RUN_ID ?? `b4-${Date.now()}-${randomUUID().slice(0, 8)}`);
  const runRoot = resolve(runtimeRoot, runId);
  const secretRoot = resolve(runRoot, 'secrets');
  const artifactRoot = resolve(testRoot, '.runtime', runId, 'artifacts');
  await mkdir(secretRoot, { recursive: true, mode: 0o700 });
  await mkdir(artifactRoot, { recursive: true, mode: 0o700 });
  const hostUid = typeof process.getuid === 'function' ? process.getuid() : 1000;
  const hostGid = typeof process.getgid === 'function' ? process.getgid() : 1000;
  const testUid = hostUid === 0 ? 1000 : hostUid;
  const testGid = hostUid === 0 ? 1000 : hostGid;
  if (hostUid === 0) await chown(artifactRoot, testUid, testGid);

  const dbPassword = secret(30);
  const signingKey = secret(48);
  const cursorKey = secret(48);
  const actorToken = secret(48);
  const paths = {
    signing: resolve(secretRoot, 'signing.key'), cursor: resolve(secretRoot, 'cursor.key'),
    users: resolve(secretRoot, 'users.json'), token: resolve(secretRoot, 'admin.token')
  };
  await Promise.all([
    writeFile(paths.signing, `${signingKey}\n`, { mode: 0o644 }),
    writeFile(paths.cursor, `${cursorKey}\n`, { mode: 0o644 }),
    writeFile(paths.token, `${actorToken}\n`, { mode: 0o644 }),
    writeFile(paths.users, `${JSON.stringify({ version: 1, users: [{ username: 'admin', tokenSha256: sha256(actorToken) }] }, null, 2)}\n`, { mode: 0o644 })
  ]);

  const seedMode = process.env.BETA4_SEED_MODE ?? 'fresh';
  if (!['fresh', 'restore'].includes(seedMode)) throw new Error('BETA4_SEED_MODE must be fresh or restore');
  let seedDump = resolve(testRoot, 'fixtures/NO_RESTORE_DUMP_SELECTED');
  if (seedMode === 'restore') {
    if (!process.env.BETA4_SEED_DUMP || !resolve(process.env.BETA4_SEED_DUMP).startsWith('/')) throw new Error('Restore mode requires an absolute BETA4_SEED_DUMP');
    seedDump = await realpath(process.env.BETA4_SEED_DUMP);
    const metadata = await stat(seedDump);
    if (!metadata.isFile() || metadata.size === 0) throw new Error('Restore seed must be a non-empty readable file');
  }

  const environment = {
    BETA4_PROJECT_NAME: `projeqtor-${runId}`,
    BETA4_RUN_ID: runId,
    BETA4_APP_IMAGE: `projeqtor-app:test-${runId}`,
    BETA4_MCP_IMAGE: `projeqtor-mcp:test-${runId}`,
    BETA4_BACKEND_SUBNET: `10.249.${Number.parseInt(sha256(runId).slice(0, 2), 16)}.0/24`,
    BETA4_MCP_IP: `10.249.${Number.parseInt(sha256(runId).slice(0, 2), 16)}.13`,
    BETA4_DB_PASSWORD: dbPassword,
    BETA4_SIGNING_KEY_FILE: paths.signing,
    BETA4_CURSOR_KEY_FILE: paths.cursor,
    BETA4_USERS_FILE: paths.users,
    BETA4_ADMIN_TOKEN_FILE: paths.token,
    BETA4_ARTIFACT_DIR: artifactRoot,
    BETA4_TEST_UID: String(testUid),
    BETA4_TEST_GID: String(testGid),
    BETA4_SEED_MODE: seedMode,
    BETA4_SEED_DUMP: seedDump
  };
  const envPath = resolve(runRoot, 'harness.env');
  await writeFile(envPath, `${Object.entries(environment).map(([key, value]) => `${key}=${value}`).join('\n')}\n`, { mode: 0o600 });
  await writeFile(currentFile, `${runId}\n`, { mode: 0o600 });
  await chmod(runtimeRoot, 0o700);
  await chmod(secretRoot, 0o700);
  await chmod(runRoot, 0o700);
  process.stdout.write(`${JSON.stringify({ runId, seedMode, environmentFile: envPath, artifactDirectory: artifactRoot })}\n`);
  return { runId, runRoot, envPath, environment };
}

async function loadRun() {
  const runId = process.env.BETA4_RUN_ID ?? await currentRunId();
  if (!runId) return prepare();
  const runRoot = resolve(runtimeRoot, safeRunId(runId));
  const envPath = resolve(runRoot, 'harness.env');
  const text = await readFile(envPath, 'utf8');
  const environment = Object.fromEntries(text.split('\n').filter(Boolean).map(line => {
    const split = line.indexOf('=');
    return [line.slice(0, split), line.slice(split + 1)];
  }));
  return { runId, runRoot, envPath, environment };
}

function compose(run, args, options = {}) {
  const command = ['compose', '--env-file', run.envPath, '-f', resolve(testRoot, 'compose.yaml')];
  if (run.environment.BETA4_SEED_MODE === 'restore') command.push('--profile', 'restore');
  command.push(...args);
  const outcome = spawnSync('docker', command, { cwd: testRoot, stdio: options.capture ? 'pipe' : 'inherit', encoding: 'utf8' });
  if (outcome.status !== 0) {
    const message = options.capture ? `${outcome.stderr ?? ''}\n${outcome.stdout ?? ''}`.trim() : 'Docker Compose command failed';
    throw new Error(message);
  }
  return outcome.stdout;
}

async function main() {
  const command = process.argv[2] ?? 'help';
  if (command === 'prepare') return prepare();
  const run = await loadRun();
  if (command === 'config') {
    compose(run, ['config', '--quiet']);
    process.stdout.write(`${JSON.stringify({ ok: true, runId: run.runId })}\n`);
    return;
  }
  if (command === 'up') {
    compose(run, ['config', '--quiet']);
    if (run.environment.BETA4_SEED_MODE === 'restore') {
      compose(run, ['up', '-d', 'db']);
      compose(run, ['run', '--rm', 'restore-seed']);
    }
    compose(run, ['up', '-d', '--build', 'db', 'app', 'worker', 'mcp', 'gateway', 'mail']);
    process.stdout.write(`${JSON.stringify({ ok: true, runId: run.runId, seedMode: run.environment.BETA4_SEED_MODE })}\n`);
    return;
  }
  if (command === 'test') {
    const suite = process.argv[3] ?? 'integration';
    const extra = process.argv.slice(4);
    compose(run, ['run', '--rm', '-e', `PROJEQTOR_TEST_MODE=${suite}`, 'test-runner', 'node', 'tests/run-suite.mjs', suite, ...extra]);
    return;
  }
  if (command === 'down') {
    compose(run, ['down', '--volumes', '--remove-orphans']);
    if (await currentRunId() === run.runId) await rm(currentFile, { force: true });
    await rm(run.runRoot, { recursive: true, force: true });
    process.stdout.write(`${JSON.stringify({ ok: true, runId: run.runId, volumesRemoved: true, credentialsRemoved: true })}\n`);
    return;
  }
  if (command === 'sanitize') {
    process.env.PROJEQTOR_TEST_ARTIFACT_DIR = run.environment.BETA4_ARTIFACT_DIR;
    const services = compose(run, ['ps', '--format', 'json'], { capture: true });
    await writeSanitizedArtifact(`harness-${run.runId}.json`, { runId: run.runId, services: services.split('\n').filter(Boolean).map(line => JSON.parse(line)) });
    return;
  }
  process.stdout.write('Usage: harness.mjs prepare|config|up|test [suite]|sanitize|down\n');
}

main().catch(error => {
  process.stderr.write(`Beta 4 harness: ${error.message}\n`);
  process.exitCode = 1;
});
