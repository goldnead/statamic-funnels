<?php

namespace Goldnead\StatamicFunnels\Tokens;

use Closure;
use Goldnead\StatamicFunnels\Http\Middleware\MarkPagePreview;
use Statamic\Contracts\Tokens\Token;

/**
 * What Statamic's token middleware does with a page-preview pass: nothing.
 *
 * The pass is read by {@see MarkPagePreview}, which has to know the
 * answer before the rest of the request starts. This class exists so that a route in the group where Statamic resolves
 * token handlers does not fail on a handler that is not a class.
 */
class PagePreviewHandler
{
    public function handle(Token $token, $request, Closure $next)
    {
        return $next($request);
    }
}
