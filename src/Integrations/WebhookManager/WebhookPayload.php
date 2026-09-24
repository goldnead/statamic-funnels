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
        return [
            'event' => $handle,
            'occurred_at' => now()->toIso8601String(),
            'brand' => self::brand(self::brandId($event)),
            ...self::body($event),
        ];
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
     * @return array{id: int, product: string, amount_cent: int, currency: string, status: string, provider: string, paid_at: string|null}
     */
    public static function payment(Payment $payment): array
    {
        return [
            'id' => (int) $payment->id,
            'product' => (string) $payment->product,
            'amount_cent' => (int) $payment->amount_cent,
            'currency' => strtoupper((string) $payment->currency),
            'status' => (string) $payment->status,
            'provider' => (string) $payment->provider,
            'paid_at' => $payment->paid_at?->toIso8601String(),
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
