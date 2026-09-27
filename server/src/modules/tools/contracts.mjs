export const TOOLS_ACTIONS = Object.freeze([
  'tools.document.manage', 'tools.document.rights', 'tools.document.version', 'tools.document.extract', 'tools.document.version.delete',
  'tools.attachment.delete', 'tools.note.manage', 'tools.note.delete',
  'tools.link.manage', 'tools.link.delete', 'tools.relationship.link', 'tools.relationship.unlink', 'tools.clone.start', 'tools.clone.schedule',
  'tools.notification.status', 'tools.notification.subscribe', 'tools.notification.unsubscribe',
  'tools.notification.send', 'tools.mail.send', 'tools.automation.manage',
  'tools.localization.manage', 'tools.asset.manage',
  'tools.image.upload.begin', 'tools.image.upload.commit',
  'import.start', 'import.cleanup', 'export.start',
  'attachment.upload.begin', 'attachment.upload.chunk', 'attachment.upload.commit', 'attachment.upload.abort'
]);

export const TOOLS_JOBS = Object.freeze([
  'tools.document.version', 'tools.document.extract', 'tools.clone.start', 'tools.notification.send',
  'tools.mail.send', 'tools.image.upload.commit', 'import.start', 'export.start'
]);

export const TOOLS_HANDLERS = Object.freeze([
  'tool:changeStatusNotification', 'tool:copyDocumentTo', 'tool:documentExplorerColumnAction',
  'tool:documentExplorerMove', 'tool:dynamicDialogWorkflowProfileParameter',
  'tool:extractDocumentFromObject', 'tool:readNotification', 'tool:readNotificationTree',
  'tool:removeAttachment', 'tool:removeAttachmentSendMail', 'tool:removeDocumentVersion',
  'tool:removeLink', 'tool:removeNote', 'tool:removeObjectLinkedByIdToMainObject',
  'tool:saveAttachment', 'tool:saveDataCloning', 'tool:saveDocumentRight',
  'tool:saveDocumentVersion', 'tool:saveLink', 'tool:saveNote', 'tool:saveNoteStream',
  'tool:saveObjectLinkedByIdToMainObject', 'tool:saveSubscription',
  'tool:saveWorkflowProfileParameter', 'tool:sendMail', 'tool:sendMailTest', 'tool:uploadImage',
  'view:menuNotificationRead'
]);

export const TOOLS_WORKFLOW_FAMILIES = Object.freeze([
  'documents', 'versions', 'attachments', 'notes', 'links', 'cloning',
  'notifications', 'mail', 'automation', 'localization', 'assets', 'imports', 'exports'
]);
