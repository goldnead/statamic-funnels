<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\BrandContext\Models\Brand;
use Goldnead\StatamicFunnels\Events\FunnelFormSubmitted;
use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Events\FunnelSaved;
use Goldnead\StatamicFunnels\Integrations\WebhookManager\FunnelsTrigger;
use Goldnead\StatamicFunnels\Integrations\WebhookManager\WebhookManagerBridge;
use Goldnead\StatamicFunnels\Integrations\WebhookManager\WebhookPayload;
use Goldnead\StatamicFunnels\Listeners\AdvanceOnPayment;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\GraphWriter;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\WebhookManager\Domain\OutboundWebhook\Models\OutboundWebhook;
use Goldnead\WebhookManager\Events\TriggerDetected;
use Goldnead\WebhookManager\Facades\WebhookManager;
use Goldnead\WebhookManager\Jobs\ProcessOutboundDeliveryJob;
use Goldnead\WebhookManager\ValueObjects\TriggerEvent;
use Goldnead\WebhookManager\WebhookManagerServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\CP\Nav;

/**
 * Der echte statamic-webhook-manager neben diesem Addon, ohne Fakes: sein
 * Provider, seine Tabellen, seine Zustellkette. Die Site ohne ihn prüft
 * {@see BootWithoutWebhookManagerTest} in einem eigenen Prozess.
 */
class WebhookManagerBridgeTest extends TestCase
{
    use WalksAFunnel;

