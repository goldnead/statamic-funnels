<?php

namespace Goldnead\StatamicFunnels\Jobs;

use Goldnead\StatamicFunnels\Mail\FunnelMail;
use Goldnead\StatamicFunnels\Models\FunnelMailDelivery;
use Goldnead\StatamicFunnels\Nodes\MailStep;
use Goldnead\StatamicFunnels\Support\FunnelMailRenderer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Eine Mail eines Mail-Knotens wirklich verschicken.
 *
 * Bekommt die **Zeile**, nicht die Daten: Empfaenger, Vorlage und Bestellung
 * werden erst hier gelesen. Bei einer Mail „drei Tage nach dem Einstieg" ist
 * das der Unterschied zwischen einer Adresse, die der Besuch inzwischen
 * genannt hat, und einer leeren.
 *
 * Nichts hier wirft nach aussen. Ein Fehlschlag steht mit Grund an der Zeile,
 * damit der Editor „fehlgeschlagen" zeigen kann statt „ausgeloest" fuer immer.
 */
class SendFunnelMail implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(FunnelMailRenderer $renderer): void
    {
        $delivery = FunnelMailDelivery::query()->find($this->deliveryId);

        if (! $delivery || $delivery->sent_at) {
            return;
        }

        $visit = $delivery->visit()->first();
        $funnel = $delivery->funnel()->with(['steps', 'edges'])->first();
        $step = $funnel?->stepByKey($delivery->node_key);

        if (! $visit || ! $funnel || ! $step) {
            $delivery->markFailed(__('statamic-funnels::messages.mail_error_node_gone'));

            return;
        }

        $to = $this->recipient($step->config ?? [], $visit->email);

        if ($to === null) {
            $delivery->markFailed(__('statamic-funnels::messages.mail_error_no_recipient'));

            return;
        }

        try {
            $mail = $renderer->render($step, $visit);
        } catch (Throwable $e) {
            $delivery->markFailed($e->getMessage(), $to);

            Log::warning('statamic-funnels: a funnel mail could not be rendered.', [
                'delivery' => $delivery->getKey(),
                'node' => $step->node_key,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        try {
            Mail::to($to)->send(new FunnelMail($mail['subject'], $mail['html']));
        } catch (Throwable $e) {
            $delivery->markFailed($e->getMessage(), $to);

            Log::warning('statamic-funnels: a funnel mail could not be sent.', [
                'delivery' => $delivery->getKey(),
                'node' => $step->node_key,
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        $delivery->markSent($to);
    }

    /**
     * An wen. Der Besuch, oder eine feste Adresse aus dem Knoten.
     *
     * @param  array<string, mixed>  $config
     */
    protected function recipient(array $config, ?string $visitorEmail): ?string
    {
        if (($config['recipient'] ?? MailStep::RECIPIENT_VISITOR) === MailStep::RECIPIENT_FIXED) {
            $fixed = trim((string) ($config['recipient_address'] ?? ''));

            return filter_var($fixed, FILTER_VALIDATE_EMAIL) ? $fixed : null;
        }

        $email = trim((string) $visitorEmail);

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }
}
