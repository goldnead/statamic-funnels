<?php

namespace Goldnead\StatamicFunnels\Jobs;

use Goldnead\StatamicFunnels\Contracts\ConversionSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Ein Ereignis an die Conversions API, ausserhalb der Anfrage.
 *
 * Eine Seite wartet nicht auf Meta, und ein Webhook des Zahlungsanbieters
 * auch nicht. Drei Versuche mit Abstand; danach steht es im Log des Senders
 * und in `failed_jobs`.
 */
class SendConversionEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    /**
     * @param  array<string, mixed>  $event
     */
    public function __construct(public string $pixelId, public array $event) {}

    public function handle(ConversionSender $sender): void
    {
        if ($sender->send($this->pixelId, $this->event) || ! $sender->enabled()) {
            return;
        }

        // Zurueckgestellt, nicht geworfen: auf einer Site ohne Warteschlange
        // (`sync`) liefe eine Ausnahme bis in die Seite des Besuchers, und ein
        // Werbepixel darf keine Kasse umwerfen. Der Grund steht schon im Log.
        if ($this->job !== null && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
