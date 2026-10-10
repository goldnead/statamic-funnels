<?php

namespace Goldnead\StatamicFunnels\Integrations;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * The optional path from a funnel step to `goldnead/statamic-lead-magnets`.
 *
 * The step "Lead magnet" hands the address of the visit to the sibling, which
 * owns everything about delivery: the double opt-in, the signed expiring link,
 * the private disk, the count. This class owns the walk: when the visitor may
 * go on, and what is measured on the way.
 *
 * ## Two words that must not be mixed up
 *
 * **Requested** is the moment the funnel asked for the resource. **Confirmed**
 * is the moment the address was proven (or, for a resource without double
 * opt-in, the same instant). Both are step events, so the report shows the
 * drop-off between them: people who asked and never clicked.
 *
 * ## How the visitor comes back
 *
 * With a double opt-in the visitor leaves the funnel for their mailbox. The
 * request carries a **return URL**: a link to this addon's resume route, signed
 * with the application key. lead-magnets redirects there after the click and
 * follows nothing else (see its `ReturnUrl`). The resume route then checks
 * the grant itself and never trusts the click: a signed link proves it was
 * issued, not that the address was confirmed.
 *
 * ## The rules this family learned
 *
 * 1. Every probe is a `class_exists` on a **string**. A class that names the
 *    sibling's types in `implements` or `extends` is not loaded before the
 *    probe has said the sibling is there.
 * 2. Never `method_exists()` on a Facade, which forwards everything through
 *    `__callStatic`. The facade here is only ever called, never probed.
 * 3. The sibling failing is not the walk failing: a thrown exception becomes a
 *    visible `failed` state on the page and a log line, never a 500.
 */
class LeadMagnetsBridge
{
    protected const FACADE = '\Goldnead\LeadMagnets\Facades\LeadMagnets';

    protected const RESOURCE = '\Goldnead\LeadMagnets\Models\Resource';

    /**
     * The class that carries the return-URL feature. Without it the sibling
     * would send the visitor to its own confirmation page and the funnel
     * would never hear of the click, so an older release counts as absent.
     */
    protected const RETURN_URL = '\Goldnead\LeadMagnets\Support\ReturnUrl';

    public const STATUS_WAITING = 'waiting';

    public const STATUS_NO_EMAIL = 'no_email';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CONFIRMED = 'confirmed';

    public function __construct(protected FunnelWalk $walk) {}

    /** Whether the step type exists on this site. */
    public static function available(): bool
    {
        return (bool) config('statamic-funnels.integrations.lead_magnets', true)
            && class_exists(self::FACADE)
            && class_exists(self::RESOURCE)
            && class_exists(self::RETURN_URL);
    }

