<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Log;

/**
 * Welche Fassung eines A/B-Tests gewinnt, gemessen am gewaehlten Ziel (F2).
 *
 * Vier Ziele, alle aus Zeilen, die es schon gibt:
 *
 * - `continue`: weitergegangen, auf einen der Schritte, zu denen dieser fuehrt.
 *   Das war bisher die einzige Zahl, und sie bleibt die Vorgabe.
 * - `purchase`: ein Kauf auf diesem oder einem spaeteren Schritt. Gezaehlt
 *   wird `accepted`, und das schreibt erst der Webhook: eine Zahlung, die beim
 *   Anbieter haengt, ist kein Kauf.
 * - `upsell`: ein angenommenes Angebot auf einem **spaeteren** Schritt. Fuer
 *   den Test einer Kassenseite, bei dem die Frage ist, ob danach mehr gekauft
 *   wird, nicht ob hier gekauft wird.
 * - `revenue`: Umsatz je Besuch, aus den bezahlten Zahlungen dieser Kaeufe
 *   (abzueglich Erstattungen).
 *
 * **Ein Gewinner steht fest**, wenn jede Fassung die Mindestzahl an Besuchen
 * hat und der Unterschied mit 95 % Sicherheit kein Zufall ist: fuer die Quoten
 * ein Zwei-Stichproben-z-Test auf Anteile, fuer den Umsatz ein Welch-Test auf
 * die Mittelwerte (bei den Mindestzahlen hier normal angenaehert). Gespeichert
 * in `funnels.meta.split_winners`, nicht am Schritt: den Schritt schreibt der
 * Editor beim Speichern ganz neu, und ein Gewinner, den ein offenes Browserfenster
 * ueberschreibt, waere eine Laune.
 */
class SplitResults
{
    public const GOAL_CONTINUE = 'continue';

    public const GOAL_PURCHASE = 'purchase';

    public const GOAL_UPSELL = 'upsell';

    public const GOAL_REVENUE = 'revenue';

    /** Ab dieser Sicherheit gilt ein Unterschied als festgestellt. */
    public const CONFIDENCE = 0.95;

    public const DEFAULT_MIN_VISITS = 100;

    /** @return list<string> */
    public static function goals(): array
    {
        return [self::GOAL_CONTINUE, self::GOAL_PURCHASE, self::GOAL_UPSELL, self::GOAL_REVENUE];
    }

    /** @return list<array{value: string, label: string}> */
    public static function goalOptions(): array
    {
        return array_map(fn (string $goal) => [
            'value' => $goal,
            'label' => (string) __('statamic-funnels::nodes.split_goal_'.$goal),
        ], self::goals());
    }

    public static function goal(FunnelStep $step): string
    {
        $goal = $step->config('split_goal');

        return in_array($goal, self::goals(), true) ? $goal : self::GOAL_CONTINUE;
    }

