<?php

namespace Goldnead\StatamicFunnels\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eine Mail, die ein Mail-Knoten fuer einen Besuch ausgeloest hat.
 *
 * Eine Zeile je (Besuch, Knoten), und das ist die Idempotenz: ein Angebot,
 * dessen Webhook dreimal zugestellt wird, loest die Kaufmail einmal aus.
 *
 * Drei Zeitstempel erzaehlen den Weg: `queued_at` ist der Moment des
 * Ausloesens, `sent_at` der Versand, `failed_at` mit `error` der Fehlschlag.
 * Ohne diese Tabelle waere es wieder eine Mail, die schweigend ausfaellt.
 *
 * @property int $id
 * @property int $visit_id
 * @property int $funnel_id
 * @property string $node_key
 * @property string|null $template
 * @property string|null $to
 * @property string|null $brand_id
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property string|null $error
 */
class FunnelMailDelivery extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FunnelVisit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(FunnelVisit::class, 'visit_id');
    }

    /** @return BelongsTo<Funnel, $this> */
    public function funnel(): BelongsTo
    {
        return $this->belongsTo(Funnel::class);
    }

    public function markSent(string $to): void
    {
        $this->forceFill(['to' => $to, 'sent_at' => now(), 'failed_at' => null, 'error' => null])->save();
    }

    public function markFailed(string $error, ?string $to = null): void
    {
        $this->forceFill([
            'to' => $to ?? $this->to,
            'failed_at' => now(),
            'error' => mb_substr($error, 0, 1000),
        ])->save();
    }
}
