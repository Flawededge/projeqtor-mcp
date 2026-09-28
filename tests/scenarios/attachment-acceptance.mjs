import { createHash } from 'node:crypto';
import { callTool, decodeResource } from './resource-verification.mjs';

async function guardedAction(client, action, arguments_) {
  const prepared = await callTool(client, 'projeqtor_prepare_action', { action, arguments: arguments_ });
  if (typeof prepared.confirmationToken !== 'string' || prepared.confirmationToken.length < 20) {
    throw new Error(`${action} returned no confirmation token`);
  }
  return callTool(client, 'projeqtor_commit_action', { confirmationToken: prepared.confirmationToken });
}

async function abortUpload(client, uploadId) {
  const aborted = await guardedAction(client, 'attachment.upload.abort', { uploadId });
  if (aborted.ok !== true || aborted.status !== 'aborted') throw new Error('Attachment upload abort did not complete');
}

async function deleteAttachment(client, id, expectedVersion) {
  const result = await guardedAction(client, 'tools.attachment.delete', {
    items: [{ id, expectedVersion }]
  });
  if (result.ok !== true || result.rolledBack === true || result.items?.[0]?.status !== 'deleted') {
    throw new Error(`Attachment #${id} cleanup failed`);
  }
}

export async function runAttachmentAcceptance({ client, identity, ledger, runId }) {
  if (identity.isResource !== true || !Number.isSafeInteger(identity.id) || identity.id < 1) {
    throw new Error('Disposable artifact acceptance requires a resource-backed actor');
  }

  const payload = Buffer.from(`Beta 4 attachment acceptance ${runId}\n`, 'utf8');
  const digest = createHash('sha256').update(payload).digest('hex');
  let committed = null;
  let openUploadId = null;
  try {
    const begun = await callTool(client, 'projeqtor_execute_action', {
      action: 'attachment.upload.begin',
      arguments: {
        refType: 'Resource', refId: identity.id, fileName: `beta4-${runId}.txt`,
        mimeType: 'text/plain', expectedBytes: payload.length, description: 'Disposable Beta 4 acceptance fixture'
      }
    });
    openUploadId = begun.upload?.uploadId;
    if (typeof openUploadId !== 'string' || !/^[a-f0-9]{48}$/.test(openUploadId)) throw new Error('Attachment begin returned an invalid upload ID');
    const chunk = await callTool(client, 'projeqtor_execute_action', {
      action: 'attachment.upload.chunk',
      arguments: { uploadId: openUploadId, offset: 0, base64: payload.toString('base64') }
    });
    if (chunk.receivedBytes !== payload.length || chunk.expectedBytes !== payload.length) throw new Error('Attachment chunk byte accounting failed');
    const committedResult = await callTool(client, 'projeqtor_execute_action', {
      action: 'attachment.upload.commit', arguments: { uploadId: openUploadId }
    });
    openUploadId = null;
    committed = committedResult.attachment;
    const uri = committedResult.resource;
    if (!Number.isSafeInteger(committed?.id) || committed.id < 1 ||
        typeof committed._version !== 'string' || uri !== `projeqtor://attachments/${committed.id}`) {
      throw new Error('Attachment commit returned invalid metadata');
    }
    await ledger.record({ module: 'tools', kind: 'attachment', objectClass: 'Attachment', id: committed.id });
    const downloaded = decodeResource(await client.readResource(uri), uri);
    if (downloaded.mimeType !== 'text/plain' || !downloaded.bytes.equals(payload) ||
        createHash('sha256').update(downloaded.bytes).digest('hex') !== digest) throw new Error('Attachment resource did not round-trip');

    const abortPayload = Buffer.from('abort-me', 'utf8');
    const abortBegin = await callTool(client, 'projeqtor_execute_action', {
      action: 'attachment.upload.begin',
      arguments: { refType: 'Resource', refId: identity.id, fileName: `beta4-abort-${runId}.txt`, mimeType: 'text/plain', expectedBytes: abortPayload.length }
    });
    openUploadId = abortBegin.upload?.uploadId;
    if (typeof openUploadId !== 'string') throw new Error('Abort fixture did not return an upload ID');
    await callTool(client, 'projeqtor_execute_action', {
      action: 'attachment.upload.chunk',
      arguments: { uploadId: openUploadId, offset: 0, base64: abortPayload.toString('base64') }
    });
    await abortUpload(client, openUploadId);
    openUploadId = null;

    await deleteAttachment(client, committed.id, committed._version);
    await ledger.complete({ objectClass: 'Attachment', id: committed.id });
    let unavailable = false;
    try { await client.readResource(uri); } catch { unavailable = true; }
    if (!unavailable) throw new Error('Deleted attachment resource remained readable');
    const summary = {
      attachmentId: committed.id, bytes: payload.length, mimeType: downloaded.mimeType,
      digestVerified: true, abortVerified: true, cleanupVerified: true
    };
    committed = null;
    return summary;
  } finally {
    if (openUploadId) {
      try { await abortUpload(client, openUploadId); } catch {}
    }
    if (committed?.id && committed?._version) {
      try { await deleteAttachment(client, committed.id, committed._version); } catch {}
    }
  }
}
