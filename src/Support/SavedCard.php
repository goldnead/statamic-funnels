<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\FollowUp;

/**
 * Ob dieser Schritt ohne erneute Karteneingabe abbuchen wuerde — und womit.
 *
 * Es gibt genau eine Antwort auf diese Frage, und zwei Stellen brauchen sie:
 * die Seite, die es dem Kaeufer vorher sagen muss, und die Aktion, die es
 * danach tut. Standen sie getrennt, konnten sie auseinanderlaufen — und das
 * Auseinanderlaufen ist hier nicht kosmetisch, sondern der Unterschied
 * zwischen einer angekuendigten und einer stillen Abbuchung.
 *
 * Der Kaeufer wird an der Adresse erkannt, nicht am Geraet. Ein Mandat gehoert
 * dem Menschen; wer den Besuchs-Cookie dafuer nimmt, bucht der zweiten Person
 * am selben Rechner die Karte der ersten ab.
 */
final class SavedCard
{
    public function __construct(protected FollowUp $followUp) {}

    /**
     * Die Zahlung, gegen die hier abgebucht wuerde. Null heisst: normale Kasse.
     */
    public function chargeableFrom(?FunnelVisit $visit): ?Payment
    {
        if (! $visit || ! $visit->payment_id) {
            return null;
        }

        $previous = Payment::find($visit->payment_id);

        if (! $previous) {
            return null;
        }

        return $this->followUp->eligible($previous, $visit->email)
            ? $previous
            : null;
    }

    /**
     * Was die Seite darueber sagen muss, oder null.
     *
     * `last4` und `label` koennen fehlen, auch wenn abgebucht wird: bei
     * Zahlungsarten ohne Karte gibt es sie nicht, und bei Zahlungen von vor
     * dieser Fassung wurden sie nicht mitgeschrieben. Die Ankuendigung bleibt
     * trotzdem Pflicht — dann eben ohne die vier Ziffern.
     *
     * @return array{last4: string|null, label: string|null}|null
     */
    public function forTemplate(?FunnelVisit $visit): ?array
    {
        $previous = $this->chargeableFrom($visit);

        if (! $previous) {
            return null;
        }

        return [
            'last4' => $previous->card_last4,
            'label' => $previous->card_label,
        ];
    }
}
