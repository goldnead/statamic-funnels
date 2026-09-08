<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * The funnels utility registers itself with the addon, so its nav entry is
 * there before anybody has run `php artisan migrate`. Clicking it then hit
 * `Funnel::query()->withCount(...)` against tables that did not exist and the
 * page answered HTTP 500. These tests reproduce that install — everything
 * present except this addon's own tables — and hold the page to an empty state
 * plus a line in the log.
 */
class SetupGuardTest extends TestCase
{
    private function admin()
    {
        return tap(User::make()->email('setup@example.test')->makeSuper())->save();
    }

    /**
     * Children first: every one of them has a cascading foreign key onto
     * `funnels` or `funnel_visits`, so dropping the parents alone would fail.
     */
    private function dropAddonTables(): void
    {
        Schema::dropIfExists('funnel_mail_deliveries');
        Schema::dropIfExists('funnel_step_events');
        Schema::dropIfExists('funnel_visits');
        Schema::dropIfExists('funnel_edges');
        Schema::dropIfExists('funnel_steps');
        Schema::dropIfExists('funnels');
    }

    #[Test]
    public function the_index_answers_200_when_its_tables_are_missing(): void
    {
        $this->dropAddonTables();

        $this->actingAs($this->admin())
            ->get(cp_route('utilities.funnels'))
            ->assertOk();
    }

    #[Test]
    public function the_index_renders_the_setup_screen_and_names_the_missing_tables(): void
    {
        $this->dropAddonTables();

        $response = $this->actingAs($this->admin())
            ->get(cp_route('utilities.funnels'))
            ->assertOk();

        $page = $response->viewData('page');

        $this->assertSame('statamic-funnels::SetupRequired', $page['component']);
        $this->assertContains('funnels', $page['props']['tables']);
        $this->assertContains('funnel_steps', $page['props']['tables']);
        $this->assertContains('funnel_visits', $page['props']['tables']);
        $this->assertNotEmpty($page['props']['heading']);
        $this->assertNotEmpty($page['props']['description']);
    }

    /**
     * The point of the guard is a readable page, not a quiet one. If this test
     * ever goes red the addon has traded a visible 500 for a silent nothing.
     */
    #[Test]
    public function the_reason_reaches_the_log(): void
    {
        $this->dropAddonTables();

        Log::spy();

        $this->actingAs($this->admin())
            ->get(cp_route('utilities.funnels'))
            ->assertOk();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'statamic-funnels')
                && str_contains($message, 'php artisan migrate'))
            ->once();
    }

    /**
     * One missing table is enough — the counts join all three, so the page
     * cannot render without any of them.
     */
    #[Test]
    public function a_single_missing_table_is_enough_to_stop_the_page(): void
    {
        Schema::dropIfExists('funnel_step_events');
        Schema::dropIfExists('funnel_mail_deliveries');
        Schema::dropIfExists('funnel_visits');

        $response = $this->actingAs($this->admin())
            ->get(cp_route('utilities.funnels'))
            ->assertOk();

        $page = $response->viewData('page');

        $this->assertSame('statamic-funnels::SetupRequired', $page['component']);
        $this->assertSame(['funnel_visits'], $page['props']['tables']);
    }

    #[Test]
    public function a_migrated_install_still_renders_the_listing(): void
    {
        $response = $this->actingAs($this->admin())
            ->get(cp_route('utilities.funnels'))
            ->assertOk();

        $this->assertSame('statamic-funnels::Funnels/Index', $response->viewData('page')['component']);
    }
}
