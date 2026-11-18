<?php

namespace Goldnead\StatamicFunnels\Commands;

use Goldnead\StatamicFunnels\Jobs\SendFunnelMail;
use Goldnead\StatamicFunnels\Models\FunnelMailDelivery;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * Fehlgeschlagene Funnel-Mails erneut einreihen.
 *
 * Eine Zeile steht je (Besuch, Knoten) und ist damit die Idempotenz; eine als
 * fehlgeschlagen markierte Zeile wurde deshalb nie wieder gestellt, auch wenn
 * die Queue oder der Mailserver laengst zurueck war. Dieser Befehl stellt sie
 * erneut, hoechstens `--max-tries` mal je Mail und erst nach `--min-age`
 * Minuten Ruhe seit dem letzten Fehlschlag. Gesendete Mails fasst er nie an.
 */
class RetryFailedMails extends Command
{
    use RunsInPlease;

    protected $signature = 'funnels:retry-mails
        {--max-tries=3 : Wie oft eine Mail hoechstens erneut gestellt wird}
        {--min-age=10 : Minuten seit dem letzten Fehlschlag, bevor sie wieder drankommt}';

    protected $description = 'Queue funnel mails that failed again, a limited number of times.';

    public function handle(): int
    {
        $maxTries = max(1, (int) $this->option('max-tries'));
        $minAge = max(0, (int) $this->option('min-age'));

        $count = 0;

        FunnelMailDelivery::query()
            ->whereNotNull('failed_at')
            ->whereNull('sent_at')
            ->where('attempts', '<', $maxTries)
            ->where('failed_at', '<=', now()->subMinutes($minAge))
            ->orderBy('id')
            ->each(function (FunnelMailDelivery $delivery) use (&$count) {
                $delivery->increment('attempts');
                SendFunnelMail::dispatch($delivery->getKey());
                $count++;
            });

        $this->components->info("{$count} failed funnel mail(s) queued again.");

        return self::SUCCESS;
    }
}
