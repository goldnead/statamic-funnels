<?php

namespace Goldnead\StatamicFunnels\Http\Middleware;

use Closure;
use Goldnead\StatamicFunnels\Support\PagePreviewToken;
use Goldnead\StatamicFunnels\Support\ProbePreview;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * "This request only looks", for the request the Probelauf makes to the site's own page.
 *
 * Sits first in the `web` group, ahead of the session and of everything the site puts after it (an attribution that is
 * remembered, a last-seen stamp), so that all of them can ask {@see ProbePreview} instead of knowing about the Probelauf.
 *
 * A request without a good pass (see {@see PagePreviewToken}) passes through untouched: it is an ordinary visit, and a
 * header that somebody made up marks nothing. A marked request:
 *
 * - keeps its session in memory, so it neither reads a visitor's session nor writes one;
 * - is never cached by anyone and never indexed;
 * - is told apart for the views by `probe_preview` (and `preview`), for a template that has a script to leave out.
 */
class MarkPagePreview
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ProbePreview::active($request) && PagePreviewToken::consume($request)) {
            ProbePreview::mark($request);

            // Before the session middleware resolves its driver, which is why this middleware comes first.
            config(['session.driver' => 'array']);
        }

        if (! ProbePreview::active($request)) {
            return $next($request);
        }

        view()->share(['probe_preview' => true, 'preview' => true]);

        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        // A look sets no cookie: whatever the page tried to hand out stays here.
        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        return $response;
    }
}
