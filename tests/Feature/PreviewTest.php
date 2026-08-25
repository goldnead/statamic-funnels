<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\PreviewToken;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * Looking at a funnel from the Control Panel.
 *
 * The promise is narrow and worth testing exactly: the preview shows what is on
 * screen rather than what is saved, it works on a draft, and it leaves no trace
 * anywhere. The last one is the reason this file is longer than it looks like it
 * should be — "changes nothing" is only demonstrated by counting things before
 * and after.
 */
class PreviewTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function funnel(bool $published = false): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => $published]);
        $funnel->steps()->create(['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => ['headline' => 'Gespeicherte Überschrift']]);
        $funnel->steps()->create(['node_key' => 'offer_1', 'type' => 'offer', 'slug' => 'angebot', 'label' => 'Angebot', 'config' => ['offer' => 'cd']]);

        return $funnel->fresh(['steps', 'edges']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function graph(array $nodes = []): array
    {
        return [
            'nodes' => $nodes ?: [
                ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => ['headline' => 'Ungespeicherte Überschrift']],
            ],
            'edges' => [],
        ];
    }

    #[Test]
    public function it_mints_a_pass_and_says_where_to_look(): void
    {
        $funnel = $this->funnel();

        $response = $this->actingAs($this->user())
            ->postJson(cp_route('utilities.funnels.preview', $funnel->id), [
                'node_key' => 'entry_1',
            ] + $this->graph());

        $response->assertOk();
        $response->assertJsonStructure(['url', 'order']);
        $this->assertStringContainsString('/f/kurs/_preview/entry_1?token=', $response->json('url'));
    }

    #[Test]
    public function the_stepper_order_follows_the_graph_not_the_table(): void
    {
        $funnel = $this->funnel();

        $response = $this->actingAs($this->user())
            ->postJson(cp_route('utilities.funnels.preview', $funnel->id), [
                'node_key' => 'entry_1',
                // Deliberately out of order: the finish is listed first.
                'nodes' => [
                    ['node_key' => 'finish_1', 'type' => 'finish', 'config' => []],
                    ['node_key' => 'entry_1', 'type' => 'entry', 'config' => []],
                    ['node_key' => 'page_1', 'type' => 'page', 'config' => []],
                ],
                'edges' => [
                    ['from_node_key' => 'entry_1', 'to_node_key' => 'page_1', 'from_output' => 'default'],
                    ['from_node_key' => 'page_1', 'to_node_key' => 'finish_1', 'from_output' => 'default'],
                ],
            ]);

        $this->assertSame(['entry_1', 'page_1', 'finish_1'], $response->json('order'));
    }

    #[Test]
    public function the_stepper_walks_one_branch_to_its_end_before_the_other(): void
    {
        $funnel = $this->funnel();

        $response = $this->actingAs($this->user())
            ->postJson(cp_route('utilities.funnels.preview', $funnel->id), [
                'node_key' => 'entry_1',
                'nodes' => [
                    ['node_key' => 'entry_1', 'type' => 'entry', 'config' => []],
                    ['node_key' => 'offer_1', 'type' => 'offer', 'config' => []],
                    ['node_key' => 'upsell_1', 'type' => 'offer', 'config' => []],
                    ['node_key' => 'danke_1', 'type' => 'finish', 'config' => []],
                    ['node_key' => 'schade_1', 'type' => 'page', 'config' => []],
                ],
                'edges' => [
                    ['from_node_key' => 'entry_1', 'to_node_key' => 'offer_1', 'from_output' => 'default'],
                    ['from_node_key' => 'offer_1', 'to_node_key' => 'upsell_1', 'from_output' => 'accepted'],
                    ['from_node_key' => 'upsell_1', 'to_node_key' => 'danke_1', 'from_output' => 'accepted'],
                    ['from_node_key' => 'offer_1', 'to_node_key' => 'schade_1', 'from_output' => 'declined'],
                ],
            ]);

        // Breadth-first would interleave the two ways out of the offer and give
        // entry, offer, upsell, schade, danke. A person walks one path to its
        // end and then comes back for the other.
        $this->assertSame(
            ['entry_1', 'offer_1', 'upsell_1', 'danke_1', 'schade_1'],
            $response->json('order'),
        );
    }

    #[Test]
    public function an_orphaned_step_is_shown_last_rather_than_hidden(): void
    {
        $funnel = $this->funnel();

        $response = $this->actingAs($this->user())
            ->postJson(cp_route('utilities.funnels.preview', $funnel->id), [
                'node_key' => 'entry_1',
                'nodes' => [
                    ['node_key' => 'entry_1', 'type' => 'entry', 'config' => []],
                    ['node_key' => 'waise_1', 'type' => 'page', 'config' => []],
                ],
                'edges' => [],
            ]);

        // A step nothing leads to is exactly what somebody opens the preview to
        // notice, so it must not quietly disappear from the stepper.
        $this->assertSame(['entry_1', 'waise_1'], $response->json('order'));
    }

    #[Test]
    public function somebody_without_the_permission_cannot_mint_one(): void
    {
        $funnel = $this->funnel();

        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();
        $user = tap(User::make()->email('gast@example.com')->assignRole($role))->save();

        $this->actingAs($user)
            ->postJson(cp_route('utilities.funnels.preview', $funnel->id), [
                'node_key' => 'entry_1',
            ] + $this->graph())
            ->assertForbidden();
    }

    #[Test]
    public function the_preview_shows_the_unsaved_graph(): void
    {
        $funnel = $this->funnel();

        $token = PreviewToken::mint($funnel, $this->graph());

        $this->get("/f/kurs/_preview/entry_1?token={$token}")
            ->assertOk()
            ->assertSee('Ungespeicherte Überschrift')
            ->assertDontSee('Gespeicherte Überschrift');
    }

    #[Test]
    public function a_draft_funnel_can_be_previewed(): void
    {
        $funnel = $this->funnel(published: false);

        // The ordinary route refuses, which is the whole reason the preview
        // route exists.
        $this->get('/f/kurs')->assertNotFound();

        $token = PreviewToken::mint($funnel, $this->graph());

        $this->get("/f/kurs/_preview/entry_1?token={$token}")->assertOk();
    }

    #[Test]
    public function no_pass_means_there_is_nothing_there(): void
    {
        $this->funnel();

        $this->get('/f/kurs/_preview/entry_1')->assertNotFound();
        $this->get('/f/kurs/_preview/entry_1?token=erfunden')->assertNotFound();
    }

    #[Test]
    public function a_pass_for_one_funnel_does_not_open_another(): void
    {
        $one = $this->funnel();

        $two = Funnel::create(['handle' => 'anderer', 'title' => 'Anderer', 'published' => false]);
        $two->steps()->create(['node_key' => 'entry_1', 'type' => 'entry']);

        $token = PreviewToken::mint($one, $this->graph());

        $this->get("/f/anderer/_preview/entry_1?token={$token}")->assertNotFound();
    }

    #[Test]
    public function an_expired_pass_shows_nothing(): void
    {
        $funnel = $this->funnel();

        $token = PreviewToken::mint($funnel, $this->graph());

        $this->travel(PreviewToken::MINUTES + 1)->minutes();

        $this->get("/f/kurs/_preview/entry_1?token={$token}")->assertNotFound();
    }

    #[Test]
    public function a_step_that_is_not_in_the_graph_is_not_a_page(): void
    {
        $funnel = $this->funnel();

        $token = PreviewToken::mint($funnel, $this->graph());

        $this->get("/f/kurs/_preview/gibt_es_nicht?token={$token}")->assertNotFound();
    }

    #[Test]
    public function a_refresh_reuses_the_pass_instead_of_leaving_copies_behind(): void
    {
        $funnel = $this->funnel();

        $first = PreviewToken::mint($funnel, $this->graph());

        $second = PreviewToken::mint($funnel, [
            'nodes' => [['node_key' => 'entry_1', 'type' => 'entry', 'config' => ['headline' => 'Zweite Fassung']]],
            'edges' => [],
        ], $first);

        // Same pass, new contents. Minting a new one per keystroke left dozens
        // of full copies of the graph on disk, each a working URL into an
        // unpublished funnel for as long as it lived.
        $this->assertSame($first, $second);

        $this->get("/f/kurs/_preview/entry_1?token={$second}")
            ->assertOk()
            ->assertSee('Zweite Fassung');
    }

    #[Test]
    public function a_pass_from_another_funnel_is_not_reused_but_replaced(): void
    {
        $one = $this->funnel();

        $two = Funnel::create(['handle' => 'anderer', 'title' => 'Anderer', 'published' => false]);
        $two->steps()->create(['node_key' => 'entry_1', 'type' => 'entry']);

        $stolen = PreviewToken::mint($one, $this->graph());

        // Handing another funnel's pass in must not let it be overwritten to
        // point at this one: that would turn a stale tab into a way to see a
        // draft somebody else was working on.
        $fresh = PreviewToken::mint($two, $this->graph(), $stolen);

        $this->assertNotSame($stolen, $fresh);
        $this->get("/f/kurs/_preview/entry_1?token={$stolen}")->assertOk();
    }

    #[Test]
    public function looking_leaves_no_trace(): void
    {
        $funnel = $this->funnel();

        $token = PreviewToken::mint($funnel, $this->graph());

        $this->get("/f/kurs/_preview/entry_1?token={$token}")->assertOk();

        $this->assertSame(0, FunnelVisit::count(), 'A preview created a visit.');
        $this->assertSame(0, FunnelStepEvent::count(), 'A preview recorded a step event.');
    }

    #[Test]
    public function looking_at_an_offer_does_not_count_as_showing_it(): void
    {
        $funnel = $this->funnel();

        $offer = Offer::create([
            'handle' => 'cd',
            'name' => 'Begleit-CD',
            'product' => 'begleit-cd',
            'active' => true,
        ]);

        $token = PreviewToken::mint($funnel, [
            'nodes' => [
                ['node_key' => 'offer_1', 'type' => 'offer', 'config' => ['offer' => 'cd']],
            ],
            'edges' => [],
        ]);

        $this->get("/f/kurs/_preview/offer_1?token={$token}")->assertOk();

        // The acceptance rate on the offers screen is the number this addon is
        // judged by. An editor clicking through their own funnel while building
        // it must not move it.
        $this->assertSame(0, (int) $offer->fresh()->shown_count);
    }

    #[Test]
    public function a_finish_step_previewed_does_not_complete_anything(): void
    {
        $funnel = $this->funnel();

        $token = PreviewToken::mint($funnel, [
            'nodes' => [
                ['node_key' => 'finish_1', 'type' => 'finish', 'config' => ['headline' => 'Danke', 'redirect' => 'https://example.com/woanders']],
            ],
            'edges' => [],
        ]);

        // And it does not follow the redirect either: a preview that navigates
        // away from itself shows the editor somebody else's website.
        $this->get("/f/kurs/_preview/finish_1?token={$token}")
            ->assertOk()
            ->assertSee('Danke');

        $this->assertSame(0, FunnelVisit::count());
    }
}
