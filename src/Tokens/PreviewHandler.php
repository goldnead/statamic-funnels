<?php

namespace Goldnead\StatamicFunnels\Tokens;

use Closure;
use Statamic\Contracts\Tokens\Token;

/**
 * What Statamic's token middleware does with a funnel preview pass: nothing.
 *
 * The preview route reads the token itself, because it needs the graph *before*
 * it can decide which step it is even rendering. This class exists so that a
 * funnel route which one day sits inside the `statamic.web` group does not blow
 * up: `Token::handle()` resolves the handler string out of the container, and a
 * handler that is not a class is a 500 rather than a missed feature.
 */
class PreviewHandler
{
    public function handle(Token $token, $request, Closure $next)
    {
        return $next($request);
    }
}
