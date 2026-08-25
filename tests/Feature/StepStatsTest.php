<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Support\StepStats;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Where people stop.
 *
 * The numbers only earn their place on the card if they are right, and the two
 * ways to get them wrong are both easy: counting page loads instead of people,
 * and showing a zero where the honest answer is "nobody has been here".
 */
class StepStatsTest extends TestCase
{
    protected function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->create(['node_key' => 'entry_1', 'type' => 'entry']);
        $funnel->steps()->create(['node_key' => 'offer_1', 'type' => 'offer', 'slug' => 'angebot']);
        $funnel->steps()->create(['node_key' => 'finish_1', 'type' => 'finish', 'slug' => 'danke']);
        $funnel->edges()->create(['from_node_key' => 'entry_1', 'to_node_key' => 'offer_1', 'from_output' => 'default']);
        $funnel->edges()->create(['from_node_key' => 'offer_1', 'to_node_key' => 'finish_1', 'from_output' => 'accepted']);

        return $funnel->fresh(['steps', 'edges', 'visits']);
    }

    protected function walk(Funnel $funnel, string $token, array $steps): void
    {
        $visit = $funnel->visits()->create(['token' => $token]);

        foreach ($steps as $key) {
            FunnelStepEvent::create([
                'visit_id' => $visit->id,
                'node_key' => $key,
                'event' => FunnelStepEvent::ENTERED,
            ]);
        }
    }

    #[Test]
    public function it_counts_people_not_page_loads(): void
    {
        $funnel = $this->funnel();

        // One person who reloaded the entry three times.
        $this->walk($funnel, 'a', ['entry_1', 'entry_1', 'entry_1']);

        $stats = StepStats::forFunnel($funnel->fresh(['steps', 'edges', 'visits']));

        $this->assertSame(1, $stats['entry_1']['visits']);
    }

    #[Test]
    public function continuing_means_reaching_a_step_this_one_leads_to(): void
    {
        $funnel = $this->funnel();

        $this->walk($funnel, 'a', ['entry_1', 'offer_1', 'finish_1']);
        $this->walk($funnel, 'b', ['entry_1', 'offer_1']);
        $this->walk($funnel, 'c', ['entry_1']);

        $stats = StepStats::forFunnel($funnel->fresh(['steps', 'edges', 'visits']));

        $this->assertSame(3, $stats['entry_1']['visits']);
        $this->assertSame(2, $stats['entry_1']['continued']);
        $this->assertEqualsWithDelta(66.7, $stats['entry_1']['rate'], 0.05);

        $this->assertSame(2, $stats['offer_1']['visits']);
        $this->assertSame(1, $stats['offer_1']['continued']);
        $this->assertEqualsWithDelta(50.0, $stats['offer_1']['rate'], 0.05);
    }

    #[Test]
    public function a_step_nobody_reached_has_no_rate_rather_than_a_rate_of_zero(): void
    {
        $funnel = $this->funnel();

        $stats = StepStats::forFunnel($funnel);

        $this->assertSame(0, $stats['offer_1']['visits']);
        // Null, not 0.0. A step that has never been seen has no conversion
        // rate, and "0 %" on the card reads as "this step is broken".
        $this->assertNull($stats['offer_1']['rate']);
    }

    #[Test]
    public function the_last_step_has_nowhere_to_continue_to(): void
    {
        $funnel = $this->funnel();

        $this->walk($funnel, 'a', ['entry_1', 'offer_1', 'finish_1']);

        $stats = StepStats::forFunnel($funnel->fresh(['steps', 'edges', 'visits']));

        $this->assertSame(1, $stats['finish_1']['visits']);
        $this->assertSame(0, $stats['finish_1']['continued']);
        // And it says so, so the card can leave the rate off entirely instead
        // of announcing that the thank-you page converts at 0 %.
        $this->assertTrue($stats['finish_1']['terminal']);
        $this->assertNull($stats['finish_1']['rate']);
        $this->assertFalse($stats['entry_1']['terminal']);
    }

    #[Test]
    public function accepting_and_declining_are_counted_separately(): void
    {
        $funnel = $this->funnel();

        $visit = $funnel->visits()->create(['token' => 'a']);
        FunnelStepEvent::create(['visit_id' => $visit->id, 'node_key' => 'offer_1', 'event' => FunnelStepEvent::ENTERED]);
        FunnelStepEvent::create(['visit_id' => $visit->id, 'node_key' => 'offer_1', 'event' => FunnelStepEvent::ACCEPTED]);

        $other = $funnel->visits()->create(['token' => 'b']);
        FunnelStepEvent::create(['visit_id' => $other->id, 'node_key' => 'offer_1', 'event' => FunnelStepEvent::ENTERED]);
        FunnelStepEvent::create(['visit_id' => $other->id, 'node_key' => 'offer_1', 'event' => FunnelStepEvent::DECLINED]);

        $stats = StepStats::forFunnel($funnel->fresh(['steps', 'edges', 'visits']));

        $this->assertSame(1, $stats['offer_1']['accepted']);
        $this->assertSame(1, $stats['offer_1']['declined']);
    }

    #[Test]
    public function another_funnels_walks_are_not_counted_here(): void
    {
        $funnel = $this->funnel();

        $other = Funnel::create(['handle' => 'anderer', 'title' => 'Anderer', 'published' => true]);
        $other->steps()->create(['node_key' => 'entry_1', 'type' => 'entry']);

        // Same node key, different funnel. Node keys are only unique within a
        // funnel, so a query that forgets to scope by funnel adds up strangers.
        $this->walk($other, 'fremd', ['entry_1', 'entry_1']);
        $this->walk($funnel, 'a', ['entry_1']);

        $stats = StepStats::forFunnel($funnel->fresh(['steps', 'edges', 'visits']));

        $this->assertSame(1, $stats['entry_1']['visits']);
    }
}
