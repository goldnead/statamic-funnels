<?php

namespace Goldnead\StatamicFunnels\Contracts;

use Goldnead\StatamicFunnels\Integrations\MetaConversions;

/**
 * Wer Ereignisse serverseitig an eine Werbeplattform meldet.
 *
 * Meta ist der erste Fall und bisher der einzige ({@see MetaConversions}).
 * Die Schnittstelle steht, damit ein zweites Ziel danebenpasst; gebaut wird
 * es erst, wenn jemand es braucht.
 */
interface ConversionSender
{
    /**
     * Ein Ereignis senden. True nur, wenn die Plattform es angenommen hat.
     *
     * @param  array<string, mixed>  $event  Die Form der Meta Conversions API: `event_name`,
     *                                       `event_time`, `event_id`, `action_source`,
     *                                       `event_source_url`, `user_data`, `custom_data`.
     */
    public function send(string $pixelId, array $event): bool;

    /** Ob dieser Sender ueberhaupt senden kann (Zugangsschluessel gesetzt). */
    public function enabled(): bool;
}
