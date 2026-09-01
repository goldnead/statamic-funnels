/**
 * What a node is, in this addon, as data for the shared editor.
 *
 * `@goldnead/flow-canvas` knows how to draw a graph and nothing about funnels.
 * This file is the whole of what makes its canvas a *funnel* canvas — and it is
 * the same file, in shape, that the automations addon has. That is why the two
 * editors look like one product rather than two that resemble each other.
 */

import { createNodeIcon } from '@goldnead/flow-canvas';

/**
 * The structural half of a kind: which group it belongs to, what colour it
 * wears, whether there may be only one.
 *
 * The *words* are not here. Statamic's JavaScript `__()` only knows core and
 * application strings, so an addon label written in JS renders as its raw key
 * in the middle of the canvas. Every word comes from the server and is merged
 * in by `withLabels()` below.
 */
const KIND_SHAPES = {
    entry: {
        group: 'entrys',
        color: 'blue',
        // One per funnel: a path has one beginning. Also why it cannot be
        // duplicated and why it offers "Replace" rather than "Delete" —
        // deleting the only entry leaves a funnel nobody can walk into.
        unique: true,
        hasInput: false,
    },
    page: {
        group: 'pages',
        color: 'emerald',
        fallback: true,
    },
    offer: {
        group: 'offers',
        color: 'amber',
    },
    finish: {
        group: 'finishs',
        color: 'purple',
    },
    // A mail hangs off a step and is never entered. It has an input handle
    // (the edge from the step's output) and no outputs, and the shared canvas
    // draws it as a branch beside the real way on — which is the whole
    // picture: HighLevel's look, a funnel's behaviour.
    mail: {
        group: 'mails',
        color: 'rose',
    },
};

/**
 * The kinds, with the server's words folded in.
 *
 * @param {Object} labels  The `labels.kinds` payload from the controller.
 */
export function withLabels(labels = {}) {
    const kinds = {};

    for (const [kind, shape] of Object.entries(KIND_SHAPES)) {
        kinds[kind] = { ...shape, ...(labels[kind] ?? {}) };
    }

    return kinds;
}

/**
 * Node handle → icon. Every name is a real icon shipped by `@statamic/cms`;
 * an invented one renders as nothing, which looks like a broken build.
 */
export const nodeIcon = createNodeIcon({
    entry: 'sign-post',
    page: 'file-content-list',
    capture: 'forms',
    offer: 'money-cashier-price-tag',
    finish: 'flag',
    mail: 'mail',
}, {
    entry: 'sign-post',
    page: 'file-content-list',
    offer: 'money-cashier-price-tag',
    finish: 'flag',
    mail: 'mail',
});
