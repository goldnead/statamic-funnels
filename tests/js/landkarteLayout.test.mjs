// Run with: node --test "tests/js/*.test.mjs"
//
// The Landkarte routes its own lines from the fixed grid. The promise is that a
// line never crosses a card (other than the two it joins, at their sides), so
// this checks it for every pair of cards on a 5 x 4 board.
import test from 'node:test';
import assert from 'node:assert/strict';

import {
    HEAD_H, LANE_PAD, LANE_W, NODE_H, NODE_W, ROW_H, connectOutcome, dropSlot, flowEdges, layoutNodes, moveRef, pathThrough, strokeOf,
    targetsFor, wayThrough,
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

// --- dragging a card -------------------------------------------------------------

const refs = (...ids) => ids.map((id) => ({ type: 'x', ref_id: id }));
const lanesOf = (...lists) => lists.map((ids, i) => ({ name: `L${i}`, refs: refs(...ids) }));
const ids = (lanes) => lanes.map((l) => l.refs.map((r) => r.ref_id));
const at = (lane, row) => ({ x: lane2x(lane), y: HEAD_H + LANE_PAD + row * ROW_H });

test('a card dropped where it was does not move', () => {
    const lanes = lanesOf(['a', 'b', 'c'], []);
    assert.deepEqual(dropSlot(lanes, 'x:b', at(0, 1)), { lane: 0, index: 1 });
    assert.equal(moveRef(lanes, 'x:b', dropSlot(lanes, 'x:b', at(0, 1))), null);
    assert.equal(moveRef(lanes, 'x:b', dropSlot(lanes, 'x:b', { x: at(0, 1).x + 20, y: at(0, 1).y - 15 })), null);
});

test('a card dropped in another lane lands at the place under its middle', () => {
    const lanes = lanesOf(['a', 'b'], ['c', 'd', 'e'], []);

    // Between d and e: its middle is below d's middle and above e's.
    const slot = dropSlot(lanes, 'x:a', at(1, 1.6));
    assert.deepEqual(slot, { lane: 1, index: 2 });
    assert.deepEqual(ids(moveRef(lanes, 'x:a', slot)), [['b'], ['c', 'd', 'a', 'e'], []]);

    // Above everything, below everything, into an empty lane.
    assert.deepEqual(dropSlot(lanes, 'x:a', at(1, -1)), { lane: 1, index: 0 });
    assert.deepEqual(dropSlot(lanes, 'x:a', at(1, 9)), { lane: 1, index: 3 });
    assert.deepEqual(ids(moveRef(lanes, 'x:b', dropSlot(lanes, 'x:b', at(2, 0)))), [['a'], ['c', 'd', 'e'], ['b']]);
});

test('a card moved within its lane is placed against the cards that stay', () => {
    const lanes = lanesOf(['a', 'b', 'c']);

    assert.deepEqual(ids(moveRef(lanes, 'x:a', dropSlot(lanes, 'x:a', at(0, 1.2)))), [['b', 'a', 'c']]);
    assert.deepEqual(ids(moveRef(lanes, 'x:a', dropSlot(lanes, 'x:a', at(0, 2.4)))), [['b', 'c', 'a']]);
    assert.deepEqual(ids(moveRef(lanes, 'x:c', dropSlot(lanes, 'x:c', at(0, 0)))), [['c', 'a', 'b']]);
    // The lanes handed in are left alone.
    assert.deepEqual(ids(lanes), [['a', 'b', 'c']]);
});

test('a drop outside the board falls into the nearest lane', () => {
    const lanes = lanesOf(['a'], ['b']);
    assert.equal(dropSlot(lanes, 'x:a', { x: -900, y: 0 }).lane, 0);
    assert.equal(dropSlot(lanes, 'x:a', { x: 9000, y: 0 }).lane, 1);
    assert.equal(dropSlot([], 'x:a', { x: 0, y: 0 }), null);
});

test('cards the owner no longer knows are not draggable, the others are when asked', () => {
    const nodes = [{ key: 'a', lane: 0, row: 0 }, { key: 'g', lane: 0, row: null, ghost: true }];
    const flow = (options) => layoutNodes([{ name: 'x' }], nodes, options).flowNodes.filter((n) => n.type === 'landkarte');

    assert.deepEqual(flow().map((n) => n.draggable), [false, false]);
    assert.deepEqual(flow({ draggable: true }).map((n) => n.draggable), [true, false]);
    assert.equal(layoutNodes([{ name: 'x' }], nodes, { adders: true }).flowNodes.filter((n) => n.type === 'adder').length, 1);
});

// --- drawing a connection --------------------------------------------------------

const RULES = [
    { from_type: 'lead_magnet', to_type: 'automation', kind: 'triggers', label: 'startet' },
    { from_type: 'lead_magnet', to_type: 'course', kind: 'unlocks', label: 'schaltet frei' },
    { from_type: 'course', to_type: 'offer', kind: 'shows_box', label: 'zeigt' },
];
const CARDS = [
    { key: 'lead_magnet:l', type: 'lead_magnet', status: 'found' },
    { key: 'automation:a', type: 'automation', status: 'found' },
    { key: 'course:c', type: 'course', status: 'found' },
    { key: 'offer:o', type: 'offer', status: 'found' },
    { key: 'automation:dead', type: 'automation', status: 'missing', ghost: true },
];
const card = (key) => CARDS.find((c) => c.key === key);

test('only the pairs a rule names can be dropped on each other', () => {
    assert.deepEqual([...targetsFor(RULES, CARDS, 'lead_magnet:l')].sort(), ['automation:a', 'course:c']);
    assert.deepEqual([...targetsFor(RULES, CARDS, 'offer:o')], []);
});

test('a refused drop says why', () => {
    assert.equal(connectOutcome(RULES, card('lead_magnet:l'), card('automation:a')).rule.kind, 'triggers');
    assert.equal(connectOutcome(RULES, card('lead_magnet:l'), card('lead_magnet:l')).reason, 'same');
    assert.equal(connectOutcome(RULES, card('lead_magnet:l'), card('offer:o')).reason, 'pair');
    assert.deepEqual(connectOutcome(RULES, card('lead_magnet:l'), card('offer:o')).allowed, ['automation', 'course']);
    assert.equal(connectOutcome(RULES, card('offer:o'), card('course:c')).reason, 'none');
    assert.equal(connectOutcome(RULES, card('lead_magnet:l'), card('automation:dead')).reason, 'gone');
    assert.equal(connectOutcome([], card('lead_magnet:l'), card('automation:a')).ok, false);
});

test('a line is solid when an addon holds it, dashed when drawn by hand, dotted when it waits', () => {
    assert.equal(strokeOf({ kind: 'triggers' }), 'real');
    assert.equal(strokeOf({ kind: 'hand', hand: true, dashed: true }), 'note');
    assert.equal(strokeOf({ kind: 'waits_for', dashed: true }), 'wait');
});
