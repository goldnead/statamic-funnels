<?php

namespace Goldnead\StatamicFunnels\Support;

use Illuminate\Http\Request;

/**
 * "This request only looks." The page a Probelauf (dry run) shows is rendered on the server, and that render is
 * not a visit.
 *
 * One flag on the request, read by everything that counts: the walk (a visit row, a step event, a cookie), the
 * split test (a share-out written onto the visit), the tracking code and the server-side pixel. Each of them
 * asks here instead of knowing about the dry run, so a place that counts and is added later has one thing to
 * ask. The flag lives on the request object only: it is never stored, never sent, and an outsider cannot set it,
 * because a request that arrives from outside has no way to put an attribute on itself.
 */
final class ProbePreview
{
    public const ATTRIBUTE = 'statamic-funnels.probe_preview';

    /** Mark a request as a look and nothing else. */
    public static function mark(Request $request): Request
    {
        $request->attributes->set(self::ATTRIBUTE, true);

        return $request;
    }

    /** Whether the request in hand (the current one when none is given) only looks. */
    public static function active(?Request $request = null): bool
    {
        $request ??= app()->bound('request') ? app('request') : null;

        return $request instanceof Request && $request->attributes->get(self::ATTRIBUTE) === true;
    }
}
