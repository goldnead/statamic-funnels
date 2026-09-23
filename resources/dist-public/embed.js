/**
 * Einen Funnel auf einer fremden Seite zeigen: als Popup oder eingebettet.
 *
 * Auf der fremden Seite, einmal:
 *
 *   <script src="https://deine-site.de/vendor/statamic-funnels/embed.js" async></script>
 *
 * Popup, ausgeloest von einem Knopf, einem Bild oder einem Textlink:
 *
 *   <a href="https://deine-site.de/f/kurs" data-funnel-popup>Jetzt anmelden</a>
 *   <button data-funnel-popup="https://deine-site.de/f/kurs/kasse">Kaufen</button>
 *
 * Eingebettet an dieser Stelle der Seite:
 *
 *   <div data-funnel-embed="https://deine-site.de/f/kurs"></div>
 *
 * Die Funnel-Seite im Rahmen meldet ihre Hoehe, damit eine eingebettete Kasse
 * keinen zweiten Scrollbalken bekommt. Der Bestellknopf verlaesst den Rahmen:
 * Stripe und Mollie lassen sich nicht einbetten, bezahlt wird oben.
 *
 * Welche Seiten den Funnel rahmen duerfen, steht im Funnel (Einstellungen →
 * Einbetten). Eine Seite, die dort fehlt, zeigt einen leeren Rahmen.
 *
 * Kein Build, keine Abhaengigkeit, keine globalen Namen.
 */
(function () {
    'use strict';

    if (window.__statamicFunnelsEmbed) return;
    window.__statamicFunnelsEmbed = true;

    var frames = [];

    function embedUrl(url) {
        try {
            var u = new URL(url, window.location.href);
            u.searchParams.set('embed', '1');

            return u;
        } catch (e) {
            return null;
        }
    }

    function frame(url, title) {
        var iframe = document.createElement('iframe');
        iframe.src = url.toString();
        iframe.title = title || '';
        iframe.setAttribute('allow', 'payment; clipboard-write');
        iframe.style.border = '0';
        iframe.style.width = '100%';
        iframe.style.display = 'block';
        iframe.dataset.funnelOrigin = url.origin;
        frames.push(iframe);

        return iframe;
    }

    // ------------------------------------------------------------- Popup

    var open = null;

    function close() {
        if (!open) return;

        var state = open;
        open = null;
        state.overlay.remove();
        document.documentElement.style.overflow = state.overflow;
        document.removeEventListener('keydown', state.onKey, true);
        frames = frames.filter(function (f) { return f !== state.iframe; });

        if (state.trigger && state.trigger.focus) state.trigger.focus();
    }

    function popup(url, trigger) {
        close();

        var overlay = document.createElement('div');
        overlay.setAttribute('data-funnel-overlay', '');
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483000;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;padding:16px;';

        var dialog = document.createElement('div');
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.style.cssText = 'position:relative;width:100%;max-width:680px;height:min(90vh,900px);background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 20px 60px rgba(0,0,0,.35);';

        var button = document.createElement('button');
        button.type = 'button';
        button.setAttribute('aria-label', trigger.getAttribute('data-funnel-close-label') || 'Schließen');
        button.textContent = '×';
        button.style.cssText = 'position:absolute;top:8px;right:8px;z-index:1;width:36px;height:36px;border:0;border-radius:999px;background:rgba(0,0,0,.06);color:#111;font:24px/36px system-ui,sans-serif;cursor:pointer;';
        button.addEventListener('click', close);

        var iframe = frame(url, trigger.getAttribute('aria-label') || trigger.textContent.trim());
        iframe.style.height = '100%';

        dialog.appendChild(button);
        dialog.appendChild(iframe);
        overlay.appendChild(dialog);

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) close();
        });

        var onKey = function (event) {
            if (event.key === 'Escape') {
                event.stopPropagation();
                close();
            }
        };

        document.addEventListener('keydown', onKey, true);

        open = { overlay: overlay, iframe: iframe, trigger: trigger, onKey: onKey, overflow: document.documentElement.style.overflow };
        document.documentElement.style.overflow = 'hidden';
        document.body.appendChild(overlay);
        button.focus();
    }

    // Der Ausloeser kann ein Bild in einem Link sein: vom geklickten Element
    // aufwaerts bis zu dem, das `data-funnel-popup` traegt.
    function triggerOf(node) {
        while (node && node.nodeType === 1) {
            if (node.hasAttribute('data-funnel-popup')) return node;
            node = node.parentElement;
        }

        return null;
    }

    document.addEventListener('click', function (event) {
        var trigger = triggerOf(event.target);

        if (!trigger) return;

        var url = embedUrl(trigger.getAttribute('data-funnel-popup') || trigger.getAttribute('href') || '');

        if (!url) return;

        // Mit Strg, Cmd oder mittlerer Taste bleibt der Link ein Link.
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.button === 1) return;

        event.preventDefault();
        popup(url, trigger);
    });

    // ---------------------------------------------------------- Eingebettet

    function inline(node) {
        if (node.getAttribute('data-funnel-embedded-by')) return;

        var url = embedUrl(node.getAttribute('data-funnel-embed'));

        if (!url) return;

        node.setAttribute('data-funnel-embedded-by', 'statamic-funnels');

        var iframe = frame(url, node.getAttribute('data-funnel-title') || '');
        iframe.style.height = (parseInt(node.getAttribute('data-funnel-height'), 10) || 600) + 'px';
        iframe.dataset.funnelInline = '1';

        node.appendChild(iframe);
    }

    function scan() {
        document.querySelectorAll('[data-funnel-embed]').forEach(inline);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }

    // Nur die Hoehe, und nur von dem Rahmen, den dieses Skript gebaut hat.
    window.addEventListener('message', function (event) {
        var data = event.data;

        if (!data || data.type !== 'statamic-funnels:height' || typeof data.height !== 'number') return;

        frames.forEach(function (iframe) {
            if (iframe.contentWindow !== event.source || iframe.dataset.funnelOrigin !== event.origin) return;
            if (iframe.dataset.funnelInline !== '1') return;

            iframe.style.height = Math.max(200, Math.min(data.height, 20000)) + 'px';
        });
    });
})();
