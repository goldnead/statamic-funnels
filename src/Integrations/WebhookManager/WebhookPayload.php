<?php

namespace Goldnead\StatamicFunnels\Integrations\WebhookManager;

use Goldnead\StatamicFunnels\Events\FunnelCompleted;
use Goldnead\StatamicFunnels\Events\FunnelFormSubmitted;
use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Events\FunnelOfferDeclined;
use Goldnead\StatamicFunnels\Events\FunnelSaved;
use Goldnead\StatamicFunnels\Events\FunnelStepEntered;
use Goldnead\StatamicFunnels\Events\UpsellDeclined;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What a webhook receiver gets for a funnel event.
 *
 * Chosen field by field. Never sent: the visit token (it is the visitor's
 * cookie, whoever holds it walks on as them), the visit's meta (pending
 * payments, consent records), a payment's mandate or card hint.
 *
 * **`form_submitted.values` is personal data.** It is what the visitor typed
 * on the capture step: address, and whatever else the step asks (billing
 * address, phone, company, VAT id, fields the offer defines). Only fields
 * named like a secret or card data are held back (password, token, secret,
 * IBAN, BIC, card, Kredit(karte), CVC, CVV) and framework fields (`_…`).
 * Whoever points a hook at it hands that data to the receiver, which needs
 * a data processing agreement like any other processor.
 *
 * Every payload has the frame its suite siblings share:
 *
 *     event         the trigger handle, e.g. funnels.upsell_declined
 *     event_id      sha1(handle|visit:<id>|step:<key>|…), stable per moment
 *     occurred_at   the moment's own time, ISO 8601 with offset
 *     brand         {id, handle} or null
 *     subject_type  funnel
 *     subject_id    the funnel's id
 *
 * Amounts are integer cents next to their currency.
 *
 * Free of webhook-manager classes, so it loads on a site without that addon.
 */
