/**
 * Control Panel entry. The registered names must match what the controllers
 * pass to `Inertia::render()`, exactly: a mismatch is a blank screen with
 * nothing in the log.
 */

import TeamsIndex from './pages/Teams/Index.vue';
import TeamsShow from './pages/Teams/Show.vue';
import TeamsWiring from './pages/Teams/Wiring.vue';
import RolesIndex from './pages/Roles/Index.vue';
import SetupRequired from './pages/SetupRequired.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('teams::Teams/Index', TeamsIndex);
    Statamic.$inertia.register('teams::Teams/Show', TeamsShow);
    Statamic.$inertia.register('teams::Teams/Wiring', TeamsWiring);
    Statamic.$inertia.register('teams::Roles/Index', RolesIndex);
    Statamic.$inertia.register('teams::SetupRequired', SetupRequired);
});