    protected function getPackageProviders($app)
    {
        return array_merge(parent::getPackageProviders($app), [WebhookManagerServiceProvider::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../../vendor/goldnead/statamic-webhook-manager/database/migrations');

        // AddonTestCase tauscht Nav gegen einen strengen Mock; die Navigation
        // des Webhook-Managers ist hier nicht das Thema.
        Nav::shouldReceive('extend');

        $provider = $this->app->getProvider(WebhookManagerServiceProvider::class);
        $provider->bootAddon();

        // Statamic verdrahtet die $listen-Liste eines Addons (TriggerDetected →
        // DispatchTriggerListener) aus einem booted-Callback, den Testbench nie
        // auslöst.
        (new \ReflectionMethod($provider, 'bootEvents'))->invoke($provider);

        // Beide booted-Versuche der Brücke liefen vor dem bootAddon() oben und
        // gaben auf, wie auf einer Site, auf der der Webhook-Manager später
        // bootet. Das hier ist der Retry, den eine Site bekommt.
        $this->app->make(WebhookManagerBridge::class)->boot($this->app->make('events'));

        // Auf einer Site findet Statamic diesen Listener in src/Listeners, aus
        // demselben booted-Callback. Er macht aus „bezahlt" den Kauf im Funnel.
        Event::listen(PaymentPaid::class, [AdvanceOnPayment::class, 'handle']);
    }

    protected function hook(string $trigger, string $handle = 'hook'): OutboundWebhook
    {
        return OutboundWebhook::create([
            'uuid' => (string) Str::uuid(),
            'name' => $handle,
            'handle' => $handle,
            'enabled' => true,
            'trigger_type' => $trigger,
            'url' => 'https://example.test/'.$handle,
            'method' => 'POST',
            'payload_type' => 'raw_json',
            'queue_enabled' => true,
        ]);
    }

    /** @return array<string, list<TriggerEvent>> */
    protected function detected(): array
    {
        return Event::dispatched(TriggerDetected::class)
            ->map(fn ($args) => $args[0]->trigger)
            ->groupBy('triggerHandle')
            ->map->values()->map->all()->all();
    }

    protected function mitUpsell(): void
    {
        $funnel = $this->kasse();
        Offer::create(['handle' => 'cd', 'name' => 'Begleit-CD', 'product' => 'begleit-cd', 'slot' => 'post_purchase', 'active' => true]);
        $funnel->edges()->where('from_node_key', 'kasse')->where('from_output', 'accepted')->delete();
        $funnel->steps()->create(['node_key' => 'upsell', 'type' => 'offer', 'slug' => 'upsell', 'config' => ['offer' => 'cd']]);
        $funnel->edges()->create(['from_node_key' => 'kasse', 'to_node_key' => 'upsell', 'from_output' => 'accepted']);
        $funnel->edges()->create(['from_node_key' => 'upsell', 'to_node_key' => 'finish_1', 'from_output' => 'declined']);
    }

    #[Test]
    public function jedes_funnel_ereignis_ist_ein_ausloeser_mit_eigener_quelle(): void
    {
        $registry = WebhookManager::triggers();

        $this->assertCount(7, WebhookManagerBridge::TRIGGERS);

        foreach (WebhookManagerBridge::TRIGGERS as $handle) {
            $this->assertInstanceOf(FunnelsTrigger::class, $registry->get($handle));
            $this->assertSame('funnels', $registry->get($handle)->sourceType());
            $this->assertArrayHasKey($handle, $registry->options());
        }

        app()->setLocale('de');
        $this->assertSame('Funnels: Upsell nach Kauf abgelehnt', $registry->get('funnels.upsell_declined')->label());
        app()->setLocale('en');
        $this->assertSame('Funnels: upsell declined after a purchase', $registry->get('funnels.upsell_declined')->label());
    }

    #[Test]
    public function ein_ganzer_lauf_meldet_formular_kauf_und_abgelehnten_upsell_ohne_token(): void
    {
        $this->mitUpsell();
        Event::fake([TriggerDetected::class]);

        $this->bisZurKasse('k@example.com', ['name' => 'Kim Sopran', 'street' => 'Weg 1', 'postal_code' => '12345', 'city' => 'Ort']);
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);
        $zahlung = $this->bezahlen(Payment::query()->firstOrFail());
        $this->asVisitor()->get('/f/kurs/upsell');
        $this->asVisitor()->post('/f/kurs/upsell/advance', ['accept' => '0']);

        $events = $this->detected();
        $visit = FunnelVisit::query()->firstOrFail();

        foreach (['funnels.step_entered', 'funnels.form_submitted', 'funnels.offer_accepted', 'funnels.offer_declined', 'funnels.upsell_declined'] as $handle) {
            $this->assertArrayHasKey($handle, $events, $handle.' fehlt');
        }

        $form = $events['funnels.form_submitted'][0];
        $this->assertSame('funnels', $form->sourceType);
        $this->assertSame((string) $visit->funnel_id, $form->sourceReference);
        $this->assertSame('funnel', $form->payload['subject_type']);
        $this->assertSame($visit->funnel_id, $form->payload['subject_id']);
        $this->assertSame(['id' => $visit->id, 'email' => 'k@example.com', 'name' => 'Kim Sopran'], $form->payload['visit']);
        $this->assertSame(['key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'], $form->payload['step']);
        $this->assertSame('k@example.com', $form->payload['values']['email']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $form->payload['occurred_at']);

        $accepted = $events['funnels.offer_accepted'][0]->payload;
        $this->assertSame(['event', 'event_id', 'occurred_at', 'brand', 'subject_type', 'subject_id', 'funnel', 'step', 'visit', 'offer', 'payment'], array_keys($accepted));
        $this->assertSame(['handle' => 'kurs'], $accepted['offer']);

        // Derselbe Zahlungsblock wie in den Webhooks von statamic-payments.
        $this->assertSame([
            'id', 'provider', 'provider_id', 'status', 'product', 'amount_cent', 'currency', 'discount_code',
            'discount_cent', 'refunded_cent', 'email', 'name', 'country', 'subscription_id', 'parent_payment_id',
            'items', 'attribution', 'created_at', 'paid_at', 'refunded_at', 'charged_back_at',
        ], array_keys($accepted['payment']));
        $this->assertSame($zahlung->id, $accepted['payment']['id']);
        $this->assertSame('offer:kurs', $accepted['payment']['product']);
        $this->assertSame(9900, $accepted['payment']['amount_cent']);
        $this->assertSame($zahlung->currency, $accepted['payment']['currency']);
        $this->assertSame($zahlung->paid_at?->format(\DATE_ATOM), $accepted['payment']['paid_at']);
        $this->assertSame('offer:kurs', $accepted['payment']['items'][0]['product']);
        $this->assertArrayNotHasKey('card_last4', $accepted['payment']);
        $this->assertArrayNotHasKey('meta', $accepted['payment']);
        // Eine Marke nur, wenn es eine gibt: payments stempelt 0 für „keine".
        $this->assertTrue($accepted['brand'] === null || $accepted['brand']['id'] > 0);

        $upsell = $events['funnels.upsell_declined'][0]->payload;
        $this->assertSame(['handle' => 'cd'], $upsell['offer']);
        $this->assertSame($zahlung->id, $upsell['bought']['id']);

        // Das Token ist der Cookie des Besuchers: wer es hat, geht als er weiter.
        foreach ($events as $list) {
            foreach ($list as $event) {
                $this->assertStringNotContainsString($this->token, json_encode($event->payload));
                $this->assertArrayNotHasKey('meta', $event->payload['visit'] ?? []);
            }
        }
    }

    #[Test]
    public function der_kauf_geht_an_den_webhook_der_ihn_hoert_und_an_keinen_anderen(): void
    {
        $this->kasse();
        Queue::fake();
        $this->hook('funnels.offer_accepted', 'kauf');
        $this->hook('funnels.completed', 'fertig-nicht-hier');

        $this->bisZurKasse();
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);
        $this->bezahlen(Payment::query()->firstOrFail());

        $this->assertDatabaseHas('webhook_deliveries', ['trigger_type' => 'funnels.offer_accepted']);
        Queue::assertPushed(ProcessOutboundDeliveryJob::class, fn ($job) => true);
        $this->assertSame(
            DB::table('webhook_deliveries')->count(),
            DB::table('webhook_deliveries')->whereIn('trigger_type', ['funnels.offer_accepted', 'funnels.completed'])->count(),
        );
    }

