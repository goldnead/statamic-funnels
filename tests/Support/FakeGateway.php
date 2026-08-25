<?php

namespace Goldnead\StatamicFunnels\Tests\Support;

use Goldnead\StatamicPayments\Contracts\PaymentGateway;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\CheckoutSession;
use Goldnead\StatamicPayments\Support\RemotePayment;
use RuntimeException;

/** Stands in for the provider so no test here needs the network. */
class FakeGateway implements PaymentGateway
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

    public function markPaid(string $providerId, ?string $email = null): void
    {
        $this->remote[$providerId] = new RemotePayment($providerId, Payment::STATUS_PAID, [], $email);
    }
}
