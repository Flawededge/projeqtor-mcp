import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const compose = await readFile(new URL('../compose.yaml', import.meta.url), 'utf8');

test('disposable topology contains every required service without host ports or Tailscale', () => {
  for (const service of ['db', 'app', 'worker', 'mcp', 'gateway', 'mail', 'test-runner']) {
    assert.match(compose, new RegExp(`^  ${service}:`, 'm'));
  }
  assert.doesNotMatch(compose, /^\s+ports:/m);
  assert.doesNotMatch(compose, /tailscale/i);
  assert.equal((compose.match(/^\s+internal: true$/gm) ?? []).length, 2);
});

test('worker remains backend-only, unprivileged, and capability-free', () => {
  const worker = compose.slice(compose.indexOf('  worker:'), compose.indexOf('\n  mcp:'));
  assert.match(worker, /user: "33:33"/);
  assert.match(worker, /cap_drop: \[ALL\]/);
  assert.match(worker, /networks: \[backend\]/);
  assert.match(worker, /no-new-privileges:true/);
});

test('app and worker trust exactly the fixed disposable MCP address', () => {
  const trustedIp = compose.match(/^  PROJEQTOR_MCP_TRUSTED_IP: (.+)$/m)?.[1];
  const fixedMcpIp = compose.match(/^        ipv4_address: (.+)$/m)?.[1];
  assert.equal(trustedIp, '${BETA4_MCP_IP:-10.249.0.13}');
  assert.equal(trustedIp, fixedMcpIp);
  assert.match(compose, /^    environment: \*app-environment$/m);
  assert.equal((compose.match(/^    environment: \*app-environment$/gm) ?? []).length, 3);
});

test('gateway is non-root, capability-free, and has only narrow writable tmpfs mounts', () => {
  const gateway = compose.slice(compose.indexOf('  gateway:'), compose.indexOf('\n  mail:'));
  assert.match(gateway, /user: "101:101"/);
  assert.match(gateway, /cap_drop: \[ALL\]/);
  assert.match(gateway, /no-new-privileges:true/);
  assert.match(gateway, /\/var\/cache\/nginx:uid=101,gid=101,mode=0755/);
  assert.match(gateway, /\/var\/run:uid=101,gid=101,mode=0755/);
  assert.doesNotMatch(gateway, /^\s+ports:/m);
});

test('test runner uses the preparing actor and writes only to its artifact mount', () => {
  const runner = compose.slice(compose.indexOf('  test-runner:'), compose.indexOf('\nnetworks:'));
  assert.match(runner, /user: "\$\{BETA4_TEST_UID:[^}]+\}:\$\{BETA4_TEST_GID:[^}]+\}"/);
  assert.match(runner, /BETA4_ARTIFACT_DIR[^\n]+:\/artifacts/);
  assert.match(runner, /\.\.:\/workspace:ro/);
});

test('restore seed is explicit, read-only, and isolated from fresh mode', () => {
  const restore = compose.slice(compose.indexOf('  restore-seed:'), compose.indexOf('\n  app:'));
  assert.match(restore, /profiles: \[restore\]/);
  assert.match(restore, /restore\.dump:ro/);
  assert.match(restore, /--no-owner/);
  assert.match(restore, /--no-privileges/);
});