    #[Test]
    public function ein_bezahlter_kauf_feuert_in_der_marke_der_zahlung_auch_ohne_aktuelle_marke(): void
    {
        config()->set('brand-context.multi_brand', true);
        app('brand-context')->forget();
        $akademie = Brand::create(['handle' => 'akademie', 'name' => 'Akademie']);
        $studio = Brand::create(['handle' => 'studio', 'name' => 'Studio']);

        Queue::fake();
        app('brand-context')->runFor($akademie, fn () => $this->hook('funnels.offer_accepted', 'akademie'));
        app('brand-context')->runFor($studio, fn () => $this->hook('funnels.offer_accepted', 'studio'));

        $funnel = app('brand-context')->runFor($akademie, fn () => $this->kasse());
        $visit = $funnel->visits()->create(['token' => Str::random(32), 'email' => 'k@example.com']);
        $payment = app('brand-context')->runFor($akademie, fn () => Payment::create([
            'provider' => 'mollie', 'provider_id' => 'tr_test', 'product' => 'kurs', 'amount_cent' => 9900,
            'currency' => 'eur', 'status' => 'paid', 'email' => 'k@example.com',
        ]));

        // Wie aus dem Webhook des Anbieters: keine Marke ist gesetzt.
        app('brand-context')->forget();
        FunnelOfferAccepted::dispatch($visit, $funnel->steps->firstWhere('node_key', 'kasse'), $payment);

        $delivery = DB::table('webhook_deliveries')->where('trigger_type', 'funnels.offer_accepted')->sole();
        $this->assertSame($akademie->id, (int) $delivery->brand_id);
        $this->assertSame(['id' => $akademie->id, 'handle' => 'akademie'], json_decode((string) $delivery->request_body, true)['payload']['brand']);
    }

    #[Test]
    public function derselbe_moment_hat_bei_jeder_zustellung_dieselbe_event_id_und_seine_eigene_zeit(): void
    {
        $this->kasse();
        Event::fake([TriggerDetected::class]);
        $this->bisZurKasse();
        $this->asVisitor()->post('/f/kurs/kasse/advance', ['accept' => '1', 'confirmed' => '1']);
        $zahlung = $this->bezahlen(Payment::query()->firstOrFail());
        $visit = FunnelVisit::query()->firstOrFail();

        $this->travel(5)->minutes();

        // Eine zweite Zustellung desselben Moments, wie ein erneutes „bezahlt".
        $step = $visit->funnel->stepByKey('kasse');
        $nochmal = WebhookManager::triggers()->get('funnels.offer_accepted')->build(new FunnelOfferAccepted($visit, $step, $zahlung));
        $erst = $this->detected()['funnels.offer_accepted'][0];

        $this->assertSame($erst->payload['event_id'], $nochmal->payload['event_id']);
        // Das Rezept der Suite: sha1(handle|<typ>:<id>|…|<Zeitpunkt der Zeile>).
        $this->assertSame(
            sha1('funnels.offer_accepted|visit:'.$visit->id.'|step:kasse|payment:'.$zahlung->id.'|'.$zahlung->paid_at->format(\DATE_ATOM)),
            $erst->payload['event_id'],
        );
        $this->assertSame($zahlung->paid_at->format(\DATE_ATOM), $nochmal->eventAt->format(\DATE_ATOM));
        $this->assertSame($zahlung->paid_at->format(\DATE_ATOM), $nochmal->payload['occurred_at']);
    }

