export default {
  id: 'scrum',
  locks: [],
  requiredTools: ['projeqtor_manage_sprint'],
  requiredActions: [
    'scrum.sprint.manage', 'scrum.story.manage', 'scrum.backlog.prioritize',
    'scrum.sprint.lifecycle', 'scrum.kanban.board.get', 'scrum.kanban.board.manage',
    'scrum.kanban.columns.replace', 'scrum.kanban.card.move', 'scrum.kanban.preferences',
    'scrum.poker.state.get', 'scrum.poker.session.lifecycle', 'scrum.poker.item.manage',
    'scrum.poker.item.state', 'scrum.poker.vote.cast', 'scrum.poker.vote.visibility'
  ],
  workflowFamilies: [
    'backlog-priorities', 'stories-and-points', 'sprint-lifecycle',
    'kanban-boards', 'kanban-cards', 'planning-poker'
  ]
};
