import { defineModule } from '../runtime.mjs';
import { TOOLS_ACTIONS, TOOLS_HANDLERS, TOOLS_JOBS } from './contracts.mjs';

export default defineModule({
  id: 'tools',
  version: '2.1.0',
  dependencies: ['core', 'configuration'],
  enabledStateRequirements: [],
  claims: {
    actions: TOOLS_ACTIONS,
    handlers: TOOLS_HANDLERS,
    jobs: TOOLS_JOBS
  },
  register() {
    // Semantic actions are exposed through the canonical action tools. The
    // module pack reserves ownership here without adding another public tool.
  }
});
