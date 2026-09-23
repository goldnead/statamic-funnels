<?php

namespace Goldnead\StatamicFunnels\Events;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ein Upsell wurde abgelehnt: ein Nein auf ein Angebot, nachdem in diesem Lauf
 * schon etwas bezahlt ist.
 *
 * Zusaetzlich zu {@see FunnelOfferDeclined}, das bei jedem Nein feuert. Dieses
 * hier ist fuer die Frage „wer hat gekauft und das Zusatzangebot
 * ausgeschlagen" (automations, A2): die Kaeuferin ist bekannt, der Kauf davor
 * auch.
 *
 * - `visit`: der Lauf, mit Adresse und Name der Kaeuferin.
 * - `step`: der Angebotsschritt, auf dem abgelehnt wurde.
 * - `offerHandle`: das abgelehnte Angebot (statamic-offers).
 * - `payment`: der letzte bezahlte Kauf dieses Laufs davor.
 */
class UpsellDeclined
{
    use Dispatchable;

    public function __construct(
        public readonly FunnelVisit $visit,
        public readonly FunnelStep $step,
        public readonly string $offerHandle,
        public readonly ?Payment $payment,
    ) {}
}