    /**
     * The resources the editor can pick from.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        if (! self::available()) {
            return [];
        }

        try {
            $model = self::RESOURCE;

            return $model::query()
                ->where('published', true)
                ->orderBy('title')
                ->get(['handle', 'title'])
                ->map(fn ($resource): array => [
                    'value' => (string) $resource->handle,
                    'label' => (string) ($resource->title ?: $resource->handle),
                ])
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::warning('statamic-funnels: the lead magnet resources could not be listed.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * A visitor stands on a lead-magnet step.
     *
     * Idempotent: the request is made once per visit and step, however often
     * the page is loaded. A reload while waiting asks the sibling again whether
     * the address has been confirmed, so a visitor who confirmed on another
     * device and came back by hand is not stranded.
     *
     * @return array{status: string, email: string|null, next: FunnelStep|null}
     */
    public function arrive(Funnel $funnel, FunnelStep $step, FunnelVisit $visit): array
    {
        $email = $this->email($visit);

        if ($this->hasEvent($visit, $step, FunnelStepEvent::LEAD_MAGNET_CONFIRMED)) {
            // Already through. Show the way on without recording it again.
            return $this->outcome(self::STATUS_CONFIRMED, $email, $funnel->nextStep($step->node_key, 'default'));
        }

        if (! self::available()) {
            Log::warning('statamic-funnels: step ['.$step->node_key.'] is a lead magnet step but statamic-lead-magnets is not installed (or too old); the walk stops here.');

            return $this->outcome(self::STATUS_UNAVAILABLE, $email);
        }

        if ($email === null) {
            // Without a form step in front there is nobody to send it to.
            // Guessing an address would be worse than stopping.
            return $this->outcome(self::STATUS_NO_EMAIL, null);
        }

        $resource = $this->resource($step);

        if ($resource === null) {
            Log::warning('statamic-funnels: step ['.$step->node_key.'] names a lead magnet resource that does not exist or is not published.');

            return $this->outcome(self::STATUS_UNAVAILABLE, $email);
        }

        try {
            $grant = $this->hasEvent($visit, $step, FunnelStepEvent::LEAD_MAGNET_REQUESTED)
                ? $this->grantOf($visit, $step)
                : null;

            // Nothing asked yet, or asked for another address: the visitor went
            // back and typed a different one. The old grant is theirs no more.
            if ($grant === null || mb_strtolower(trim((string) $grant->email)) !== mb_strtolower($email)) {
                $grant = $this->request($funnel, $step, $visit, $resource, $email);
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('statamic-funnels: asking lead-magnets for ['.$step->node_key.'] failed.', ['error' => $e->getMessage()]);

            return $this->outcome(self::STATUS_FAILED, $email);
        }

        return $this->settle($funnel, $step, $visit, $grant, $email);
    }

    /**
     * The visitor clicked the confirmation link and was sent back.
     *
     * Trusts nothing about the click. The grant is asked, and only a grant that
     * stands moves the walk on.
     *
     * @return array{status: string, email: string|null, next: FunnelStep|null}
     */
    public function resume(Funnel $funnel, FunnelStep $step, FunnelVisit $visit): array
    {
        return $this->arrive($funnel, $step, $visit);
    }

    /**
     * Ask lead-magnets for the resource and write down that we did.
     */
    protected function request(Funnel $funnel, FunnelStep $step, FunnelVisit $visit, object $resource, string $email): ?object
    {
        $facade = self::FACADE;

        $meta = array_filter([
            'source' => 'funnel:'.$funnel->handle,
            'funnel' => $funnel->handle,
            'step' => $step->node_key,
            'newsletter' => $this->newsletter($visit),
            // Only where there is a click to come back from.
            'return_url' => (bool) $resource->requires_confirmation
                ? URL::signedRoute('statamic-funnels.lead-magnet.resume', [
                    'funnel' => $funnel->handle,
                    'nodeKey' => $step->node_key,
                    'visit' => $visit->getKey(),
                ])
                : null,
        ], static fn ($value): bool => $value !== null);

        $grant = $facade::request($resource, $email, $meta);

        $meta = (array) ($visit->meta ?? []);
        $meta['lead_magnet'][$step->node_key] = ['grant_id' => $grant->id, 'resource' => $resource->handle];
        $visit->forceFill(['meta' => $meta])->save();

        $visit->record($step->node_key, FunnelStepEvent::LEAD_MAGNET_REQUESTED, [
            'resource' => $resource->handle,
            'confirmation' => (bool) $resource->requires_confirmation,
        ]);

        return $grant;
    }

    /**
     * Where the request stands now, and the walk with it.
     *
     * @return array{status: string, email: string|null, next: FunnelStep|null}
     */
    protected function settle(Funnel $funnel, FunnelStep $step, FunnelVisit $visit, ?object $grant, string $email): array
    {
        if ($grant === null) {
            return $this->outcome(self::STATUS_UNAVAILABLE, $email);
        }

        $grant->refresh();

        if ($grant->isRedeemable()) {
            $next = $this->walk->advance($visit, $step, 'default', FunnelStepEvent::LEAD_MAGNET_CONFIRMED, [
                'grant' => $grant->id,
            ]);

            return $this->outcome(self::STATUS_CONFIRMED, $email, $next);
        }

        if ($grant->isPending()) {
            return $this->outcome(self::STATUS_WAITING, $email);
        }

        // Revoked, scheduled, expired: the sibling will not deliver, and
        // waiting for a click that cannot succeed would be a lie.
        return $this->outcome(self::STATUS_UNAVAILABLE, $email);
    }

    /** The visit's address, or null. Never taken from the request. */
    protected function email(FunnelVisit $visit): ?string
    {
        $email = trim((string) $visit->email);

        return $email !== '' ? $email : null;
    }

    /**
     * What the visitor ticked in the form, or nothing.
     *
     * Passed on only when the box was ticked. An address left in a form is not
     * a consent, so a visit without a ticked box hands over no `newsletter` key
     * at all.
     *
     * @return array<string, mixed>|null
     */
    protected function newsletter(FunnelVisit $visit): ?array
    {
        $newsletter = $visit->meta['newsletter'] ?? null;

        if (! is_array($newsletter) || empty($newsletter['opted_in'])) {
            return null;
        }

        return [
            'opted_in' => true,
            'at' => $newsletter['at'] ?? null,
            'text' => $newsletter['text'] ?? null,
        ];
    }

    protected function resource(FunnelStep $step): ?object
    {
        $handle = $step->config('resource');

        if (! is_string($handle) || $handle === '') {
            return null;
        }

        $model = self::RESOURCE;

        $resource = $model::query()->where('handle', $handle)->where('published', true)->first();

        return $resource ?: null;
    }

    protected function grantOf(FunnelVisit $visit, FunnelStep $step): ?object
    {
        $id = $visit->meta['lead_magnet'][$step->node_key]['grant_id'] ?? null;

        if (! is_numeric($id)) {
            return null;
        }

        $model = '\Goldnead\LeadMagnets\Models\Grant';

        return class_exists($model) ? $model::query()->find((int) $id) : null;
    }

    protected function hasEvent(FunnelVisit $visit, FunnelStep $step, string $event): bool
    {
        return $visit->events()->where('node_key', $step->node_key)->where('event', $event)->exists();
    }

    /**
     * @return array{status: string, email: string|null, next: FunnelStep|null}
     */
    protected function outcome(string $status, ?string $email, ?FunnelStep $next = null): array
    {
        return ['status' => $status, 'email' => $email, 'next' => $next];
    }
}
