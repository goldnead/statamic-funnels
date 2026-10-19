/**
 * Where things sit on a Landkarte, and which of them a selection lights up.
 *
 * Pure functions, no Vue: the page hands in what the server sent and gets back
 * what the canvas draws. Lanes run left to right, a lane's cards stack top to
 * bottom in the order the lane stores them, and every lane is as tall as the
 * tallest, so the coloured stripes read as one board.
 */

export const LANE_W = 330;
export const NODE_W = 250;
export const ROW_H = 168;
export const HEAD_H = 96;
export const LANE_PAD = 30;
export const LANE_MIN_H = 470;

/** The accent and the icon of each kind of object. Unknown kinds get the neutral one. */
export const KINDS = {
    entry: { icon: 'file-content-list', accent: 'blue' },
    lead_magnet: { icon: 'magnet-action', accent: 'green' },
    automation: { icon: 'workflow', accent: 'sky' },
    course: { icon: 'content-book-open', accent: 'indigo' },
    funnel: { icon: 'shopping-cart', accent: 'fuchsia' },
    product: { icon: 'package-box-crate', accent: 'purple' },
    offer: { icon: 'tag-new', accent: 'amber' },
};

export const kindOf = (type) => KINDS[type] ?? { icon: 'link', accent: 'gray' };

/**
 * Rows within a lane: a card keeps the row its lane stores; a card that has no
 * row yet (a broken reference the page drew for an edge) goes below the last.
 */
function rowsOf(lanes, nodes) {
    const next = lanes.map(() => 0);
    const rows = {};

    for (const node of nodes) {
        if (node.row !== null && node.row !== undefined) {
            rows[node.key] = node.row;
            next[node.lane] = Math.max(next[node.lane], node.row + 1);
        }
    }

    for (const node of nodes) {
        if (node.row === null || node.row === undefined) {
            rows[node.key] = next[node.lane]++;
        }
    }

    return { rows, counts: next };
}

/**
 * @returns {{ flowNodes: object[], laneHeight: number, rows: Record<string, number> }}
 */
export function layoutNodes(lanes, nodes, { editing = false } = {}) {
    const { rows, counts } = rowsOf(lanes, nodes);
    const deepest = Math.max(0, ...counts) + (editing ? 1 : 0);
    const laneHeight = Math.max(LANE_MIN_H, HEAD_H + LANE_PAD + deepest * ROW_H + LANE_PAD);
    const flowNodes = [];

    lanes.forEach((lane, index) => {
        flowNodes.push({
            id: `lane:${index}`,
            type: 'lane',
            position: { x: index * LANE_W, y: 0 },
            data: { lane, index, last: index === lanes.length - 1, width: LANE_W, height: laneHeight, headHeight: HEAD_H },
            draggable: false,
            selectable: false,
            focusable: false,
            zIndex: -1,
        });
    });

    for (const node of nodes) {
        flowNodes.push({
            id: node.key,
            type: 'landkarte',
            position: {
                x: node.lane * LANE_W + (LANE_W - NODE_W) / 2,
                y: HEAD_H + LANE_PAD + rows[node.key] * ROW_H,
            },
            data: node,
            draggable: false,
            zIndex: 2,
        });
    }

    if (editing) {
        lanes.forEach((lane, index) => {
            flowNodes.push({
                id: `adder:${index}`,
                type: 'adder',
                position: { x: index * LANE_W + (LANE_W - NODE_W) / 2, y: HEAD_H + LANE_PAD + counts[index] * ROW_H },
                data: { index },
                draggable: false,
                selectable: false,
                zIndex: 1,
            });
        });
    }

    return { flowNodes, laneHeight, rows };
}

/**
 * The sides an edge leaves and arrives on. Forward in the reading direction it
 * leaves right and arrives left; backward the other way round; within one lane
 * it runs down or up the side that faces the other card.
 */
export function handlesFor(edge, nodesByKey, rows) {
    const a = nodesByKey[edge.source];
    const b = nodesByKey[edge.target];

    if (!a || !b) return { sourceHandle: 'sr', targetHandle: 'tl' };

    if (a.lane < b.lane) return { sourceHandle: 'sr', targetHandle: 'tl' };
    if (a.lane > b.lane) return { sourceHandle: 'sl', targetHandle: 'tr' };

    return rows[edge.source] <= rows[edge.target]
        ? { sourceHandle: 'sb', targetHandle: 'tt' }
        : { sourceHandle: 'st', targetHandle: 'tb' };
}

export function flowEdges(edges, nodes, rows) {
    const byKey = Object.fromEntries(nodes.map((n) => [n.key, n]));

    return edges.map((edge) => ({
        id: edge.id,
        source: edge.source,
        target: edge.target,
        type: 'landkarte',
        ...handlesFor(edge, byKey, rows),
        data: edge,
        // Lines run behind the cards, so a long connection never crosses a card's text.
        zIndex: 0,
    }));
}

/**
 * Everything that leads to the node and everything it leads to: the whole way
 * before and after, which is what makes a selection answer "where does this
 * fit". Returns the node keys and the edge ids.
 */
export function wayThrough(key, edges) {
    const nodes = new Set([key]);
    const ids = new Set();

    const walk = (from, towards) => {
        const queue = [from];

        while (queue.length) {
            const at = queue.shift();

            for (const edge of edges) {
                const here = towards ? edge.source : edge.target;
                const there = towards ? edge.target : edge.source;

                if (here === at && !ids.has(edge.id)) {
                    ids.add(edge.id);
                    if (!nodes.has(there)) {
                        nodes.add(there);
                        queue.push(there);
                    }
                }
            }
        }
    };

    walk(key, true);
    walk(key, false);

    return { nodes, ids };
}
