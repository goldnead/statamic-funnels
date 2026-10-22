<?php

namespace Goldnead\StatamicFunnels\Http\Middleware;

use Closure;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Support\DailyToken;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\ProbePreview;
use Goldnead\StatamicFunnels\Support\TrackingConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * A page of the site that is a step of a funnel.
 *
 * `statamic-funnels.track:{funnel},{nodeKey}` on a route says: opening this
 * page is arriving at that step. The page stays the site's own; the funnel
 * only counts. Nothing about the response is changed (no header, no body, no
 * status), the only thing that can come out of this middleware besides the
 * answer itself is the walk's cookie, queued like any other.
 *
 * ## What counts
 *
 * One person opening the page, once per walk (the walk itself ignores a
 * second arrival, see {@see FunnelWalk::enter()}). So these do not count:
 *
 *  - anything but a `GET` that was answered `200`;
 *  - a partial reload or a prefetch (Inertia sends both as requests of their
 *    own, and neither is anyone looking at the page), and any other script
 *    request that is not a page navigation;
 *  - a crawler, a link preview, an uptime monitor ({@see self::ignored()}:
 *    a list of user agents in `statamic-funnels.page_tracking.ignore_user_agents`,
 *    no user agent at all, and a header that any monitor can send to opt out);
 *  - the Probelauf and the page preview of the Control Panel
 *    ({@see ProbePreview}): a look is nobody's visit;
 *  - a funnel that is not published, and a step that is not an `external`
 *    step with this path or has been switched off.
 *
 * An Inertia **visit** (a click from another page of the site) is a page view
 * like any other and counts. That is how most people arrive on a single-page
 * site.
 *
 * ## Consent
 *
 * The walk is a first-party cookie with a random token. With
 * `page_tracking.consent_service` set, the cookie and the visit are only made
 * once {@see TrackingConsent} says the visitor agreed to that service; without
 * it nothing asks. Whether a site needs the question is the site's decision.
 *
 * ## Nothing here may break the page
 *
 * The answer is already made when this runs, and every failure is caught and
 * logged. A funnel that cannot count is a gap in a chart, not an error page.
 */
class TrackPage
{
    public function __construct(protected FunnelWalk $walk) {}

    public function handle(Request $request, Closure $next, string $funnel = '', string $nodeKey = ''): Response
    {
        $response = $next($request);

        try {
            if ($funnel !== '' && $nodeKey !== '' && $this->counts($request, $response)) {
                $this->arrive($request, $funnel, $nodeKey);
            }
        } catch (Throwable $e) {
            Log::warning('statamic-funnels: the page view could not be counted.', [
                'funnel' => $funnel,
                'step' => $nodeKey,
                'error' => $e->getMessage(),
            ]);
        }

        return $response;
    }

    protected function arrive(Request $request, string $handle, string $nodeKey): void
    {
        $funnel = Funnel::query()->where('handle', $handle)->where('published', true)->with(['steps', 'edges'])->first();
        $step = $funnel?->stepByKey($nodeKey);

        if (! $funnel || ! $step || $step->type !== 'external' || $step->disabled) {
            return;
        }

        if (DailyToken::enabled()) {
            // No cookie and no consent: the visit is the hash of the day.
            $this->walk->enter($this->walk->visitFor($funnel, DailyToken::for($request, $funnel)), $step);

            return;
        }

        $service = trim((string) config('statamic-funnels.page_tracking.consent_service'));

        if ($service !== '' && ! TrackingConsent::state($request, $service)['granted']) {
            return;
        }

        $this->walk->enter($this->walk->visit($funnel), $step);
    }

    /** Whether this exchange is one person looking at a page. */
    protected function counts(Request $request, Response $response): bool
    {
        return $request->isMethod('GET')
            && $response->getStatusCode() === 200
            && ! ProbePreview::active($request)
            && ! $this->ignored($request);
    }

    protected function ignored(Request $request): bool
    {
        $header = trim((string) config('statamic-funnels.page_tracking.ignore_header', 'X-Funnels-Ignore'));

        if ($header !== '' && $request->headers->has($header)) {
            return true;
        }

        // A browser says why it asks when it is not a visit yet.
        foreach (['Purpose', 'Sec-Purpose', 'X-Purpose', 'X-Moz'] as $name) {
            if (preg_match('/prefetch|preview|prerender/i', (string) $request->headers->get($name))) {
                return true;
            }
        }

        $inertia = $request->headers->has('X-Inertia');

        if ($inertia && ($request->headers->has('X-Inertia-Partial-Component') || $request->headers->has('X-Inertia-Partial-Data'))) {
            return true;
        }

        // A script asking for the page (fetch, XHR) is not someone arriving,
        // except Inertia's own navigation, which is.
        if (! $inertia) {
            $mode = strtolower((string) $request->headers->get('Sec-Fetch-Mode'));

            if ($request->ajax() || ($mode !== '' && $mode !== 'navigate')) {
                return true;
            }
        }

        $agent = trim((string) $request->userAgent());

        if ($agent === '') {
            return true;
        }

        foreach ((array) config('statamic-funnels.page_tracking.ignore_user_agents', []) as $pattern) {
            if (is_string($pattern) && $pattern !== '' && stripos($agent, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
