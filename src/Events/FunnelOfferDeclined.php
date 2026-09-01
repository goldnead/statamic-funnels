<?php

namespace Goldnead\StatamicFunnels\Events;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Ein Angebot im Funnel wurde abgelehnt.
 *
 * Ablehnen ist eine Antwort, kein Fehler — und die haeufigere. Bis hierher gab
 * es fuer sie kein Ereignis, nur die Wegmarke; ein Mail-Knoten am Ausgang
 * `declined` braucht aber einen Moment, an dem er feuern kann.
 */
class FunnelOfferDeclined
{
    use Dispatchable;

    public function __construct(
        public readonly FunnelVisit $visit,
        public readonly FunnelStep $step,
    ) {}
}
