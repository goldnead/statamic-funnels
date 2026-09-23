<?php

namespace Goldnead\StatamicFunnels\Integrations;

use Goldnead\StatamicFunnels\Contracts\ConversionSender;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Die Meta Conversions API.
 *
 * Ein POST je Ereignis an `graph.facebook.com/<version>/<pixel>/events`, mit
 * dem Zugangsschluessel aus der Config (`FUNNELS_META_CAPI_TOKEN`) und, fuer
 * den Ereignismanager, einem Testcode.
 *
 * **Eine 200 ist kein Beleg.** Meta quittiert auch Sendungen, deren Ereignisse
 * nie im Konto auftauchen. Geprueft wird deshalb `events_received`; alles
 * andere steht im Log, mit der Antwort von Meta, und der Job versucht es noch
 * einmal. Ob der Abgleich mit dem Pixel greift, zeigt nur der
 * Ereignismanager selbst (ein Ereignis, nicht zwei).
 */
class MetaConversions implements ConversionSender
{
    public function enabled(): bool
    {
        return trim((string) config('statamic-funnels.tracking.meta.access_token', '')) !== '';
    }

    public function send(string $pixelId, array $event): bool
    {
        if (! $this->enabled() || preg_match('/^\d{5,32}$/', $pixelId) !== 1) {
            return false;
        }

        $version = trim((string) config('statamic-funnels.tracking.meta.api_version', 'v21.0')) ?: 'v21.0';

        $payload = array_filter([
            'data' => [$event],
            'access_token' => (string) config('statamic-funnels.tracking.meta.access_token'),
            'test_event_code' => config('statamic-funnels.tracking.meta.test_event_code') ?: null,
        ]);

        try {
            $response = Http::asJson()
                ->timeout(10)
                ->post('https://graph.facebook.com/'.$version.'/'.$pixelId.'/events', $payload);
        } catch (Throwable $e) {
            Log::warning('statamic-funnels: Meta hat das Ereignis nicht angenommen (keine Verbindung).', [
                'event' => $event['event_name'] ?? null,
                'event_id' => $event['event_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($response->successful() && (int) $response->json('events_received', 0) >= 1) {
            return true;
        }

        Log::warning('statamic-funnels: Meta hat das Ereignis nicht angenommen.', [
            'event' => $event['event_name'] ?? null,
            'event_id' => $event['event_id'] ?? null,
            'status' => $response->status(),
            'error' => $response->json('error.message') ?? mb_substr((string) $response->body(), 0, 500),
        ]);

        return false;
    }
}
