/**
 * How the dry run's tree is laid out on the page. Pure, so it can be tested without a browser.
 *
 * The planner (brand-context) returns a literal tree: after every mail a fork with the alternatives
 * "opened, clicked, nothing" (merged into one, since they lead to the same place) and, for every
 * product on the map, "bought". Drawn as it comes, a six-mail series would sit eighteen levels deep.
 * So the page flattens what is only the passing of time:
 *
 * - after a mail, the merged alternative IS the sequence going on: it continues at the mail's own level;
 *   what differs (a purchase) hangs under the mail, folded;
 * - a wait, a grant, a page or a trait is followed on the same level;
 * - a card, a fork or an alternative opens a new block.
 */

/** Effects that lead on in time and stay on the same level as what follows them. */
export const CONTINUES = ['wait', 'access', 'page', 'trait'];

/** The fork the planner put after a mail, or null. */
export function mailFork(node) {
    const only = node.kind === 'mail' && node.children.length === 1 ? node.children[0] : null;
    return only && only.kind === 'fork' && only.meta?.after_mail ? only : null;
}

/**
 * @param {{ kind: string, children: object[], meta?: object }} node
 * @returns {{ flow: object[], nested: object[] }} `flow` follows on the node's own level, `nested` hangs below it
 */
export function splitNode(node) {
    const fork = mailFork(node);

    if (fork) {
        const same = fork.children.find((a) => a.kind === 'alt' && a.meta?.merged?.includes('opened')) ?? null;

        return { flow: same?.children ?? [], nested: fork.children.filter((a) => a !== same) };
    }

    return CONTINUES.includes(node.kind)
        ? { flow: node.children, nested: [] }
        : { flow: [], nested: node.children };
}

/** Side ways (a purchase) start folded: the page opens on the main line of the series. */
export function startsOpen(node) {
    return !(node.kind === 'alt' && node.meta?.merged?.includes('bought'));
}

/**
 * "Tag 2" for whole days, "3 Std." and "20 Min." inside the first day, "Tag 2, 3 Std." in between.
 *
 * @param {number} seconds
 * @param {(key: string) => string} t translated words: probe_now, probe_day (:day), probe_hours, probe_minutes (:count)
 */
export function timeWords(seconds, t) {
    if (!seconds) return t('probe_now');

    const day = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.round((seconds % 3600) / 60);

    if (day === 0) {
        return hours > 0 ? t('probe_hours').replace(':count', hours) : t('probe_minutes').replace(':count', Math.max(1, minutes));
    }

    const days = t('probe_day').replace(':day', day);

    return hours > 0 ? `${days}, ${t('probe_hours').replace(':count', hours)}` : days;
}
