<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\StatamicFunnels\Jobs\SendFunnelMail;
use Goldnead\StatamicFunnels\Mail\FunnelMail;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelMailDelivery;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\FunnelMailRenderer;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\PreviewToken;
use Goldnead\StatamicFunnels\Support\StepOrder;
use Goldnead\StatamicFunnels\Support\StepStats;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Fulfilment;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * Der Mail-Knoten: haengt an einem Schritt, wird nie betreten.
 *
 * Die eine Zusicherung, um die sich alles dreht: **der Weg fuehrt an der Mail
 * vorbei.** Wer sie als Station zaehlte, haette eine URL, die flackert, einen
 * Zurueck-Knopf, der bricht, und eine Abbruchstatistik ueber einen Schritt, an
 * dem nie jemand stand. Der Rest hier ist, dass sie trotzdem rausgeht — genau
 * einmal, zum richtigen Moment, mit der richtigen Verzoegerung — und dass ein
 * Fehlschlag als Fehlschlag dasteht.
 */
class MailStepTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../Fakes/email-templates-facade.php';

        EmailTemplates::$templates = [
            'willkommen' => [
                'subject' => 'Willkommen, {{ visitor.name }}',
                'body' => '<p>{{ contact.salutation }}, danke für {{ funnel.title }}. {{ order.total }}</p>',
            ],
        ];
    }

    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    /**
     * Einstieg → Formular → Angebot → Danke. Und drei Mails: eine am Einstieg
     * (`default`), eine am Angebot bei `accepted`, eine bei `declined`.
     *
     * @param  array<string, mixed>  $mailConfig
     */
    protected function funnel(array $mailConfig = []): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);

        $mail = array_merge(['template' => 'willkommen', 'delay_amount' => 0, 'delay_unit' => 'minutes', 'recipient' => 'visitor'], $mailConfig);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'offer_1', 'type' => 'offer', 'label' => 'Angebot', 'slug' => 'angebot', 'config' => ['offer' => 'kurs-angebot']],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
            ['node_key' => 'page_1', 'type' => 'page', 'label' => 'Schade', 'slug' => 'schade'],
            ['node_key' => 'mail_start', 'type' => 'mail', 'label' => 'Hallo-Mail', 'slug' => null, 'config' => $mail],
            ['node_key' => 'mail_yes', 'type' => 'mail', 'label' => 'Kauf-Mail', 'slug' => null, 'config' => $mail],
            ['node_key' => 'mail_no', 'type' => 'mail', 'label' => 'Schade-Mail', 'slug' => null, 'config' => $mail],
        ]);

        $funnel->edges()->createMany([
            // Die Mail zuerst, damit der Test beweist, dass die Reihenfolge der
            // Kanten keine Rolle spielt: der Weg nimmt trotzdem die Seite.
            ['from_node_key' => 'entry_1', 'to_node_key' => 'mail_start', 'from_output' => 'default'],
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'offer_1', 'from_output' => 'default'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'mail_yes', 'from_output' => 'accepted'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'mail_no', 'from_output' => 'declined'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'page_1', 'from_output' => 'declined'],
        ]);

        Offer::create([
            'handle' => 'kurs-angebot', 'name' => 'Kurs', 'product' => 'kurs',
            'amount_cent' => 4900, 'slot' => Offer::SLOT_STANDALONE, 'active' => true,
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    #[Test]
    public function the_node_is_registered_without_a_page_and_without_outputs(): void
    {
        $registry = app(StepRegistry::class);

        $this->assertTrue($registry->has('mail'));

        $class = $registry->find('mail');
        $this->assertFalse($class::isPage());
        $this->assertSame([], $class::outputs());

        $library = $registry->library();
        $this->assertArrayHasKey('mails', $library);
        $this->assertSame('mail', $library['mails'][0]['kind']);
    }

    #[Test]
    public function the_walk_goes_past_the_mail_to_the_next_page(): void
    {
        $funnel = $this->funnel();

        // Aus dem Modell heraus: der naechste Schritt am Einstieg ist die Seite,
        // obwohl die Kante zur Mail zuerst gespeichert wurde.
        $this->assertSame('capture_1', $funnel->nextStep('entry_1', 'default')?->node_key);

        // Und ueber HTTP: weitergehen landet auf dem Formular, nie auf der Mail.
        Bus::fake();
        $this->asVisitor()->get('/f/kurs')->assertOk();
        $this->asVisitor()->post('/f/kurs/entry_1/advance')->assertRedirect('/f/kurs/anmeldung');

        // Eine Mail hat keine Seite und nichts, von dem aus man weitergeht.
        $this->asVisitor()->post('/f/kurs/mail_start/advance')->assertNotFound();
    }

    #[Test]
    public function entering_the_parent_queues_exactly_one_job_with_the_delay(): void
    {
        Bus::fake();
        $this->funnel(['delay_amount' => 2, 'delay_unit' => 'hours']);

        $this->travelTo(now()->startOfMinute());

        $this->asVisitor()->get('/f/kurs')->assertOk();
        // Ein Neuladen ist kein zweites Betreten.
        $this->asVisitor()->get('/f/kurs')->assertOk();

        Bus::assertDispatchedTimes(SendFunnelMail::class, 1);
        Bus::assertDispatched(SendFunnelMail::class, function (SendFunnelMail $job) {
            return $job->delay !== null && (int) round(now()->diffInSeconds($job->delay)) === 7200;
        });

        $delivery = FunnelMailDelivery::query()->sole();
        $this->assertSame('mail_start', $delivery->node_key);
        $this->assertSame('willkommen', $delivery->template);
        $this->assertNotNull($delivery->queued_at);
        $this->assertNull($delivery->sent_at);
    }

    #[Test]
    public function declining_fires_only_the_declined_branch(): void
    {
        Bus::fake();
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/angebot')->assertOk();
        $this->asVisitor()->post('/f/kurs/offer_1/advance', ['accept' => '0'])->assertRedirect('/f/kurs/schade');

        $nodes = FunnelMailDelivery::query()->pluck('node_key')->all();

        // Der Einstieg wurde nie betreten, die Seite „schade" traegt keine
        // Mail: uebrig bleibt genau der Zweig, der genommen wurde.
        $this->assertSame(['mail_no'], $nodes);
    }

    #[Test]
    public function paying_fires_only_the_accepted_branch_and_only_once(): void
    {
        Bus::fake();
        $this->funnel();

        $this->asVisitor()->get('/f/kurs/anmeldung')->assertOk();
        $this->asVisitor()->post('/f/kurs/capture_1/advance', ['email' => 'maria@example.com', 'name' => 'Maria Beispiel']);
        $this->asVisitor()->get('/f/kurs/angebot')->assertOk();
        $this->asVisitor()->post('/f/kurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1']);

        // Der Klick allein loest nichts aus: erst der Webhook sagt „bezahlt".
        $this->assertSame([], FunnelMailDelivery::query()->where('node_key', 'mail_yes')->pluck('id')->all());

        $payment = Payment::query()->sole();
        $this->gateway->markPaid($payment->provider_id, 'maria@example.com');

        // Zweimal zugestellt, wie ein Anbieter das tut.
        app(Fulfilment::class)->handle($payment->provider_id);
        app(Fulfilment::class)->handle($payment->provider_id);

        $this->assertSame(1, FunnelMailDelivery::query()->where('node_key', 'mail_yes')->count());
        $this->assertSame(0, FunnelMailDelivery::query()->where('node_key', 'mail_no')->count());
    }

    #[Test]
    public function the_job_sends_the_rendered_mail_and_writes_sent_at(): void
    {
        Mail::fake();
        $funnel = $this->funnel();

        $visit = FunnelVisit::create([
            'funnel_id' => $funnel->id, 'token' => str_repeat('a', 32),
            'email' => 'maria@example.com', 'name' => 'Maria Beispiel', 'current_node_key' => 'entry_1',
        ]);

        $delivery = FunnelMailDelivery::create([
            'visit_id' => $visit->id, 'funnel_id' => $funnel->id, 'node_key' => 'mail_start',
            'template' => 'willkommen', 'queued_at' => now(),
        ]);

        (new SendFunnelMail($delivery->id))->handle(app(FunnelMailRenderer::class));

        Mail::assertSent(FunnelMail::class, function (FunnelMail $mail) {
            return $mail->hasTo('maria@example.com')
                && $mail->subjectLine === 'Willkommen, Maria Beispiel'
                // Die Suite laeuft auf Englisch; die Anrede kommt aus der
                // Sprachdatei, der Rest aus der Vorlage.
                && str_contains($mail->htmlBody, 'Hello Maria, danke für Kurs.');
        });

        $delivery->refresh();
        $this->assertNotNull($delivery->sent_at);
        $this->assertNull($delivery->failed_at);
        $this->assertSame('maria@example.com', $delivery->to);
    }

    #[Test]
    public function a_mail_without_a_template_fails_visibly(): void
    {
        Mail::fake();
        $funnel = $this->funnel(['template' => null]);

        $visit = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => str_repeat('b', 32), 'email' => 'maria@example.com']);
        $delivery = FunnelMailDelivery::create(['visit_id' => $visit->id, 'funnel_id' => $funnel->id, 'node_key' => 'mail_start', 'queued_at' => now()]);

        (new SendFunnelMail($delivery->id))->handle(app(FunnelMailRenderer::class));

        Mail::assertNothingSent();

        $delivery->refresh();
        $this->assertNotNull($delivery->failed_at);
        $this->assertNull($delivery->sent_at);
        $this->assertStringContainsString('template', (string) $delivery->error);
    }

    #[Test]
    public function a_mail_before_the_address_is_known_fails_with_a_reason(): void
    {
        Mail::fake();
        $funnel = $this->funnel();

        // Ein Besuch, der den Einstieg gesehen und noch nichts eingetippt hat.
        $visit = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => str_repeat('c', 32)]);
        $delivery = FunnelMailDelivery::create(['visit_id' => $visit->id, 'funnel_id' => $funnel->id, 'node_key' => 'mail_start', 'template' => 'willkommen', 'queued_at' => now()]);

        (new SendFunnelMail($delivery->id))->handle(app(FunnelMailRenderer::class));

        Mail::assertNothingSent();
        $this->assertStringContainsString('recipient', (string) $delivery->fresh()->error);
    }

    #[Test]
    public function a_fixed_recipient_gets_the_mail_instead_of_the_visitor(): void
    {
        Mail::fake();
        $funnel = $this->funnel(['recipient' => 'fixed', 'recipient_address' => 'buero@example.com']);

        $visit = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => str_repeat('d', 32), 'email' => 'maria@example.com']);
        $delivery = FunnelMailDelivery::create(['visit_id' => $visit->id, 'funnel_id' => $funnel->id, 'node_key' => 'mail_start', 'template' => 'willkommen', 'queued_at' => now()]);

        (new SendFunnelMail($delivery->id))->handle(app(FunnelMailRenderer::class));

        Mail::assertSent(FunnelMail::class, fn (FunnelMail $mail) => $mail->hasTo('buero@example.com') && ! $mail->hasTo('maria@example.com'));
    }

    #[Test]
    public function the_stepper_lists_a_mail_right_behind_the_step_it_hangs_off(): void
    {
        $nodes = [
            ['node_key' => 'entry_1', 'type' => 'entry'],
            ['node_key' => 'offer_1', 'type' => 'offer'],
            ['node_key' => 'finish_1', 'type' => 'finish'],
            ['node_key' => 'page_1', 'type' => 'page'],
            ['node_key' => 'mail_yes', 'type' => 'mail'],
            ['node_key' => 'mail_start', 'type' => 'mail'],
        ];

        $edges = [
            ['from_node_key' => 'entry_1', 'to_node_key' => 'offer_1', 'from_output' => 'default'],
            ['from_node_key' => 'entry_1', 'to_node_key' => 'mail_start', 'from_output' => 'default'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'mail_yes', 'from_output' => 'accepted'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'page_1', 'from_output' => 'declined'],
        ];

        $this->assertSame(
            ['entry_1', 'mail_start', 'offer_1', 'mail_yes', 'finish_1', 'page_1'],
            StepOrder::keys($nodes, $edges),
        );
    }

    #[Test]
    public function the_mail_preview_renders_with_a_pass_and_is_not_there_without(): void
    {
        $funnel = $this->funnel();

        $graph = [
            'nodes' => [
                ['node_key' => 'entry_1', 'type' => 'entry', 'config' => []],
                ['node_key' => 'offer_1', 'type' => 'offer', 'config' => ['offer' => 'kurs-angebot']],
                // Ein Knoten, den es in der Tabelle nicht gibt: die Vorschau
                // zeigt den Graphen vom Bildschirm.
                ['node_key' => 'mail_new', 'type' => 'mail', 'config' => ['template' => 'willkommen']],
            ],
            'edges' => [],
        ];

        $token = PreviewToken::mint($funnel, $graph);

        $response = $this->get('/f/kurs/_preview-mail/mail_new?token='.$token);

        $response->assertOk();
        // Beispieldaten: der Name, und die Bestellung ueber das Angebot des
        // Funnels, damit `order.*` in der Vorschau nicht leer bleibt.
        $response->assertSee('Hello Maria, danke für Kurs. 49,00 EUR');

        // Ohne Pass ist da nichts — wie bei der Seiten-Vorschau nebenan.
        $this->get('/f/kurs/_preview-mail/mail_new')->assertNotFound();

        // Und nichts wurde geschrieben.
        $this->assertSame(0, FunnelVisit::count());
        $this->assertSame(0, FunnelMailDelivery::count());
    }

    #[Test]
    public function the_mail_preview_says_so_when_no_template_is_chosen(): void
    {
        $funnel = $this->funnel();

        $token = PreviewToken::mint($funnel, ['nodes' => [['node_key' => 'mail_new', 'type' => 'mail', 'config' => []]], 'edges' => []]);

        $this->get('/f/kurs/_preview-mail/mail_new?token='.$token)
            ->assertOk()
            ->assertSee('No template chosen');
    }

    #[Test]
    public function the_editor_hands_the_preview_a_mail_url_for_a_mail_node(): void
    {
        $funnel = $this->funnel();
        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $response = $this->actingAs($user)->postJson(cp_route('utilities.funnels.preview', $funnel->id), [
            'node_key' => 'mail_start',
            'nodes' => [
                ['node_key' => 'entry_1', 'type' => 'entry', 'config' => []],
                ['node_key' => 'mail_start', 'type' => 'mail', 'config' => []],
            ],
            'edges' => [['from_node_key' => 'entry_1', 'to_node_key' => 'mail_start', 'from_output' => 'default']],
        ]);

        $response->assertOk();
        $this->assertStringContainsString('/f/kurs/_preview-mail/mail_start?token=', $response->json('url'));
        $this->assertSame(['entry_1', 'mail_start'], $response->json('order'));
    }

    #[Test]
    public function a_mail_is_not_a_station_in_the_drop_off_numbers(): void
    {
        $funnel = $this->funnel();

        $stats = StepStats::forFunnel($funnel);

        $this->assertArrayNotHasKey('mail_start', $stats);
        // Der Abschluss fuehrt nirgendwohin; eine Mail daran aenderte das nicht.
        $this->assertTrue($stats['finish_1']['terminal']);
    }
}
