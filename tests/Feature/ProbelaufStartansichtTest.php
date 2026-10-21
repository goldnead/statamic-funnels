<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\BrandContext\Simulation\Simulator;
use Goldnead\StatamicAutomations\Integrations\Map\AutomationMapObjects;
use Goldnead\StatamicFunnels\Models\Landkarte;
use Goldnead\StatamicFunnels\Support\MailFrame;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/** Objects of the map for the start view: a name per card, the right to see it, and a way to begin. */
abstract class StartViewObjects implements ProvidesMapObjects
{
    public function typeLabel(): string
    {
        return ucfirst($this->type());
    }

    public function objects(?string $query = null, int $limit = 50): array
    {
        return [];
    }

    public function find(string $id): ?MapObject
    {
        return new MapObject($this->type(), $id, 'Name of '.$id);
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    public function simulationAuthorized(string $id, $user): bool
    {
        return $user !== null && $user->can('see start view');
    }

    public function paths(string $id): array
    {
        return [];
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return [Effect::unknown('nothing known')];
    }
}

class StartViewProduct extends StartViewObjects
{
    public function type(): string
    {
        return 'product';
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::PURCHASED)];
    }
}

class StartViewCourse extends StartViewObjects
{
    public function type(): string
    {
        return 'course';
    }
}

class StartViewLeadMagnet extends StartViewObjects
{
    public function type(): string
    {
        return 'lead_magnet';
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::REQUESTED)];
    }
}

// The automations addon is no dependency of this addon; the picker asks its provider by name and only when it is there. A stand-in
// with that name, only where the real one is not installed.
if (! class_exists(AutomationMapObjects::class)) {
    eval('namespace Goldnead\StatamicAutomations\Integrations\Map; class AutomationMapObjects extends \Goldnead\StatamicFunnels\Tests\Feature\StartViewObjects {
        public function type(): string { return "automation"; }
        public function simulationTags(string $id): array { return $id === "welcome" ? ["Chorleitung", "Solo", "chorleitung"] : ["Newsletter"]; }
    }');
}

