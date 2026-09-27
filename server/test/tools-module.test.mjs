import test from 'node:test';
import assert from 'node:assert/strict';
import tools from '../src/modules/tools/index.mjs';
import {
  TOOLS_ACTIONS, TOOLS_HANDLERS, TOOLS_JOBS, TOOLS_WORKFLOW_FAMILIES
} from '../src/modules/tools/contracts.mjs';
import { createProjeqtorServer } from '../src/tools.mjs';

test('Tools module reserves all semantic workflow families without expanding the 36-tool surface', () => {
  assert.equal(tools.id, 'tools');
  assert.deepEqual(tools.dependencies, ['core', 'configuration']);
  assert.equal(TOOLS_ACTIONS.length, 31);
  assert.deepEqual(tools.claims.actions, TOOLS_ACTIONS);
  assert.deepEqual(tools.claims.handlers, TOOLS_HANDLERS);
  assert.deepEqual(tools.claims.jobs, TOOLS_JOBS);
  assert.deepEqual(TOOLS_WORKFLOW_FAMILIES, [
    'documents', 'versions', 'attachments', 'notes', 'links', 'cloning',
    'notifications', 'mail', 'automation', 'localization', 'assets', 'imports', 'exports'
  ]);
  const server = createProjeqtorServer({ username: 'tester', apiRequest: async () => ({ items: [] }) });
  assert.equal(Object.keys(server._registeredTools).length, 36);
});

test('Tools jobs include every long-running or externally delivered operation', () => {
  assert.deepEqual(TOOLS_JOBS, [
    'tools.document.version', 'tools.document.extract', 'tools.clone.start', 'tools.notification.send',
    'tools.mail.send', 'tools.image.upload.commit', 'import.start', 'export.start'
  ]);
  for (const job of TOOLS_JOBS) assert.ok(TOOLS_ACTIONS.includes(job));
});

test('Tools handler ownership is duplicate-free and excludes Product asset composition', () => {
  assert.equal(new Set(TOOLS_HANDLERS).size, TOOLS_HANDLERS.length);
  assert.ok(TOOLS_HANDLERS.includes('tool:saveDocumentVersion'));
  assert.ok(TOOLS_HANDLERS.includes('tool:sendMail'));
  assert.ok(!TOOLS_HANDLERS.includes('tool:saveAssetComposition'));
  assert.ok(!TOOLS_HANDLERS.includes('tool:removeAssetComposition'));
});

test('existing import, export, and attachment action IDs stay reserved', () => {
  for (const action of [
    'import.start', 'import.cleanup', 'export.start',
    'attachment.upload.begin', 'attachment.upload.chunk',
    'attachment.upload.commit', 'attachment.upload.abort'
  ]) assert.ok(TOOLS_ACTIONS.includes(action));
});
