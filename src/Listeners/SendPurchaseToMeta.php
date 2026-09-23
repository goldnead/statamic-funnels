<?php

namespace Goldnead\StatamicFunnels\Listeners;

use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\Tracking;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Purchase an die Meta Conversions API, wenn das Geld da ist (F7).
 *
 * Am Ereignis von payments, nicht an der Rueckkehr vom Anbieter: wer den Tab
 * schliesst, hat trotzdem gekauft. payments muss dafuer nichts wissen.
 * Die Ereignis-ID ist `purchase-<Zahlung>`, dieselbe, die der Pixel auf der
 * Danke-Seite nennt; Meta legt beide zusammen.
 *
 * Autoloaded by core off the first parameter type below.
 */
class SendPurchaseToMeta
{
    public function handle(PaymentPaid $event): void
    {
        try {
            $visit = FunnelVisit::query()->where('payment_id', $event->payment->getKey())->first();

            if (! $visit) {
                return;
            }

            Tracking::purchase($visit, $event->payment);
        } catch (Throwable $e) {
            // Ein Werbeereignis darf den Webhook nicht umwerfen; der Kauf ist
            // wichtiger als seine Meldung.
            Log::warning('statamic-funnels: der Kauf konnte nicht an Meta gemeldet werden.', [
                'payment_id' => $event->payment->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
