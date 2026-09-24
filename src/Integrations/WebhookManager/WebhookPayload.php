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
use Throwable;

/**
 * What a webhook receiver gets for a funnel event.
 *
 * Chosen field by field. Never sent: the visit token (it is the visitor's
 * cookie, whoever holds it walks on as them), the visit's meta (billing
 * address, pending payments, consent records), a payment's provider ids,
 * mandate or card hint.
 *
 * Every payload has the frame its suite siblings share:
 *
 *     event        the trigger handle, e.g. funnels.upsell_declined
 *     occurred_at  ISO 8601 with offset
 *     brand        {id, handle} or null
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
        [$key, $at] = self::moment($event);
        $at ??= now();

        return [
            'event' => $handle,
            // The same moment always gets the same id, however often it is
            // sent: `<handle>:<subject_id>:<key>`, formed as in the payments
            // addon. A receiver deduplicates on it; order is not guaranteed.
            'event_id' => $handle.':'.$subject.':'.$key,
            'occurred_at' => $at->format(\DATE_ATOM),
            'brand' => self::brand(self::brandId($event)),
            // Named outright, as the payments addon does: without it the
            // manager files a `funnels.*` delivery by guessing.
            'subject_type' => 'funnel',
            'subject_id' => $subject,
            ...$body,
        ];
    }

    /**
     * What makes this moment this moment, and when it happened: the visit and
     * the step, plus the row the moment wrote (arrival, decline, completion,
     * the payment) for its time.
     *
     * @return array{0: string, 1: \DateTimeInterface|null}
     */
    public static function moment(object $event): array
    {
        $atom = fn (?\DateTimeInterface $at): string => ($at ?? now())->format(\DATE_ATOM);

        if ($event instanceof FunnelSaved) {
            return [$atom($event->funnel->updated_at), $event->funnel->updated_at];
        }

        $visit = $event->visit ?? null;

        if (! $visit instanceof FunnelVisit) {
            return [$atom(null), null];
        }

        $walk = 'visit-'.$visit->id;
        $step = $event->step ?? null;
        $node = $step instanceof FunnelStep ? $walk.':'.$step->node_key : $walk;
        $row = fn (string $kind) => $step instanceof FunnelStep
            ? $visit->events()->where('node_key', $step->node_key)->where('event', $kind)->oldest('id')->first()?->created_at
            : null;

        return match (true) {
            $event instanceof FunnelCompleted => [$walk, $visit->completed_at],
            $event instanceof FunnelStepEntered => [$node, $row('entered')],
            $event instanceof FunnelOfferAccepted => [$node.':payment-'.$event->payment->id, $event->payment->paid_at],
            $event instanceof FunnelOfferDeclined, $event instanceof UpsellDeclined => [$node, $row('declined')],
            // Announced before the step writes its row; the visit was saved
            // with the address a moment earlier.
            $event instanceof FunnelFormSubmitted => [$node.':'.$atom($visit->updated_at), $visit->updated_at],
            default => [$node.':'.$atom(null), null],
        };
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
                && preg_match('/password|passwort|token|secret|iban/i', $key) !== 1,
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
