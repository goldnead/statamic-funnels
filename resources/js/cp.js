/**
 * Control Panel entry. The registered names must match what the controller
 * passes to `Inertia::render()`, exactly.
 */

import FunnelsIndex from './pages/Funnels/Index.vue';
import FunnelsEdit from './pages/Funnels/Edit.vue';
import LandkartenIndex from './pages/Landkarten/Index.vue';
import LandkartenShow from './pages/Landkarten/Show.vue';
import SetupRequired from './pages/SetupRequired.vue';
import { watchReturnBar } from './support/mapReturnBar.js';
import { watchNavHints } from './support/navHints.js';

Statamic.booting(() => {
    Statamic.$inertia.register('statamic-funnels::Funnels/Index', FunnelsIndex);
    Statamic.$inertia.register('statamic-funnels::Funnels/Edit', FunnelsEdit);
    Statamic.$inertia.register('statamic-funnels::Landkarten/Index', LandkartenIndex);
    Statamic.$inertia.register('statamic-funnels::Landkarten/Show', LandkartenShow);
    Statamic.$inertia.register('statamic-funnels::SetupRequired', SetupRequired);

    // The bar above another addon's editor that leads back to the Landkarte,
    // and the line under this addon's two navigation entries.
    watchReturnBar();
    watchNavHints();
});
