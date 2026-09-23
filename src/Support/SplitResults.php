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
 * **Entschieden wird genau einmal, mit fester Stichprobe.** Sobald jede Fassung
 * die Mindestzahl an Besuchen hat (mindestens 100) und deren Letzter Zeit zum
 * Kaufen hatte (einen Tag, beim Ziel `continue` eine Stunde), werden die
 * **ersten** `min` Besuche je Fassung verglichen: fuer die Quoten ein
 * Zwei-Stichproben-z-Test auf Anteile, fuer den Umsatz ein Welch-Test auf die
 * Mittelwerte (normal angenaehert). Liegt eine mit 95 % Sicherheit vorn, ist
 * sie Gewinner; sonst steht „kein Unterschied" fest, und beide laufen weiter.
 *
 * Warum nicht bei jedem Aufruf neu: wer nach jedem Besuch nachsieht und beim
 * ersten Mal ueber 95 % aufhoert, findet bei zwei gleichen Fassungen in rund
 * einem Drittel der Faelle einen „Gewinner" (Kritik 23.09.2026, A/A-Simulation).
 * Mit einer festen Stichprobe bleibt der Fehler bei den zugesagten 5 %.
 *
 * Gespeichert in `funnels.meta.split_winners`, nicht am Schritt: den Schritt
 * schreibt der Editor beim Speichern ganz neu.
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

    /** Darunter ist ein z-Test auf Anteile keine Aussage. */
    public const FLOOR_MIN_VISITS = 100;

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

        $min = is_numeric($raw) && (int) $raw > 0 ? (int) $raw : self::DEFAULT_MIN_VISITS;

        return max(self::FLOOR_MIN_VISITS, $min);
    }

    /** Wie lange der letzte Besuch der Stichprobe Zeit zum Kaufen hatte, bevor entschieden wird. */
    public static function settleSeconds(string $goal): int
    {
        return $goal === self::GOAL_CONTINUE ? 3600 : 86400;
    }

    /**
     * Die Entscheidung mit fester Stichprobe, rein rechnerisch.
     *
     * Null, solange eine Fassung weniger als `min` Ergebnisse hat. Sonst die
     * ersten `min` jeder Fassung verglichen, einmal: `winner` ist die vorn
     * liegende bei mindestens 95 % Sicherheit, sonst null.
     *
     * @param  list<int|float>  $a  Ergebnis je Besuch in Reihenfolge des Eintritts (0/1 oder Cent)
     * @param  list<int|float>  $b
     * @return array{winner: string|null, confidence: float|null, leader: string|null}|null
     */
    public static function fixedHorizon(string $goal, array $a, array $b, int $min): ?array
    {
        if (count($a) < $min || count($b) < $min) {
            return null;
        }

        [$confidence, $leader] = self::verdict($goal, array_slice($a, 0, $min), array_slice($b, 0, $min));

        return [
            'winner' => $confidence !== null && $confidence >= self::CONFIDENCE ? $leader : null,
            'confidence' => $confidence,
            'leader' => $leader,
        ];
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
        $variants = self::variantsFor($funnel, $step);

        [$confidence, $leader] = self::verdict($goal, $variants[Split::A]['outcomes'], $variants[Split::B]['outcomes']);

        $decision = self::decision($funnel, $step);

        return [
            'goal' => $goal,
            'goal_label' => (string) __('statamic-funnels::nodes.split_goal_'.$goal),
            'auto' => self::auto($step),
            'min_visits' => self::minVisits($step),
            // Ohne die Werte je Besuch: der Editor braucht die Summen.
            'variants' => array_map(fn (array $v) => array_diff_key($v, ['outcomes' => true, 'entered_at' => true]), $variants),
            // Der laufende Stand, ausdruecklich kein Ergebnis.
            'confidence' => $confidence,
            'leader' => $leader,
            'winner' => $decision['variant'] ?? null,
            'decided' => $decision !== null,
            'decided_at' => $decision['decided_at'] ?? null,
            'decided_confidence' => $decision['confidence'] ?? null,
        ];
    }

    /**
     * Die gespeicherte Entscheidung fuer das aktuelle Ziel, oder null.
     *
     * @return array{variant: string|null, goal: string, confidence: float|null, decided_at: string, sample: int}|null
     */
    public static function decision(Funnel $funnel, FunnelStep $step): ?array
    {
        $stored = ($funnel->meta ?? [])['split_winners'][$step->node_key] ?? null;

        // Ohne `sample` stammt die Entscheidung aus der Zeit vor der festen
        // Stichprobe (nach jedem Aufruf neu, siehe oben) und zaehlt nicht.
        if (! is_array($stored) || ($stored['goal'] ?? null) !== self::goal($step)
            || ! array_key_exists('decided_at', $stored) || ! array_key_exists('sample', $stored)) {
            return null;
        }

        return $stored;
    }

    /**
     * Einmal entscheiden, wenn die feste Stichprobe voll ist. Gibt den Gewinner
     * zurueck, sonst null (noch nicht entschieden, oder kein Unterschied).
     *
     * Nur mit Automatik. Einmal entschieden, bleibt es dabei, solange das Ziel
     * dasselbe ist; wer das Ziel wechselt, startet neu.
     */
    public static function decide(Funnel $funnel, FunnelStep $step): ?string
    {
        if (! self::auto($step) || ! Split::running($step)) {
            return null;
        }

        $step->setRelation('funnel', $funnel);

        if (($schon = self::decision($funnel, $step)) !== null) {
            return $schon['variant'];
        }

        $goal = self::goal($step);
        $min = self::minVisits($step);
        $r = self::variantsFor($funnel, $step);

        // Der `min`-te Besuch jeder Fassung muss Zeit zum Kaufen gehabt haben:
        // sonst waeren die letzten der Stichprobe Nicht-Kaeufer, nur weil der
        // Webhook noch nicht da war.
        foreach ([Split::A, Split::B] as $v) {
            $zeitpunkt = $r[$v]['entered_at'][$min - 1] ?? null;

            if ($zeitpunkt === null || $zeitpunkt->getTimestamp() > now()->getTimestamp() - self::settleSeconds($goal)) {
                return null;
            }
        }

        $ergebnis = self::fixedHorizon($goal, $r[Split::A]['outcomes'], $r[Split::B]['outcomes'], $min);

        if ($ergebnis === null) {
            return null;
        }

        $meta = $funnel->fresh()->meta ?? [];
        $meta['split_winners'][$step->node_key] = [
            'variant' => $ergebnis['winner'],
            'goal' => $goal,
            'confidence' => $ergebnis['confidence'] === null ? null : round($ergebnis['confidence'], 4),
            'decided_at' => now()->toIso8601String(),
            'sample' => $min,
        ];

        $funnel->forceFill(['meta' => $meta])->save();

        Log::info('statamic-funnels: ein A/B-Test ist entschieden.', [
            'funnel' => $funnel->handle,
            'step' => $step->node_key,
            'winner' => $ergebnis['winner'],
            'goal' => $goal,
            'sample' => $min,
        ]);

        return $ergebnis['winner'];
    }

    /**
     * Beide Fassungen gemessen, mit Ergebnis und Eintrittszeit je Besuch.
     *
     * @return array<string, array<string, mixed>>
     */
    protected static function variantsFor(Funnel $funnel, FunnelStep $step): array
    {
        $goal = self::goal($step);

        // In der Reihenfolge des Eintritts: die feste Stichprobe sind die
        // ersten `min` Besuche je Fassung, nicht irgendwelche.
        $rows = FunnelStepEvent::query()
            ->whereIn('visit_id', $funnel->visits()->select('id'))
            ->where('node_key', $step->node_key)
            ->where('event', FunnelStepEvent::ENTERED)
            ->orderBy('id')
            ->get(['visit_id', 'payload', 'created_at']);

        $seen = [Split::A => [], Split::B => []];

        foreach ($rows as $row) {
            $variant = $row->payload['variant'] ?? Split::A;

            if (isset($seen[$variant]) && ! array_key_exists((int) $row->visit_id, $seen[$variant])) {
                $seen[$variant][(int) $row->visit_id] = $row->created_at;
            }
        }

        $downstream = self::downstream($funnel, $step->node_key);
        $direct = self::targets($funnel)[$step->node_key] ?? [];

        $variants = [];

        foreach ($seen as $variant => $ids) {
            $variants[$variant] = self::measure($goal, array_keys($ids), $step->node_key, $direct, $downstream)
                + ['entered_at' => array_values($ids)];
        }

        return $variants;
    }

    /**
     * Eine Fassung, gemessen.
     *
     * @param  list<int>  $ids
     * @param  list<string>  $direct
     * @param  list<string>  $downstream
     * @return array{visits: int, conversions: int, rate: float|null, revenue_cent: int|float, revenue_per_visit_cent: int|null, outcomes: list<int|float>}
     */
    protected static function measure(string $goal, array $ids, string $self, array $direct, array $downstream): array
    {
        $visits = count($ids);
        $conversions = 0;
        $revenue = 0;
        $values = [];
        $converted = [];

        if ($visits > 0) {
            if ($goal === self::GOAL_CONTINUE) {
                $converted = $direct === [] ? [] : FunnelStepEvent::query()
                    ->whereIn('visit_id', $ids)
                    ->whereIn('node_key', $direct)
                    ->where('event', FunnelStepEvent::ENTERED)
                    ->distinct()
                    ->pluck('visit_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $conversions = count($converted);
            } else {
                $nodes = $goal === self::GOAL_UPSELL ? $downstream : array_merge([$self], $downstream);

                $accepted = $nodes === [] ? collect() : FunnelStepEvent::query()
                    ->whereIn('visit_id', $ids)
                    ->whereIn('node_key', $nodes)
                    ->where('event', FunnelStepEvent::ACCEPTED)
                    ->get(['visit_id', 'payload']);

                $converted = $accepted->pluck('visit_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
                $conversions = count($converted);

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

        // Ergebnis je Besuch, in der Reihenfolge von `$ids`: Cent beim Umsatz,
        // sonst 0 oder 1.
        $flip = array_flip($converted);
        $outcomes = $goal === self::GOAL_REVENUE
            ? $values
            : array_map(fn (int $id) => isset($flip[$id]) ? 1 : 0, $ids);

        return [
            'visits' => $visits,
            'conversions' => $conversions,
            'rate' => $visits > 0 && $goal !== self::GOAL_REVENUE ? round($conversions / $visits * 100, 1) : null,
            'revenue_cent' => $revenue,
            'revenue_per_visit_cent' => $goal === self::GOAL_REVENUE && $visits > 0 ? (int) round($revenue / $visits) : null,
            'outcomes' => $outcomes,
        ];
    }

    /**
     * Sicherheit des Unterschieds und die vorn liegende Fassung, aus den
     * Ergebnissen je Besuch.
     *
     * @param  list<int|float>  $a
     * @param  list<int|float>  $b
     * @return array{0: float|null, 1: string|null}
     */
    public static function verdict(string $goal, array $a, array $b): array
    {
        $na = count($a);
        $nb = count($b);

        if ($na < 2 || $nb < 2) {
            return [null, null];
        }

        if ($goal === self::GOAL_REVENUE) {
            $ma = array_sum($a) / $na;
            $mb = array_sum($b) / $nb;
            $se = sqrt(self::variance($a, $ma) / $na + self::variance($b, $mb) / $nb);
            $diff = $mb - $ma;
        } else {
            $ca = array_sum($a);
            $cb = array_sum($b);
            $pa = $ca / $na;
            $pb = $cb / $nb;
            $p = ($ca + $cb) / ($na + $nb);
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
