import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { pathToFileURL } from 'node:url';
import { McpTestClient } from '../support/mcp-client.mjs';
import { structuredToolResult, verifyWhoami } from '../support/tool-results.mjs';
import { decodeResource, waitForJob } from './resource-verification.mjs';

function mailApi() {
  const configured = new URL(process.env.PROJEQTOR_MAIL_API_URL ?? '');
  if (configured.protocol !== 'http:' || configured.hostname !== 'mail' || configured.port !== '8025' ||
      configured.pathname.replace(/\/$/, '') !== '/api/v1') {
    throw new Error('Disposable mail acceptance must use the internal Mailpit API at http://mail:8025/api/v1');
  }
  configured.pathname = `${configured.pathname.replace(/\/$/, '')}/`;
  return configured;
}

async function apiFetch(base, path, options = {}) {
  const response = await fetch(new URL(path, base), {
    ...options, signal: AbortSignal.timeout(10_000)
  });
  if (!response.ok) throw new Error(`Mailpit ${options.method ?? 'GET'} ${path} returned HTTP ${response.status}`);
  if (response.status === 204 || options.method === 'DELETE') return null;
  const text = await response.text();
  return text ? JSON.parse(text) : null;
}

async function messages(base) {
  const result = await apiFetch(base, 'messages');
  if (!result || !Array.isArray(result.messages)) throw new Error('Mailpit returned an invalid message list');
  return result.messages;
}

function addressValues(entries) {
  return (entries ?? []).map(entry => typeof entry === 'string' ? entry : entry?.Address).filter(Boolean);
}

async function waitForMessage(base, priorIds, subject) {
  const deadline = Date.now() + 30_000;
  while (Date.now() < deadline) {
    const found = (await messages(base)).find(message =>
      !priorIds.has(String(message.ID)) && message.Subject === subject
    );
    if (found) return found;
    await new Promise(resolve => setTimeout(resolve, 250));
  }
  throw new Error('Expected mail did not arrive in the private sink');
}

async function jobResult(client, queued) {
  assert.equal(queued.queued, true);
  assert.ok(Number.isSafeInteger(queued.job?.id));
  const job = await waitForJob(client, queued.job.id, { timeoutMs: 120_000 });
  assert.equal(job.status, 'succeeded', `Mail job #${job.id} ended as ${job.status}`);
  const resource = decodeResource(await client.readResource(job.resultResource), job.resultResource);
  const result = JSON.parse(resource.bytes.toString('utf8'));
  assert.equal(result.ok, true);
  assert.equal(result.recipientCount, 1);
  assert.equal(result.items?.[0]?.status, 'sent');
  return { job, result };
}

export async function runMailAcceptance({ client, runId }) {
  assert.match(runId, /^b4-[A-Za-z0-9-]{8,64}$/);
  const base = mailApi();
  const before = await messages(base);
  const priorIds = new Set(before.map(message => String(message.ID)));
  const suffix = createHash('sha256').update(runId).digest('hex').slice(0, 12);
  const recipient = `beta4-${suffix}@beta4.invalid`;
  const subject = `Beta 4 private sink ${runId}`;
  const body = `Disposable delivery proof ${suffix}`;
  let delivered = null;
  try {
    const prepared = structuredToolResult(await client.callTool('projeqtor_prepare_action', {
      action: 'tools.mail.send',
      arguments: { recipients: [recipient], subject, body, isHtml: false, saveAsNote: false }
    }), 'projeqtor_prepare_action');
    assert.equal(prepared.preview?.recipientCount, 1);
    assert.deepEqual(prepared.preview?.recipientDomains, ['beta4.invalid']);
    assert.equal(prepared.preview?.delivery, 'guarded_external');
    assert.equal(prepared.preview?.payloadRedacted, true);
    assert.equal(typeof prepared.confirmationToken, 'string');

    const queued = structuredToolResult(await client.callTool('projeqtor_commit_action', {
      confirmationToken: prepared.confirmationToken
    }), 'projeqtor_commit_action');
    const completed = await jobResult(client, queued);
    const serialized = JSON.stringify(completed.result);
    assert.equal(serialized.includes(recipient), false, 'Mail result exposed a recipient address');
    assert.equal(serialized.includes(body), false, 'Mail result exposed body content');

    delivered = await waitForMessage(base, priorIds, subject);
    const detail = await apiFetch(base, `message/${encodeURIComponent(delivered.ID)}`);
    assert.equal(detail.Subject, subject);
    assert.deepEqual(addressValues(detail.To), [recipient]);
    assert.equal(String(detail.Text ?? '').trim(), body);
    const newMessages = (await messages(base)).filter(message => !priorIds.has(String(message.ID)));
    assert.equal(newMessages.length, 1, 'Mail action produced an unexpected number of sink messages');

    return {
      jobId: completed.job.id, recipientCount: 1,
      recipientHashVerified: typeof completed.result.items[0].recipientHash === 'string',
      sinkHost: base.hostname, sinkOnly: true, payloadRedacted: true, cleanupVerified: true
    };
  } finally {
    const created = (await messages(base)).filter(message => !priorIds.has(String(message.ID)));
    if (created.length) {
      await apiFetch(base, 'messages', {
        method: 'DELETE', headers: { 'content-type': 'application/json' },
        body: JSON.stringify({ IDs: created.map(message => String(message.ID)) })
      });
    }
    const residual = (await messages(base)).filter(message => !priorIds.has(String(message.ID)));
    assert.equal(residual.length, 0, 'Disposable mail fixture remains in the private sink');
  }
}

async function main() {
  const client = await McpTestClient.fromEnvironment();
  await client.initialize();
  verifyWhoami(
    await client.callTool('projeqtor_whoami'),
    process.env.PROJEQTOR_TEST_ACTOR ?? 'beta4-admin'
  );
  const result = await runMailAcceptance({
    client, runId: process.env.PROJEQTOR_TEST_RUN_ID
  });
  process.stdout.write(`${JSON.stringify({ ok: true, ...result })}\n`);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch(error => {
    process.stderr.write(`Mail acceptance failed: ${error.message}\n`);
    process.exitCode = 1;
  });
}
