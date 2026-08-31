<?php

namespace Goldnead\StatamicFunnels\Tests\Support;

use Goldnead\StatamicPayments\Contracts\FollowUpGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;
use RuntimeException;

/**
 * Stands in for the provider so no test here needs the network.
 *
 * Kann auch nachfassen. Solange es das nicht konnte, war der Zweig, der eine
 * gespeicherte Karte belastet, aus jedem Test dieses Pakets heraus
 * unerreichbar — und genau dort sass der Fehler, bei dem die zweite Person am
 * selben Rechner auf die Karte der ersten gebucht wurde.
 */
class FakeGateway implements FollowUpGateway
{
    /** @var array<string, RemotePayment> */
    public array $remote = [];

    public int $created = 0;

    /** What the last `createPayment()` was handed, so a test can look at it. */
    public array $lastPayload = [];

    public function provider(): string
    {
        return 'fake';
    }

    public function createPayment(array $payload): CheckoutSession
    {
        $this->lastPayload = $payload;
        $this->created++;
        $id = 'tr_'.$this->created;
        $this->remote[$id] = new RemotePayment($id, Payment::STATUS_OPEN);

        return new CheckoutSession($id, 'https://checkout.example/'.$id);
    }

    public function fetch(string $providerId): RemotePayment
    {
        return $this->remote[$providerId] ?? throw new RuntimeException('no such payment');
    }

    public function markPaid(string $providerId, ?string $email = null, ?string $cardLast4 = null, ?string $cardLabel = null): void
    {
        $this->remote[$providerId] = new RemotePayment(
            providerId: $providerId,
            status: Payment::STATUS_PAID,
            metadata: $this->metadata[$providerId] ?? [],
            email: $email,
            cardLast4: $cardLast4,
            cardLabel: $cardLabel,
        );
    }

    /** @var array<string, array<string, mixed>> */
    public array $metadata = [];

    /** Customer ids the provider will accept a second charge for. */
    public array $mandates = [];

    public bool $refuseFollowUp = false;

    public bool $refuseToRemember = false;

    public function supportsFollowUp(): bool
    {
        return true;
    }

    public function rememberBuyer(array $buyer): string
    {
        if ($this->refuseToRemember) {
            throw new RuntimeException('the provider would not remember this buyer');
        }

        $reference = 'cst_'.(count($this->mandates) + 1);
        $this->mandates[] = $reference;

        return $reference;
    }

    public function chargeAgain(string $customerReference, array $payload): RemotePayment
    {
        if ($this->refuseFollowUp || ! in_array($customerReference, $this->mandates, true)) {
            // Was Mollie tut, wenn es kein Mandat gibt: es lehnt ab. Und das
            // ist richtig — kein Mandat heisst, der Kaeufer hat nie zugestimmt.
            throw new RuntimeException('no mandate for '.$customerReference);
        }

        $this->created++;
        $id = 'tr_folge_'.$this->created;
        $this->metadata[$id] = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];

        // Bewusst nicht `paid`: eine wiederkehrende Abbuchung wird erst
        // angenommen und spaeter bestaetigt. Ein Double, das sofort `paid`
        // sagt, verdeckt jeden Fehler, der Annahme fuer Zahlung haelt.
        $this->remote[$id] = new RemotePayment($id, Payment::STATUS_OPEN, $this->metadata[$id]);

        return $this->remote[$id];
    }
}
