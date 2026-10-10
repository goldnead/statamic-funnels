<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\GraphWriter;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A step whose type this site cannot draw is not a step to throw away.
 *
 * `lead_magnet` is the case that found it: remove (or downgrade) the sibling,
 * open the funnel in the Control Panel, press save, and the step and its edges
 * were gone without a word. Nothing here is specific to that type; the type
 * is only one that is not registered in this suite.
 */
class UnknownStepsSurviveSavingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists('Goldnead\LeadMagnets\Support\ReturnUrl')) {
            $this->markTestSkipped('lead-magnets is installed here, so the type is registered.');
        }
    }

    protected function stored(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'lm_1', 'type' => 'lead_magnet', 'label' => 'Geschenk', 'slug' => 'geschenk', 'config' => ['resource' => 'einsing']],
            ['node_key' => 'page_1', 'type' => 'page', 'label' => 'Danke', 'slug' => 'danke'],
        ]);
        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'lm_1', 'from_output' => 'default'],
            ['from_node_key' => 'lm_1', 'to_node_key' => 'page_1', 'from_output' => 'default'],
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    /** What the editor sends back when it never touched the unknown card. */
    protected function payload(array $nodes, array $edges): array
    {
        return ['title' => 'Kurs', 'handle' => 'kurs', 'published' => true, 'nodes' => $nodes, 'edges' => $edges];
    }

    #[Test]
    public function saving_without_the_unknown_node_in_the_payload_keeps_it_and_its_edges(): void
    {
        $funnel = $this->stored();

        app(GraphWriter::class)->write($funnel, $this->payload(
            [
                ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => []],
                ['node_key' => 'page_1', 'type' => 'page', 'label' => 'Danke', 'config' => []],
            ],
            [],
        ));

        $funnel = $funnel->fresh(['steps', 'edges']);

        $this->assertSame(['entry_1', 'lm_1', 'page_1'], $funnel->steps->pluck('node_key')->sort()->values()->all());
        $this->assertSame(['resource' => 'einsing'], $funnel->steps->firstWhere('node_key', 'lm_1')->config);
        $this->assertSame(
            ['entry_1>lm_1', 'lm_1>page_1'],
            $funnel->edges->map(fn ($e) => $e->from_node_key.'>'.$e->to_node_key)->sort()->values()->all(),
        );
    }

    #[Test]
    public function saving_with_the_unknown_node_in_the_payload_does_not_rewrite_it(): void
    {
        $funnel = $this->stored();

        app(GraphWriter::class)->write($funnel, $this->payload(
            [
                ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => []],
                ['node_key' => 'lm_1', 'type' => 'lead_magnet', 'label' => 'Geschenk', 'config' => ['resource' => 'veraendert']],
                ['node_key' => 'page_1', 'type' => 'page', 'label' => 'Danke', 'config' => []],
            ],
            [
                ['from_node_key' => 'entry_1', 'to_node_key' => 'lm_1', 'from_output' => 'default'],
                ['from_node_key' => 'lm_1', 'to_node_key' => 'page_1', 'from_output' => 'default'],
            ],
        ));

        $step = $funnel->fresh('steps')->steps->firstWhere('node_key', 'lm_1');

        // The editor cannot have edited it: it has no form for the type.
        $this->assertSame(['resource' => 'einsing'], $step->config);
        $this->assertCount(2, $funnel->fresh('edges')->edges);
    }

    #[Test]
    public function a_node_of_an_unknown_type_that_was_never_stored_is_still_refused(): void
    {
        $funnel = $this->stored();

        app(GraphWriter::class)->write($funnel, $this->payload(
            [
                ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => []],
                ['node_key' => 'evil_1', 'type' => 'does_not_exist', 'label' => 'X', 'config' => []],
            ],
            [],
        ));

        $this->assertNull($funnel->fresh('steps')->steps->firstWhere('node_key', 'evil_1'));
    }

    #[Test]
    public function deleting_a_known_step_still_works(): void
    {
        $funnel = $this->stored();

        app(GraphWriter::class)->write($funnel, $this->payload(
            [['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => []]],
            [],
        ));

        $keys = $funnel->fresh('steps')->steps->pluck('node_key')->sort()->values()->all();

        $this->assertSame(['entry_1', 'lm_1'], $keys, 'page_1 is gone, the unknown step stays');
    }

    #[Test]
    public function the_editor_is_told_which_steps_it_cannot_draw(): void
    {
        $funnel = $this->stored();

        $this->assertSame(
            [['node_key' => 'lm_1', 'label' => 'Geschenk', 'type' => 'lead_magnet']],
            app(StepRegistry::class)->unavailable($funnel->steps),
        );
    }
}
