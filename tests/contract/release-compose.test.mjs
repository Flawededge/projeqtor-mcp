import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const releaseCompose = await readFile(new URL('../../deployment/compose.yaml', import.meta.url), 'utf8');
const setupHost = await readFile(new URL('../../scripts/setup-host.sh', import.meta.url), 'utf8');
const initializer = await readFile(new URL('../../deployment/app/initialize.php', import.meta.url), 'utf8');

test('release database health waits for the final TCP listener', () => {
  const database = releaseCompose.slice(releaseCompose.indexOf('  db:'), releaseCompose.indexOf('\n  initialize:'));
  assert.match(database, /pg_isready -h 127\.0\.0\.1 -U projeqtor -d projeqtor/);
});

test('release tmpfs options remain single Compose mount values', () => {
  assert.equal((releaseCompose.match(/tmpfs: \["\/tmp:size=16m,mode=1777"\]/g) ?? []).length, 2);
  assert.doesNotMatch(releaseCompose, /tmpfs: \[\/tmp:size=16m,mode=1777\]/);
});

test('MCP bridge traffic uses the fixed-address backend network', () => {
  assert.match(releaseCompose, /aliases: \[projeqtor-app-backend\]/);
  assert.match(releaseCompose, /PROJEQTOR_API_URL: http:\/\/projeqtor-app-backend\/mcp-api/);
});

test('generated host secrets are traversable only by root and readable by unprivileged consumers', () => {
  assert.match(setupHost, /chmod 0700 "\$secrets"/);
  assert.match(setupHost, /chmod 0600 "\$env_file" "\$secrets"\/\*/);
  for (const file of ['db-password', 'admin-password', 'api.htpasswd', 'mcp-signing-key', 'mcp-cursor-key', 'mcp-users.json']) {
    assert.match(setupHost, new RegExp(`chmod 0444 [^\\n]*"\\$secrets/${file.replace('.', '\\.')}`));
  }
  for (const file of ['keycloak-db-password', 'keycloak-admin-password', 'claude-oauth-client-secret', 'entra-client-secret']) {
    assert.match(setupHost, new RegExp(`chmod 0444 [^\\n]*"\\$secrets/${file}`));
  }
  assert.doesNotMatch(setupHost, /chmod 0444[^\n]*admin-mcp-token/);
  assert.doesNotMatch(setupHost, /chmod 0444[^\n]*api-password/);
});

test('release Compose uses portable read-only secret bind mounts', () => {
  assert.doesNotMatch(releaseCompose, /^\s*secrets:/m);
  assert.doesNotMatch(releaseCompose, /mode: 0444/);
  assert.equal((releaseCompose.match(/db-password:\/run\/secrets\/db_password:ro/g) ?? []).length, 5);
  assert.equal((releaseCompose.match(/mcp-signing-key:\/run\/secrets\/mcp_signing_key:ro/g) ?? []).length, 4);
  assert.equal((releaseCompose.match(/mcp-cursor-key:\/run\/secrets\/mcp_cursor_key:ro/g) ?? []).length, 3);
  assert.equal((releaseCompose.match(/admin-password:\/run\/secrets\/admin_password:ro/g) ?? []).length, 1);
  assert.equal((releaseCompose.match(/api\.htpasswd:\/run\/secrets\/projeqtor-api-htpasswd:ro/g) ?? []).length, 1);
  assert.equal((releaseCompose.match(/mcp-users\.json:\/run\/secrets\/mcp_users:ro/g) ?? []).length, 1);
  assert.equal((releaseCompose.match(/keycloak-db-password:\/run\/secrets\/keycloak_db_password:ro/g) ?? []).length, 2);
  assert.equal((releaseCompose.match(/keycloak-admin-password:\/run\/secrets\/keycloak_admin_password:ro/g) ?? []).length, 1);
  assert.equal((releaseCompose.match(/entra-client-secret:\/run\/secrets\/entra_client_secret:ro/g) ?? []).length, 1);
  assert.equal((releaseCompose.match(/claude-oauth-client-secret:\/run\/secrets\/claude_oauth_client_secret:ro/g) ?? []).length, 1);
});

test('generated MCP digest matches the trimmed bearer token clients send', () => {
  assert.match(setupHost, /tr -d '\\r\\n' < "\$secrets\/admin-mcp-token" \| sha256sum/);
});

test('fresh initialization survives a failure after schema migration', () => {
  assert.match(initializer, /fresh-pending/);
  assert.match(initializer, /\$freshDatabase = \$currentVersion === '' \|\| \$initializationState === 'fresh-pending'/);
  const secureAdmin = initializer.indexOf('if ($freshDatabase)');
  const completeState = initializer.indexOf('"initialized\\n"');
  assert.ok(secureAdmin >= 0 && completeState > secureAdmin);
  assert.match(initializer, /rename\(\$temporaryStatePath, \$initializationStatePath\)/);
});
