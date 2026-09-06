<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\FollowUp;

/**
 * Ob dieser Schritt ohne erneute Karteneingabe abbuchen wuerde — und womit.
 *
 * Zwei Stellen brauchen eine Antwort: die Seite, die es dem Kaeufer vorher
 * sagen muss, und die Aktion, die es danach tut. Sie stehen hier zusammen,
 * damit sie nicht auseinanderlaufen — das Auseinanderlaufen ist nicht
 * kosmetisch, sondern der Unterschied zwischen einer angekuendigten und einer
 * stillen Abbuchung.
 *
 * **Sie sind trotzdem nicht dieselbe Frage, und der Unterschied ist Absicht.**
 * Die Aktion fragt {@see FollowUp::eligible()}, also auch, ob die erste Zahlung
 * schon bezahlt gemeldet ist. Die Ankuendigung fragt das nicht. Der Kaeufer
 * kommt vom Bezahldienst zurueck, waehrend der Webhook noch unterwegs ist —
 * beim Kauftest am 02.09.2026 lagen zwischen beidem weniger als eine Sekunde —,
 * und in dieser Sekunde rendert die Seite. Haengt der Satz an `isPaid()`, sieht
 * **jeder erste Kaeufer** den Upsell ohne das Ein-Klick-Versprechen; beim Klick
 * ist der Webhook dann da und es wird per Mandat abgebucht, ohne dass die Seite
 * es je gesagt hat. Das ist die gefaehrliche Richtung, und sie ist damit zu.
 *
 * Die andere Richtung ist harmlos: angekuendigt, aber beim Klick immer noch
 * nicht bezahlt — dann gibt `eligible()` null zurueck, und der Kaeufer bekommt
 * die normale Kasse statt eines Ein-Klicks. Eine Unbequemlichkeit, keine
 * Abbuchung.
 *
 * Das Mandat traegt die Ankuendigung, und es steht rechtzeitig da:
 * `customer_reference` wird **vor** dem Sprung zum Anbieter geschrieben, im
 * selben Block, der `sequenceType: first` setzt (`Checkout::start()`). Beim
 * Ruecksprung steht die Spalte also, unabhaengig vom Webhook — ohne dass hier
 * jemand den Anbieter fragen muesste.
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
     *
     * Die strenge Frage, fuer die Aktion. Sie schliesst `isPaid()` ein.
     */
    public function chargeableFrom(?FunnelVisit $visit): ?Payment
    {
        $previous = $this->vorgaenger($visit);

        if (! $previous) {
            return null;
        }

        return $this->followUp->eligible($previous, $visit?->email)
            ? $previous
            : null;
    }

    /**
     * Was die Seite darueber sagen muss, oder null.
     *
     * `last4` und `label` koennen fehlen, auch wenn abgebucht wird: bei
     * Zahlungsarten ohne Karte gibt es sie nicht, bei Wallet-Zahlungen nennt
     * der Anbieter oft nur die Marke, bei Zahlungen von vor dieser Fassung
     * wurden sie nicht mitgeschrieben — und in der Sekunde nach dem
     * Ruecksprung hat der Webhook sie noch nicht geschrieben. Die Ankuendigung
     * bleibt in allen vier Faellen Pflicht, dann eben ohne die vier Ziffern.
     *
     * @return array{last4: string|null, label: string|null}|null
     */
    public function forTemplate(?FunnelVisit $visit): ?array
    {
        $previous = $this->vorgaenger($visit);

        if (! $previous || ! $this->ankuendbar($previous, $visit?->email)) {
            return null;
        }

        // Die Ziffern nur, wenn auch belegt ist, dass GENAU diese Karte
        // belastet wird.
        //
        // `mandate_id` sagt, welches Einzugsrecht die Folgeabbuchung benennt
        // (statamic-payments, seit 06.09.2026). Steht die Spalte leer, reicht
        // payments nur die Kundenkennung weiter und der Anbieter waehlt selbst
        // — dann sind „•••• 9996" eine Behauptung ueber eine Karte, die es
        // vielleicht nicht wird.
        //
        // Betroffen sind vor allem Zeilen aus dem Fenster zwischen dem 31.08.
        // (seit da gibt es `card_last4`) und dem 06.09. (seit da `mandate_id`).
        // Klein, aber nicht null.
        //
        // Was dann passiert, ist kein Verlust: `saved_card_unnamed` in der
        // Vorlage sagt weiter, dass ohne neue Karteneingabe abgebucht wird —
        // nur ohne die Ziffern. Der Ein-Klick bleibt, die Behauptung faellt
        // weg. Genau der Fall, fuer den die Vorlage ihre dritte Fassung schon
        // hat.
        //
        // **Nur wenn payments die Spalte ueberhaupt kennt.** Dieses Paket laeuft
        // auch gegen aeltere payments-Fassungen, und dort gibt es weder
        // `mandate_id` noch das Pinnen. Die Ziffern dann zu verschlucken waere
        // eine Verschlechterung ohne Gegenwert: die Ankuendigung wuerde
        // vager, ohne dass die Abbuchung genauer wird. Also: kennt payments
        // Mandate, muss eines dastehen; kennt es sie nicht, bleibt es beim
        // alten Verhalten.
        $kenntMandate = array_key_exists('mandate_id', $previous->getAttributes());

        $belegt = ! $kenntMandate
            || (is_string($previous->mandate_id) && trim($previous->mandate_id) !== '');

        return [
            'last4' => $belegt ? $previous->card_last4 : null,
            'label' => $belegt ? $previous->card_label : null,
        ];
    }

    /**
     * Ob die Seite den Ein-Klick ankuendigen muss.
     *
     * `eligible()` ohne `isPaid()`, und sonst nichts weggelassen: dieselbe
     * Marken-Schaltung, dasselbe Mandat, derselbe Mensch. Eine gescheiterte,
     * abgelaufene oder abgebrochene Zahlung kuendigt nichts an — aus der wird
     * nie eine Abbuchung, und ein Satz darueber waere schlicht falsch.
     */
    protected function ankuendbar(Payment $previous, ?string $email): bool
    {
        $endgueltig = [
            Payment::STATUS_FAILED,
            Payment::STATUS_EXPIRED,
            Payment::STATUS_CANCELED,
        ];

        return $this->followUp->available()
            && is_string($previous->customer_reference)
            && trim($previous->customer_reference) !== ''
            && ! in_array($previous->status, $endgueltig, true)
            && $this->followUp->sameBuyer($previous, $email);
    }

    /** Die Zahlung, die dieser Besuch mitbringt. */
    protected function vorgaenger(?FunnelVisit $visit): ?Payment
    {
        if (! $visit || ! $visit->payment_id) {
            return null;
        }

        return Payment::find($visit->payment_id);
    }
}
