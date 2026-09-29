export default {
  id: 'environment',
  locks: ['global-configuration', 'planning-engine'],
  requiredTools: [],
  requiredActions: [
    'environment.calendar.update',
    'environment.calendar.bank_holidays.apply',
    'environment.resource.manage',
    'environment.team.manage',
    'environment.contact.manage',
    'environment.capacity.update',
    'environment.cost.update',
    'environment.cost.apply_role_defaults',
    'environment.surbooking.update',
    'environment.incompatibility.update',
    'environment.support.update',
    'environment.intervention.capacity.update',
    'environment.intervention.schedule',
    'environment.organization.manage',
    'environment.prospect.event.manage',
    'environment.prospect.convert',
    'environment.client_relationship.promote'
  ],
  workflowFamilies: [
    'calendars', 'holidays', 'resources', 'teams', 'contacts', 'capacity',
    'costs', 'prospect-events', 'prospect-conversion', 'client-promotion', 'surbooking', 'incompatibility', 'support', 'interventions', 'organizations'
  ]
};