    #[Test]
    public function zwei_absendungen_in_derselben_sekunde_sind_zwei_momente(): void
    {
        $this->kasse();
        Event::fake([TriggerDetected::class]);
        $this->freezeSecond();

        $this->bisZurKasse();
        $this->asVisitor()->get('/f/kurs/anmeldung');
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'k@example.com']);

        $ids = collect($this->detected()['funnels.form_submitted'])->map(fn ($e) => $e->payload['event_id']);

        $this->assertCount(2, $ids);
        $this->assertCount(2, $ids->unique());
    }

    #[Test]
    public function kartendaten_und_geheimnisse_gehen_nie_mit_dem_formular(): void
    {
        $step = new FunnelStep(['node_key' => 'capture_1', 'type' => 'capture']);
        $visit = new FunnelVisit(['email' => 'k@example.com']);
        $visit->setRelation('funnel', null);

        $payload = WebhookPayload::for('funnels.form_submitted', new FunnelFormSubmitted($visit, $step, [
            'email' => 'k@example.com',
            'phone' => '0171',
            'card_number' => '4242',
            'Kreditkarte' => '4242',
            'cvc' => '123',
            'cvv' => '123',
            'bic' => 'DEUTDEFF',
            'iban' => 'DE00',
            'password' => 'x',
            '_token' => 'x',
        ]));

        $this->assertSame(['email' => 'k@example.com', 'phone' => '0171'], $payload['values']);
    }

    #[Test]
    public function eine_zahlung_mit_unbekannter_marke_geht_an_niemanden(): void
    {
        Queue::fake();
        Log::spy();
        $this->hook('funnels.offer_accepted', 'aktuell');
        $funnel = $this->kasse();
        $visit = $funnel->visits()->create(['token' => Str::random(32), 'email' => 'k@example.com']);
        $payment = Payment::create([
            'provider' => 'mollie', 'provider_id' => 'tr_x', 'product' => 'kurs', 'amount_cent' => 9900,
            'currency' => 'eur', 'status' => 'paid', 'email' => 'k@example.com',
        ]);
        $payment->forceFill(['brand_id' => 999])->save();

        FunnelOfferAccepted::dispatch($visit, $funnel->steps->firstWhere('node_key', 'kasse'), $payment);

        $this->assertSame(0, DB::table('webhook_deliveries')->count());
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context = []) => str_contains($message, 'brand that cannot be set') && ($context['brand_id'] ?? null) === 999);
    }

    #[Test]
    public function zugestellt_wird_erst_nach_dem_commit_und_nach_rollback_gar_nicht(): void
    {
        $funnel = $this->kasse();
        Event::fake([TriggerDetected::class]);

        try {
            DB::transaction(function () use ($funnel) {
                app(GraphWriter::class)->seed($funnel);
                FunnelSaved::dispatch($funnel);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([], $this->detected());

        DB::transaction(function () use ($funnel) {
            FunnelSaved::dispatch($funnel);
            $this->assertSame([], $this->detected());
        });

        $this->assertArrayHasKey('funnels.funnel_saved', $this->detected());
    }

    #[Test]
    public function ein_fehler_im_webhook_manager_bricht_den_funnel_nicht(): void
    {
        $this->kasse();
        Event::listen(TriggerDetected::class, fn () => throw new \RuntimeException('down'));

        $this->bisZurKasse();

        $this->assertSame('k@example.com', $this->besuch()->email);
    }
}