/** The dry run opens on one person: who starts where, what she can be given to choose from, and how a mail looks. */
class ProbelaufStartansichtTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Simulator::class)) {
            $this->markTestSkipped('needs statamic-brand-context 1.21 (the dry run planner)');
        }

        Permission::register('see start view');

        $registry = app(MapObjectRegistry::class);

        foreach ([StartViewProduct::class, StartViewCourse::class, StartViewLeadMagnet::class] as $provider) {
            $registry->register($provider);
        }
    }

    protected function landkarte(array $refs): Landkarte
    {
        $landkarte = Landkarte::query()->create(['name' => 'Start']);
        $stage = $landkarte->stages()->create(['name' => 'Phase 1', 'position' => 0]);

        foreach ($refs as $i => [$type, $id]) {
            $stage->refs()->create(['type' => $type, 'ref_id' => $id, 'position' => $i]);
        }

        return $landkarte;
    }

    protected function asProber()
    {
        Role::make('start-prober')->title('Prober')->permissions(['access cp', 'access funnels utility', 'see start view'])->save();

        return $this->actingAs(tap(User::make()->email('start@example.com')->assignRole('start-prober'))->save());
    }

    protected function plan(Landkarte $landkarte, array $body = []): array
    {
        return $this->postJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf", ['person' => 'test', ...$body])->assertOk()->json();
    }

    #[Test]
    public function the_first_start_is_a_lead_magnet_when_the_map_has_one(): void
    {
        // The product comes first on the map; the screen still opens on "gets a lead magnet".
        $landkarte = $this->landkarte([['product', 'masterclass'], ['lead_magnet', 'freebie'], ['lead_magnet', 'second']]);
        $this->asProber();

        $json = $this->plan($landkarte);

        $this->assertSame(['lead_magnet:freebie:requested', 'lead_magnet:second:requested', 'product:masterclass:purchased'], array_column($json['starts'], 'key'));
        $this->assertSame('lead_magnet:freebie:requested', $json['start']);
    }

    #[Test]
    public function a_start_that_is_named_is_the_one_that_is_planned(): void
    {
        $landkarte = $this->landkarte([['product', 'masterclass'], ['lead_magnet', 'freebie']]);
        $this->asProber();

        $this->assertSame('product:masterclass:purchased', $this->plan($landkarte, ['start' => 'product:masterclass:purchased'])['start']);
    }

    #[Test]
    public function access_is_offered_by_name_and_kind_from_the_map_and_not_as_free_text(): void
    {
        $landkarte = $this->landkarte([['lead_magnet', 'freebie'], ['product', 'masterclass'], ['course', 'stimme'], ['automation', 'welcome']]);
        $this->asProber();

        $access = $this->plan($landkarte)['options']['access'];

        // Every lead magnet, product and course, with the name the card shows and the kind it is; an automation grants nothing a person holds.
        $this->assertSame(['freebie', 'masterclass', 'stimme'], array_column($access, 'value'));
        $this->assertSame(['Name of freebie', 'Name of masterclass', 'Name of stimme'], array_column($access, 'label'));
        $this->assertSame(['Lead_magnet', 'Product', 'Course'], array_column($access, 'kind'));
    }

    #[Test]
    public function the_tags_come_from_the_automations_on_the_map_once_each(): void
    {
        $registry = app(MapObjectRegistry::class);

        if (! is_subclass_of(AutomationMapObjects::class, StartViewObjects::class)) {
            $this->markTestSkipped('the real automations addon is installed; its own suite proves its tags');
        }

        $registry->register(AutomationMapObjects::class);
        $landkarte = $this->landkarte([['lead_magnet', 'freebie'], ['automation', 'welcome'], ['automation', 'other']]);
        $this->asProber();

        // The list of the stand-in has "Chorleitung" and "chorleitung": a tag is a word, and two spellings are two words; the
        // same word from two automations is one.
        $this->assertSame(['Chorleitung', 'Solo', 'chorleitung', 'Newsletter'], $this->plan($landkarte)['options']['tags']);
    }

    #[Test]
    public function without_the_automations_addon_the_tags_are_empty_and_free_to_type(): void
    {
        $landkarte = $this->landkarte([['lead_magnet', 'freebie']]);
        $this->asProber();

        $this->assertSame([], $this->plan($landkarte)['options']['tags']);
    }

    #[Test]
    public function the_tags_the_person_has_are_planned_as_words_from_a_list(): void
    {
        $landkarte = $this->landkarte([['lead_magnet', 'freebie']]);
        $this->asProber();

        $json = $this->plan($landkarte, ['tags' => ['Chorleitung', 'Solo'], 'access' => ['masterclass']]);

        $this->assertSame(['Chorleitung', 'Solo'], $json['state']['tags']);
        $this->assertSame(['masterclass'], $json['state']['access']);
    }

    // --- the mail frame ------------------------------------------------------------------------------------------------------

    #[Test]
    public function an_image_that_is_switched_off_becomes_a_quiet_area_with_its_alternative_text(): void
    {
        $html = '<!doctype html><html><head><meta charset="utf-8"></head><body><p>Hi</p><img alt="Adrian am Klavier" data-sim-src="https://cdn.example/a.jpg" data-sim-blocked="1"><img data-sim-blocked="1"></body></html>';

        $calm = MailFrame::calm($html, 'Bild');

        $this->assertStringNotContainsString('<img', $calm);
        $this->assertStringNotContainsString('cdn.example', $calm);
        $this->assertStringContainsString('class="sim-img"', $calm);
        $this->assertStringContainsString('Adrian am Klavier', $calm);
        // No alternative text: the word for it.
        $this->assertStringContainsString('aria-label="Bild"', $calm);
        $this->assertStringContainsString('<p>Hi</p>', $calm);
        $this->assertStringContainsString('.sim-img{', $calm);
    }

    #[Test]
    public function a_mail_without_images_keeps_its_content_and_its_policy(): void
    {
        $html = '<!doctype html><html><head><meta http-equiv="Content-Security-Policy" content="default-src \'none\'"></head><body><p>Hi</p></body></html>';

        $calm = MailFrame::calm($html, 'Bild');

        $this->assertStringContainsString('<p>Hi</p>', $calm);
        $this->assertStringContainsString('Content-Security-Policy', $calm);
        $this->assertStringNotContainsString('sim-img', $calm);
    }

    #[Test]
    public function whatever_link_got_past_the_first_layer_is_taken_out_again(): void
    {
        $html = '<!doctype html><html><head><meta http-equiv="refresh" content="0;url=/cp"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'"></head><body>'
            .'<a href="https://track.example/c/1" target="_self">Go</a> <a href="#">Stay</a> <a/href="https://odd.example/">Odd</a>'
            .'<svg><a xlink:href="https://svg.example/"><text>Svg</text></a></svg> <area href="/cp"></body></html>';

        $calm = MailFrame::calm($html, 'Bild');

        foreach (['track.example', 'odd.example', 'svg.example', 'url=/cp', 'refresh', 'xlink:href'] as $gone) {
            $this->assertStringNotContainsString($gone, $calm, $gone.' is still in the mail');
        }

        // The policy stays, and every link that is left has no address but `#` and opens "in a new window", which the sandbox refuses.
        $this->assertStringContainsString('Content-Security-Policy', $calm);
        $this->assertStringContainsString('<base target="_blank">', $calm);
        $this->assertSame(0, preg_match('/href="(?!#")/', $calm));
    }

    #[Test]
    public function the_calm_area_loads_nothing_and_runs_nothing(): void
    {
        $calm = MailFrame::calm('<html><head></head><body><img alt="x&quot; onerror=&quot;alert(1)" data-sim-blocked="1"></body></html>', 'Bild');

        $this->assertStringNotContainsString('<script', $calm);

        // The text that looks like an attribute is text (inside a quoted value), not an attribute: read it as a browser does.
        $document = new \DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($calm);
        libxml_clear_errors();

        foreach ((new \DOMXPath($document))->query('//*') as $element) {
            foreach ($element->attributes as $attribute) {
                $this->assertDoesNotMatchRegularExpression('/^(on|src$|href$)/i', $attribute->nodeName, "{$element->nodeName} carries {$attribute->nodeName}");
            }
        }
    }
}
