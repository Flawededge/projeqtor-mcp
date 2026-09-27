export default {
  id: 'environment', locks: ['global-configuration'], requiredTools: [],
  requiredActions: ['environment.calendar.update', 'environment.capacity.update', 'environment.team.manage'],
  workflowFamilies: ['calendars', 'holidays', 'resources', 'teams', 'contacts', 'capacity', 'costs', 'surbooking', 'incompatibility', 'support']
};
