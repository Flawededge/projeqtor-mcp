import { defineModule } from '../runtime.mjs';

export const ENVIRONMENT_ACTIONS = Object.freeze([
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
  'environment.prospect.event.manage',
  'environment.prospect.convert',
  'environment.client_relationship.promote',
  'environment.organization.manage'
]);

export const ENVIRONMENT_HANDLERS = Object.freeze([
  'tool:addGenericBankOffDaysToCalendar',
  'tool:removeContact',
  'tool:removeResourceCapacity',
  'tool:removeResourceCost',
  'tool:removeResourceSurbooking',
  'tool:saveCalendar',
  'tool:saveContact',
  'tool:saveInterventionCapacity',
  'tool:saveResourceCapacity',
  'tool:saveResourceCost',
  'tool:saveResourceIncompatible',
  'tool:saveResourceSupport',
  'tool:saveResourceSurbooking',
  'tool:saveProspectEvent',
  'tool:saveProspectTransform',
  'tool:switchOtherClient',
  'tool:selectInterventionDate',
  'tool:updateResourceCost',
]);

export default defineModule({
  id: 'environment',
  version: '2.0.0-beta.4',
  dependencies: ['core', 'configuration'],
  enabledStateRequirements: [],
  claims: {
    classes: [
      'Calendar', 'CalendarBankOffDays', 'CalendarDefinition', 'Resource', 'Team', 'Contact',
      'Client', 'OtherClient', 'Prospect', 'ProspectEvent', 'Organization', 'ResourceCapacity', 'ResourceCost', 'ResourceSurbooking',
      'ResourceIncompatible', 'ResourceSupport',
      'InterventionCapacity'
    ],
    actions: ENVIRONMENT_ACTIONS,
    handlers: ENVIRONMENT_HANDLERS
  },
  register() {
    // Environment workflows are intentionally exposed through the canonical
    // action engine; no extra convenience tool or alternate business path.
  }
});
