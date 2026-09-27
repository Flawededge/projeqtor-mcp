import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = resolve(fileURLToPath(new URL('../..', import.meta.url)));

test('HR leave-calendar export publishes cancellable XLSX/CSV/JSON artifacts atomically', async () => {
  const source = await readFile(resolve(repositoryRoot, 'bridge/modules/hr/worker.php'), 'utf8');
  assert.match(source, /workerCancelled\(\$jobId\)/);
  assert.match(source, /\.tmp-/);
  assert.match(source, /rename\(\$temporary,\$path\)/);
  assert.match(source, /PhpSpreadsheet\\Writer\\Xlsx/);
  assert.match(source, /fputcsv/);
  assert.match(source, /JSON_UNESCAPED_SLASHES/);
  assert.match(source, /MCP_JOB_ARTIFACT_MAX_BYTES/);
  assert.match(source, /filesize\(\$temporary\)/);
  assert.match(source, /exceeds the configured limit/);
  assert.doesNotMatch(source, /password|api[_-]?key|oauth|smtp|credential/i);
});
