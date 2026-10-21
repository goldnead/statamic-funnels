<?php

namespace Goldnead\StatamicFunnels\Http\Middleware;

use Closure;
use Goldnead\StatamicFunnels\Support\PagePreviewToken;
use Goldnead\StatamicFunnels\Support\ProbePreview;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * "This request only looks", for the request the Probelauf makes to the site's own page.
 *
 * Sits first in the `web` group (and in `statamic.web`, the group of the catch-all that serves entries), ahead of the session
 * and of everything the site puts after it (an attribution that is remembered, a last-seen stamp), so that all of them can ask
 * {@see ProbePreview} instead of knowing about the Probelauf.
 *
 * A request without a pass passes through untouched: it is an ordinary visit, and a header that somebody made up marks nothing.
 * A request WITH a good pass for another path than the one it asks for is answered 404 and logged, never served as a visit:
 * the pass was minted for one page, and a request that does not match is a mistake of ours that must not count by accident.
 * A marked request:
 *
 * - keeps its session in memory, so it neither reads a visitor's session nor writes one;
 * - is never cached by anyone ({@see KeepPreviewOutOfTheCache}) and never indexed;
 * - is told apart for the views by `probe_preview` (and `preview`), for a template that has a script to leave out;
 * - sets no cookie.
 *
 * What it changed for the request (the session driver, the shared view variables) is put back when the answer is out.
 */
class MarkPagePreview
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ProbePreview::active($request)) {
            $pass = PagePreviewToken::consume($request);

            if ($pass === PagePreviewToken::MISMATCH) {
                Log::warning('statamic-funnels: a page-preview pass came with another path than it was made for; the request was refused.', ['path' => $request->getPathInfo()]);

                return response('', 404, ['Cache-Control' => 'no-store, private']);
            }

            if ($pass === PagePreviewToken::GOOD) {
                ProbePreview::mark($request);
            }
        }

        if (! ProbePreview::active($request)) {
            return $next($request);
        }

        $driver = config('session.driver');
        // Before the session middleware resolves its driver, which is why this middleware comes first.
        config(['session.driver' => 'array']);
        view()->share(['probe_preview' => true, 'preview' => true]);

        try {
            $response = $next($request);
        } finally {
            config(['session.driver' => $driver]);
            // A process that serves more than one request (Octane) must not carry the look into the next visitor's page.
            view()->share(['probe_preview' => false, 'preview' => false]);
        }

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        // A look sets no cookie: whatever the page tried to hand out stays here.
        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        return $response;
    }
}
