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
 * - a card, a fork or an alternative opens a new block;
 * - a card whose only word is "effect unknown" says so on its own line instead of in a row of its own.
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
 * @returns {{ flow: object[], nested: object[], note: string|null }} `flow` follows on the node's own level,
 *          `nested` hangs below it, `note` is said on the node's own line
 */
export function splitNode(node) {
    const fork = mailFork(node);

    if (fork) {
        const same = fork.children.find((a) => a.kind === 'alt' && a.meta?.merged?.includes('opened')) ?? null;

        return { flow: same?.children ?? [], nested: fork.children.filter((a) => a !== same), note: null };
    }

    if (CONTINUES.includes(node.kind)) return { flow: node.children, nested: [], note: null };

    if (node.kind === 'enter') {
        const unknown = node.children.find((c) => c.kind === 'unknown' && c.children.length === 0) ?? null;

        if (unknown) return { flow: [], nested: node.children.filter((c) => c !== unknown), note: unknown.label };
    }

    return { flow: [], nested: node.children, note: null };
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

/** The icon of each kind of line, from Statamic's own set. */
export const ICONS = {
    enter: 'arrow-right',
    mail: 'mail',
    wait: 'time-clock',
    access: 'key',
    page: 'page',
    trait: 'tag-new',
    stop: 'x',
    unknown: 'warning-diamond',
    denied: 'eye-slash',
    rejoin: 'arrow-up',
    more: 'dots',
    fork: 'split-window',
    decision: 'git',
    action: 'user-avatar',
};

const ACTION_ICONS = { buy: 'shopping-cart', click: 'link', unsubscribe: 'x' };

/** The icon of one line: by its kind, and for what the person does by what she does. */
export function iconFor(entry) {
    if (entry.kind === 'action') return ACTION_ICONS[entry.meta?.action] ?? ICONS.action;

    return ICONS[entry.kind] ?? null;
}

/**
 * The entries of a known person's way, grouped by the day they fall on, in order.
 *
 * Two lines say nothing the page does not already say and are folded away: a wait that ends exactly on
 * a day boundary (the day header is that wait), and "effect unknown" right behind a card, which joins
 * the card's own line as a note, as it does in the tree.
 *
 * @param {{ day: number, at: number, kind: string, label: string }[]} entries
 * @returns {{ day: number, entries: object[] }[]}
 */
export function groupByDay(entries) {
    const days = [];
    let previous = null;

    for (const entry of entries) {
        if (entry.kind === 'wait' && entry.at % 86400 === 0) continue;

        if (entry.kind === 'unknown' && previous && previous.kind === 'enter') {
            previous.note = entry.label;
            continue;
        }

        const shown = { ...entry };
        const last = days[days.length - 1];

        if (last && last.day === shown.day) last.entries.push(shown);
        else days.push({ day: shown.day, entries: [shown] });

        previous = shown;
    }

    return days;
}

/** The time of day inside a day ("3 Std."), or null at midnight of it: the day header already says it. */
export function timeInDay(seconds, t) {
    const inDay = seconds % 86400;

    return inDay === 0 ? null : timeWords(inDay, t);
}

/** Query keys under which click tracking hides where a link really goes. */
const TARGET_KEYS = ['to', 'url', 'u', 'target', 'redirect', 'r', 'link', 'dest'];

/**
 * Where a link of a mail really leads. Click tracking wraps the address of a link in its own
 * (`https://track.test/c/abc?to=https%3A%2F%2Fsite.test%2Fkurs`); the person wants the goal, not the wrapper.
 *
 * @param {string} url
 * @returns {{ goal: string, wrapped: string|null }} `wrapped` is the original address when a goal was found behind it
 */
export function linkTarget(url) {
    try {
        const parsed = new URL(url, 'https://base.invalid');

        for (const key of TARGET_KEYS) {
            const value = parsed.searchParams.get(key);

            if (value && /^https?:\/\//i.test(value)) {
                return { goal: value.replace(/^https?:\/\//i, '').replace(/\/$/, ''), wrapped: url };
            }
        }
    } catch {
        // Not an address the page can read: it is shown as it is.
    }

    return { goal: url, wrapped: null };
}

/**
 * The entries up to and including a day: "fast forward" to where the person stands on that day.
 *
 * @param {{ day: number }[]} entries
 * @param {number|null} day null is the whole way
 */
export function untilDay(entries, day) {
    return day === null || day === undefined ? entries : entries.filter((e) => e.day <= day);
}

/**
 * Where the person stands after these entries: the mails she has got, the access she was given, the
 * purchases and unsubscribes she made.
 *
 * @param {{ kind: string, meta?: object, label: string }[]} entries
 */
export function standing(entries) {
    const access = [];
    let mails = 0;
    let purchases = 0;
    let unsubscribed = false;

    for (const e of entries) {
        if (e.kind === 'mail') mails++;
        if (e.kind === 'access' && e.meta?.slug && !access.includes(e.meta.slug)) access.push(e.meta.slug);
        if (e.kind === 'action' && e.meta?.action === 'buy') purchases++;
        if (e.kind === 'action' && e.meta?.action === 'unsubscribe') unsubscribed = true;
    }

    return { mails, access, purchases, unsubscribed };
}

/** Plain words from a comma list, as the person editor sends them. */
export function words(list) {
    return String(list ?? '').split(',').map((w) => w.trim()).filter(Boolean);
}

/** The actions as the server reads them: what she does after which mail. */
export function encodeActions(actions) {
    return JSON.stringify(actions.map((a) => ({
        type: a.type, mail: a.mail, product: a.product ?? null, url: a.url ?? null, after_seconds: a.after_seconds ?? 0,
    })));
}

/**
 * What the person has, read off her way: the access she was given (with the day) and, for each course she
 * got into, its lessons with the day each one opens.
 *
 * A course is any entry that names `meta.course`; its lessons name the same `meta.course` and `meta.lesson`.
 * A lesson of unknown opening (after a payment, paused) is `later: null`.
 *
 * @param {{ kind: string, label: string, detail: string|null, day: number, at: number, meta?: object }[]} entries
 * @returns {{ access: { slug: string, label: string, day: number }[], courses: { id: string, title: string, day: number, lessons: object[] }[] }}
 */
export function accountOf(entries) {
    const access = [];
    const courses = new Map();

    for (const e of entries) {
        const course = e.meta?.course;

        // Access she was given: by its slug, else by what the line says (an access line of an addon that names no slug is still access).
        if (e.kind === 'access' && !course) {
            const slug = e.meta?.slug ?? e.label;

            if (!access.some((a) => a.slug === slug)) access.push({ slug, label: e.label, day: e.day });
        }

        if (!course) continue;

        if (e.kind === 'access' && !courses.has(course)) {
            courses.set(course, { id: course, title: e.label, day: e.day, lessons: [] });
        } else if (e.meta?.lesson) {
            if (!courses.has(course)) courses.set(course, { id: course, title: course, day: e.day, lessons: [] });
            courses.get(course).lessons.push({
                slug: e.meta.lesson,
                title: e.label,
                detail: e.detail,
                // A lesson whose opening is not known (after a payment, paused) is an unknown line: no day.
                day: e.kind === 'page' ? e.day : null,
            });
        }
    }

    return { access, courses: [...courses.values()] };
}

/** The pages on the way: a page line that is no lesson. */
export function pagesOf(entries) {
    return entries.filter((e) => e.kind === 'page' && !e.meta?.lesson);
}
