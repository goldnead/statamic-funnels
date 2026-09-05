<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * What the funnel table is fed.
 *
 * The screen is core's `<Listing>` in client-side mode, and that component is
 * silent about a wrong payload: a column whose `field` is not a key on a row
 * renders empty and sorts every row to the same place, and a missing action URL
 * takes the checkbox column and the bulk toolbar with it without a warning.
 * Both are things a screenshot passes.
 */
class FunnelsListingTest extends TestCase
{
    protected $superuser = null;

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function funnels(): void
    {
        $kurs = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $kurs->steps()->create(['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start']);

        Funnel::create(['handle' => 'brief', 'title' => 'Brief', 'published' => false]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function props(): array
    {
        $response = $this->actingAs($this->user())
            ->get('/cp/utilities/funnels')
            ->assertOk();

        preg_match('/data-page="(.*?)"/s', $response->getContent(), $matches);

        return json_decode(html_entity_decode($matches[1], ENT_QUOTES), true)['props'];
    }

    #[Test]
    public function every_column_names_a_key_that_exists_on_a_row(): void
    {
        $this->funnels();

        $props = $this->props();
        $row = $props['funnels'][0];

        $this->assertNotEmpty($props['columns']);

        foreach ($props['columns'] as $column) {
            $this->assertArrayHasKey($column['field'], $row, "Spalte {$column['field']} steht in keiner Zeile.");
            $this->assertNotSame('', (string) $column['label']);
        }
    }

    #[Test]
    public function the_listing_gets_an_action_url_and_every_row_gets_its_actions(): void
    {
        $this->funnels();

        $props = $this->props();

        $this->assertSame(cp_route('utilities.funnels.actions'), $props['actionUrl']);

        foreach ($props['funnels'] as $row) {
            $this->assertContains(
                'statamic_funnels_delete_funnel',
                array_column($row['actions'], 'handle'),
            );
        }
    }

    #[Test]
    public function the_delete_action_removes_every_selected_funnel(): void
    {
        $this->funnels();

        $ids = Funnel::query()->pluck('id')->map(fn ($id) => (string) $id)->all();
        $this->assertCount(2, $ids);

        $this->actingAs($this->user())
            ->postJson('/cp/utilities/funnels/actions', [
                'action' => 'statamic_funnels_delete_funnel',
                'selections' => $ids,
                'values' => [],
                'context' => [],
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(0, Funnel::query()->count());
    }

    #[Test]
    public function a_user_without_the_permission_cannot_run_the_delete_action(): void
    {
        $this->funnels();

        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();
        $user = tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();

        $this->actingAs($user)
            ->postJson('/cp/utilities/funnels/actions', [
                'action' => 'statamic_funnels_delete_funnel',
                'selections' => Funnel::query()->pluck('id')->map(fn ($id) => (string) $id)->all(),
                'values' => [],
                'context' => [],
            ])
            ->assertForbidden();

        $this->assertSame(2, Funnel::query()->count());
    }
}
