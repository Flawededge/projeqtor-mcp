export default {
  id: 'tools',
  locks: ['mail-delivery', 'document-storage'],
  requiredTools: [],
  requiredActions: [
    'tools.document.manage', 'tools.document.rights', 'tools.document.version', 'tools.document.extract', 'tools.attachment.delete',
    'tools.note.manage', 'tools.link.manage', 'tools.relationship.link', 'tools.relationship.unlink', 'tools.clone.start', 'tools.clone.schedule',
    'tools.notification.status', 'tools.notification.subscribe', 'tools.notification.send',
    'tools.mail.send', 'tools.automation.manage', 'tools.localization.manage',
    'tools.asset.manage', 'import.start', 'import.cleanup', 'export.start'
  ],
  workflowFamilies: [
    'documents', 'versions', 'attachments', 'notes', 'links', 'cloning',
    'notifications', 'mail', 'automation', 'localization', 'assets', 'imports', 'exports'
  ],
  executionPolicy: {
    liveSafe: ['documents', 'notes', 'links', 'imports', 'exports'],
    disposableOnly: ['mail', 'notifications', 'automation'],
    mailSinkRequired: true
  }
};
