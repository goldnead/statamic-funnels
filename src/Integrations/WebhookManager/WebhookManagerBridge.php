<?php

namespace Goldnead\StatamicFunnels\Integrations\WebhookManager;

use Goldnead\StatamicFunnels\Events\FunnelCompleted;
use Goldnead\StatamicFunnels\Events\FunnelFormSubmitted;
use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Events\FunnelOfferDeclined;
use Goldnead\StatamicFunnels\Events\FunnelSaved;
use Goldnead\StatamicFunnels\Events\FunnelStepEntered;
use Goldnead\StatamicFunnels\Events\UpsellDeclined;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Optional coupling to goldnead/statamic-webhook-manager: every funnel event
 * becomes a trigger an outbound webhook can listen to.
 *
 * Nothing of the webhook manager is touched before its classes are checked by
 * name; {@see FunnelsTrigger} implements its interface and is only created
 * after that check.
 *
 * Booted from an `app->booted()` callback with a retry at the end of that
 * queue, because whether the webhook manager has bound its service by then
 * depends on package order. Idempotent, so the retry costs nothing.
 */
class WebhookManagerBridge
{
    public const FACADE = 'Goldnead\\WebhookManager\\Facades\\WebhookManager';

    public const TRIGGER_INTERFACE = 'Goldnead\\WebhookManager\\Contracts\\TriggerInterface';

    /**
     * Event class => trigger handle, named as the automations triggers are.
     *
     * @var array<class-string, string>
     */
    public const TRIGGERS = [
        FunnelStepEntered::class => 'funnels.step_entered',
        FunnelFormSubmitted::class => 'funnels.form_submitted',
        FunnelOfferAccepted::class => 'funnels.offer_accepted',
        FunnelOfferDeclined::class => 'funnels.offer_declined',
        UpsellDeclined::class => 'funnels.upsell_declined',
        FunnelCompleted::class => 'funnels.completed',
        FunnelSaved::class => 'funnels.funnel_saved',
    ];

    protected bool $booted = false;

    public static function available(): bool
    {
        return (bool) config('statamic-funnels.webhook_manager.enabled', true)
            && class_exists(self::FACADE)
            && interface_exists(self::TRIGGER_INTERFACE);
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->booted || ! static::available()) {
            return;
        }

        // Bound once the webhook manager's own provider has booted. Not yet:
        // stay unbooted so the retry can still register.
        if (! app()->bound('webhook-manager')) {
            return;
        }

        $this->booted = true;

        foreach (static::TRIGGERS as $eventClass => $handle) {
            try {
                WebhookManager::registerTrigger(new FunnelsTrigger($handle, 'statamic-funnels::webhooks.'.self::key($handle)));
            } catch (\Throwable $e) {
                Log::warning('Funnels → Webhook Manager: trigger ['.$handle.'] not registered: '.$e->getMessage());

                continue;
            }

            $events->listen($eventClass, function (object $event) use ($handle): void {
                $this->dispatch($handle, $event);
            });
        }
    }

    /** `funnels.upsell_declined` → `upsell_declined`, the label's translation key. */
    public static function key(string $handle): string
    {
        return substr($handle, strlen('funnels.'));
    }

    /**
     * In the event's brand: the webhook manager looks hooks up per brand, and
     * a paid event arrives from the provider's webhook with none current.
     * Never breaks the funnel.
     */
    protected function dispatch(string $handle, object $event): void
    {
        // After the write is committed: a moment inside a transaction that
        // is rolled back never happened. Outside a transaction this runs at
        // once.
        try {
            // What makes the moment unique, read now: a form's count of
            // earlier submissions changes as soon as the step writes its row.
            WebhookPayload::remember($event);

            DB::afterCommit(fn () => $this->deliver($handle, $event));
        } catch (\Throwable $e) {
            Log::warning('Funnels → Webhook Manager: ['.$handle.'] not dispatched: '.$e->getMessage());
        }
    }

    protected function deliver(string $handle, object $event): void
    {
        try {
            $trigger = WebhookManager::triggers()->get($handle);

            if ($trigger === null) {
                return;
            }

            // In the payment's brand, or not at all when that brand cannot be
            // set: the only brand left would be whichever is current.
            WebhookPayload::runForBrand(
                WebhookPayload::brandId($event),
                fn () => event(new TriggerDetected($trigger->build($event))),
                $handle,
            );
        } catch (\Throwable $e) {
            Log::warning('Funnels → Webhook Manager: ['.$handle.'] not dispatched: '.$e->getMessage());
        }
    }
}
