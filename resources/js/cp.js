/**
 * Control Panel entry. The registered names must match what the controller
 * passes to `Inertia::render()`, exactly.
 */

import FunnelsIndex from './pages/Funnels/Index.vue';
import FunnelsEdit from './pages/Funnels/Edit.vue';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-funnels::Funnels/Index', FunnelsIndex);
    Statamic.$inertia.register('statamic-funnels::Funnels/Edit', FunnelsEdit);
});
