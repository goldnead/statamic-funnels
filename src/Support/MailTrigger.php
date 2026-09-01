<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Jobs\SendFunnelMail;
use Goldnead\StatamicFunnels\Models\FunnelMailDelivery;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Nodes\MailStep;
use Illuminate\Database\QueryException;
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
            foreach ($visit->funnel->mailStepsFrom($parent->node_key, $output) as $mail) {
                if ($this->queue($visit, $mail)) {
                    $queued++;
                }
            }
        } catch (Throwable $e) {
            Log::warning('statamic-funnels: a mail node could not be queued; the walk continues.', [
                'visit' => $visit->getKey(),
                'step' => $parent->node_key,
                'output' => $output,
                'exception' => $e->getMessage(),
            ]);
        }

        return $queued;
    }

    /**
     * Eine Zeile anlegen und den Job stellen. Einmal je (Besuch, Knoten).
     *
     * Die Einmaligkeit ist der Unique-Index, nicht ein `exists()` davor: zwei
     * Webhook-Zustellungen im selben Augenblick wuerden beide „noch nicht da"
     * lesen. Der zweite Insert schlaegt an der Datenbank fehl, und das ist die
     * gewollte Antwort.
     */
    protected function queue(FunnelVisit $visit, FunnelStep $mail): bool
    {
        try {
            $delivery = FunnelMailDelivery::create([
                'visit_id' => $visit->getKey(),
                'funnel_id' => $visit->funnel_id,
                'node_key' => $mail->node_key,
                'template' => $mail->config('template') ?: null,
                'brand_id' => $this->brand(),
                'queued_at' => now(),
            ]);
        } catch (QueryException) {
            // Schon ausgeloest. Kein Fehler, kein zweiter Versand.
            return false;
        }

        $delay = MailStep::delaySeconds((array) ($mail->config ?? []));

        SendFunnelMail::dispatch($delivery->getKey())
            ->delay($delay > 0 ? now()->addSeconds($delay) : null);

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
