/**
 * Where things sit on a Landkarte, where the lines run, and which cards a
 * selection lights up.
 *
 * Pure functions, no Vue: the page hands in what the server sent and gets back
 * what the canvas draws. Lanes run left to right, a lane's cards stack top to
 * bottom in the order the lane stores them, every card has the same size, and
 * every lane is as tall as the tallest. Because the grid is fixed, the lines
 * are routed here from plain arithmetic instead of being asked of the canvas:
 * they run in the gutters between the lanes and in the gap between two rows,
 * which no card ever occupies, so a line never crosses a card.
 */

export const LANE_W = 256;
export const NODE_W = 204;
export const NODE_H = 112;
export const ROW_H = 148;
export const HEAD_H = 68;
export const LANE_PAD = 26;
export const LANE_MIN_H = 360;

const GAP = ROW_H - NODE_H;
const STEP = 9;
const SPREAD = 18;
const RADIUS = 8;

/** The icon of each kind of object. Colour is not part of a kind: the card says what it is in words. */
export const KINDS = {
    entry: { icon: 'file-content-list' },
    lead_magnet: { icon: 'magnet-action' },
    automation: { icon: 'workflow' },
    course: { icon: 'content-book-open' },
    funnel: { icon: 'shopping-cart' },
    product: { icon: 'package-box-crate' },
    offer: { icon: 'tag-new' },
};

export const kindOf = (type) => KINDS[type] ?? { icon: 'link' };

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

const cardLeft = (lane) => lane * LANE_W + (LANE_W - NODE_W) / 2;
const cardTop = (row) => HEAD_H + LANE_PAD + row * ROW_H;

/**
 * @returns {{ flowNodes: object[], laneHeight: number, rows: Record<string, number> }}
 */
