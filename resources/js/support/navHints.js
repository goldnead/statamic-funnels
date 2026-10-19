/**
 * One line under each of the two navigation entries this addon owns.
 *
 * The Control Panel's navigation has no slot for a description, and the two
 * entries sit next to each other and sound alike: "Landkarten" is weeks,
 * "Kassen & Besucherwege" is minutes. The line says which is which where the
 * choice is made. The words are here and not in a language file because the
 * navigation renders before any page props reach this bundle.
 */

const HINTS = {
    de: {
        '/utilities/funnels/maps': 'Strategische Übersicht deiner Funnels (Wochen)',
        '/utilities/funnels': 'Einzelne Besuche analysieren (Minuten)',
    },
    en: {
        '/utilities/funnels/maps': 'Strategic overview of your funnels (weeks)',
        '/utilities/funnels': 'Analyse single visits (minutes)',
    },
};

const MARK = 'data-lk-hint';

export function applyNavHints(doc = document) {
    const lang = (doc.documentElement.lang || 'en').slice(0, 2).toLowerCase();
    const hints = HINTS[lang] ?? HINTS.en;

    for (const link of doc.querySelectorAll('nav a[href], aside a[href]')) {
        if (link.hasAttribute(MARK)) continue;

        let path;
        try {
            path = new URL(link.href, doc.location.origin).pathname.replace(/\/+$/, '');
        } catch {
            continue;
        }

        const key = Object.keys(hints).find((suffix) => path.endsWith(suffix));
        if (!key) continue;

        link.setAttribute(MARK, '');
        link.setAttribute('title', hints[key]);

        const text = doc.createElement('span');
        text.className = 'lk-nav-hint';
        text.textContent = hints[key];
        link.insertAdjacentElement('afterend', text);
    }
}

export function watchNavHints() {
    let queued = false;
    const run = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            applyNavHints();
        });
    };

    applyNavHints();
    new MutationObserver(run).observe(document.body, { childList: true, subtree: true });
}
