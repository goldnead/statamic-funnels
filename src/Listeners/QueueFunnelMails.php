<?php

namespace Goldnead\StatamicFunnels\Listeners;

use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Events\FunnelOfferDeclined;
use Goldnead\StatamicFunnels\Events\FunnelStepEntered;
use Goldnead\StatamicFunnels\Support\MailTrigger;

/**
 * Die drei Momente, an denen ein Mail-Knoten feuert.
 *
 * - Ein Schritt wird betreten → Mails am Ausgang `default`. Am Abschluss ist
 *   das der Moment, in dem der Weg zu Ende ist; ein eigenes Ereignis dafuer
 *   braucht es nicht, weil das Betreten des Abschlusses der Abschluss ist.
 * - Ein Angebot wird bezahlt → `accepted`. Ueber das Ereignis, das erst der
 *   Webhook ausloest, nie ueber den Klick.
 * - Ein Angebot wird abgelehnt → `declined`.
 *
 * Autoloaded by core off the first parameter type of each `handle*` method.
 */
class QueueFunnelMails
{
    public function __construct(protected MailTrigger $trigger) {}

    public function handleEntered(FunnelStepEntered $event): void
    {
        $this->trigger->fire($event->visit, $event->step, 'default');
    }

    public function handleAccepted(FunnelOfferAccepted $event): void
    {
        $this->trigger->fire($event->visit, $event->step, 'accepted');
    }

    public function handleDeclined(FunnelOfferDeclined $event): void
    {
        $this->trigger->fire($event->visit, $event->step, 'declined');
    }
}
