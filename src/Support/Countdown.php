<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * A deadline on an offer, and the only kind worth having: one that is real.
 *
 * A countdown that merely counts is a lie told in Javascript. The number runs
 * out, the visitor reloads, and the offer is still there — which teaches them
 * that every deadline on the site is decoration. So the deadline here is
 * **enforced on the server**: past it, the offer step refuses to be accepted,
 * whatever the page says.
 *
 * Two shapes, and they answer different questions:
 *
 * - **fixed** — everyone shares one moment. A launch that closes on Friday.
 *   Honest, and it stops being useful on Saturday.
 * - **rolling** — each visitor gets their own window, counted from the first
 *   time *they* saw the step. The evergreen funnel. It is the one people mean
 *   and the one that needs saying out loud: the deadline is per visitor, and
 *   clearing cookies gets a new one. That is a property of the mechanism, not
 *   a bug, and pretending otherwise would mean identifying people to enforce it.
 *
 * The visitor's own end time is written to the walk the first time they arrive,
 * so a reload does not extend it and a second device does not reset it for a
 * walk already under way.
 */
class Countdown
{
    public const NONE = 'none';

    public const FIXED = 'fixed';

    public const ROLLING = 'rolling';

    /** @return list<string> */
    public static function kinds(): array
    {
        return [self::NONE, self::FIXED, self::ROLLING];
    }

    /**
     * When this visitor's window closes on this step, or null if it does not.
     *
     * Writes the end time on first sight for a rolling window. Reading is
     * therefore not free of side effects, and that is deliberate: the moment
     * somebody is shown the offer is the moment their clock starts, and any
     * other place to start it would be one they could avoid.
     */
    public static function endsAt(FunnelStep $step, ?FunnelVisit $visit): ?Carbon
    {
        $kind = (string) ($step->config('countdown') ?: self::NONE);

        if ($kind === self::FIXED) {
            return self::parse((string) $step->config('countdown_until'));
        }

        if ($kind !== self::ROLLING) {
            return null;
        }

        $hours = (int) $step->config('countdown_hours');

        if ($hours <= 0) {
            return null;
        }

        // No walk, no clock. In a preview there is no visitor to time, so the
        // page renders with a window that starts now and is never written down.
        if (! $visit) {
            return Carbon::now()->addHours($hours);
        }

        $meta = $visit->meta ?? [];
        $stored = $meta['countdowns'][$step->node_key] ?? null;

        if (is_string($stored) && ($parsed = self::parse($stored))) {
            return $parsed;
        }

        $ends = Carbon::now()->addHours($hours);

        $meta['countdowns'][$step->node_key] = $ends->toIso8601String();
        $visit->forceFill(['meta' => $meta])->save();

        return $ends;
    }

    /** Whether this visitor is too late. */
    public static function expired(FunnelStep $step, ?FunnelVisit $visit): bool
    {
        $ends = self::endsAt($step, $visit);

        return $ends !== null && $ends->isPast();
    }

    /**
     * What a template needs to draw one, or null when there is no deadline.
     *
     * `seconds` is the honest number at render time; the page ticks it down
     * from there. `ends_at` is what a script should trust after that, because a
     * tab left open for an hour has a `seconds` that is an hour stale.
     *
     * @return array{ends_at: string, seconds: int, expired: bool}|null
     */
    public static function forTemplate(FunnelStep $step, ?FunnelVisit $visit): ?array
    {
        $ends = self::endsAt($step, $visit);

        if (! $ends) {
            return null;
        }

        return [
            'ends_at' => $ends->toIso8601String(),
            'seconds' => max(0, $ends->diffInSeconds(Carbon::now(), absolute: false) * -1),
            'expired' => $ends->isPast(),
        ];
    }

    /**
     * A configured moment, or null if it is not one.
     *
     * An unreadable date is treated as no deadline rather than as one that has
     * passed. A typo in the Control Panel should leave an offer buyable, not
     * close it for everybody.
     */
    protected static function parse(string $value): ?Carbon
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
