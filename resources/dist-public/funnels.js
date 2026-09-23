/**
 * The few things a funnel page needs a browser for.
 *
 * Everything that matters already happens on the server — a deadline is stored
 * per visitor and enforced, a bump rule is applied when the order comes in, the
 * embed's allowed domains are a header. This file only draws: the ticking
 * clock, which bumps are visible for the current choice, the height of an
 * embedded page, the "copy link" button of the in-app browser notice.
 * Switching it off changes nothing about what can be bought.
 *
 * No build step, no dependency, no globals. A site with its own script can
 * ignore this file and read the `data-funnel-*` attributes itself.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------ Countdown
    //
    // Reads `ends_at` rather than the rendered seconds, because a tab left open
    // for an hour has a rendered number that is an hour wrong.
    (function countdown() {
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

                    // The page is now wrong about what can be bought, and only
                    // the server knows the truth. Reloading asks it.
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

    // ---------------------------------------------------------------- Bumps
    //
    // A bump can belong to some pricing options only, or hang on another bump.
    // Hidden bumps are also unticked: a box nobody can see must not end up in
    // the basket. The server drops them anyway; this keeps the page honest.
    (function bumps() {
        var labels = document.querySelectorAll('[data-funnel-bump]');

        if (!labels.length) return;

        // Das Formular, zu dem die Haekchen gehoeren: jedes Eingabefeld kennt es.
        var first = labels[0].querySelector('input[type="checkbox"]');
        var form = first ? first.form : null;

        if (!form) return;

        function chosenOption() {
            var radio = form.querySelector('input[name="pricing_option"]:checked');

            return radio ? radio.value : null;
        }

        function box(label) {
            return label.querySelector('input[type="checkbox"]');
        }

        function ticked(handle) {
            var label = form.querySelector('[data-funnel-bump="' + handle + '"]');

            return !!(label && !label.hidden && box(label) && box(label).checked);
        }

        function update() {
            var option = chosenOption();

            // Twice, because a bump that disappears can take one hanging on it
            // with it.
            for (var round = 0; round < 2; round++) {
                labels.forEach(function (label) {
                    var options = (label.getAttribute('data-funnel-bump-options') || '').split(',').filter(Boolean);
                    var requires = label.getAttribute('data-funnel-bump-requires');
                    var show = (options.length === 0 || (option !== null && options.indexOf(option) !== -1))
                        && (!requires || ticked(requires));
                    var input = box(label);

                    if (show && label.hidden) {
                        label.hidden = false;
                        if (input && label.hasAttribute('data-funnel-bump-preselected')) input.checked = true;
                    } else if (!show && !label.hidden) {
                        label.hidden = true;
                        if (input) input.checked = false;
                    }
                });
            }
        }

        form.addEventListener('change', function (event) {
            var target = event.target;

            if (target && (target.name === 'pricing_option' || target.name === 'bumps[]')) update();
        });

        update();
    })();

    // ---------------------------------------------------- In-app browser notice
    //
    // Instagram, Facebook, TikTok and LinkedIn open links in their own browser,
    // where saved passwords, Apple Pay and the bank's app are missing. The
    // notice is drawn by the server; this only makes its "copy link" work.
    (function inAppNotice() {
        var buttons = document.querySelectorAll('[data-funnel-copy-link]');

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var url = button.getAttribute('data-funnel-copy-link') || window.location.href;
                var done = function () {
                    var copied = button.getAttribute('data-funnel-copied');
                    if (copied) button.textContent = copied;
                };

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(url).then(done, function () { window.prompt('', url); });
                } else {
                    window.prompt('', url);
                }
            });
        });
    })();

    // ------------------------------------------------------------ Embedded
    //
    // Inside the embed script's iframe the page tells its parent how tall it
    // is, so an inline embed never shows a second scroll bar. Only the height
    // leaves the frame, and only to the page that framed it.
    (function embedded() {
        var root = document.querySelector('[data-funnel-embedded]');

        if (!root || window.parent === window) return;

        var last = 0;

        // Gemessen am Inhalt, nicht am Dokument: `scrollHeight` ist nie
        // kleiner als der Rahmen selbst, und ein Rahmen, der nur wachsen kann,
        // schrumpft nach dem ersten Schritt nie wieder auf eine kurze Seite.
        function report() {
            var bottom = root.getBoundingClientRect().bottom + window.scrollY;
            var height = Math.ceil(bottom + 16);

            if (height === last) return;

            last = height;
            window.parent.postMessage({ type: 'statamic-funnels:height', height: height }, '*');
        }

        report();
        window.addEventListener('load', report);

        if (window.ResizeObserver) {
            new ResizeObserver(report).observe(root);
        } else {
            window.setInterval(report, 500);
        }
    })();
})();
