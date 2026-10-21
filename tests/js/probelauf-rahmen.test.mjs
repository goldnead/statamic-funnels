// Run with: node --test "tests/js/*.test.mjs"
//
// Both frames of the dry run (the mail and the page) are shown in an iframe with an EMPTY sandbox: no script, no same-origin,
// no forms, no popups, no navigation. No exceptions: a flag added later fails this test.
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const read = (name) => readFileSync(new URL(`../../resources/js/components/landkarte/${name}`, import.meta.url), 'utf8');

for (const name of ['ProbeMailView.vue', 'ProbePageView.vue']) {
    test(`${name}: every iframe has sandbox=""`, () => {
        const frames = read(name).match(/<iframe[\s\S]*?\/>/g) ?? [];

        assert.ok(frames.length >= 1, 'no iframe found');

        for (const frame of frames) {
            assert.match(frame, /\ssandbox=""/);
            assert.doesNotMatch(frame, /allow-/);
        }
    });
}

test('the mail frame does not read into its document (it has no origin to read with)', () => {
    assert.doesNotMatch(read('ProbeMailView.vue'), /contentDocument|contentWindow/);
});
