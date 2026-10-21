// Run with: node --test "tests/js/*.test.mjs"
//
// The dry run's tree comes planned from the server. The page lays it out so a series reads top to
// bottom: after a mail the merged "opened, clicked, nothing" alternative is the sequence going on,
// a purchase hangs under the mail, folded.
import test from 'node:test';
import assert from 'node:assert/strict';

import { groupByDay, mailFork, splitNode, startsOpen, timeInDay, timeWords } from '../../resources/js/support/probelauf.js';

const words = {
    probe_now: 'sofort',
    probe_day: 'Tag :day',
    probe_hours: ':count Std.',
    probe_minutes: ':count Min.',
};
const t = (key) => words[key];

const node = (kind, children = [], meta = {}, label = kind) => ({ id: label, kind, label, children, meta });

// mail -> fork -> [same -> (wait -> mail2), bought -> (enter product)]
function series() {
    const mail2 = node('mail', [], {}, 'mail2');
    const wait = node('wait', [mail2], {}, 'wait');
    const same = node('alt', [wait], { merged: ['opened', 'clicked', 'nothing'] }, 'same');
    const bought = node('alt', [node('enter', [], {}, 'product')], { merged: ['bought'] }, 'bought');
    const fork = node('fork', [same, bought], { after_mail: true }, 'fork');

    return { mail1: node('mail', [fork], {}, 'mail1'), mail2, wait, same, bought, fork };
}

test('the sequence after a mail continues on the mail\'s own level, the purchase hangs below', () => {
    const { mail1, wait, bought, fork } = series();
    const { flow, nested } = splitNode(mail1);

    assert.equal(mailFork(mail1), fork);
    assert.deepEqual(flow, [wait]);
    assert.deepEqual(nested, [bought]);
});

test('a mail with no fork of the planner keeps its children below', () => {
    const child = node('wait');
    const mail = node('mail', [child]);

    assert.deepEqual(splitNode(mail), { flow: [], nested: [child], note: null });
});

test('a wait is followed on the same level, a card opens a block', () => {
    const next = node('mail');

    assert.deepEqual(splitNode(node('wait', [next])), { flow: [next], nested: [], note: null });
    assert.deepEqual(splitNode(node('access', [next])), { flow: [next], nested: [], note: null });
    assert.deepEqual(splitNode(node('enter', [next])), { flow: [], nested: [next], note: null });
    assert.deepEqual(splitNode(node('fork', [next])), { flow: [], nested: [next], note: null });
    assert.deepEqual(splitNode(node('alt', [next])), { flow: [], nested: [next], note: null });
});

test('a card that only says "effect unknown" says it on its own line', () => {
    const unknown = node('unknown', [], {}, 'Wirkung unbekannt');
    const next = node('enter', [], {}, 'Kurs');

    assert.deepEqual(splitNode(node('enter', [unknown, next])), { flow: [], nested: [next], note: 'Wirkung unbekannt' });
    // An unknown step that has something after it stays a row of its own.
    const busy = node('unknown', [next], {}, 'Schleife');
    assert.deepEqual(splitNode(node('enter', [busy])), { flow: [], nested: [busy], note: null });
});

test('a purchase starts folded, every other alternative open', () => {
    assert.equal(startsOpen(node('alt', [], { merged: ['bought'] })), false);
    assert.equal(startsOpen(node('alt', [], {})), true);
    assert.equal(startsOpen(node('enter')), true);
});

test('the whole series flattens to one line of siblings', () => {
    const { mail1 } = series();
    const level = [];
    const walk = (n) => {
        level.push(n.label);
        splitNode(n).flow.forEach(walk);
    };
    walk(mail1);

    assert.deepEqual(level, ['mail1', 'wait', 'mail2']);
});

test('time reads as day, hours and minutes', () => {
    assert.equal(timeWords(0, t), 'sofort');
    assert.equal(timeWords(20 * 60, t), '20 Min.');
    assert.equal(timeWords(30, t), '1 Min.');
    assert.equal(timeWords(3 * 3600, t), '3 Std.');
    assert.equal(timeWords(2 * 86400, t), 'Tag 2');
    assert.equal(timeWords(2 * 86400 + 3 * 3600, t), 'Tag 2, 3 Std.');
});

test('the known person\'s way is grouped by day, in order', () => {
    const entries = [
        { day: 0, at: 0, kind: 'mail', id: 'a' }, { day: 0, at: 0, kind: 'mail', id: 'b' },
        { day: 2, at: 2 * 86400, kind: 'mail', id: 'c' }, { day: 5, at: 5 * 86400, kind: 'mail', id: 'd' },
    ];

    assert.deepEqual(groupByDay(entries).map((g) => [g.day, g.entries.map((e) => e.id)]), [[0, ['a', 'b']], [2, ['c']], [5, ['d']]]);
    assert.deepEqual(groupByDay([]), []);
});

test('a wait that ends on a day boundary is the day header, a wait inside a day stays', () => {
    const entries = [
        { day: 0, at: 0, kind: 'mail', id: 'a' },
        { day: 2, at: 2 * 86400, kind: 'wait', id: 'w1' },
        { day: 2, at: 2 * 86400 + 3 * 3600, kind: 'wait', id: 'w2' },
    ];

    assert.deepEqual(groupByDay(entries).map((g) => g.entries.map((e) => e.id)), [['a'], ['w2']]);
});

test('"effect unknown" behind a card joins the card\'s line as a note', () => {
    const entries = [
        { day: 0, at: 0, kind: 'enter', id: 'k', label: 'Kurs' },
        { day: 0, at: 0, kind: 'unknown', id: 'u', label: 'Wirkung unbekannt' },
        { day: 0, at: 0, kind: 'mail', id: 'm', label: 'Mail' },
        { day: 0, at: 0, kind: 'unknown', id: 'x', label: 'Schritt unbekannt' },
    ];
    const [group] = groupByDay(entries);

    assert.deepEqual(group.entries.map((e) => e.id), ['k', 'm', 'x']);
    assert.equal(group.entries[0].note, 'Wirkung unbekannt');
});

test('the time inside a day is said, midnight of it is not', () => {
    assert.equal(timeInDay(0, t), null);
    assert.equal(timeInDay(2 * 86400, t), null);
    assert.equal(timeInDay(2 * 86400 + 3 * 3600, t), '3 Std.');
});
