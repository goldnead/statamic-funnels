<?php

namespace Goldnead\StatamicFunnels\Listeners;

use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Support\MailTrigger;

/**
 * Der eine Ausgang, der nicht ueber `FunnelWalk::advance()` genommen wird.
 *
 * `default` und `declined` feuern dort, wo der Weg sie nimmt ({@see
 * \Goldnead\StatamicFunnels\Support\FunnelWalk::advance()}). `accepted` nimmt
 * niemand per Klick: das entscheidet der Webhook, und das Ereignis dafuer ist
 * `FunnelOfferAccepted` — auf beiden Kaufwegen, genau einmal.
 *
 * Autoloaded by core off the first parameter type below.
 */
class QueueFunnelMails
{
    public function __construct(protected MailTrigger $trigger) {}

    public function handle(FunnelOfferAccepted $event): void
    {
        $this->trigger->fire($event->visit, $event->step, 'accepted');
    }
}
