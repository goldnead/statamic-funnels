<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Models\Payment;

/**
 * Die Bestellung eines Laufs, fuer eine Danke-Seite und fuer eine Mail.
 *
 * **Warum es das braucht.** Der ganze Text nach einem bezahlten Kauf war
 * „Danke. Das war alles von dieser Seite." — kein Produkt, kein Betrag,
 * keine Bestellnummer. Wer gerade Geld ueberwiesen hat, bekam nichts, womit
 * er den Vorgang benennen koennte.
 *
 * **Nur bezahlt.** Eine Zahlung, die noch beim Anbieter haengt, ist keine
 * Bestellung; sie zu zeigen hiesse, dem Kaeufer eine Bestaetigung zu geben,
 * die der Webhook noch widerrufen kann.
 *
 * **Alle Zahlungen des Laufs, nicht nur die letzte.** Ein Upsell nach der
 * Danke-Seite ist ein zweiter Kauf im selben Weg. Wer nur `payment_id` liest,
 * zeigt genau den, den der Kaeufer zuletzt gemacht hat — und verschweigt den,
 * wegen dem er ueberhaupt hier ist.
 */
class OrderSummary
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forVisit(?FunnelVisit $visit): ?array
    {
        if ($visit === null) {
            return null;
        }

        $ids = array_values(array_filter(array_merge(
            array_values((array) (($visit->meta ?? [])['payments'] ?? [])),
            [$visit->payment_id],
        )));

        if ($ids === []) {
            return null;
        }

        $payments = Payment::query()
            ->with('items')
            ->whereIn('id', array_unique($ids))
            ->orderBy('id')
            ->get()
            ->filter(fn (Payment $payment) => $payment->isPaid());

        if ($payments->isEmpty()) {
            return null;
        }

        $lines = [];
        $total = 0;
        $currency = 'EUR';

        foreach ($payments as $payment) {
            $currency = strtoupper((string) ($payment->currency ?: $currency));
            $total += (int) $payment->amount_cent;

            // Zeilen, wo es welche gibt: ein Bump ist eine eigene Zeile und
            // gehoert einzeln aufgefuehrt. Eine Zahlung aus der Zeit vor
            // `payment_items` traegt ihre ganze Wahrheit im Handle — denselben
            // Rueckfall macht `InvoiceWriter::lines()`.
            if ($payment->items->isNotEmpty()) {
                foreach ($payment->items as $item) {
                    $lines[] = [
                        'name' => (string) ($item->name ?: $item->product),
                        'amount' => self::money((int) $item->amount_cent * max(1, (int) $item->quantity), $currency),
                        'quantity' => (int) $item->quantity,
                    ];
                }

                continue;
            }

            $lines[] = [
                'name' => (string) $payment->product,
                'amount' => self::money((int) $payment->amount_cent, $currency),
                'quantity' => 1,
            ];
        }

        return [
            // Die Nummer, mit der ein Kaeufer nachfragen kann. Die der ersten
            // Zahlung, weil das der Kauf ist, wegen dem er hier steht.
            'reference' => (string) $payments->first()->getKey(),
            'lines' => $lines,
            'total' => self::money($total, $currency),
            'currency' => $currency,
            'email' => $visit->email,
        ];
    }

    /**
     * Beispieldaten fuer die Vorschau einer Mail: das Angebot des Funnels als
     * eine bezahlte Zeile, damit `order.*` in der Vorschau nicht leer bleibt.
     *
     * @return array<string, mixed>
     */
    public static function sample(?string $name, ?int $cent, string $currency = 'EUR', ?string $email = null): array
    {
        $name = trim((string) $name) ?: __('statamic-funnels::messages.mail_sample_product');
        $cent ??= 4900;

        return [
            'reference' => '1234',
            'lines' => [['name' => $name, 'amount' => self::money($cent, $currency), 'quantity' => 1]],
            'total' => self::money($cent, $currency),
            'currency' => $currency,
            'email' => $email,
        ];
    }

    /**
     * Ein Betrag, deutsch geschrieben.
     *
     * Hier und nicht in der Vorlage, damit Bestelluebersicht, Kasse und Mail
     * dieselbe Schreibweise haben — `249.00` statt `249,00` war schon einmal
     * ein Fehler in diesem Addon.
     */
    public static function money(int $cent, string $currency): string
    {
        return number_format($cent / 100, 2, ',', '.').' '.$currency;
    }
}
