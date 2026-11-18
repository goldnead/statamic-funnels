<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Events\FunnelCompleted;
use Goldnead\StatamicFunnels\Jobs\SendFunnelMail;
use Goldnead\StatamicFunnels\Listeners\AdvanceOnPayment;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelMailDelivery;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelMailRenderer;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Queue\Jobs\FakeJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\ExpectationFailedException;

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
        // Der Befehl zaehlt nicht selbst: gezaehlt wird jeder echte Sendeversuch im Job.
        $this->assertSame(0, $delivery->fresh()->attempts);
    }

    #[Test]
    public function eine_mail_im_backoff_wird_nicht_ein_zweites_mal_gestellt(): void
    {
        Bus::fake();
        $this->delivery();

        // Der Job laeuft (hier: noch nicht) und wartet im Backoff von
        // SendFunnelMail; der naechste Lauf darf ihn nicht verdoppeln.
        $this->artisan('funnels:retry-mails', ['--min-age' => 0])->assertSuccessful();
        $this->artisan('funnels:retry-mails', ['--min-age' => 0])->assertSuccessful();

        Bus::assertDispatchedTimes(SendFunnelMail::class, 1);
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

    /** Ein Job auf einer Fake-Queue, dessen Versand immer scheitert. */
    protected function scheiterndenVersandLaufenLassen(FunnelMailDelivery $delivery): FakeJob|SendFunnelMail
    {
        $job = (new SendFunnelMail($delivery->id))->withFakeQueueInteractions();
        $renderer = \Mockery::mock(FunnelMailRenderer::class);
        $renderer->shouldReceive('render')->andReturn(['subject' => 'Hallo', 'html' => '<p>Hallo</p>']);
        $job->handle($renderer);

        return $job;
    }

    #[Test]
    public function eine_mail_scheitert_insgesamt_nach_hoechstens_drei_versuchen(): void
    {
        $versuche = 0;
        Mail::shouldReceive('to')->andReturnUsing(function () use (&$versuche) {
            $versuche++;
            throw new \RuntimeException('SMTP weg');
        });

        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->create(['node_key' => 'mail_1', 'type' => 'mail', 'label' => 'Mail', 'slug' => null, 'config' => ['recipient' => 'visitor']]);
        $visit = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => $this->token, 'email' => 'k@example.com']);
        $delivery = FunnelMailDelivery::create(['visit_id' => $visit->id, 'funnel_id' => $funnel->id, 'node_key' => 'mail_1', 'queued_at' => now()]);

        // Erster Lauf (Job mit Backoff) und danach alle Nachhol-Laeufe, solange etwas eingereiht wird.
        for ($runde = 0; $runde < 12; $runde++) {
            $delivery->refresh();
            if ($delivery->sent_at || ($delivery->failed_at === null && $runde > 0 && $delivery->attempts >= 3)) {
                break;
            }
            $job = $this->scheiterndenVersandLaufenLassen($delivery);
            $delivery->refresh();
            if ($delivery->failed_at === null) {
                continue; // freigegeben, naechster Versuch im Backoff
            }
            Bus::fake();
            $this->artisan('funnels:retry-mails', ['--min-age' => 0])->assertSuccessful();
            try {
                Bus::assertNotDispatched(SendFunnelMail::class);
                break;
            } catch (ExpectationFailedException) {
                // wurde erneut eingereiht, der naechste Durchlauf simuliert den Job
            }
        }

        $this->assertSame(3, $versuche);
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

    #[Test]
    public function der_abschluss_laeuft_erst_nach_dem_commit_des_kaufs(): void
    {
        Event::listen(PaymentPaid::class, [AdvanceOnPayment::class, 'handle']);
        $this->kasse();

        $this->bisZurKasse();
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);

        // Der Test selbst laeuft in einer Transaktion; maßgeblich ist, dass
        // der Listener keine zusaetzliche, noch offene Ebene sieht.
        $basis = DB::transactionLevel();
        $ebenen = [];
        Event::listen(FunnelCompleted::class, function () use (&$ebenen) {
            $ebenen[] = DB::transactionLevel();
        });

        $this->bezahlen(Payment::query()->firstOrFail());

        $this->assertSame([$basis], $ebenen, 'FunnelCompleted muss genau einmal und ausserhalb der Kauf-Transaktion laufen.');
    }
}
