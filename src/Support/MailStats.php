<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelMailDelivery;
use Illuminate\Support\Facades\DB;

/**
 * Was die Mail-Knoten eines Funnels getan haben.
 *
 * Drei Zahlen je Knoten: ausgeloest, zugestellt, fehlgeschlagen. Die Differenz
 * ist, was noch in der Warteschlange wartet — eine Mail mit drei Tagen
 * Verzoegerung ist drei Tage lang „ausgeloest" und sonst nichts, und das ist
 * richtig so.
 */
class MailStats
{
    /**
     * @return array<string, array{queued: int, sent: int, failed: int}>
     */
    public static function forFunnel(Funnel $funnel): array
    {
        // Ueber den Query Builder, nicht das Model: das Ergebnis sind Summen,
        // keine Auslieferungen, und ein Model mit erfundenen Attributen liest
        // sich wie eines mit Spalten, die es nicht gibt.
        $rows = DB::table((new FunnelMailDelivery)->getTable())
            ->where('funnel_id', $funnel->id)
            ->select('node_key', DB::raw('COUNT(*) as queued'), DB::raw('SUM(CASE WHEN sent_at IS NOT NULL THEN 1 ELSE 0 END) as sent'), DB::raw('SUM(CASE WHEN failed_at IS NOT NULL THEN 1 ELSE 0 END) as failed'))
            ->groupBy('node_key')
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->node_key] = [
                'queued' => (int) $row->queued,
                'sent' => (int) $row->sent,
                'failed' => (int) $row->failed,
            ];
        }

        return $out;
    }
}
