<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\Funnel;
use Illuminate\Http\Request;

/**
 * The walk of a visitor who has no cookie.
 *
 * `page_tracking.mode = cookieless`: nothing is stored in the browser and nothing is asked. The visit is told apart by
 * a keyed hash of the app key, the day, the funnel, the IP and the user agent. The hash is the only thing that is
 * stored (as the visit's token); the IP and the user agent are never written anywhere, and the hash cannot be turned
 * back into them without guessing both.
 *
 * It changes every day. The same person reloading today is one visit; tomorrow is a new visit, and nobody can be
 * followed across days. Two people behind one address with the same browser count as one: the price of not asking.
 */
class DailyToken
{
    public static function enabled(): bool
    {
        return config('statamic-funnels.page_tracking.mode', 'cookie') === 'cookieless';
    }

    /** 32 characters, the shape of every walk token. */
    public static function for(Request $request, Funnel $funnel): string
    {
        $parts = [now()->format('Y-m-d'), (string) $funnel->id, (string) $request->ip(), (string) $request->userAgent()];

        return substr(hash_hmac('sha256', implode("\0", $parts), (string) config('app.key')), 0, 32);
    }
}