final class WebhookPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(string $handle, object $event): array
    {
        $body = self::body($event);
        $subject = $body['funnel']['id'] ?? null;
        $parts = self::momentParts($event);

        return [
            'event' => $handle,
            // The same moment always gets the same id, however often it is
            // sent. A receiver deduplicates on it; order is not guaranteed.
            'event_id' => self::eventId($handle, $parts),
            'occurred_at' => self::occurredAt($parts)->format(\DATE_ATOM),
            'brand' => self::brand(self::brandId($event)),
            // Named outright, as the payments addon does: without it the
            // manager files a `funnels.*` delivery by guessing.
            'subject_type' => 'funnel',
            'subject_id' => $subject,
            ...$body,
        ];
    }

    /**
     * Parts worked out when the event fired, for the moments whose parts
     * depend on what is written afterwards (a form's count of earlier
     * submissions). The bridge records them before it waits for the commit.
     *
     * @var \WeakMap<object, list<mixed>>|null
     */
    protected static ?\WeakMap $remembered = null;

    /** Work out and keep a moment's parts now, before anything else is written. */
    public static function remember(object $event): void
    {
        self::$remembered ??= new \WeakMap;
        self::$remembered[$event] = self::momentParts($event);
    }

    /**
     * `sha1(handle|part|part…)`, dates as DATE_ATOM: the same recipe in every
     * addon of the suite.
     *
     * @param  list<mixed>  $parts
     */
    public static function eventId(string $handle, array $parts): string
    {
        return sha1(implode('|', array_map(
            fn ($part) => $part instanceof \DateTimeInterface ? $part->format(\DATE_ATOM) : (string) $part,
            [$handle, ...$parts],
        )));
    }

    /**
     * The first date among the parts, the moment's own time. The clock only
     * where no row records one.
     *
     * @param  list<mixed>  $parts
     */
    public static function occurredAt(array $parts): \DateTimeInterface
    {
        foreach ($parts as $part) {
            if ($part instanceof \DateTimeInterface) {
                return $part;
            }
        }

        return now();
    }

    /**
     * What separates this moment from every other moment of the same kind:
     * the visit and the step as `<type>:<id>`, then the time the row of the
     * moment records (arrival, decline, completion, the payment). Never the
     * time of sending.
     *
     * A form can be submitted twice within one second (a second capture step,
     * a corrected address), so a submission is counted, not timed: the n-th
     * submission of this step in this walk.
     *
     * @return list<mixed>
     */
    public static function momentParts(object $event): array
    {
        if (self::$remembered !== null && isset(self::$remembered[$event])) {
            return self::$remembered[$event];
        }

        if ($event instanceof FunnelSaved) {
            return ['funnel:'.$event->funnel->id, $event->funnel->updated_at ?? ''];
        }

        $visit = $event->visit ?? null;

        if (! $visit instanceof FunnelVisit) {
            return [$event::class];
        }

        $walk = 'visit:'.$visit->id;
        $step = $event->step ?? null;
        $node = $step instanceof FunnelStep ? 'step:'.$step->node_key : 'step:';
        $rows = fn (string $kind) => $step instanceof FunnelStep && $visit->exists
            ? $visit->events()->where('node_key', $step->node_key)->where('event', $kind)
            : null;

        return match (true) {
            $event instanceof FunnelCompleted => [$walk, $visit->completed_at ?? 'completed'],
            $event instanceof FunnelStepEntered => [$walk, $node, $rows('entered')?->oldest('id')->first()->created_at ?? 'entered'],
            $event instanceof FunnelOfferAccepted => [$walk, $node, 'payment:'.$event->payment->id, $event->payment->paid_at ?? 'paid'],
            $event instanceof FunnelOfferDeclined, $event instanceof UpsellDeclined => [$walk, $node, $rows('declined')?->oldest('id')->first()->created_at ?? 'declined'],
            // Announced before the step writes its own row: the ones already
            // there are the earlier submissions.
            $event instanceof FunnelFormSubmitted => [$walk, $node, 'submission:'.((int) $rows('submitted')?->count() + 1), $visit->updated_at ?? ''],
            default => [$walk, $node, $event::class],
        };
    }

    /**
     * Run the hand-over as the brand the moment names, or not at all.
     *
     * A brand that cannot be made current (a payment stamped with a brand
     * since deleted) is not replaced by whichever brand is current: its hooks
     * belong to another tenant. Logged, not delivered. No brand named, or no
     * brand-context installed: runs as it is. The same rule as the payments
     * addon's `WebhookPayload::runForBrand()`.
     *
     * @param  \Closure(): void  $callback
     */
    public static function runForBrand(?int $brand, \Closure $callback, string $handle): bool
    {
        if (! $brand || ! app()->bound('brand-context')) {
            $callback();

            return true;
        }

        $ran = false;

        try {
            app('brand-context')->runFor($brand, function () use ($callback, &$ran): void {
                $ran = true;
                $callback();
            });

            return true;
        } catch (Throwable $e) {
            if ($ran) {
                throw $e;
            }

            Log::warning('statamic-funnels: the moment names a brand that cannot be set; the webhook was not delivered rather than sent through another brand\'s hooks.', [
                'trigger' => $handle,
                'brand_id' => $brand,
                'exception' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * The brand a funnel event belongs to. A funnel has no brand column; what
     * it sold does. Else the brand of the request it happened in, which the
     * funnel's own controllers set from the offer on the step.
     */
    public static function brandId(object $event): ?int
    {
        $payment = $event->payment ?? null;

        // 0 is payments' stamp for "no brand" on a single-brand install.
        if ($payment instanceof Payment && is_numeric($payment->brand_id) && (int) $payment->brand_id > 0) {
            return (int) $payment->brand_id;
        }

        try {
            $manager = app()->bound('brand-context') ? app('brand-context') : null;

            return $manager !== null && $manager->hasCurrent() ? (int) $manager->currentId() : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected static function body(object $event): array
    {
        return match (true) {
            $event instanceof FunnelSaved => [
                'funnel' => self::funnel($event->funnel) + ['published' => (bool) $event->funnel->published],
                'steps' => $event->funnel->steps->count(),
            ],
            $event instanceof FunnelCompleted => [
                'funnel' => self::funnel($event->visit->funnel),
                'visit' => self::visit($event->visit),
                'completed_at' => $event->visit->completed_at?->toIso8601String(),
            ],
            $event instanceof FunnelStepEntered => [
                'funnel' => self::funnel($event->visit->funnel),
                'step' => self::step($event->step),
                'visit' => self::visit($event->visit),
            ],
            $event instanceof FunnelFormSubmitted => [
                'funnel' => self::funnel($event->visit->funnel),
                'step' => self::step($event->step),
                'visit' => self::visit($event->visit),
                'form' => is_string($form = $event->step->config('form')) && $form !== '' ? $form : null,
                'values' => self::values($event->values),
            ],
            $event instanceof FunnelOfferAccepted => [
                'funnel' => self::funnel($event->visit->funnel),
                'step' => self::step($event->step),
                'visit' => self::visit($event->visit),
                'offer' => self::offer($event->step),
                'payment' => self::payment($event->payment),
            ],
            $event instanceof UpsellDeclined => [
                'funnel' => self::funnel($event->visit->funnel),
                'step' => self::step($event->step),
                'visit' => self::visit($event->visit),
                'offer' => ['handle' => $event->offerHandle !== '' ? $event->offerHandle : null],
                'bought' => $event->payment instanceof Payment ? self::payment($event->payment) : null,
            ],
            $event instanceof FunnelOfferDeclined => [
                'funnel' => self::funnel($event->visit->funnel),
                'step' => self::step($event->step),
                'visit' => self::visit($event->visit),
                'offer' => self::offer($event->step),
            ],
            default => [],
        };
    }

    /**
     * @return array{id: int, handle: string, title: string}|null
     */
    protected static function funnel(?Funnel $funnel): ?array
    {
        return $funnel === null ? null : [
            'id' => (int) $funnel->id,
            'handle' => (string) $funnel->handle,
            'title' => (string) $funnel->title,
        ];
    }

    /**
     * @return array{key: string, type: string, label: string|null, slug: string|null}
     */
    protected static function step(FunnelStep $step): array
    {
        return [
            'key' => (string) $step->node_key,
            'type' => (string) $step->type,
            'label' => $step->label,
            'slug' => $step->slug,
        ];
    }

    /**
     * The walk, without its token.
     *
     * @return array{id: int, email: string|null, name: string|null}
     */
    protected static function visit(FunnelVisit $visit): array
    {
        return [
            'id' => (int) $visit->id,
            'email' => $visit->email,
            'name' => $visit->name,
        ];
    }

    /**
     * @return array{handle: string|null}
     */
    protected static function offer(FunnelStep $step): array
    {
        $handle = $step->config('offer');

        return ['handle' => is_string($handle) && $handle !== '' ? $handle : null];
    }

    /**
     * The payment block exactly as statamic-payments sends it in its own
     * webhooks (`WebhookPayload::payment()` there, 1.26), so a receiver reads
     * one shape from every addon. A copy, not a call: funnels runs on payments
     * 1.25, which has no such class. The test pins the keys.
     * Not: card digits or label, mandate, customer reference, meta, consent
     * text, referrer, landing page.
     *
     * @return array<string, mixed>
     */
    public static function payment(Payment $payment): array
    {
        $providerId = (string) $payment->provider_id;

        return [
            'id' => $payment->getKey(),
            'provider' => $payment->provider,
            // Null while the provider has not answered: a placeholder nobody can look up.
            'provider_id' => $providerId === '' || Payment::isPlaceholderProviderId($providerId) ? null : $providerId,
            'status' => $payment->status,
            'product' => $payment->product,
            'amount_cent' => (int) $payment->amount_cent,
            'currency' => $payment->currency,
            'discount_code' => $payment->discount_code,
            'discount_cent' => $payment->discount_cent === null ? null : (int) $payment->discount_cent,
            'refunded_cent' => (int) ($payment->refunded_cent ?? 0),
            'email' => $payment->email,
            'name' => $payment->name,
            'country' => $payment->country,
            'subscription_id' => $payment->subscription_id,
            'parent_payment_id' => $payment->parent_payment_id,
            'items' => $payment->exists ? $payment->items()->orderBy('id')->get()->map(fn ($item): array => [
                'product' => $item->product,
                'offer' => $item->offer,
                'name' => $item->name,
                'kind' => $item->kind,
                'quantity' => (int) $item->quantity,
                'amount_cent' => (int) $item->amount_cent,
                'discount_cent' => (int) ($item->discount_cent ?? 0),
            ])->values()->all() : [],
            'attribution' => [
                'utm_source' => $payment->utm_source,
                'utm_medium' => $payment->utm_medium,
                'utm_campaign' => $payment->utm_campaign,
                'utm_term' => $payment->utm_term,
                'utm_content' => $payment->utm_content,
            ],
            'created_at' => $payment->created_at?->format(\DATE_ATOM),
            'paid_at' => $payment->paid_at?->format(\DATE_ATOM),
            'refunded_at' => $payment->refunded_at?->format(\DATE_ATOM),
            'charged_back_at' => $payment->charged_back_at?->format(\DATE_ATOM),
        ];
    }

    /**
     * What the visitor typed, without framework fields (`_token`, `_redirect`)
     * or anything named like a secret.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected static function values(array $values): array
    {
        return array_filter(
            $values,
            static fn (string $key): bool => ! str_starts_with($key, '_')
                && preg_match('/password|passwort|token|secret|iban|bic|card|kredit|karte|cvc|cvv/i', $key) !== 1,
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array{id: int, handle: string|null}|null
     */
    public static function brand(?int $brandId): ?array
    {
        if ($brandId === null) {
            return null;
        }

        $model = 'Goldnead\\BrandContext\\Models\\Brand';
        $handle = null;

        if (class_exists($model)) {
            try {
                $handle = $model::query()->whereKey($brandId)->value('handle');
            } catch (Throwable) {
                $handle = null;
            }
        }

        return ['id' => $brandId, 'handle' => is_string($handle) ? $handle : null];
    }
}
