<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\Countdown;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\Split;
use Goldnead\StatamicFunnels\Support\StepStats;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use PHPUnit\Framework\Attributes\Test;

/**
 * A deadline that holds, and a test that can be counted.
 *
 * Both are features whose whole worth is in one property, and both are usually
 * built without it:
 *
 * - A countdown is worth nothing unless the **server** keeps it. Otherwise the
 *   number runs out, the visitor reloads, and the offer is still there.
 * - A split test is worth nothing unless the version is **stable per visitor**
 *   and **recorded**. Otherwise the numbers underneath are about refreshing.
 *
 * So that is what these press on.
 */
class CountdownAndSplitTest extends TestCase
{
    protected function funnel(array $offerConfig = []): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->create(['node_key' => 'entry_1', 'type' => 'entry', 'config' => ['headline' => 'Start']]);
        $funnel->steps()->create([
            'node_key' => 'offer_1', 'type' => 'offer', 'slug' => 'angebot',
            'config' => array_merge(['offer' => 'cd'], $offerConfig),
        ]);
        $funnel->steps()->create(['node_key' => 'danke_1', 'type' => 'finish', 'slug' => 'danke', 'config' => ['headline' => 'Danke']]);
        $funnel->edges()->create(['from_node_key' => 'entry_1', 'to_node_key' => 'offer_1', 'from_output' => 'default']);
        $funnel->edges()->create(['from_node_key' => 'offer_1', 'to_node_key' => 'danke_1', 'from_output' => 'accepted']);

        Offer::create(['handle' => 'cd', 'name' => 'Begleit-CD', 'product' => 'begleit-cd', 'active' => true]);

