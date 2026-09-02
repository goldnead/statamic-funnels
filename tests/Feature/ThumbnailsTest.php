<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Contracts\ThumbnailRenderer;
use Goldnead\StatamicFunnels\Jobs\RenderStepThumbnail;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Tests\Support\FakeRenderer;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicFunnels\Thumbnails\ConsentCookie;
use Goldnead\StatamicFunnels\Thumbnails\NullRenderer;
use Goldnead\StatamicFunnels\Thumbnails\Thumbnails;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Token;
use Statamic\Facades\User;

/**
 * A picture of every page, and of nothing else.
 *
 * Two hosts are tested: one with a renderer, where every page step ends up with
 * a stored picture the editor can show, and one without, where nothing changes
 * and nothing breaks — because that second host is the common one, and a
 * feature that turns a missing browser into a broken save is worse than no
 * feature.
 */
class ThumbnailsTest extends TestCase
{
    protected $superuser = null;

    protected FakeRenderer $renderer;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');

        $this->renderer = new FakeRenderer;
    }

    protected function withRenderer(): void
    {
        $this->app->instance(ThumbnailRenderer::class, $this->renderer);
    }

    protected function user()
    {
        return $this->superuser ??= tap(User::make()->email('studio@example.com')->makeSuper())->save();
    }

    protected function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => false]);
        $funnel->steps()->create(['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start']);

        return $funnel->fresh(['steps', 'edges']);
    }

    #[Test]
    public function a_second_save_during_a_render_is_not_swallowed(): void
    {
        // Unique until processing, not until done: a lock that outlives handle()
        // would drop the save that landed while the browser was busy.
        $this->assertInstanceOf(ShouldBeUniqueUntilProcessing::class, new RenderStepThumbnail(1));
    }

    /**
     * @return array<string, mixed>
     */
    protected function graph(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Kurs',
            'handle' => 'kurs',
            'published' => false,
            'nodes' => [
                ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'config' => ['headline' => 'Hallo']],
                ['node_key' => 'page_1', 'type' => 'page', 'label' => 'Mehr', 'config' => ['headline' => 'Mehr dazu']],
                ['node_key' => 'mail_1', 'type' => 'mail', 'label' => 'Danke-Mail', 'config' => ['subject' => 'Danke']],
            ],
            'edges' => [
                ['from_node_key' => 'entry_1', 'to_node_key' => 'page_1', 'from_output' => 'default'],
                ['from_node_key' => 'page_1', 'to_node_key' => 'mail_1', 'from_output' => 'default'],
            ],
        ], $overrides);
    }

    /** Where a step's picture goes, from the one place that decides it. */
    protected function path(Funnel $funnel, string $nodeKey): string
    {
        return Thumbnails::path($funnel->fresh(['steps'])->stepByKey($nodeKey));
    }

    protected function save(Funnel $funnel, array $overrides = []): void
    {
        $this->actingAs($this->user())
            ->patch('/cp/utilities/funnels/'.$funnel->id, $this->graph($overrides))
            ->assertRedirect();
    }

    #[Test]
    public function saving_photographs_every_page_and_no_mail(): void
    {
        $this->withRenderer();
        $funnel = $this->funnel();

        $this->save($funnel);

        $funnel = $funnel->fresh(['steps']);
        $page = $funnel->stepByKey('page_1');
        $entry = $funnel->stepByKey('entry_1');
        $mail = $funnel->stepByKey('mail_1');

        $this->assertSame($this->path($funnel, 'page_1'), $page->config('thumbnail.path'));
        $this->assertNotNull($page->config('thumbnail.rendered_at'));
        Storage::disk('public')->assertExists($this->path($funnel, 'page_1'));
        Storage::disk('public')->assertExists($this->path($funnel, 'entry_1'));

        // The entry is a page too; the mail is not a place anybody stands on.
        $this->assertNotNull($entry->config('thumbnail.path'));
        $this->assertNull($mail->config('thumbnail'));
        Storage::disk('public')->assertMissing($this->path($funnel, 'mail_1'));

        // The picture was taken through the preview route, with a pass.
        $this->assertCount(2, $this->renderer->urls);
        $this->assertStringContainsString('/f/kurs/_preview/page_1?token=', $this->renderer->urls[1]);
    }

    #[Test]
    public function the_file_name_cannot_be_guessed_without_the_app_key(): void
    {
        $funnel = $this->funnel();
        $step = $funnel->stepByKey('entry_1');

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $first = Thumbnails::path($step);

        config()->set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
        $second = Thumbnails::path($step);

        $this->assertNotSame($first, $second);
        $this->assertMatchesRegularExpression('#^funnels/thumbs/'.$funnel->id.'/entry_1-[0-9a-f]{16}\.png$#', $first);
        $this->assertSame($second, Thumbnails::path($step), 'The name is stable under one key.');
    }

    #[Test]
    public function the_pass_it_used_is_gone_afterwards(): void
    {
        $this->withRenderer();
        $funnel = $this->funnel();

        $this->save($funnel);

        foreach ($this->renderer->urls as $url) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

            $this->assertNull(Token::find($query['token']), 'A render left its pass on disk.');
        }
    }

    #[Test]
    public function without_a_renderer_nothing_changes_and_the_editor_says_so(): void
    {
        // The default binding on this test host: browsershot is not installed.
        $this->assertInstanceOf(NullRenderer::class, $this->app->make(ThumbnailRenderer::class));

        $funnel = $this->funnel();

        $this->save($funnel);

        $funnel = $funnel->fresh(['steps']);

        $this->assertNull($funnel->stepByKey('page_1')->config('thumbnail'));
        $this->assertSame(['headline' => 'Mehr dazu'], $funnel->stepByKey('page_1')->config);
        $this->assertSame([], Storage::disk('public')->allFiles());

        $this->actingAs($this->user())
            ->get('/cp/utilities/funnels/'.$funnel->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('thumbnails.enabled', true)
                ->where('thumbnails.available', false)
                ->where('labels.thumbnails.hint', 'Thumbnails need Chromium (see the docs).')
            );
    }

    #[Test]
    public function the_null_renderer_says_so_once_per_process(): void
    {
        NullRenderer::reset();

        Log::shouldReceive('notice')->once();

        $renderer = new NullRenderer;

        $this->assertNull($renderer->render('http://example.test', 640, 400));
        $this->assertNull($renderer->render('http://example.test', 640, 400));
    }

    #[Test]
    public function with_a_renderer_the_editor_gets_a_url_per_page(): void
    {
        $this->withRenderer();
        $funnel = $this->funnel();

        $this->save($funnel);

        $this->actingAs($this->user())
            ->get('/cp/utilities/funnels/'.$funnel->id.'/edit')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('thumbnails.available', true)
                ->where('funnel.nodes', function ($nodes) use ($funnel) {
                    $nodes = collect($nodes)->keyBy('node_key');

                    $this->assertStringEndsWith('/'.$this->path($funnel, 'page_1'), (string) $nodes['page_1']['config']['thumbnail']['url']);
                    $this->assertNotEmpty($nodes['page_1']['config']['thumbnail']['rendered_at']);
                    $this->assertArrayNotHasKey('thumbnail', $nodes['mail_1']['config']);

                    return true;
                })
            );
    }

    #[Test]
    public function a_step_saved_unchanged_is_not_photographed_again(): void
    {
        $this->withRenderer();
        $funnel = $this->funnel();

        $this->save($funnel);
        $this->save($funnel);

        $this->assertCount(2, $this->renderer->urls);

        // A change to the page is seen; a change to the mail is not a page.
        $graph = $this->graph();
        $graph['nodes'][1]['config']['headline'] = 'Anders';
        $graph['nodes'][2]['config']['subject'] = 'Anders';

        $this->save($funnel, $graph);

        $this->assertCount(3, $this->renderer->urls);
    }

    #[Test]
    public function a_second_save_before_reloading_keeps_the_picture(): void
    {
        $this->withRenderer();
        $funnel = $this->funnel();

        $this->save($funnel);

        $stored = $funnel->fresh(['steps'])->stepByKey('page_1')->config('thumbnail');

        // The editor sends the config it loaded before the picture existed, and
        // even one that claims a picture of its own.
        $graph = $this->graph();
        $graph['nodes'][1]['config']['thumbnail'] = ['path' => 'somewhere/else.png'];

        $this->save($funnel, $graph);

        $this->assertSame($stored, $funnel->fresh(['steps'])->stepByKey('page_1')->config('thumbnail'));
    }

    #[Test]
    public function the_client_cannot_plant_a_thumbnail(): void
    {
        $funnel = $this->funnel();

        $graph = $this->graph();
        $graph['nodes'][1]['config']['thumbnail'] = ['path' => '../../.env'];

        $this->save($funnel, $graph);

        $this->assertNull($funnel->fresh(['steps'])->stepByKey('page_1')->config('thumbnail'));
    }

    #[Test]
    public function a_removed_step_takes_its_picture_with_it(): void
    {
        $this->withRenderer();
        $funnel = $this->funnel();

        $this->save($funnel);

        // Remembered now: once the step is gone there is nothing to ask.
        $pagePicture = $this->path($funnel, 'page_1');
        Storage::disk('public')->assertExists($pagePicture);

        $graph = $this->graph();
        unset($graph['nodes'][1]);
        $graph['nodes'] = array_values($graph['nodes']);
        $graph['edges'] = [];

        $this->save($funnel, $graph);

        Storage::disk('public')->assertMissing($pagePicture);
        Storage::disk('public')->assertExists($this->path($funnel, 'entry_1'));
    }

    #[Test]
    public function a_deleted_funnel_takes_its_folder_with_it(): void
    {
        $this->withRenderer();
        $funnel = $this->funnel();

        $this->save($funnel);

        $this->actingAs($this->user())
            ->delete('/cp/utilities/funnels/'.$funnel->id)
            ->assertRedirect();

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    #[Test]
    public function the_command_renders_again_only_when_forced(): void
    {
        $this->withRenderer();
        $funnel = $this->funnel();

        $this->save($funnel);
        $this->assertCount(2, $this->renderer->urls);

        $this->artisan('funnels:thumbnails', ['funnel' => 'kurs'])
            ->expectsOutputToContain('Already current')
            ->assertExitCode(0);

        $this->assertCount(2, $this->renderer->urls);

        $this->artisan('funnels:thumbnails', ['funnel' => 'kurs', '--force' => true])
            ->assertExitCode(0);

        $this->assertCount(4, $this->renderer->urls);

        $this->artisan('funnels:thumbnails', ['funnel' => 'gibt-es-nicht'])
            ->assertExitCode(1);
    }

    #[Test]
    public function the_command_without_a_renderer_does_not_pretend(): void
    {
        $this->funnel();

        $this->artisan('funnels:thumbnails')
            ->expectsOutputToContain('No thumbnail renderer')
            ->assertExitCode(1);
    }

    #[Test]
    public function configured_cookies_and_selectors_reach_the_renderer(): void
    {
        config()->set('statamic-funnels.thumbnails.cookies', ['banner_seen' => 'yes', 'lang' => 'de']);
        config()->set('statamic-funnels.thumbnails.hide_selectors', ['.cookie-bar', ' #chat ', '']);

        $this->withRenderer();
        $funnel = $this->funnel();

        $this->save($funnel);

        $this->assertCount(2, $this->renderer->cookies);
        $this->assertSame(['banner_seen' => 'yes', 'lang' => 'de'], $this->renderer->cookies[0]);
        $this->assertSame(['.cookie-bar', '#chat'], $this->renderer->hideSelectors[0]);
    }

    #[Test]
    public function without_configuration_and_without_the_consent_addon_nothing_is_sent(): void
    {
        // The consent addon is not installed on this test host.
        $this->assertFalse(class_exists('\Goldnead\StatamicConsent\Support\Registry'));

        $this->withRenderer();
        $funnel = $this->funnel();

        $this->save($funnel);

        $this->assertSame([], $this->renderer->cookies[0]);
        $this->assertSame([], $this->renderer->hideSelectors[0]);
    }

    #[Test]
    public function the_consent_cookie_is_written_the_way_its_script_reads_it(): void
    {
        // Mirrors `parse()` in statamic-consent's consent.js: decodeURIComponent,
        // JSON.parse, `v` must equal the version, `granted` must be an array.
        $value = ConsentCookie::value(['analytics', 'youtube'], 3);

        $data = json_decode(rawurldecode($value), true);

        $this->assertSame(3, $data['v']);
        $this->assertSame(['analytics', 'youtube'], $data['granted']);
        $this->assertIsInt($data['ts']);
        $this->assertNotEmpty($data['id']);
        $this->assertDoesNotMatchRegularExpression('/[{}",\[\] ]/', $value, 'The value has to be cookie-safe, as encodeURIComponent makes it.');
    }

    #[Test]
    public function switched_off_means_off(): void
    {
        config()->set('statamic-funnels.thumbnails.enabled', false);

        $this->withRenderer();
        $funnel = $this->funnel();

        $this->save($funnel);

        $this->assertCount(0, $this->renderer->urls);

        $this->actingAs($this->user())
            ->get('/cp/utilities/funnels/'.$funnel->id.'/edit')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('thumbnails.enabled', false));
    }
}
