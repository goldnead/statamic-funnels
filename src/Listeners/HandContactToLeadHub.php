<?php

namespace Goldnead\StatamicFunnels\Listeners;

use Goldnead\StatamicFunnels\Events\FunnelFormSubmitted;
use Goldnead\StatamicFunnels\Integrations\LeadHubBridge;

/** Autoloaded by core off the first parameter type below. */
class HandContactToLeadHub
{
    public function __construct(protected LeadHubBridge $bridge) {}

    public function handle(FunnelFormSubmitted $event): void
    {
        $this->bridge->capture($event->visit);
    }
}