        return $funnel->fresh(['steps', 'edges']);
    }

    protected function step(Funnel $funnel, string $key)
    {
        return $funnel->steps->firstWhere('node_key', $key);
    }

    /**
     * One visitor across several requests.
     *
     * The walk lives in a cookie, and the test client does not carry one between
     * requests on its own — without this each `get()` would start a new walk and
     * a deadline test would be measuring three different visitors.
     */
    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    // ---------------------------------------------------------------- Frist

    #[Test]
    public function without_a_deadline_nothing_changes(): void
    {
        $funnel = $this->funnel();

        $this->assertNull(Countdown::forTemplate($this->step($funnel, 'offer_1'), null));
        $this->assertFalse(Countdown::expired($this->step($funnel, 'offer_1'), null));
    }

    #[Test]
    public function a_fixed_deadline_is_the_same_moment_for_everybody(): void
    {
        $funnel = $this->funnel([
            'countdown' => Countdown::FIXED,
            'countdown_until' => now()->addDays(2)->toDateTimeString(),
        ]);

        $step = $this->step($funnel, 'offer_1');

        $one = $funnel->visits()->create(['token' => 'a']);
        $two = $funnel->visits()->create(['token' => 'b']);

        $this->assertEquals(
            Countdown::endsAt($step, $one)->toIso8601String(),
            Countdown::endsAt($step, $two)->toIso8601String(),
        );
    }

    #[Test]
    public function a_rolling_window_starts_when_this_visitor_first_looks(): void
    {
        $funnel = $this->funnel(['countdown' => Countdown::ROLLING, 'countdown_hours' => 48]);
        $step = $this->step($funnel, 'offer_1');

        $visit = $funnel->visits()->create(['token' => 'a']);

        $first = Countdown::endsAt($step, $visit);

        // Two days later somebody else arrives and gets their own two days.
        $this->travel(2)->days();

        $other = $funnel->visits()->create(['token' => 'b']);

        $this->assertTrue(Countdown::endsAt($step, $other)->isAfter($first));
    }

    #[Test]
    public function reloading_does_not_extend_the_window(): void
    {
        $funnel = $this->funnel(['countdown' => Countdown::ROLLING, 'countdown_hours' => 1]);
        $step = $this->step($funnel, 'offer_1');

        $visit = $funnel->visits()->create(['token' => 'a']);

        $first = Countdown::endsAt($step, $visit);

        $this->travel(30)->minutes();

        // The whole point of writing it down: a page that recomputed the window
        // on every render would never run out for anybody who kept reloading.
        $this->assertSame($first->toIso8601String(), Countdown::endsAt($step, $visit->fresh())->toIso8601String());
    }

    #[Test]
    public function an_unreadable_deadline_leaves_the_offer_open(): void
    {
        $funnel = $this->funnel(['countdown' => Countdown::FIXED, 'countdown_until' => 'irgendwann']);

        // A typo in the Control Panel should not close an offer for everybody.
        $this->assertFalse(Countdown::expired($this->step($funnel, 'offer_1'), null));
    }

    #[Test]
    public function a_late_order_is_refused_by_the_server(): void
    {
        $this->funnel([
            'countdown' => Countdown::FIXED,
            'countdown_until' => now()->addHour()->toDateTimeString(),
        ]);

        // Reach the offer while it is still open.
        $this->asVisitor()->get('/f/kurs')->assertOk();
        $this->asVisitor()->get('/f/kurs/angebot')->assertOk();

        $this->travel(2)->hours();

        // The page in a stale tab still shows a button. The server does not care.
        $this->asVisitor()
            ->post('/f/kurs/offer_1/advance', ['accept' => 1, 'confirmed' => 1])
            ->assertSessionHasErrors('offer');

        $this->assertSame(0, Payment::count());
    }

    #[Test]
    public function declining_still_works_after_the_deadline(): void
    {
        $funnel = $this->funnel([
            'countdown' => Countdown::FIXED,
            'countdown_until' => now()->addHour()->toDateTimeString(),
        ]);

        $this->asVisitor()->get('/f/kurs')->assertOk();
        $this->asVisitor()->get('/f/kurs/angebot')->assertOk();

        $this->travel(2)->hours();

        // A closed offer is not a closed funnel. Somebody standing on an expired
        // page must still be able to move on rather than being stuck.
        $this->asVisitor()
            ->post('/f/kurs/offer_1/advance', ['accept' => 0])
            ->assertSessionHasNoErrors();
    }

    #[Test]
    public function a_preview_is_never_late(): void
    {
        $funnel = $this->funnel(['countdown' => Countdown::ROLLING, 'countdown_hours' => 24]);

        // No walk, no clock that was written down: an editor looking at the
        // step gets a fresh window and leaves no trace of it.
        $shown = Countdown::forTemplate($this->step($funnel, 'offer_1'), null);

        $this->assertFalse($shown['expired']);
        $this->assertSame(0, FunnelVisit::count());
    }

    // ------------------------------------------------------------- A/B-Test

    #[Test]
    public function a_step_with_no_second_version_is_not_running_a_test(): void
    {
        $funnel = $this->funnel(['split_share' => 50]);

        // A share without anything to show is a coin toss over one page.
        $this->assertFalse(Split::running($this->step($funnel, 'offer_1')));
    }

    #[Test]
    public function a_share_of_zero_or_a_hundred_is_not_a_test(): void
    {
        foreach ([0, 100] as $share) {
            $funnel = Funnel::create(['handle' => 'k'.$share, 'title' => 'K', 'published' => true]);
            $step = $funnel->steps()->create([
                'node_key' => 'offer_1', 'type' => 'offer',
                'config' => ['split_share' => $share, 'variant_headline' => 'B'],
            ]);

            $this->assertFalse(Split::running($step), 'share '.$share);
        }
    }

    /**
     * An empty share means off, and it used to mean fifty-fifty.
     *
     * The most natural way to work — write the B version first, set the share
     * afterwards — silently started a live test on half of all visitors, on a
     * version its author believed unpublished. Both the README and the field
     * help said an empty share was no test; only the code disagreed, and
     * nothing on the screen showed it. The numbers came back looking like a
     * deliberate experiment.
     */
    #[Test]
    public function an_empty_share_is_not_a_test_even_with_a_variant_written(): void
    {
        foreach ([null, ''] as $leer) {
            $funnel = Funnel::create(['handle' => 'leer'.(int) is_string($leer), 'title' => 'L', 'published' => true]);
            $step = $funnel->steps()->create([
                'node_key' => 'offer_1', 'type' => 'offer',
                'config' => ['split_share' => $leer, 'variant_headline' => 'Fassung B'],
            ]);

            $this->assertSame(0, Split::share($step));
            $this->assertFalse(Split::running($step), 'leerer Anteil: '.var_export($leer, true));
        }
    }

    /**
     * And the half that must not change: a share somebody actually typed still
     * runs. Turning empty into "off" would be worthless if it also turned a
     * configured test off.
     */
    #[Test]
    public function a_share_that_was_typed_still_runs(): void
    {
        $funnel = $this->funnel(['split_share' => 50, 'variant_headline' => 'Fassung B']);

        $this->assertTrue(Split::running($this->step($funnel, 'offer_1')));
    }

    #[Test]
    public function a_visitor_sees_the_same_version_every_time(): void
    {
        $funnel = $this->funnel(['split_share' => 50, 'variant_headline' => 'Fassung B']);
        $step = $this->step($funnel, 'offer_1');

        $visit = $funnel->visits()->create(['token' => 'ein-fester-token']);

        $first = Split::variantFor($step, $visit);

        foreach (range(1, 20) as $ignored) {
            $this->assertSame($first, Split::variantFor($step, $visit->fresh()));
        }
    }

    #[Test]
    public function both_versions_actually_get_traffic(): void
    {
        $funnel = $this->funnel(['split_share' => 50, 'variant_headline' => 'Fassung B']);
        $step = $this->step($funnel, 'offer_1');

        $seen = [];

        foreach (range(1, 60) as $i) {
            $visit = $funnel->visits()->create(['token' => 'besucher-'.$i]);
            $seen[Split::variantFor($step, $visit)] = true;
        }

        // A split that only ever produces one answer is the bug that hides
        // behind a hash that looked fine.
        $this->assertArrayHasKey(Split::A, $seen);
        $this->assertArrayHasKey(Split::B, $seen);
    }

    #[Test]
    public function only_the_fields_b_sets_are_swapped(): void
    {
        $funnel = $this->funnel([
            'split_share' => 100,
            'headline' => 'Fassung A',
            'body' => 'Der Text, der bleiben soll',
            'variant_headline' => 'Fassung B',
        ]);

        $step = $this->step($funnel, 'offer_1');

        $context = Split::apply($step, Split::B, ['headline' => 'Fassung A', 'body' => 'Der Text, der bleiben soll']);

        $this->assertSame('Fassung B', $context['headline']);
        // A test that changes one headline must not silently blank the body.
        $this->assertSame('Der Text, der bleiben soll', $context['body']);
    }

    #[Test]
    public function the_version_is_written_onto_the_arrival(): void
    {
        $funnel = $this->funnel(['split_share' => 50, 'variant_headline' => 'Fassung B']);

        $this->asVisitor()->get('/f/kurs')->assertOk();
        $this->asVisitor()->get('/f/kurs/angebot')->assertOk();

        $arrival = FunnelStepEvent::query()
            ->where('node_key', 'offer_1')
            ->where('event', FunnelStepEvent::ENTERED)
            ->first();

        // Without this the test cannot be counted afterwards, which makes it a
        // coin toss with extra steps.
        $this->assertNotNull($arrival);
        $this->assertContains($arrival->payload['variant'] ?? null, [Split::A, Split::B]);
    }

    #[Test]
    public function the_numbers_can_be_read_per_version(): void
    {
        $funnel = $this->funnel(['split_share' => 50, 'variant_headline' => 'Fassung B']);

        // Two visitors on A, one of whom carried on. One on B, who did not.
        foreach ([[Split::A, true], [Split::A, false], [Split::B, false]] as [$variant, $carriedOn]) {
            $visit = $funnel->visits()->create(['token' => uniqid()]);
            FunnelStepEvent::create([
                'visit_id' => $visit->id, 'node_key' => 'offer_1',
                'event' => FunnelStepEvent::ENTERED, 'payload' => ['variant' => $variant],
            ]);

            if ($carriedOn) {
                FunnelStepEvent::create(['visit_id' => $visit->id, 'node_key' => 'danke_1', 'event' => FunnelStepEvent::ENTERED]);
            }
        }

        $split = StepStats::byVariant($funnel->fresh(['steps', 'edges', 'visits']));

        $this->assertSame(2, $split['offer_1'][Split::A]['visits']);
        $this->assertSame(1, $split['offer_1'][Split::A]['continued']);
        $this->assertEqualsWithDelta(50.0, $split['offer_1'][Split::A]['rate'], 0.05);

        $this->assertSame(1, $split['offer_1'][Split::B]['visits']);
        $this->assertEqualsWithDelta(0.0, $split['offer_1'][Split::B]['rate'], 0.05);
    }

    #[Test]
    public function a_step_that_is_not_testing_is_left_out_of_the_split_report(): void
    {
        $funnel = $this->funnel();

        // A funnel where every card sprouted an A and a B would bury the one
        // number that matters under two that are the same.
        $this->assertSame([], StepStats::byVariant($funnel->fresh(['steps', 'edges', 'visits'])));
    }
}
