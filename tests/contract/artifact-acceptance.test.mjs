import test from 'node:test';
import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import {
  decodeResource,
  reportArguments,
  verifyArtifactSignature,
  waitForJob
} from '../scenarios/resource-verification.mjs';

const testsRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
const repositoryRoot = path.dirname(testsRoot);
const registryPath = path.join(repositoryRoot, 'bridge/core/module-registry.php');

test('resource verification accepts one canonical bounded blob and rejects malformed envelopes', () => {
  const uri = 'projeqtor://attachments/7';
  const blob = Buffer.from('fixture').toString('base64');
  const decoded = decodeResource({ contents: [{ uri, mimeType: 'text/plain', blob }] }, uri);
  assert.equal(decoded.bytes.toString(), 'fixture');
  assert.equal(decoded.mimeType, 'text/plain');
  assert.throws(() => decodeResource({ contents: [] }, uri), /invalid content envelope/);
  assert.throws(() => decodeResource({ contents: [{ uri, mimeType: 'text/plain', blob: '***=' }] }, uri), /malformed base64/);
  assert.throws(() => decodeResource({ contents: [{ uri, mimeType: 'text/plain', blob }] }, uri, { maxBytes: 2 }), /bounded download/);
});

test('artifact signatures fail closed for PDF, image, JSON, and CSV output', () => {
  assert.doesNotThrow(() => verifyArtifactSignature('pdf', Buffer.from('%PDF-1.7\n')));
  assert.doesNotThrow(() => verifyArtifactSignature('png', Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])));
  assert.doesNotThrow(() => verifyArtifactSignature('jpeg', Buffer.from([0xff, 0xd8, 0xff, 0x00])));
  assert.doesNotThrow(() => verifyArtifactSignature('json', Buffer.from('{"ok":true}')));
  assert.doesNotThrow(() => verifyArtifactSignature('csv', Buffer.from('a,b\n1,2\n')));
  assert.throws(() => verifyArtifactSignature('pdf', Buffer.from('not-pdf')), /PDF signature/);
  assert.throws(() => verifyArtifactSignature('png', Buffer.from('not-png')), /PNG signature/);
  assert.throws(() => verifyArtifactSignature('json', Buffer.from('{')), /JSON artifact/);
  assert.throws(() => verifyArtifactSignature('csv', Buffer.from([0])), /CSV artifact/);
});

test('schema patterns safely validate resource URIs containing path separators', () => {
  const script = `require ${JSON.stringify(registryPath)};` +
    `$schema=array('type'=>'string','pattern'=>'^projeqtor://attachments/[0-9]+$');` +
    `echo json_encode(array('valid'=>mcpValidateSchemaValue('projeqtor://attachments/7',$schema),` +
    `'invalid'=>mcpValidateSchemaValue('https://example.invalid/7',$schema)),JSON_THROW_ON_ERROR);`;
  const result = JSON.parse(execFileSync('php', ['-r', script], { encoding: 'utf8' }));
  assert.deepEqual(result.valid, []);
  assert.deepEqual(result.invalid, [{ path: '$', code: 'pattern' }]);
});

test('report candidate selection uses defaults and rejects unresolved required parameters', () => {
  const report = {
    id: 4, formats: ['pdf'],
    parameters: [{ name: 'idProject', required: true, defaultValue: 2 }]
  };
  assert.deepEqual(reportArguments(report, 'pdf'), { idReport: 4, format: 'pdf', parameters: { idProject: 2 } });
  assert.equal(reportArguments({ ...report, parameters: [{ name: 'idProject', required: true, defaultValue: null }] }, 'pdf'), null);
  assert.equal(reportArguments(report, 'png'), null);
});

test('job polling stops only on a well-formed terminal state', async () => {
  const responses = [
    { id: 8, status: 'queued' },
    { id: 8, status: 'running' },
    { id: 8, status: 'succeeded', resultResource: 'projeqtor://jobs/8/result' }
  ];
  const client = { callTool: async () => ({ structuredContent: responses.shift() }) };
  const job = await waitForJob(client, 8, { timeoutMs: 500, intervalMs: 0 });
  assert.equal(job.status, 'succeeded');
  await assert.rejects(
    waitForJob({ callTool: async () => ({ structuredContent: { id: 9 } }) }, 9, { timeoutMs: 10, intervalMs: 0 }),
    /malformed state/
  );
});

