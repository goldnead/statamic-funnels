<?php

namespace Goldnead\StatamicFunnels\Http\Middleware;

use Closure;
use Goldnead\StatamicFunnels\Support\ProbePreview;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A look is never stored for the visitors that come after it.
 *
 * Statamic's static cache sits at the end of the `statamic.web` group and decides on the answer it gets back, so what keeps a page
 * out of it has to be on the answer while it is still inside that middleware: this one is pushed behind it (and so runs inside it),
 * and says "uncacheable" for a request that is only a look. A page that is cached without its analytics script, marked as a
 * preview, would be the page every visitor gets.
 */
class KeepPreviewOutOfTheCache
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (ProbePreview::active($request)) {
            $response->headers->set('X-Statamic-Uncacheable', '1');
        }

        return $response;
    }
}
