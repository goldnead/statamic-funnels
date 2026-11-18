<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\EmailTemplates\Facades\EmailTemplates;
use Goldnead\StatamicFunnels\Jobs\SendFunnelMail;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelMailDelivery;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelMailRenderer;
use Goldnead\StatamicFunnels\Support\MailTrigger;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * Eine Funnel-Mail geht raus oder steht als Fehlschlag da, nie dazwischen.
 *
 * Zwei Luecken: die Zeile wurde angelegt, bevor der Job in der Warteschlange
 * war (wirft die Warteschlange, bleibt eine Zeile, die „ausgeloest" sagt und
 * nie verschickt wird), und ein einzelner SMTP-Aussetzer war endgueltig.
 */
class MailVersandRobustTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        require_once __DIR__.'/../Fakes/email-templates-facade.php';

        EmailTemplates::$templates = [
            'willkommen' => ['subject' => 'Willkommen', 'body' => '<p>Hallo</p>'],
        ];
    }

    protected function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $mail = ['template' => 'willkommen', 'delay_amount' => 0, 'delay_unit' => 'minutes', 'recipient' => 'visitor'];

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
            ['node_key' => 'mail_a', 'type' => 'mail', 'label' => 'Mail A', 'slug' => null, 'config' => $mail],
            ['node_key' => 'mail_b', 'type' => 'mail', 'label' => 'Mail B', 'slug' => null, 'config' => $mail],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'mail_a', 'from_output' => 'default'],
            ['from_node_key' => 'entry_1', 'to_node_key' => 'mail_b', 'from_output' => 'default'],
            ['from_node_key' => 'entry_1', 'to_node_key' => 'finish_1', 'from_output' => 'default'],
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    protected function visit(Funnel $funnel): FunnelVisit
    {
        return FunnelVisit::create([
            'funnel_id' => $funnel->id, 'token' => str_repeat('a', 32),
            'email' => 'maria@example.com', 'current_node_key' => 'entry_1',
        ]);
    }

    #[Test]
    public function wirft_die_warteschlange_steht_jede_mail_als_fehlgeschlagen_da(): void
    {
        $funnel = $this->funnel();
        $visit = $this->visit($funnel);

        // Eine Datenbank-Warteschlange ohne ihre Tabelle: jeder dispatch wirft,
        // wie bei einem weggebrochenen Redis.
        config(['queue.default' => 'database', 'queue.connections.database.table' => 'fehlt_jobs']);

        Log::spy();

        $queued = app(MailTrigger::class)->fire($visit, $funnel->stepByKey('entry_1'), 'default');

        $this->assertSame(0, $queued);

        $rows = FunnelMailDelivery::query()->orderBy('node_key')->get();

        // Beide Mails, nicht nur die erste: ein Fehler bei A stoppt B nicht.
        $this->assertSame(['mail_a', 'mail_b'], $rows->pluck('node_key')->all());

        foreach ($rows as $row) {
            $this->assertNotNull($row->failed_at, $row->node_key.' steht nicht als fehlgeschlagen da.');
            $this->assertNotSame('', (string) $row->error);
            $this->assertNull($row->sent_at);
        }

        Log::shouldHaveReceived('error')->twice();
    }

    #[Test]
    public function ein_smtp_aussetzer_wird_wiederholt_statt_endgueltig_zu_scheitern(): void
    {
        $funnel = $this->funnel();
        $visit = $this->visit($funnel);
        $delivery = FunnelMailDelivery::create([
            'visit_id' => $visit->id, 'funnel_id' => $funnel->id, 'node_key' => 'mail_a',
            'template' => 'willkommen', 'queued_at' => now(),
        ]);

        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp kurz weg'));
        Log::spy();

        $job = (new SendFunnelMail($delivery->id))->withFakeQueueInteractions();
        $job->job->attempts = 1;
        $job->handle(app(FunnelMailRenderer::class));

        $this->assertGreaterThanOrEqual(3, $job->tries);
        $job->assertReleased();
        $this->assertNull($delivery->fresh()->failed_at, 'Nach dem ersten Versuch ist noch nichts verloren.');
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function auf_der_sync_queue_ist_der_erste_versuch_der_letzte(): void
    {
        $funnel = $this->funnel();
        $visit = $this->visit($funnel);
        $delivery = FunnelMailDelivery::create([
            'visit_id' => $visit->id, 'funnel_id' => $funnel->id, 'node_key' => 'mail_a',
            'template' => 'willkommen', 'queued_at' => now(),
        ]);

        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp weg'));
        Log::spy();

        // Die Sync-Queue stellt einen freigegebenen Job nicht neu ein; ein
        // release() dort waere ein stiller Verlust.
        config(['queue.default' => 'sync']);
        dispatch(new SendFunnelMail($delivery->id));

        $this->assertNotNull($delivery->fresh()->failed_at);
        Log::shouldHaveReceived('error')->once();
    }

    #[Test]
    public function erst_der_letzte_versuch_schreibt_den_fehlschlag_und_loggt_ihn_als_fehler(): void
    {
        $funnel = $this->funnel();
        $visit = $this->visit($funnel);
        $delivery = FunnelMailDelivery::create([
            'visit_id' => $visit->id, 'funnel_id' => $funnel->id, 'node_key' => 'mail_a',
            'template' => 'willkommen', 'queued_at' => now(),
        ]);

        Mail::shouldReceive('to')->andThrow(new RuntimeException('smtp weg'));
        Log::spy();

        $job = (new SendFunnelMail($delivery->id))->withFakeQueueInteractions();
        $job->job->attempts = $job->tries;
        $job->handle(app(FunnelMailRenderer::class));

        $job->assertNotReleased();
        $this->assertNotNull($delivery->fresh()->failed_at);
        $this->assertStringContainsString('smtp weg', (string) $delivery->fresh()->error);
        Log::shouldHaveReceived('error')->once();
    }
}
