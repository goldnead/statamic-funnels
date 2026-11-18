<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Jobs\SendFunnelMail;
use Goldnead\StatamicFunnels\Listeners\AdvanceOnPayment;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelMailDelivery;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Zwei stille Fehler: eine fehlgeschlagene Mail wurde nie wieder gestellt, und
 * ein Kauf am Ende des Funnels schloss den Besuch nicht ab.
 */
class MailNachholenUndAbschlussTest extends TestCase
{
    use WalksAFunnel;

    protected function delivery(array $attrs = []): FunnelMailDelivery
    {
        $funnel = Funnel::query()->first()
            ?? Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $visit = FunnelVisit::query()->first()
            ?? FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => $this->token, 'email' => 'k@example.com']);

        return FunnelMailDelivery::create(array_merge([
            'visit_id' => $visit->id,
            'funnel_id' => $funnel->id,
            'node_key' => 'mail_1',
            'queued_at' => now()->subHour(),
            'failed_at' => now()->subHour(),
            'error' => 'Verbindung abgelehnt',
        ], $attrs));
    }

    #[Test]
    public function eine_fehlgeschlagene_mail_wird_erneut_eingereiht(): void
    {
        Bus::fake();
        $delivery = $this->delivery();

        $this->artisan('funnels:retry-mails')->assertSuccessful();

        Bus::assertDispatched(SendFunnelMail::class, fn ($job) => $job->deliveryId === $delivery->id);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    #[Test]
    public function nach_der_grenze_wird_nicht_mehr_eingereiht(): void
    {
        Bus::fake();
        $this->delivery(['attempts' => 3]);

        $this->artisan('funnels:retry-mails', ['--max-tries' => 3])->assertSuccessful();

        Bus::assertNotDispatched(SendFunnelMail::class);
    }

    #[Test]
    public function gesendete_und_frische_fehlschlaege_bleiben_liegen(): void
    {
        Bus::fake();
        $this->delivery(['node_key' => 'a', 'sent_at' => now()->subMinutes(30)]);
        $this->delivery(['node_key' => 'b', 'failed_at' => now()->subSeconds(30)]);

        $this->artisan('funnels:retry-mails')->assertSuccessful();

        Bus::assertNotDispatched(SendFunnelMail::class);
    }

    #[Test]
    public function der_kauf_am_ende_des_funnels_schliesst_den_besuch_ab(): void
    {
        Event::listen(PaymentPaid::class, [AdvanceOnPayment::class, 'handle']);
        $this->kasse();

        $this->bisZurKasse();
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);
        $this->bezahlen(Payment::query()->firstOrFail());

        $this->assertNotNull($this->besuch()->completed_at);
    }
}
