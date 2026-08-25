<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;

/**
 * Showing two versions of a page and finding out which one works.
 *
 * The whole thing is one decision made once per visitor per step, and three
 * properties it has to have:
 *
 * 1. **Stable.** A visitor who reloads sees the same version. Splitting on
 *    every render would show somebody A, then B, then A again, and the numbers
 *    underneath would be about nothing.
 * 2. **Deterministic from what we already have.** The walk token, hashed with
 *    the step key. No extra cookie, no random number to store, and the same
 *    visitor lands the same way on a second device only if they carry the same
 *    walk — which is exactly the definition of "the same walk".
 * 3. **Recorded.** The variant is written onto the step's `entered` event, so
 *    the drop-off numbers can be split by it afterwards. A test that cannot be
 *    counted is a coin toss with extra steps.
 *
 * Splitting per *step* rather than per funnel is deliberate: it lets two tests
 * run at once without one deciding the other, and it means the hash of a token
 * that lands in A on one step is not the same token that lands in A everywhere.
 */
class Split
{
    public const A = 'a';

    public const B = 'b';

    /** Whether this step is running a test at all. */
    public static function running(FunnelStep $step): bool
    {
        $share = self::share($step);

        // 0 and 100 both mean "everybody sees one version", which is not a test
        // and should not be recorded as one.
        return $share > 0 && $share < 100 && self::hasVariant($step);
    }

    /**
     * How much of the traffic B is meant to get, as a whole percentage.
     *
     * Clamped rather than validated away: a site that typed 150 wants more B,
     * not an error, and a page that refused to render over a split percentage
     * would be a strange thing to explain.
     */
    public static function share(FunnelStep $step): int
    {
        $raw = $step->config('split_share');

        if ($raw === null || $raw === '') {
            return 50;
        }

        return max(0, min(100, (int) $raw));
    }

    /** Whether B is actually different from A. */
    protected static function hasVariant(FunnelStep $step): bool
    {
        foreach (['variant_entry', 'variant_template', 'variant_headline', 'variant_body'] as $key) {
            $value = $step->config($key);

            if (is_string($value) && trim($value) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Which version this visitor gets, decided once and remembered.
     *
     * Without a walk — a preview — the answer is A, and nothing is written. An
     * editor looking at their own funnel must not be counted into their own
     * experiment.
     */
    public static function variantFor(FunnelStep $step, ?FunnelVisit $visit): string
    {
        if (! self::running($step)) {
            return self::A;
        }

        if (! $visit) {
            return self::A;
        }

        $meta = $visit->meta ?? [];
        $stored = $meta['variants'][$step->node_key] ?? null;

        if ($stored === self::A || $stored === self::B) {
            return $stored;
        }

        $variant = self::decide((string) $visit->token, $step->node_key, self::share($step));

        $meta['variants'][$step->node_key] = $variant;
        $visit->forceFill(['meta' => $meta])->save();

        return $variant;
    }

    /**
     * The coin, and it is not random.
     *
     * `crc32` over token and step gives a value spread evenly enough for this
     * and, unlike `random_int()`, gives the same answer twice — which is what
     * makes a reload show the same page without storing anything first. The
     * stored value above is a convenience and a record, not the source of truth.
     */
    protected static function decide(string $token, string $nodeKey, int $share): string
    {
        $bucket = crc32($token.':'.$nodeKey) % 100;

        return $bucket < $share ? self::B : self::A;
    }

    /**
     * The step's own fields, with B's overrides applied where B has any.
     *
     * Only the fields B actually sets are swapped. A test that changes one
     * headline should not silently blank the body, and requiring somebody to
     * copy every field into the variant to change one of them is how a test
     * ends up comparing two things that differ in ways nobody meant.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function apply(FunnelStep $step, string $variant, array $context): array
    {
        if ($variant !== self::B) {
            return $context;
        }

        foreach (['entry', 'template', 'headline', 'body'] as $field) {
            $value = $step->config('variant_'.$field);

            if (is_string($value) && trim($value) !== '') {
                $context[$field] = $value;
            }
        }

        return $context;
    }
}
