/**
 * The one thing a countdown needs a browser for: ticking.
 *
 * Everything that matters already happened on the server — the deadline is
 * stored per visitor, and past it the offer refuses to be accepted. This file
 * only draws the number, and it is written so that switching it off changes
 * nothing about what can be bought.
 *
 * It reads `ends_at` rather than the rendered seconds, because a tab left open
 * for an hour has a rendered number that is an hour wrong.
 *
 * No build step, no dependency, no globals. A site with its own script can
 * ignore this file entirely and read `data-funnel-countdown` itself.
 */
(function () {
    'use strict';

    var nodes = document.querySelectorAll('[data-funnel-countdown]');

    if (!nodes.length) return;

    function parts(seconds) {
        var d = Math.floor(seconds / 86400);
        var h = Math.floor((seconds % 86400) / 3600);
        var m = Math.floor((seconds % 3600) / 60);
        var s = Math.floor(seconds % 60);

        return { d: d, h: h, m: m, s: s };
    }

    function pad(n) {
        return String(n).padStart(2, '0');
    }

    function render(p) {
        // Days only when there are days. "0d 00:14:32" reads as a bug.
        return (p.d > 0 ? p.d + 'd ' : '') + pad(p.h) + ':' + pad(p.m) + ':' + pad(p.s);
    }

    function tick() {
        var now = Date.now();
        var running = 0;

        nodes.forEach(function (node) {
            var clock = node.querySelector('[data-funnel-clock]');

            if (!clock) return;

            var ends = Date.parse(node.getAttribute('data-funnel-countdown'));

            if (isNaN(ends)) return;

            var left = Math.floor((ends - now) / 1000);

            if (left <= 0) {
                node.setAttribute('data-funnel-countdown-over', '');
                clock.textContent = '00:00:00';

                // The page is now wrong about what can be bought, and only the
                // server knows the truth. Reloading asks it, rather than hiding
                // the button here and hoping the two agree.
                if (!node.hasAttribute('data-funnel-reloaded')) {
                    node.setAttribute('data-funnel-reloaded', '');
                    window.setTimeout(function () { window.location.reload(); }, 1000);
                }

                return;
            }

            running++;
            clock.textContent = render(parts(left));
        });

        if (running > 0) window.setTimeout(tick, 1000);
    }

    tick();
})();
