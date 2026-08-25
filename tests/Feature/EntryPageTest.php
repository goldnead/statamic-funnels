<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * A step that points at a Statamic entry.
 *
 * This is the answer to "where is the landing page builder": there isn't one,
 * and there should not be one. Statamic already has Bard, Replicator and
 * whatever sets a site has defined. A step names a page; the page stays the
 * site's, with its own template and its own layout, and the funnel adds its
 * context on top.
 *
 * What has to hold, and what these tests are for: the entry's own content
 * renders, the funnel context reaches the template, and a page that is not fit
 * to show falls back rather than breaking the walk.
 */
class EntryPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Collection::make('pages')->routes('/{slug}')->save();

        $this->app['view']->addNamespace('funnels-test', __DIR__.'/../__fixtures__/views');
    }

    protected function entry(bool $published = true)
    {
        return tap(
            Entry::make()
                ->collection('pages')
                ->slug('landing')
                ->published($published)
                ->data(['title' => 'Frühlingskurs', 'template' => 'funnels-test::landing', 'layout' => 'funnels-test::layout'])
        )->save();
    }

    protected function funnel(array $config): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->create(['node_key' => 'entry_1', 'type' => 'entry', 'config' => $config]);

        return $funnel->fresh(['steps', 'edges']);
    }

    #[Test]
    public function the_entrys_own_content_is_what_the_step_shows(): void
    {
        $entry = $this->entry();

        $this->funnel(['entry' => $entry->id(), 'headline' => 'Die Notfassung']);

        $this->get('/f/kurs')
            ->assertOk()
            ->assertSee('Frühlingskurs')
            // The step's own headline is not used when an entry is named. Two
            // headlines on one page is what happens if it is.
            ->assertDontSee('Die Notfassung');
    }

    #[Test]
    public function the_funnel_context_reaches_the_entrys_template(): void
    {
        $entry = $this->entry();

        $this->funnel(['entry' => $entry->id()]);

        // Without this the page renders but has no way to move on, and the walk
        // is a set of dead ends wearing a nice design.
        $this->get('/f/kurs')
            ->assertOk()
            ->assertSee('/f/kurs/entry_1/advance');
    }

    #[Test]
    public function an_unpublished_page_falls_back_instead_of_breaking_the_walk(): void
    {
        $entry = $this->entry(published: false);

        $this->funnel(['entry' => $entry->id(), 'headline' => 'Die Notfassung']);

        // Somebody pulling a page back into draft must not take the funnel down
        // with it, least of all halfway through a purchase.
        $this->get('/f/kurs')
            ->assertOk()
            ->assertSee('Die Notfassung');
    }

    #[Test]
    public function a_page_that_was_deleted_falls_back_too(): void
    {
        $this->funnel(['entry' => 'gibt-es-nicht', 'headline' => 'Die Notfassung']);

        $this->get('/f/kurs')
            ->assertOk()
            ->assertSee('Die Notfassung');
    }

    #[Test]
    public function without_a_page_nothing_changes(): void
    {
        $this->funnel(['headline' => 'Die Notfassung']);

        $this->get('/f/kurs')
            ->assertOk()
            ->assertSee('Die Notfassung');
    }

    #[Test]
    public function the_entrys_own_fields_survive_the_funnel_context(): void
    {
        // The bug this exists for: the funnel used to hand `body => null` to
        // the view, and `View::gatherData()` merges that *over* the entry's own
        // data. A landing page with a `body` field — which is what Statamic's
        // own starter blueprints have — rendered empty, and no test saw it
        // because the fixture happened to use different field names.
        $entry = tap(
            Entry::make()
                ->collection('pages')
                ->slug('landing')
                ->published(true)
                ->data([
                    'title' => 'Frühlingskurs',
                    'body' => 'DER-EIGENE-INHALT',
                    'headline' => 'DIE-EIGENE-ÜBERSCHRIFT',
                    'template' => 'funnels-test::kollision',
                    'layout' => 'funnels-test::layout',
                ])
        )->save();

        $this->funnel(['entry' => $entry->id(), 'headline' => 'Die Notfassung', 'body' => 'Der Notfalltext']);

        $this->get('/f/kurs')
            ->assertOk()
            ->assertSee('DER-EIGENE-INHALT')
            ->assertSee('DIE-EIGENE-ÜBERSCHRIFT');
    }

    #[Test]
    public function a_page_that_is_not_out_yet_is_not_reachable_through_a_funnel(): void
    {
        // Statamic hides a dated entry whose date is in the future when the
        // collection says so. A funnel step that rendered it anyway would be a
        // way around the site's own embargo.
        Collection::make('termine')->routes('/termine/{slug}')->dated(true)->futureDateBehavior('private')->save();

        $entry = tap(
            Entry::make()
                ->collection('termine')
                ->slug('spaeter')
                ->date(now()->addYear())
                ->published(true)
                ->data(['title' => 'Noch geheim', 'template' => 'funnels-test::landing', 'layout' => 'funnels-test::layout'])
        )->save();

        $this->funnel(['entry' => $entry->id()]);

        $this->get('/f/kurs')->assertNotFound();
    }

    #[Test]
    public function a_step_cannot_name_a_template_outside_the_site(): void
    {
        // A step's template used to go straight into `view()`. With the preview
        // that was a way for anybody with the funnels permission to render an
        // arbitrary view of the application, including another addon's, with
        // data of their choosing.
        $this->funnel(['template' => 'statamic::layout', 'headline' => 'Die Notfassung']);

        $this->get('/f/kurs')
            ->assertOk()
            ->assertSee('Die Notfassung');
    }

    #[Test]
    public function the_picker_only_offers_pages_a_visitor_could_be_sent_to(): void
    {
        $this->entry();

        // A collection with no route has no URLs, so an entry in it is not a
        // page and offering it in the picker would be a bug shaped like a
        // feature.
        Collection::make('snippets')->save();
        tap(Entry::make()->collection('snippets')->slug('bruchstueck')->published(true)->data(['title' => 'Bruchstück']))->save();

        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();

        $response = $this->actingAs($user)->getJson(cp_route('utilities.funnels.entries'));

        $labels = collect($response->json('options'))->pluck('label');

        $this->assertTrue($labels->contains('Frühlingskurs'));
        $this->assertFalse($labels->contains('Bruchstück'));
    }
}
