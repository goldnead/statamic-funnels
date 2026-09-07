<?php

namespace Goldnead\StatamicFunnels\Tests\Support;

use Goldnead\StatamicPayments\Contracts\SubscriptionGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Models\Subscription;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;
use Goldnead\StatamicPayments\Support\RemoteSubscription;
use RuntimeException;

/**
 * Stands in for the provider so no test here needs the network.
 *
 * Kann auch nachfassen. Solange es das nicht konnte, war der Zweig, der eine
 * gespeicherte Karte belastet, aus jedem Test dieses Pakets heraus
 * unerreichbar — und genau dort sass der Fehler, bei dem die zweite Person am
 * selben Rechner auf die Karte der ersten gebucht wurde.
 *
 * **Und Abos, seit 07.09.2026.** Dieselbe Geschichte ein zweites Mal: solange
 * das Double nur `FollowUpGateway` war, gab `Subscriptions::available()` false,
 * und jede Kasse mit einem Ratenangebot lief hier in die Ablehnung. Der Zweig,
 * der aus einer Ratenoption eine Vereinbarung macht, war damit aus keinem Test
 * dieses Pakets erreichbar — waehrend Mollie ihn im Betrieb sehr wohl geht.
 * Ein Double, das weniger kann als der Anbieter, macht den Unterschied
 * unsichtbar statt sichtbar.
 */
class FakeGateway implements SubscriptionGateway
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

    /**
     * Eine bezahlte Zahlung, wie der Anbieter sie meldet.
     *
     * **Das Mandat gehoert dazu.** Mollie legt bei einer `sequenceType: first`
     * eines an und nennt es in der Antwort; seit payments 1.19 belastet die
     * Folgeabbuchung genau dieses angekuendigte Mandat, und `SavedCard` nennt
     * die Kartenziffern nur, wenn eines auf der Zeile steht. Ein Double ohne
     * Mandat sagt also „bezahlt, aber nichts gemerkt" — ein Zustand, den es
     * beim Anbieter nicht gibt, und der den ganzen Ein-Klick-Zweig dieses
     * Pakets unsichtbar wirken laesst.
     *
     * Wer den Fall ohne Mandat prueft (Wallet, altes payments), gibt hier
     * ausdruecklich `mandateId: null` mit.
     */
    public function markPaid(
        string $providerId,
        ?string $email = null,
        ?string $cardLast4 = null,
        ?string $cardLabel = null,
        ?string $mandateId = 'mdt_1',
    ): void {
        $this->remote[$providerId] = new RemotePayment(
            providerId: $providerId,
            status: Payment::STATUS_PAID,
            metadata: $this->metadata[$providerId] ?? [],
            email: $email,
            cardLast4: $cardLast4,
            cardLabel: $cardLabel,
            mandateId: $mandateId,
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

    public bool $refuseSubscriptions = false;

    /** @var array<string, RemoteSubscription> */
    public array $subscriptions = [];

    /** Was der letzte `createSubscription()` bekam, damit ein Test hinsehen kann. */
    public array $lastSubscriptionPayload = [];

    public function supportsSubscriptions(): bool
    {
        return ! $this->refuseSubscriptions;
    }

    public function createSubscription(string $customerReference, array $payload): RemoteSubscription
    {
        if ($this->refuseSubscriptions || ! in_array($customerReference, $this->mandates, true)) {
            // Wie Mollie: ohne Mandat keine Vereinbarung. Ein Double, das hier
            // nachgibt, laesst genau den Fall durch, der im Betrieb monatelang
            // still scheitert.
            throw new RuntimeException('no mandate for '.$customerReference);
        }

        $this->lastSubscriptionPayload = $payload;
        $id = 'sub_'.(count($this->subscriptions) + 1);

        // `pending`, nicht `active`: der Anbieter nimmt die Vereinbarung an und
        // bucht erst zum Termin ab.
        $this->subscriptions[$id] = new RemoteSubscription(
            providerId: $id,
            status: Subscription::STATUS_PENDING,
            nextPaymentAt: $payload['startDate'] ?? null,
        );

        return $this->subscriptions[$id];
    }

    public function cancelSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        $this->subscriptions[$subscriptionId] = new RemoteSubscription(
            providerId: $subscriptionId,
            status: Subscription::STATUS_CANCELLED,
        );

        return $this->subscriptions[$subscriptionId];
    }

    public function fetchSubscription(string $customerReference, string $subscriptionId): RemoteSubscription
    {
        return $this->subscriptions[$subscriptionId]
            ?? throw new RuntimeException('no such subscription');
    }
}
