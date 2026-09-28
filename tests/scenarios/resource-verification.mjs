import { setTimeout as delay } from 'node:timers/promises';
import { structuredToolResult } from '../support/tool-results.mjs';

export const MCP_RESOURCE_MAX_BYTES = 16 * 1024 * 1024;
export const TERMINAL_JOB_STATES = new Set(['succeeded', 'failed', 'cancelled', 'recovery_required', 'expired']);

export function safeKey(...parts) {
  return parts.join(':').replace(/[^A-Za-z0-9_.:-]/g, '-').slice(0, 255);
}

export async function callTool(client, name, arguments_ = {}) {
  return structuredToolResult(await client.callTool(name, arguments_), name);
}

export function decodeResource(result, expectedUri, { maxBytes = MCP_RESOURCE_MAX_BYTES } = {}) {
  if (!result || !Array.isArray(result.contents) || result.contents.length !== 1) {
    throw new Error('MCP resource read returned an invalid content envelope');
  }
  const content = result.contents[0];
  if (content.uri !== expectedUri || typeof content.mimeType !== 'string' || !content.mimeType) {
    throw new Error('MCP resource read returned mismatched metadata');
  }
  if (typeof content.blob !== 'string' || content.blob.length === 0 ||
      !/^[A-Za-z0-9+/]*={0,2}$/.test(content.blob) || content.blob.length % 4 !== 0) {
    throw new Error('MCP resource read returned malformed base64');
  }
  const bytes = Buffer.from(content.blob, 'base64');
  if (bytes.length === 0 || bytes.length > maxBytes) throw new Error('MCP resource violates the bounded download contract');
  if (bytes.toString('base64') !== content.blob) throw new Error('MCP resource base64 is not canonical');
  return { bytes, mimeType: content.mimeType };
}

export function verifyArtifactSignature(format, bytes) {
  if (!Buffer.isBuffer(bytes) || bytes.length === 0) throw new Error('Artifact is empty');
  if (format === 'pdf' && !bytes.subarray(0, 5).equals(Buffer.from('%PDF-'))) throw new Error('PDF signature is invalid');
  if (format === 'png' && !bytes.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))) {
    throw new Error('PNG signature is invalid');
  }
  if (format === 'jpeg' && !bytes.subarray(0, 3).equals(Buffer.from([0xff, 0xd8, 0xff]))) throw new Error('JPEG signature is invalid');
  if (format === 'json') {
    try { JSON.parse(bytes.toString('utf8')); } catch { throw new Error('JSON artifact is invalid'); }
  }
  if (format === 'csv' && (bytes.includes(0) || !bytes.toString('utf8').trim())) throw new Error('CSV artifact is invalid');
}

export function reportArguments(report, format) {
  if (!report || !Number.isSafeInteger(report.id) || report.id < 1 ||
      !Array.isArray(report.formats) || !report.formats.includes(format)) return null;
  const parameters = {};
  for (const parameter of report.parameters ?? []) {
    if (!parameter || typeof parameter.name !== 'string') return null;
    if (parameter.defaultValue !== null && parameter.defaultValue !== undefined) parameters[parameter.name] = parameter.defaultValue;
    else if (parameter.required) return null;
  }
  return { idReport: report.id, format, parameters };
}



export async function waitForJob(client, id, { timeoutMs = 180_000, intervalMs = 500 } = {}) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    const job = await callTool(client, 'projeqtor_get_job', { id });
    if (!Number.isSafeInteger(job.id) || job.id !== id || typeof job.status !== 'string') throw new Error(`Job #${id} returned malformed state`);
    if (TERMINAL_JOB_STATES.has(job.status)) return job;
    await delay(intervalMs);
  }
  throw new Error(`Job #${id} did not reach a terminal state`);
}

export async function readAndVerifyJobArtifact(client, job, format) {
  if (job.status !== 'succeeded' || job.resultResource !== `projeqtor://jobs/${job.id}/result`) {
    throw new Error(`Job #${job.id} did not publish its expected resource`);
  }
  const resource = decodeResource(await client.readResource(job.resultResource), job.resultResource);
  verifyArtifactSignature(format, resource.bytes);
  return { jobId: job.id, format, mimeType: resource.mimeType, bytes: resource.bytes.length };
}