export function layoutNodes(lanes, nodes, { editing = false } = {}) {
    const { rows, counts } = rowsOf(lanes, nodes);
    const deepest = Math.max(0, ...counts) + (editing ? 1 : 0);
    const laneHeight = Math.max(LANE_MIN_H, HEAD_H + LANE_PAD + deepest * ROW_H);
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
            position: { x: cardLeft(node.lane), y: cardTop(rows[node.key]) },
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
                position: { x: cardLeft(index), y: cardTop(counts[index]) },
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
 * it runs between the neighbouring cards, or out to the right and back in.
 */
export function handlesFor(edge, nodesByKey, rows) {
    const a = nodesByKey[edge.source];
    const b = nodesByKey[edge.target];

    if (!a || !b) return { sourceHandle: 'sr', targetHandle: 'tl' };

    if (a.lane < b.lane) return { sourceHandle: 'sr', targetHandle: 'tl' };
    if (a.lane > b.lane) return { sourceHandle: 'sl', targetHandle: 'tr' };

    const ra = rows[edge.source];
    const rb = rows[edge.target];

    if (Math.abs(ra - rb) === 1) {
        return ra < rb ? { sourceHandle: 'sb', targetHandle: 'tt' } : { sourceHandle: 'st', targetHandle: 'tb' };
    }

    return { sourceHandle: 'sr', targetHandle: 'tr' };
}

/**
 * Which channels an edge uses, as keys: `g<n>` is the gutter right of lane n
 * (`g-1` is the one left of the first lane), `r<n>` the gap under row n.
 * Edges that share a channel are spread side by side so they never lie on top
 * of each other.
 */
function plan(edge, a, b, rows) {
    const ra = rows[edge.source];
    const rb = rows[edge.target];

    if (a.lane === b.lane) {
        if (Math.abs(ra - rb) === 1) return { kind: 'column' };
        return { kind: 'around', gutter: `g${a.lane}` };
    }

    const forward = a.lane < b.lane;
    const span = Math.abs(b.lane - a.lane);
    const out = forward ? `g${a.lane}` : `g${a.lane - 1}`;
    const into = forward ? `g${b.lane - 1}` : `g${b.lane}`;

    if (span === 1) return ra === rb ? { kind: 'straight' } : { kind: 'step', out };

    // A long way goes through the gap under the source's row, which is empty in
    // every lane, then drops (or climbs) in the last gutter before the target.
    const lane = rb < ra ? `r${ra - 1}` : `r${ra}`;
    return { kind: 'long', out, into, gap: lane };
}

function spread(keys) {
    const groups = {};
    keys.forEach(({ id, key }) => { (groups[key] ??= []).push(id); });

    const offsets = {};
    for (const [key, ids] of Object.entries(groups)) {
        const half = (ids.length - 1) / 2;
        // A gap between two rows is narrow: its lines sit closer and stay inside it.
        const gap = key.startsWith('r');
        const step = gap ? STEP / 2 : STEP;
        const reach = gap ? GAP / 2 - 4 : SPREAD;
        ids.sort().forEach((id, i) => {
            const at = Math.max(-reach, Math.min(reach, (i - half) * step));
            (offsets[id] ??= {})[key] = at;
        });
    }

    return offsets;
}

/** The corner points of an edge's line, from the card sides it joins. */
export function routeEdge(edge, a, b, rows, shift = {}) {
    const ra = rows[edge.source];
    const rb = rows[edge.target];
    const left = (n) => cardLeft(n.lane);
    const right = (n) => cardLeft(n.lane) + NODE_W;
    const midY = (r) => cardTop(r) + NODE_H / 2;
    const gutterX = (key) => (key === 'g-1' ? 0 : (Number(key.slice(1)) + 1) * LANE_W) + (shift[key] ?? 0);
    const gapY = (key) => cardTop(Number(key.slice(1))) + NODE_H + GAP / 2 + (shift[key] ?? 0);
    const p = plan(edge, a, b, rows);
    const forward = a.lane < b.lane;
    const sx = forward ? right(a) : left(a);
    const tx = forward ? left(b) : right(b);
    const sy = midY(ra);
    const ty = midY(rb);

    switch (p.kind) {
        case 'straight':
            return [[sx, sy], [tx, ty]];
        case 'step': {
            const x = gutterX(p.out);
            return [[sx, sy], [x, sy], [x, ty], [tx, ty]];
        }
        case 'long': {
            const x1 = gutterX(p.out);
            const x2 = gutterX(p.into);
            const y = gapY(p.gap);
            return [[sx, sy], [x1, sy], [x1, y], [x2, y], [x2, ty], [tx, ty]];
        }
        case 'column': {
            const x = left(a) + NODE_W / 2;
            return ra < rb ? [[x, cardTop(ra) + NODE_H], [x, cardTop(rb)]] : [[x, cardTop(ra)], [x, cardTop(rb) + NODE_H]];
        }
        default: {
            // Same lane, rows apart: out to the right, along the gutter, back in from the right.
            const x = gutterX(p.gutter);
            return [[right(a), sy], [x, sy], [x, ty], [right(b), ty]];
        }
    }
}

/** A line through the points with small rounded corners. */
export function pathThrough(points, radius = RADIUS) {
    if (points.length < 2) return '';

    let d = `M ${points[0][0]} ${points[0][1]}`;

    for (let i = 1; i < points.length - 1; i++) {
        const [px, py] = points[i - 1];
        const [x, y] = points[i];
        const [nx, ny] = points[i + 1];
        const before = Math.hypot(x - px, y - py);
        const after = Math.hypot(nx - x, ny - y);
        const r = Math.min(radius, before / 2, after / 2);

        if (r < 0.5) {
            d += ` L ${x} ${y}`;
            continue;
        }

        const ax = x - ((x - px) / before) * r;
        const ay = y - ((y - py) / before) * r;
        const bx = x + ((nx - x) / after) * r;
        const by = y + ((ny - y) / after) * r;
        d += ` L ${ax} ${ay} Q ${x} ${y} ${bx} ${by}`;
    }

    const [lx, ly] = points[points.length - 1];
    return `${d} L ${lx} ${ly}`;
}

/** Middle of the longest straight piece: where the name of the connection sits when it is shown. */
export function labelPoint(points) {
    let best = { len: -1, x: points[0][0], y: points[0][1] };

    for (let i = 1; i < points.length; i++) {
        const [x1, y1] = points[i - 1];
        const [x2, y2] = points[i];
        const len = Math.abs(x2 - x1) + Math.abs(y2 - y1);
        if (len > best.len) best = { len, x: (x1 + x2) / 2, y: (y1 + y2) / 2 };
    }

    return [best.x, best.y];
}

export function flowEdges(edges, nodes, rows) {
    const byKey = Object.fromEntries(nodes.map((n) => [n.key, n]));
    const routable = edges.filter((e) => byKey[e.source] && byKey[e.target]);

    const channels = [];
    routable.forEach((edge) => {
        const p = plan(edge, byKey[edge.source], byKey[edge.target], rows);
        [p.out, p.into, p.gutter, p.gap].filter(Boolean).forEach((key) => channels.push({ id: edge.id, key }));
    });
    const shifts = spread(channels);

    return routable.map((edge) => {
        const points = routeEdge(edge, byKey[edge.source], byKey[edge.target], rows, shifts[edge.id] ?? {});

        return {
            id: edge.id,
            source: edge.source,
            target: edge.target,
            type: 'landkarte',
            ...handlesFor(edge, byKey, rows),
            data: { ...edge, path: pathThrough(points), label_at: labelPoint(points) },
            // Lines run behind the cards.
            zIndex: 0,
        };
    });
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
