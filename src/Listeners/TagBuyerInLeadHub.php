<?php

namespace Goldnead\StatamicFunnels\Listeners;

use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Integrations\LeadHubBridge;

/**
 * Wer gekauft hat, ist im CRM als Kunde lesbar — getrennt davon, ob er Post will.
 *
 * Autoloaded by core off the first parameter type below.
 */
class TagBuyerInLeadHub
{
    public function __construct(protected LeadHubBridge $bridge) {}

    public function handle(FunnelOfferAccepted $event): void
    {
        $this->bridge->purchased($event->visit);
    }
}
