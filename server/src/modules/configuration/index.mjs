import { defineModule } from '../runtime.mjs';

export default defineModule({
  id: 'configuration', version: '2.0.0-beta.4', dependencies: ['core'],
  claims: {},
  register() {
    // Module-specific actions can be added without editing the shared loader.
  }
});
