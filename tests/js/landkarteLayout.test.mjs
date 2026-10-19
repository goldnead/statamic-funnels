// Run with: node --test "tests/js/*.test.mjs"
//
// The Landkarte routes its own lines from the fixed grid. The promise is that a
// line never crosses a card (other than the two it joins, at their sides), so
// this checks it for every pair of cards on a 5 x 4 board.
import test from 'node:test';
import assert from 'node:assert/strict';

import {
    HEAD_H, LANE_PAD, LANE_W, NODE_H, NODE_W, ROW_H, flowEdges, layoutNodes, pathThrough, wayThrough,
} from '../../resources/js/support/landkarteLayout.js';

const LANES = 5;
const ROWS = 4;

function board() {
    const nodes = [];
    for (let lane = 0; lane < LANES; lane++) {
        for (let row = 0; row < ROWS; row++) nodes.push({ key: `n:${lane}-${row}`, lane, row });
    }
    return nodes;
}

const rect = (n) => {
    const x = lane2x(n.lane);
    const y = HEAD_H + LANE_PAD + n.row * ROW_H;
    return { x1: x, y1: y, x2: x + NODE_W, y2: y + NODE_H };
};
const lane2x = (lane) => lane * LANE_W + (LANE_W - NODE_W) / 2;

/** Corner points back out of a path string of `M`, `L` and `Q` commands: the straight pieces' ends. */
function pointsOf(d) {
    const nums = d.match(/-?\d+(\.\d+)?/g).map(Number);
    const points = [];
    for (let i = 0; i < nums.length; i += 2) points.push([nums[i], nums[i + 1]]);
    return points;
}

function crosses(a, b, r) {
    // Axis-parallel segment a-b against the open interior of rectangle r.
    const [x1, y1] = a;
    const [x2, y2] = b;
    const eps = 0.01;
    const minX = Math.min(x1, x2);
    const maxX = Math.max(x1, x2);
    const minY = Math.min(y1, y2);
    const maxY = Math.max(y1, y2);

    return maxX > r.x1 + eps && minX < r.x2 - eps && maxY > r.y1 + eps && minY < r.y2 - eps;
}

test('a line never crosses a card other than the two it joins', () => {
    const nodes = board();
    const { rows } = layoutNodes(Array.from({ length: LANES }, (_, i) => ({ name: `L${i}` })), nodes);
    const edges = [];

    for (const a of nodes) {
        for (const b of nodes) {
            if (a.key !== b.key) edges.push({ id: `${a.key}>${b.key}`, source: a.key, target: b.key });
        }
    }

    const drawn = flowEdges(edges, nodes, rows);
    assert.equal(drawn.length, edges.length);

    const byKey = Object.fromEntries(nodes.map((n) => [n.key, n]));
    const offenders = [];

    for (const edge of drawn) {
        // The straight pieces of the unrounded route: read them from the path's anchor points.
        const points = pointsOf(edge.data.path);
        const others = nodes.filter((n) => n.key !== edge.source && n.key !== edge.target).map(rect);

        for (let i = 1; i < points.length; i++) {
            for (const r of others) {
                if (crosses(points[i - 1], points[i], r)) offenders.push(`${edge.id} through a card`);
            }
        }

        assert.ok(byKey[edge.source] && byKey[edge.target]);
    }

    assert.deepEqual([...new Set(offenders)].slice(0, 5), []);
});

test('lines that share a gutter are spread side by side, not on top of each other', () => {
    const nodes = [
        { key: 'a', lane: 0, row: 0 }, { key: 'b', lane: 1, row: 1 }, { key: 'c', lane: 0, row: 2 }, { key: 'd', lane: 1, row: 3 },
    ];
    const { rows } = layoutNodes([{ name: 'x' }, { name: 'y' }], nodes);
    const drawn = flowEdges([
        { id: '1', source: 'a', target: 'b' },
        { id: '2', source: 'c', target: 'd' },
    ], nodes, rows);

    const gutterX = (edge) => pointsOf(edge.data.path)[2][0];
    assert.notEqual(gutterX(drawn[0]), gutterX(drawn[1]));
});

test('a path through points is one continuous M ... L line with rounded corners', () => {
    const d = pathThrough([[0, 0], [40, 0], [40, 40], [80, 40]]);
    assert.match(d, /^M 0 0 L 32 0 Q 40 0 40 8 L 40 32 Q 40 40 48 40 L 80 40$/);
});

test('the way through a card is everything before and after it, nothing beside it', () => {
    const edges = [
        { id: 'e1', source: 'a', target: 'b' },
        { id: 'e2', source: 'b', target: 'c' },
        { id: 'e3', source: 'a', target: 'x' },
    ];
    const { nodes, ids } = wayThrough('b', edges);

    assert.deepEqual([...nodes].sort(), ['a', 'b', 'c']);
    assert.deepEqual([...ids].sort(), ['e1', 'e2']);
});
