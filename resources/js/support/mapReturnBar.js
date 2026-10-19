/**
 * The way back from a drill-down.
 *
 * A double click on a checkout or an automation on a Landkarte opens that
 * object's own editor with query parameters: `lk` (the Landkarte's id), `lkn`
 * (its name), `lkt` (the object's name), `lkb` (the way back), `lkm` and `lkz`
 * (the two words the bar shows, already translated by the server). This file
 * puts one bar above the page that says "Landkarte X > Kasse Y" and leads back,
 * whichever addon's editor is underneath. Neither editor knows about it, which
 * is the point: the logic stays in the addon that owns the Landkarte, and the
 * editors stay what they were.
 *
 * The context is kept for the tab in `sessionStorage`, keyed by the page's
 * path, so saving in the editor (which may drop the query) does not drop the
 * bar. Plain DOM, no Vue: it must work on a page this bundle does not render.
 */

const BAR_ID = 'lk-return-bar';
const STORE = 'funnels.landkarte.drill';

/**
 * Only somewhere on this site: the parameter is user-controlled input in a URL.
 * A path, or an absolute address on this very origin (the server may send
 * either); the answer is always the path and query alone.
 */
export function safeBack(value, origin = window.location.origin) {
    if (typeof value !== 'string' || value.includes('\\') || value.startsWith('//')) return null;

    try {
        const url = new URL(value, origin);
        if (url.origin !== origin) return null;

        return url.pathname + url.search;
    } catch {
        return null;
    }
}

export function readDrill(search, path = '', storage = window.sessionStorage, origin = window.location.origin) {
    const params = new URLSearchParams(search);
    const back = safeBack(params.get('lkb'), origin);

    if (params.get('lk') && back) {
        const drill = {
            id: params.get('lk'),
            name: params.get('lkn') ?? '',
            target: params.get('lkt') ?? '',
            back,
            map: params.get('lkm') ?? 'Landkarte',
            backText: params.get('lkz') ?? 'Zurück zur Landkarte',
        };

        try { storage?.setItem(STORE, JSON.stringify({ path, drill })); } catch { /* a remembered bar is a convenience */ }

        return drill;
    }

    try {
        const kept = JSON.parse(storage?.getItem(STORE) ?? 'null');
        if (kept && kept.path === path && safeBack(kept.drill?.back, origin)) return kept.drill;
    } catch { /* unreadable: no bar */ }

    return null;
}

function build(drill) {
    const bar = document.createElement('div');
    bar.id = BAR_ID;
    bar.setAttribute('role', 'navigation');
    bar.setAttribute('aria-label', drill.map);

    const crumbs = document.createElement('div');
    crumbs.className = 'lk-return-bar__crumbs';

    const map = document.createElement('a');
    map.href = drill.back;
    map.textContent = `${drill.map} ${drill.name}`.trim();

    const sep = document.createElement('span');
    sep.setAttribute('aria-hidden', 'true');
    sep.textContent = '›';

    const here = document.createElement('strong');
    here.textContent = drill.target;
    here.setAttribute('aria-current', 'page');

    crumbs.append(map, sep, here);

    const back = document.createElement('a');
    back.className = 'lk-return-bar__back';
    back.href = drill.back;
    back.textContent = `← ${drill.backText}`;

    bar.append(crumbs, back);

    return bar;
}

export function syncReturnBar(doc = document, location = window.location) {
    const existing = doc.getElementById(BAR_ID);
    const drill = readDrill(location.search, location.pathname);

    if (!drill) {
        existing?.remove();
        return;
    }

    // The page frame is not there yet while the bundle boots: wait for it
    // rather than hang the bar on the body, where the header covers it.
    const host = doc.querySelector('#main-content');

    if (!host) return;

    if (existing && existing.parentElement === host && host.firstElementChild === existing) return;

    host.prepend(existing ?? build(drill));
}

/**
 * Inertia swaps the page without a reload, so the bar is checked again after
 * every visit and whenever the page's frame is rebuilt.
 */
export function watchReturnBar() {
    syncReturnBar();

    // The page frame may not be there when the bundle boots, and Inertia swaps
    // pages without a reload: look again on every visit, and for a few seconds
    // after boot. No observer on markup that is not this addon's.
    let tries = 0;
    const retry = setInterval(() => {
        syncReturnBar();
        if (++tries >= 20) clearInterval(retry);
    }, 250);

    window.addEventListener('popstate', () => syncReturnBar());
    document.addEventListener('inertia:navigate', () => setTimeout(syncReturnBar, 50));
    document.addEventListener('inertia:success', () => setTimeout(syncReturnBar, 50));
}
