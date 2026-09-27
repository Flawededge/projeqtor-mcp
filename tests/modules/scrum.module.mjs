export default {
  id: 'scrum', locks: [], requiredTools: ['projeqtor_manage_sprint'],
  requiredActions: ['scrum.backlog.rank', 'scrum.sprint.start', 'scrum.sprint.close'],
  workflowFamilies: ['backlog', 'story-points', 'sprint-lifecycle', 'kanban', 'poker']
};
