<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The editor: who may use it, and what happens to what they drew.
 *
 * The save is a replace, so the tests are mostly about that being safe: a graph
 * arrives whole, and either all of it lands or none of it does.
 */
class EditorTest extends TestCase
{
    protected $superuser = null;

    /**
     * One superuser per test, reused.
     *
     * Statamic without Pro allows exactly one user, so a helper that made a new
     * one on every call turned the second request of a test into a 500 that
     * looked like a bug in the save path.
     */
    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function userWithoutPermission()
    {
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    protected function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => false]);
        $funnel->steps()->create(['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start']);

        return $funnel->fresh(['steps', 'edges']);
    }

    /**
     * @return array<string, mixed>
     */
    protected function graph(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Kurs',
            'handle' => 'kurs',
            'published' => true,
            'nodes' => [
                ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => []],
                ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'config' => ['form' => 'kontakt']],
                ['node_key' => 'offer_1', 'type' => 'offer', 'label' => 'Angebot', 'config' => ['offer' => 'kurs-angebot']],
                ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'config' => []],
            ],
            'edges' => [
                ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
                ['from_node_key' => 'capture_1', 'to_node_key' => 'offer_1', 'from_output' => 'default'],
                ['from_node_key' => 'offer_1', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
            ],
        ], $overrides);
    }

    #[Test]
    public function a_user_without_the_permission_cannot_look_or_write(): void
    {
        $funnel = $this->funnel();
        $user = $this->userWithoutPermission();

        $this->actingAs($user)->get('/cp/utilities/funnels')->assertRedirect(cp_route('index'));

        // The write path matters more than the screen: a funnel is what takes
        // money, and editing one is editing a shop.
        $this->actingAs($user)
            ->patchJson('/cp/utilities/funnels/'.$funnel->id, $this->graph())
            ->assertForbidden();

        $this->assertFalse($funnel->fresh()->published);
    }

    /**
     * Ein Funnel hat genau einen Einstieg.
     *
     * Adrian am 03.09.2026: „Ich kann mehrere Einstiege erstellen, das soll so
     * nicht sein" — bestaetigt als Fehler. Der Editor bietet den zweiten gar
     * nicht mehr an; diese Sperre hier ist die dahinter, denn eine Regel, die
     * nur im Browser steht, ist keine.
     *
     * Warum „irgendwie verhindern" nicht reicht: `entryStep()` nimmt
     * `firstWhere('type', 'entry')`, also den erstbesten. Mit zwei Einstiegen
     * entscheidet die Zeilenreihenfolge, welcher gilt, und der Funnel liefe an
     * einem vorbei, den jemand sichtbar angelegt hat.
     */
    #[Test]
    public function a_second_entry_is_refused_and_nothing_is_saved(): void
    {
        $funnel = $this->funnel();

        $graph = $this->graph();
        $graph['nodes'][] = ['node_key' => 'entry_2', 'type' => 'entry', 'label' => 'Noch ein Start', 'config' => []];

        $this->actingAs($this->user())
            ->patchJson('/cp/utilities/funnels/'.$funnel->id, $graph)
            ->assertStatus(422)
            ->assertJsonValidationErrors('nodes');

        // Der Schreibvorgang ist ein Ersetzen: haette die Regel erst im Writer
        // gegriffen, stuende jetzt ein halber Graph in der Tabelle.
        $frisch = $funnel->fresh(['steps']);
        $this->assertSame(1, $frisch->steps->where('type', 'entry')->count());
        $this->assertNull($frisch->steps->firstWhere('node_key', 'entry_2'));
        $this->assertFalse($frisch->published, 'Der Rest des Graphen darf auch nicht durchgerutscht sein.');
    }

    #[Test]
    public function it_saves_the_whole_graph(): void
    {
        $funnel = $this->funnel();

        $this->actingAs($this->user())
            ->patch('/cp/utilities/funnels/'.$funnel->id, $this->graph())
            ->assertRedirect();

        $funnel = $funnel->fresh(['steps', 'edges']);

        $this->assertTrue($funnel->published);
        $this->assertSame(4, $funnel->steps->count());
        $this->assertSame(3, $funnel->edges->count());
        $this->assertSame('anmeldung', $funnel->stepByKey('capture_1')->slug);
    }

    #[Test]
    public function the_entry_step_has_no_slug_of_its_own(): void
    {
        $funnel = $this->funnel();

        $this->actingAs($this->user())->patch('/cp/utilities/funnels/'.$funnel->id, $this->graph());

        // It lives under the funnel's own URL: a visitor should not have to know
        // they are in a funnel to be in one.
        $this->assertNull($funnel->fresh(['steps'])->stepByKey('entry_1')->slug);
    }

    #[Test]
    public function a_slug_does_not_change_when_a_step_is_renamed(): void
    {
        $funnel = $this->funnel();
        $this->actingAs($this->user())->patch('/cp/utilities/funnels/'.$funnel->id, $this->graph());

        $before = $funnel->fresh(['steps'])->stepByKey('capture_1')->slug;

        $graph = $this->graph();
        $graph['nodes'][1]['label'] = 'Ganz anders';
        $this->actingAs($this->user())->patch('/cp/utilities/funnels/'.$funnel->id, $graph);

        // A slug that follows the label breaks every link already sent out —
        // and the second half of most funnels arrives by email.
        $this->assertSame($before, $funnel->fresh(['steps'])->stepByKey('capture_1')->slug);
    }

    #[Test]
    public function a_node_type_nobody_registered_is_dropped(): void
    {
        $funnel = $this->funnel();

        $graph = $this->graph();
        $graph['nodes'][] = ['node_key' => 'erfunden_1', 'type' => 'erfunden', 'label' => 'X', 'config' => []];
        $graph['edges'][] = ['from_node_key' => 'finish_1', 'to_node_key' => 'erfunden_1', 'from_output' => 'default'];

        $this->actingAs($this->user())->patch('/cp/utilities/funnels/'.$funnel->id, $graph);

        // A stored node the runtime cannot render is a page that fails in the
        // middle of somebody's purchase. The edge to it goes too.
        $this->assertNull($funnel->fresh(['steps'])->stepByKey('erfunden_1'));
        $this->assertSame(3, $funnel->fresh(['edges'])->edges->count());
    }

    #[Test]
    public function removing_a_node_removes_its_edges(): void
    {
        $funnel = $this->funnel();
        $this->actingAs($this->user())->patch('/cp/utilities/funnels/'.$funnel->id, $this->graph());

        $graph = $this->graph();
        $graph['nodes'] = array_values(array_filter($graph['nodes'], fn ($n) => $n['node_key'] !== 'offer_1'));
        $graph['edges'] = array_values(array_filter($graph['edges'], fn ($e) => $e['from_node_key'] !== 'offer_1' && $e['to_node_key'] !== 'offer_1'));

        $this->actingAs($this->user())->patch('/cp/utilities/funnels/'.$funnel->id, $graph);

        $funnel = $funnel->fresh(['steps', 'edges']);
        $this->assertSame(3, $funnel->steps->count());
        $this->assertSame(0, $funnel->edges->where('from_node_key', 'offer_1')->count());
    }

    #[Test]
    public function two_funnels_cannot_share_a_handle(): void
    {
        Funnel::create(['handle' => 'belegt', 'title' => 'Belegt', 'published' => true]);
        $funnel = $this->funnel();

        $this->actingAs($this->user())
            ->patch('/cp/utilities/funnels/'.$funnel->id, $this->graph(['handle' => 'belegt']))
            ->assertSessionHasErrors('handle');

        // Two funnels on one URL is one funnel nobody can reach.
        $this->assertSame('kurs', $funnel->fresh()->handle);
    }

    #[Test]
    public function creating_a_funnel_gives_it_the_one_step_it_must_have(): void
    {
        $this->actingAs($this->user())
            ->post('/cp/utilities/funnels', ['title' => 'Neuer Kurs'])
            ->assertRedirect();

        $funnel = Funnel::where('title', 'Neuer Kurs')->first();

        // An editor that opens onto nothing makes the first minute a guessing
        // game about what a funnel even is.
        $this->assertNotNull($funnel);
        $this->assertSame(1, $funnel->steps()->count());
        $this->assertSame('entry', $funnel->steps()->first()->type);
        $this->assertFalse($funnel->published);
    }
}
