<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\FollowUp;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function __construct(protected FollowUp $followUp, protected Fulfilment $fulfilment) {}

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

        $previous = $this->abgerechnet($previous);

        return $this->followUp->eligible($previous, $visit->email)
            ? $previous
            : null;
    }

    /**
     * Was die Seite darueber sagen muss, oder null.
     *
     * `last4` und `label` koennen fehlen, auch wenn abgebucht wird: bei
     * Zahlungsarten ohne Karte gibt es sie nicht, bei Wallet-Zahlungen nennt
     * der Anbieter oft nur die Marke, und bei Zahlungen von vor dieser Fassung
     * wurden sie nicht mitgeschrieben. Die Ankuendigung bleibt trotzdem
     * Pflicht — dann eben ohne die vier Ziffern.
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

    /**
     * Den Anbieter fragen, wenn der Ruecksprung den Webhook ueberholt hat.
     *
     * Der Kaeufer kommt vom Bezahldienst direkt auf den naechsten Schritt
     * zurueck, und der Webhook, der die Zahlung auf `paid` setzt, ist in dem
     * Moment oft noch unterwegs. Beim Kauftest am 02.09.2026 lagen zwischen
     * beidem weniger als eine Sekunde — und in dieser Sekunde rendert die
     * Seite. Ohne diese Frage sieht **jeder erste Kaeufer** den Upsell ohne
     * das Ein-Klick-Versprechen und erst nach einem Neuladen mit.
     *
     * Das Schlimmere ist nicht der fehlende Satz, sondern was danach passiert:
     * bis zum Klick ist der Webhook da, die Aktion nimmt den Ein-Klick-Weg,
     * und abgebucht wird etwas, das die Seite nie angekuendigt hat. Genau
     * dagegen ist diese Klasse geschrieben.
     *
     * `Fulfilment::handle()` ist derselbe Weg, den der Webhook nimmt, und
     * gegen Doppelausfuehrung gesichert (`fulfilled_at` wird als Anspruch
     * gesetzt). Er wird nur gefragt, wenn eine Antwort etwas aendern kann:
     * eine Zahlung, die beim Anbieter liegt und noch nicht bezahlt gemeldet
     * ist. Eine abgelaufene, abgebrochene oder gescheiterte Zahlung wird nicht
     * noch einmal angefasst, und `initiated` heisst, dass der Anbieter sie
     * ueberhaupt noch nicht kennt.
     *
     * Wirft etwas, bleibt es beim bekannten Stand. Ein Listener, der beim
     * Erfuellen scheitert, gibt seinen Anspruch zurueck und wirft weiter —
     * hier darf das keine oeffentliche Seite in einen Fehler kippen. Der
     * Webhook stellt erneut zu.
     */
    protected function abgerechnet(Payment $payment): Payment
    {
        if ($payment->status !== Payment::STATUS_OPEN) {
            return $payment;
        }

        $providerId = trim($payment->provider_id);

        if ($providerId === '' || str_starts_with($providerId, 'pending-')) {
            return $payment;
        }

        try {
            return $this->fulfilment->handle($providerId) ?? $payment;
        } catch (Throwable $e) {
            Log::warning('statamic-funnels: the provider could not settle this payment while a step was rendering.', [
                'payment_id' => $payment->getKey(),
                'provider_id' => $providerId,
                'exception' => $e->getMessage(),
            ]);

            return $payment->fresh() ?? $payment;
        }
    }
}