    public static function auto(FunnelStep $step): bool
    {
        return filter_var($step->config('split_auto', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function minVisits(FunnelStep $step): int
    {
        $raw = $step->config('split_min_visits');

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : self::DEFAULT_MIN_VISITS;
    }

    /**
     * Die Ergebnisse aller laufenden Tests eines Funnels, fuer den Editor.
     *
     * Legt dabei fest, was feststeht: wer den Editor oeffnet, soll den Gewinner
     * sehen, sobald es einen gibt, und nicht erst nach dem naechsten Besucher.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function forFunnel(Funnel $funnel): array
    {
        $out = [];

        foreach ($funnel->steps as $step) {
            if (! Split::running($step)) {
                continue;
            }

            self::decide($funnel, $step);
            $out[$step->node_key] = self::forStep($funnel->fresh(['steps', 'edges']) ?? $funnel, $step);
        }

        return $out;
    }

    /**
     * @return array{goal: string, goal_label: string, auto: bool, min_visits: int, variants: array<string, array<string, mixed>>, confidence: float|null, leader: string|null, winner: string|null, decided_at: string|null}
     */
    public static function forStep(Funnel $funnel, FunnelStep $step): array
    {
        $step->setRelation('funnel', $funnel);

        $goal = self::goal($step);
        $visitIds = $funnel->visits()->select('id');

        $rows = FunnelStepEvent::query()
            ->whereIn('visit_id', $visitIds)
            ->where('node_key', $step->node_key)
            ->where('event', FunnelStepEvent::ENTERED)
            ->get(['visit_id', 'payload']);

        /** @var array<string, array<int, true>> $seen */
        $seen = [Split::A => [], Split::B => []];

        foreach ($rows as $row) {
            $variant = $row->payload['variant'] ?? Split::A;

            if (isset($seen[$variant])) {
                $seen[$variant][(int) $row->visit_id] = true;
            }
        }

        $downstream = self::downstream($funnel, $step->node_key);
        $direct = self::targets($funnel)[$step->node_key] ?? [];

        $variants = [];

        foreach ($seen as $variant => $ids) {
            $variants[$variant] = self::measure($goal, array_keys($ids), $step->node_key, $direct, $downstream);
        }

        [$confidence, $leader] = self::compare($goal, $variants[Split::A], $variants[Split::B]);

        $stored = Split::winner($step);

        return [
            'goal' => $goal,
            'goal_label' => (string) __('statamic-funnels::nodes.split_goal_'.$goal),
            'auto' => self::auto($step),
            'min_visits' => self::minVisits($step),
            // Ohne die Werte je Besuch: der Editor braucht die Summen.
            'variants' => array_map(fn (array $v) => array_diff_key($v, ['values' => true]), $variants),
            'confidence' => $confidence,
            'leader' => $leader,
            'winner' => $stored,
            'decided_at' => $stored !== null
                ? (string) (($funnel->meta['split_winners'][$step->node_key]['decided_at'] ?? null) ?: '')
                : null,
        ];
    }

    /**
     * Den Gewinner festlegen, wenn er feststeht. Gibt ihn zurueck, sonst null.
     *
     * Nur mit Automatik, nur ueber der Mindestzahl in **beiden** Fassungen,
     * nur ab 95 % Sicherheit. Einmal festgelegt, bleibt er, solange das Ziel
     * dasselbe ist; wer das Ziel wechselt, startet die Auswertung neu.
     */
    public static function decide(Funnel $funnel, FunnelStep $step): ?string
    {
        if (! self::auto($step) || ! Split::running($step)) {
            return null;
        }

        $step->setRelation('funnel', $funnel);

        if (($schon = Split::winner($step)) !== null) {
            return $schon;
        }

        $r = self::forStep($funnel, $step);
        $min = self::minVisits($step);

        if ($r['variants'][Split::A]['visits'] < $min || $r['variants'][Split::B]['visits'] < $min) {
            return null;
        }

        if ($r['confidence'] === null || $r['confidence'] < self::CONFIDENCE || $r['leader'] === null) {
            return null;
        }

        $meta = $funnel->fresh()->meta ?? [];
        $meta['split_winners'][$step->node_key] = [
            'variant' => $r['leader'],
            'goal' => $r['goal'],
            'confidence' => round($r['confidence'], 4),
            'decided_at' => now()->toIso8601String(),
        ];

        $funnel->forceFill(['meta' => $meta])->save();

        Log::info('statamic-funnels: ein A/B-Test hat einen Gewinner.', [
            'funnel' => $funnel->handle,
            'step' => $step->node_key,
            'winner' => $r['leader'],
            'goal' => $r['goal'],
        ]);

        return $r['leader'];
    }

    /**
     * Eine Fassung, gemessen.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $direct
     * @param  list<string>  $downstream
     * @return array{visits: int, conversions: int, rate: float|null, revenue_cent: int, revenue_per_visit_cent: int|null, values: list<int>}
     */
    protected static function measure(string $goal, array $ids, string $self, array $direct, array $downstream): array
    {
        $visits = count($ids);
        $conversions = 0;
        $revenue = 0;
        $values = [];

        if ($visits > 0) {
            if ($goal === self::GOAL_CONTINUE) {
                $conversions = $direct === [] ? 0 : FunnelStepEvent::query()
                    ->whereIn('visit_id', $ids)
                    ->whereIn('node_key', $direct)
                    ->where('event', FunnelStepEvent::ENTERED)
                    ->distinct()
                    ->count('visit_id');
            } else {
                $nodes = $goal === self::GOAL_UPSELL ? $downstream : array_merge([$self], $downstream);

                $accepted = $nodes === [] ? collect() : FunnelStepEvent::query()
                    ->whereIn('visit_id', $ids)
                    ->whereIn('node_key', $nodes)
                    ->where('event', FunnelStepEvent::ACCEPTED)
                    ->get(['visit_id', 'payload']);

                $conversions = $accepted->pluck('visit_id')->unique()->count();

                if ($goal === self::GOAL_REVENUE) {
                    $paymentIds = $accepted->map(fn ($e) => $e->payload['payment_id'] ?? null)->filter()->unique()->values()->all();

                    $amounts = $paymentIds === [] ? collect() : Payment::query()
                        ->whereIn('id', $paymentIds)
                        ->get()
                        ->filter(fn (Payment $p) => $p->isPaid())
                        ->mapWithKeys(fn (Payment $p) => [$p->id => max(0, (int) $p->amount_cent - (int) ($p->refunded_cent ?? 0))]);

                    $perVisit = array_fill_keys($ids, 0);

                    foreach ($accepted as $event) {
                        $pid = $event->payload['payment_id'] ?? null;

                        if ($pid !== null && isset($amounts[$pid])) {
                            $perVisit[(int) $event->visit_id] += $amounts[$pid];
                            // Eine Zahlung zaehlt einmal, auch wenn zwei
                            // Wegmarken auf sie zeigen.
                            unset($amounts[$pid]);
                        }
                    }

                    $values = array_values($perVisit);
                    $revenue = array_sum($values);
                }
            }
        }

        return [
            'visits' => $visits,
            'conversions' => $conversions,
            'rate' => $visits > 0 && $goal !== self::GOAL_REVENUE ? round($conversions / $visits * 100, 1) : null,
            'revenue_cent' => $revenue,
            'revenue_per_visit_cent' => $goal === self::GOAL_REVENUE && $visits > 0 ? (int) round($revenue / $visits) : null,
            'values' => $values,
        ];
    }

    /**
     * Sicherheit des Unterschieds und die vorn liegende Fassung.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     * @return array{0: float|null, 1: string|null}
     */
    protected static function compare(string $goal, array $a, array $b): array
    {
        $na = $a['visits'];
        $nb = $b['visits'];

        if ($na < 2 || $nb < 2) {
            return [null, null];
        }

        if ($goal === self::GOAL_REVENUE) {
            $ma = $a['revenue_cent'] / $na;
            $mb = $b['revenue_cent'] / $nb;
            $se = sqrt(self::variance($a['values'], $ma) / $na + self::variance($b['values'], $mb) / $nb);
            $diff = $mb - $ma;
        } else {
            $pa = $a['conversions'] / $na;
            $pb = $b['conversions'] / $nb;
            $p = ($a['conversions'] + $b['conversions']) / ($na + $nb);
            $se = sqrt($p * (1 - $p) * (1 / $na + 1 / $nb));
            $diff = $pb - $pa;
        }

        if ($diff == 0.0) {
            return [$se > 0 ? 0.0 : null, null];
        }

        $leader = $diff > 0 ? Split::B : Split::A;

        if ($se <= 0) {
            return [null, $leader];
        }

        $z = abs($diff) / $se;

        return [round(2 * self::phi($z) - 1, 4), $leader];
    }

    /** @param  list<int>  $values */
    protected static function variance(array $values, float $mean): float
    {
        $n = count($values);

        if ($n < 2) {
            return 0.0;
        }

        $sum = 0.0;

        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return $sum / ($n - 1);
    }

    /** Standardnormalverteilung, Abramowitz/Stegun 7.1.26 (Fehler unter 1,5e-7). */
    protected static function phi(float $z): float
    {
        $x = abs($z) / M_SQRT2;
        $t = 1 / (1 + 0.3275911 * $x);
        $erf = 1 - (((((1.061405429 * $t - 1.453152027) * $t) + 1.421413741) * $t - 0.284496736) * $t + 0.254829592) * $t * exp(-$x * $x);

        return 0.5 * (1 + ($z < 0 ? -$erf : $erf));
    }

    /**
     * Alle Schritte, die hinter diesem liegen, ohne Mails und ohne ihn selbst.
     *
     * @return list<string>
     */
    protected static function downstream(Funnel $funnel, string $from): array
    {
        $targets = self::targets($funnel);
        $seen = [];
        $queue = $targets[$from] ?? [];

        while ($queue !== []) {
            $key = array_shift($queue);

            if ($key === $from || isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            foreach ($targets[$key] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        return array_keys($seen);
    }

    /** @return array<string, list<string>> */
    protected static function targets(Funnel $funnel): array
    {
        $mail = $funnel->steps->where('type', 'mail')->pluck('node_key')->flip()->all();
        $targets = [];

        foreach ($funnel->edges as $edge) {
            if (! isset($mail[$edge->to_node_key])) {
                $targets[$edge->from_node_key][] = $edge->to_node_key;
            }
        }

        return $targets;
    }
}
