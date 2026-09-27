export default {
  id: 'tools', locks: ['mail-delivery'], requiredTools: [],
  requiredActions: ['tools.document.version', 'tools.notification.send', 'tools.import.start'],
  workflowFamilies: ['documents', 'attachments', 'notes', 'links', 'cloning', 'notifications', 'mail', 'automation', 'localization', 'assets', 'imports', 'exports']
};
