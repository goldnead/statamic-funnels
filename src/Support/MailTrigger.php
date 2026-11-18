<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Jobs\SendFunnelMail;
use Goldnead\StatamicFunnels\Models\FunnelMailDelivery;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Nodes\MailStep;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Ein Ausgang wurde genommen — welche Mails haengen daran?
 *
 * **Warum die Queue und nicht die Automations-Engine.** Der Bauplan sah vor,
 * dass der Knoten in `goldnead/statamic-automations` dispatcht und die
 * Verzoegerung in `automation_scheduled_jobs` landet. Das haette jeden Funnel
 * mit einer Mail darauf zu einer Installation der Automations gezwungen, und
 * einen zweiten Weg gebaut, auf dem eine Mail „verzoegert" heisst. Laravel hat
 * dafuer schon eine Antwort im Haus: `dispatch()->delay()`. Eine Warteschlange,
 * kein zweiter Scheduler, und ein Funnel bleibt ohne Automations lauffaehig.
 *
 * Eine mehrstufige Sequenz ueber Tage bleibt trotzdem eine Automation. Ein
 * Mail-Knoten ist eine Mail an einem Moment, nicht ein Programm.
 */
class MailTrigger
{
    /**
     * Alles ausloesen, was an `$output` von `$parent` haengt.
     *
     * Ein Fehler hier stoppt nie den Weg: der Besucher will zur naechsten
     * Seite, und eine Mail, die nicht in die Warteschlange kam, steht als
     * Fehlschlag in der Tabelle statt als 500 vor dem Menschen.
     *
     * @return int Wie viele Mails neu in die Warteschlange kamen.
     */
    public function fire(FunnelVisit $visit, FunnelStep $parent, string $output): int
    {
        $queued = 0;

        try {
            $mails = $visit->funnel->mailStepsFrom($parent->node_key, $output);
        } catch (Throwable $e) {
            Log::warning('statamic-funnels: the mail nodes of a step could not be read; the walk continues.', [
                'visit' => $visit->getKey(),
                'step' => $parent->node_key,
                'output' => $output,
                'exception' => $e->getMessage(),
            ]);

            return 0;
        }

        // Je Mail einzeln: scheitert die erste, geht die zweite trotzdem raus.
        foreach ($mails as $mail) {
            try {
                if ($this->queue($visit, $mail)) {
                    $queued++;
                }
            } catch (Throwable $e) {
                Log::warning('statamic-funnels: a mail node could not be queued; the walk continues.', [
                    'visit' => $visit->getKey(),
                    'step' => $parent->node_key,
                    'node' => $mail->node_key,
                    'output' => $output,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        return $queued;
    }

    /**
     * Eine Zeile anlegen und den Job stellen. Einmal je (Besuch, Knoten).
     *
     * `firstOrCreate` auf (Besuch, Knoten): der zweite Aufruf findet die Zeile
     * des ersten und stellt keinen Job. Den Rest sichert der Unique-Index —
     * zwei Webhook-Zustellungen im selben Augenblick lesen beide „noch nicht
     * da", und dann scheitert der zweite Insert an der Datenbank; dieser
     * Fehler wird von `fire()` geloggt und nicht als Doppelaufruf verkleidet.
     */
    protected function queue(FunnelVisit $visit, FunnelStep $mail): bool
    {
        $delivery = FunnelMailDelivery::firstOrCreate(
            ['visit_id' => $visit->getKey(), 'node_key' => $mail->node_key],
            [
                'funnel_id' => $visit->funnel_id,
                'template' => $mail->config('template') ?: null,
                'brand_id' => $this->brand(),
                'queued_at' => now(),
            ],
        );

        // Schon ausgeloest. Kein Fehler, kein zweiter Versand. Alles andere,
        // was die Datenbank hier wirft, wirft weiter — `fire()` schreibt es ins
        // Log; ein stiller `catch` haette einen echten Fehler wie einen
        // Doppelaufruf aussehen lassen.
        if (! $delivery->wasRecentlyCreated) {
            return false;
        }

        $delay = MailStep::delaySeconds((array) ($mail->config ?? []));

        // Die Zeile steht schon. Kommt der Job nicht in die Warteschlange
        // (Redis weg, Tabelle fehlt), muss sie das sagen; sonst zeigt der
        // Editor „ausgeloest" fuer eine Mail, die nie jemand verschickt, und
        // `firstOrCreate` oben sperrt jeden zweiten Anlauf.
        //
        // Ueber den Dispatcher statt `SendFunnelMail::dispatch()`: dessen
        // PendingDispatch stellt den Job erst im Destruktor, und ein Fehler
        // dort floege an diesem catch vorbei.
        try {
            app(Dispatcher::class)->dispatch(
                (new SendFunnelMail($delivery->getKey()))->delay($delay > 0 ? now()->addSeconds($delay) : null),
            );
        } catch (Throwable $e) {
            $delivery->markFailed($e->getMessage());

            Log::error('statamic-funnels: a funnel mail could not be queued and is marked as failed.', [
                'delivery' => $delivery->getKey(),
                'visit' => $visit->getKey(),
                'node' => $mail->node_key,
                'exception' => $e,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Die Marke dieses Aufrufs, wenn `goldnead/statamic-brand-context` da ist.
     *
     * Nur eine Notiz an der Zeile, damit ein Bericht je Marke filtern kann.
     * Optional wie alles hier: ohne Marken-Addon bleibt die Spalte leer.
     */
    protected function brand(): ?string
    {
        $facade = '\Goldnead\BrandContext\Facades\BrandContext';

        if (! class_exists($facade)) {
            return null;
        }

        try {
            $root = $facade::getFacadeRoot();

            if (! is_object($root) || ! method_exists($root, 'current')) {
                return null;
            }

            $brand = $root->current();

            if (is_string($brand)) {
                return $brand;
            }

            if (is_object($brand) && method_exists($brand, 'handle')) {
                return (string) $brand->handle();
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }
}
