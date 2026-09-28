import {
  callTool,
  readAndVerifyJobArtifact,
  reportArguments,
  safeKey,
  TERMINAL_JOB_STATES,
  waitForJob
} from './resource-verification.mjs';

async function catalog(client) {
  const result = await callTool(client, 'projeqtor_execute_action', { action: 'reports.catalog.read', arguments: {} });
  if (result.ok !== true || !Array.isArray(result.reports) ||
      result.count !== result.reports.length || result.count < 1) {
    throw new Error('Report catalog returned no permitted reports');
  }
  return result.reports;
}

async function renderFirst(client, reports, formats, runId, label) {
  const attempts = [];
  for (const format of formats) {
    const candidates = reports.map(report => ({ report, arguments: reportArguments(report, format) }))
      .filter(candidate => candidate.arguments).slice(0, 12);
    for (const [index, candidate] of candidates.entries()) {
      const queued = await callTool(client, 'projeqtor_render_report', {
        ...candidate.arguments, idempotencyKey: safeKey('b4-artifacts-v2', runId, label, format, candidate.report.id, index)
      });
      if (queued.queued !== true || !Number.isSafeInteger(queued.job?.id)) throw new Error('Report rendering was not queued');
      const job = await waitForJob(client, queued.job.id, { timeoutMs: 30_000 });
      if (job.status === 'succeeded') return readAndVerifyJobArtifact(client, job, format);
      attempts.push({ reportId: candidate.report.id, format, status: job.status, errorCode: job.errorCode ?? null });
    }
  }
  throw new Error(`${label} report rendering had no successful permitted candidate (${attempts.length} attempts)`);
}

async function cancellationRecovery(client, runId) {
  for (let attempt = 0; attempt < 4; attempt += 1) {
    const queued = await callTool(client, 'projeqtor_execute_action', {
      action: 'export.start',
      arguments: { objectClass: 'History', format: 'json' },
      idempotencyKey: safeKey('b4', runId, 'cancel-export', attempt)
    });
    if (queued.queued !== true || !Number.isSafeInteger(queued.job?.id)) throw new Error('Safe export job was not queued');
    const cancellation = await callTool(client, 'projeqtor_cancel_job', { id: queued.job.id });
    const cancelled = TERMINAL_JOB_STATES.has(cancellation.status) ? cancellation : await waitForJob(client, queued.job.id);
    if (cancelled.status !== 'cancelled') continue;
    const retried = await callTool(client, 'projeqtor_retry_job', { id: queued.job.id });
    if (retried.status !== 'queued' || retried.retryPolicy !== 'safe') throw new Error('Cancelled safe job was not requeued');
    const completed = await waitForJob(client, queued.job.id);
    const artifact = await readAndVerifyJobArtifact(client, completed, 'json');
    return { ...artifact, cancelled: true, safeRetryVerified: true, attempts: completed.attempts };
  }
  throw new Error('Could not observe queued cancellation before the disposable worker claimed the job');
}

export async function runReportAcceptance(client, runId) {
  const reports = await catalog(client);
  const recovered = await cancellationRecovery(client, runId);
  const pdf = await renderFirst(client, reports, ['pdf'], runId, 'pdf');
  const image = await renderFirst(client, reports, ['png', 'jpeg'], runId, 'image');
  const file = await renderFirst(client, reports, ['json', 'csv'], runId, 'structured');
  return { catalogCount: reports.length, pdf, image, file, cancellationRecovery: await cancellationRecovery(client, runId) };
}
