/**
 * A JSON POST to the control panel, with the CSRF token Laravel wants.
 *
 * Returns what the server said instead of throwing on a refusal: the page turns
 * 403, 409 and 422 into a sentence from `data.message`.
 *
 * @returns {Promise<{ ok: boolean, status: number, data: object }>}
 */
export async function postJson(url, body) {
    const meta = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const cookie = document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.slice('XSRF-TOKEN='.length);
    const headers = { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };

    if (meta) headers['X-CSRF-TOKEN'] = meta;
    else if (cookie) headers['X-XSRF-TOKEN'] = decodeURIComponent(cookie);

    try {
        const response = await fetch(url, { method: 'POST', headers, credentials: 'same-origin', body: JSON.stringify(body) });
        const data = await response.json().catch(() => ({}));
        return { ok: response.ok, status: response.status, data };
    } catch {
        return { ok: false, status: 0, data: {} };
    }
}
