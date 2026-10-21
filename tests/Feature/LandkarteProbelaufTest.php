<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Carbon\CarbonImmutable;
use Goldnead\BrandContext\Contracts\ProvidesMapEdges;
use Goldnead\BrandContext\Contracts\ProvidesMapObjects;
use Goldnead\BrandContext\Contracts\ProvidesSimPersons;
use Goldnead\BrandContext\Map\MapEdge;
use Goldnead\BrandContext\Map\MapMetric;
use Goldnead\BrandContext\Map\MapObject;
use Goldnead\BrandContext\Map\MapObjectRegistry;
use Goldnead\BrandContext\Map\MapPeriod;
use Goldnead\BrandContext\Simulation\Effect;
use Goldnead\BrandContext\Simulation\PersonSources;
use Goldnead\BrandContext\Simulation\SimEvent;
use Goldnead\BrandContext\Simulation\SimPerson;
use Goldnead\BrandContext\Simulation\Simulator;
use Goldnead\StatamicFunnels\Models\Landkarte;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Permission;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/** One kind of object: label is "<TYPE> <id>", rights are "see probe". */
abstract class ProbeFakeObjects implements ProvidesMapObjects
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
        return new MapObject($this->type(), $id, ucfirst($this->type()).' '.$id);
    }

    public function metric(string $id, MapPeriod $period): ?MapMetric
    {
        return null;
    }

    public function simulationAuthorized(string $id, $user): bool
    {
        return $user !== null && $user->can('see probe');
    }
}

class ProbeLeadMagnets extends ProbeFakeObjects implements ProvidesMapEdges
{
    public function type(): string
    {
        return 'lead_magnet';
    }

    public function edges(): iterable
    {
        yield new MapEdge('lead_magnet', 'freebie', 'automation', 'welcome', 'triggers', 'starts', 'test');
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::REQUESTED)];
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return Effect::chain([Effect::mail('Please confirm')], [Effect::emit(new SimEvent(SimEvent::GRANTED, via: ['triggers']))]);
    }
}

class ProbeAutomations extends ProbeFakeObjects implements ProvidesMapEdges
{
    public function type(): string
    {
        return 'automation';
    }

    public function edges(): iterable
    {
        yield new MapEdge('product', 'masterclass', 'automation', 'thanks', 'triggers', 'starts', 'test');
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        if ($id !== 'welcome') {
            return [Effect::mail('Thank you', null, 0, ['node' => 'm3'])];
        }

        // Nobody known: the way as it was. A known person gets one more mail that a tag decides.
        if (! $person->isKnown()) {
            return Effect::chain([Effect::mail('Welcome', null, 0, ['node' => 'm1']), Effect::wait('after 2 days', 2 * 86400), Effect::mail('Follow-up', null, 0, ['node' => 'm2'])]);
        }

        $choir = in_array('chor', (array) $person->trait('contact.tags', []), true);

        return Effect::chain([
            Effect::mail('Welcome', null, 0, ['node' => 'm1']),
            Effect::wait('after 2 days', 2 * 86400),
            Effect::mail('Follow-up', null, 0, ['node' => 'm2']),
            Effect::decision('Choir or not?', $choir ? 'tag chor' : 'no tag', [Effect::mail($choir ? 'For choirs' : 'For everyone', null, 0, ['node' => 'm4', 'marketing' => true])]),
        ]);
    }

    public function mailPreview(string $id, array $meta, SimPerson $person): ?array
    {
        if (($meta['node'] ?? null) === 'm1') {
            return [
                'subject' => 'Welcome, friend',
                'from' => 'hello@example.test',
                'html' => '<p>Hi</p><script>alert(1)</script><img src="https://track.example/o.gif"><a href="https://track.example/c/1">Go</a>',
            ];
        }

        return null;
    }
}

class ProbeProducts extends ProbeFakeObjects
{
    public function type(): string
    {
        return 'product';
    }

    public function paths(string $id): array
    {
        return [new SimEvent(SimEvent::PURCHASED)];
    }

    public function react(string $id, SimEvent $event, SimPerson $person, CarbonImmutable $at): array
    {
        return [Effect::access('Access to the course'), Effect::emit(new SimEvent(SimEvent::GRANTED, via: ['triggers']))];
    }
}

/** A CRM that knows Lena: she is a choir leader, holds an access, went through an automation, and asked not to be written to. */
class ProbeCrm implements ProvidesSimPersons
{
    public function personSourceId(): string
    {
        return 'crm';
    }

    public function personsAuthorized($user): bool
    {
        return $user !== null && $user->can('see probe');
    }

    public function searchPersons(string $query, int $limit): array
    {
        return str_contains('lena probe', strtolower($query)) ? [['key' => '7', 'label' => 'Lena Probe']] : [];
    }

    public function identifyPerson(string $key): ?array
    {
        return $key === '7' ? ['email' => 'lena@example.test', 'id' => 7] : null;
    }

    public function personFacet(array $identity): array
    {
        return [
            'traits' => ['contact.tags' => ['chor'], 'contact.status' => 'customer'],
            'access' => ['kurs-stimme'],
            'ran' => ['welcome'],
            'unsubscribed' => true,
        ];
    }
}

/** The dry run of a map: the tree of every way, planned from what the owners say they would do. */
class LandkarteProbelaufTest extends TestCase
{
    protected $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(Simulator::class)) {
            $this->markTestSkipped('needs statamic-brand-context 1.21 (the dry run planner)');
        }

        Permission::register('see probe');

        if (class_exists(PersonSources::class)) {
            $sources = app(PersonSources::class);
            $sources->register(ProbeCrm::class);
        }

        $registry = app(MapObjectRegistry::class);

        foreach ([ProbeLeadMagnets::class, ProbeAutomations::class, ProbeProducts::class] as $provider) {
            $registry->register($provider);
        }
    }

    /** The map of the test: a lead magnet, its automation, a product and the automation that thanks for it. */
    protected function landkarte(): Landkarte
    {
        $landkarte = Landkarte::query()->create(['name' => 'CVT']);
        $stage = $landkarte->stages()->create(['name' => 'Phase 1', 'position' => 0]);

        foreach ([['lead_magnet', 'freebie'], ['automation', 'welcome'], ['product', 'masterclass'], ['automation', 'thanks']] as $i => [$type, $id]) {
            $stage->refs()->create(['type' => $type, 'ref_id' => $id, 'position' => $i]);
        }

        return $landkarte;
    }

    protected function asAdmin()
    {
        Role::make('prober')->title('Prober')->permissions(['access cp', 'access funnels utility', 'see probe'])->save();

        return $this->actingAs($this->admin ??= tap(User::make()->email('studio@example.com')->assignRole('prober'))->save());
    }

    /** "kind:label" of the tree, depth first. */
    protected function flat(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            $out[] = $node['kind'].':'.$node['label'];
            array_push($out, ...$this->flat($node['children']));
        }

        return $out;
    }

    #[Test]
    public function the_tree_follows_the_map_in_order(): void
    {
        $landkarte = $this->landkarte();

        $json = $this->asAdmin()->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf")->assertOk()->json();

        // The lead magnet and the product are started by nobody on the map: two roots, in the map's order.
        $this->assertSame(['enter:Lead_magnet freebie', 'enter:Product masterclass'], array_map(fn ($r) => $r['kind'].':'.$r['label'], $json['roots']));

        $this->assertSame([
            'enter:Lead_magnet freebie',
            'mail:Please confirm',
            'fork:After this mail',
            'alt:opened · clicked · nothing: the sequence goes on',
            'enter:Automation welcome',
            'mail:Welcome',
            'fork:After this mail',
            'alt:opened · clicked · nothing: the sequence goes on',
            'wait:after 2 days',
            'mail:Follow-up',
            'fork:After this mail',
            'alt:opened · clicked · nothing: the sequence goes on',
            'alt:bought: Product masterclass',
            'enter:Product masterclass',
            'access:Access to the course',
            'enter:Automation thanks',
            'mail:Thank you',
            'fork:After this mail',
            'alt:opened · clicked · nothing: the sequence goes on',
            'rejoin:the sequence goes on as above',
            'alt:bought: Product masterclass',
            'enter:Product masterclass',
            'access:Access to the course',
            'enter:Automation thanks',
            'mail:Thank you',
            'fork:After this mail',
            'alt:opened · clicked · nothing: the sequence goes on',
            'rejoin:the sequence goes on as above',
            'alt:bought: Product masterclass',
            'enter:Product masterclass',
            'access:Access to the course',
            'enter:Automation thanks',
            'mail:Thank you',
            'fork:After this mail',
            'alt:opened · clicked · nothing: the sequence goes on',
            'rejoin:the sequence goes on as above',
        ], $this->flat([$json['roots'][0]]));

        // The second root is the product bought on its own, without any mail before it.
        $this->assertSame([
            'enter:Product masterclass',
            'access:Access to the course',
            'enter:Automation thanks',
            'mail:Thank you',
            'fork:After this mail',
            'alt:opened · clicked · nothing: the sequence goes on',
        ], $this->flat([$json['roots'][1]]));
    }

    #[Test]
    public function the_leaves_the_nodes_and_the_unreached_are_counted(): void
    {
        $landkarte = $this->landkarte();

        $stats = $this->asAdmin()->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf")->assertOk()->json('stats');

        $this->assertSame(0, $stats['cut']);
        $this->assertSame([], $stats['unreached']);
        $this->assertSame(42, $stats['nodes']);
        $this->assertSame(12, $stats['leaves']);
    }

    #[Test]
    public function a_card_nobody_reaches_is_named(): void
    {
        $landkarte = $this->landkarte();
        $landkarte->stages()->first()->refs()->create(['type' => 'automation', 'ref_id' => 'lonely', 'position' => 9]);

        $stats = $this->asAdmin()->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf")->assertOk()->json('stats');

        $this->assertSame([['key' => 'automation:lonely', 'label' => 'Automation lonely', 'type_label' => 'Automation']], $stats['unreached']);
    }

    #[Test]
    public function only_what_the_user_may_see_is_shown(): void
    {
        $landkarte = $this->landkarte();
        Role::make('plain')->title('Plain')->permissions(['access cp', 'access funnels utility'])->save();

        $this->actingAs(tap(User::make()->email('plain@example.com')->assignRole('plain'))->save());

        $response = $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf")->assertOk();
        $flat = $this->flat($response->json('roots'));

        $this->assertContains('denied:'.__('brand-context::messages.sim_denied'), $flat);
        $this->assertNotContains('mail:Please confirm', $flat);
        $this->assertNotContains('mail:Welcome', $flat);
    }

    #[Test]
    public function without_the_funnels_right_there_is_nothing(): void
    {
        $landkarte = $this->landkarte();
        Role::make('nobody')->title('Nobody')->permissions(['access cp'])->save();

        $this->actingAs(tap(User::make()->email('nobody@example.com')->assignRole('nobody'))->save())
            ->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf")
            ->assertForbidden();
    }

    #[Test]
    public function the_dry_run_writes_nothing_sends_nothing_and_dispatches_nothing(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();
        Mail::fake();
        Queue::fake();
        Event::fake();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (! preg_match('/^\s*(select|pragma)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf")->assertOk();

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Queue::assertNothingPushed();
        $this->assertSame([], $writes);
    }

    #[Test]
    public function the_places_a_way_can_begin_are_listed_with_their_event(): void
    {
        $landkarte = $this->landkarte();

        $json = $this->asAdmin()->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf")->assertOk()->json();

        $this->assertSame(['lead_magnet:freebie:requested', 'product:masterclass:purchased'], array_column($json['starts'], 'key'));
        $this->assertSame(__('statamic-funnels::messages.probe_event_requested'), $json['starts'][0]['event_label']);
    }

    #[Test]
    public function the_test_person_walks_one_line_by_day(): void
    {
        $landkarte = $this->landkarte();

        $json = $this->asAdmin()
            ->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=test&start=lead_magnet:freebie:requested")
            ->assertOk()
            ->json();

        $this->assertSame('test', $json['person']);
        $this->assertSame('lead_magnet:freebie:requested', $json['start']);
        $this->assertSame([
            '0 enter:Lead_magnet freebie',
            '0 mail:Please confirm',
            '0 enter:Automation welcome',
            '0 mail:Welcome',
            '2 wait:after 2 days',
            '2 mail:Follow-up',
            '2 decision:Choir or not?',
            '2 mail:For everyone',
        ], array_map(fn ($e) => $e['day'].' '.$e['kind'].':'.$e['label'], $json['entries']));
        $this->assertSame([0, 2], $json['stats']['days']);
    }

    #[Test]
    public function a_purchase_starts_a_way_too(): void
    {
        $landkarte = $this->landkarte();

        $json = $this->asAdmin()
            ->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=test&start=product:masterclass:purchased")
            ->assertOk()
            ->json();

        $this->assertSame([
            'enter:Product masterclass',
            'access:Access to the course',
            'enter:Automation thanks',
            'mail:Thank you',
        ], array_map(fn ($e) => $e['kind'].':'.$e['label'], $json['entries']));
    }

    #[Test]
    public function a_start_the_map_does_not_offer_is_refused(): void
    {
        $landkarte = $this->landkarte();

        $this->asAdmin()->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=test&start=automation:welcome:started")->assertStatus(422);
        $this->asAdmin()->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=wild")->assertStatus(422);
    }

    #[Test]
    public function the_test_person_walks_without_writing_anything(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();
        Mail::fake();
        Queue::fake();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (! preg_match('/^\s*(select|pragma)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        // Both starts of the map, not just the first one.
        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=test&start=lead_magnet:freebie:requested")->assertOk();
        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=test&start=product:masterclass:purchased")->assertOk();
        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=unknown")->assertOk();

        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame([], $writes);
    }

    /** The id of the entry with this label in the timeline of the lead magnet start. */
    protected function entryId(Landkarte $landkarte, string $label): string
    {
        $json = $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=test&start=lead_magnet:freebie:requested")->assertOk()->json();

        return collect($json['entries'])->firstWhere('label', $label)['id'];
    }

    #[Test]
    public function the_mail_of_an_entry_is_written_and_made_safe(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();
        $id = $this->entryId($landkarte, 'Welcome');

        $json = $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/mail?start=lead_magnet:freebie:requested&entry={$id}&label=Welcome")->assertOk()->json();

        $this->assertSame('Welcome, friend', $json['subject']);
        $this->assertSame('hello@example.test', $json['from']);
        $this->assertStringContainsString('<p>Hi</p>', $json['html']);
        $this->assertStringNotContainsString('<script', $json['html']);
        $this->assertDoesNotMatchRegularExpression('/\ssrc="/', $json['html']);
        $this->assertSame([['text' => 'Go', 'url' => 'https://track.example/c/1']], $json['links']);
        $this->assertSame(1, $json['blocked_images']);
        $this->assertStringContainsString('img-src data:', html_entity_decode($json['html'], ENT_QUOTES));
    }

    #[Test]
    public function an_entry_that_is_no_mail_or_has_no_mail_to_show_is_not_found(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();
        $base = "/cp/funnels/landkarten/{$landkarte->id}/probelauf/mail?start=lead_magnet:freebie:requested";

        // The first entry is the card itself; the follow-up mail has no preview in this provider.
        $this->getJson("{$base}&entry=e1&label=x")->assertNotFound();
        $this->getJson("{$base}&entry=".$this->entryId($landkarte, 'Follow-up').'&label=Follow-up')->assertNotFound();
        $this->getJson("{$base}&entry=e999&label=x")->assertNotFound();
    }

    #[Test]
    public function a_mail_is_not_handed_out_under_another_name(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();
        $id = $this->entryId($landkarte, 'Welcome');

        // The map moved on between the list and the click: the entry under that place is not the one the user saw.
        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/mail?start=lead_magnet:freebie:requested&entry={$id}&label=Something+else")->assertStatus(409);
        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/mail?start=lead_magnet:freebie:requested&entry={$id}")->assertStatus(422);
    }

    #[Test]
    public function the_mail_endpoint_takes_only_a_start_the_map_offers_and_an_entry_of_the_right_shape(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();

        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/mail?start=automation:welcome:started&entry=e2&label=x")->assertStatus(422);
        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/mail?start=lead_magnet:freebie:requested&entry=../../x&label=x")->assertStatus(422);
        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/mail?entry=e2&label=x")->assertStatus(422);
    }

    #[Test]
    public function a_mail_the_user_may_not_see_is_not_written(): void
    {
        $landkarte = $this->landkarte();

        Role::make('plain')->title('Plain')->permissions(['access cp', 'access funnels utility'])->save();
        $this->actingAs(tap(User::make()->email('plain2@example.com')->assignRole('plain'))->save());

        // Without the right the planner names the card and nothing of what it sends: no entry is a mail.
        foreach (range(1, 8) as $i) {
            $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/mail?start=lead_magnet:freebie:requested&entry=e{$i}&label=x")->assertNotFound();
        }
    }

    #[Test]
    public function writing_a_mail_writes_nothing_and_sends_nothing(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();
        $id = $this->entryId($landkarte, 'Welcome');
        Mail::fake();
        Queue::fake();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (! preg_match('/^\s*(select|pragma)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/mail?start=lead_magnet:freebie:requested&entry={$id}&label=Welcome")->assertOk();

        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame([], $writes);
    }

    protected function walk(Landkarte $landkarte, string $query): array
    {
        return $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=test&start=lead_magnet:freebie:requested&{$query}")->assertOk()->json();
    }

    #[Test]
    public function the_tags_of_the_test_person_decide_what_the_automation_does(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();
        $mails = fn (array $json) => array_values(array_map(fn ($e) => $e['label'], array_filter($json['entries'], fn ($e) => $e['kind'] === 'mail')));

        $none = $this->walk($landkarte, '');
        $choir = $this->walk($landkarte, 'tags=chor,+solo+');

        $this->assertContains('For everyone', $mails($none));
        $this->assertContains('For choirs', $mails($choir));
        $this->assertNotContains('For everyone', $mails($choir));
        // The answer says who she was: trimmed, in the order given.
        $this->assertSame(['chor', 'solo'], $choir['state']['tags']);
    }

    #[Test]
    public function what_the_person_holds_and_has_run_is_taken_over_and_capped(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();

        $json = $this->walk($landkarte, 'access='.implode(',', range(1, 80)).'&ran[]=welcome&ran[]=thanks');

        $this->assertCount(50, $json['state']['access']);
        $this->assertSame(['welcome', 'thanks'], $json['state']['ran']);
    }

    #[Test]
    public function the_person_buys_after_a_mail_and_the_purchase_is_handed_to_the_product(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();
        $actions = json_encode([['type' => 'buy', 'mail' => 'lead_magnet:freebie|Please confirm', 'product' => 'product:masterclass', 'after_seconds' => 86400]]);

        $json = $this->walk($landkarte, 'actions='.urlencode($actions));
        $lines = array_map(fn ($e) => $e['day'].' '.$e['kind'].':'.$e['label'], $json['entries']);

        $this->assertContains('1 action:buys: Product masterclass', $lines);
        $this->assertContains('1 access:Access to the course', $lines);
        $this->assertCount(1, $json['state']['actions']);
        $this->assertSame([['key' => 'product:masterclass', 'label' => 'Product masterclass']], $json['products']);
    }

    #[Test]
    public function an_action_in_a_shape_that_is_none_is_dropped_and_broken_json_is_refused(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();

        $json = $this->walk($landkarte, 'actions='.urlencode(json_encode([['type' => 'launch', 'mail' => 'x'], ['type' => 'buy', 'mail' => 'x'], 'not an action'])));

        $this->assertSame([], $json['state']['actions']);

        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=test&actions=%7Bnot+json")->assertStatus(422);
    }

    #[Test]
    public function every_step_says_why_it_is_on_the_way(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();

        $json = $this->walk($landkarte, 'tags=chor');
        $welcome = collect($json['entries'])->firstWhere('label', 'Welcome');

        $this->assertSame([
            'Lead_magnet “Lead_magnet freebie”: asked for',
            'Automation “Automation welcome”: reached over the connection “starts”',
        ], $welcome['why']);
        $this->assertContains('Choir or not?: tag chor', collect($json['entries'])->firstWhere('label', 'For choirs')['why']);
    }

    #[Test]
    public function the_mail_is_written_for_the_same_person_the_entries_were_planned_for(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();
        $query = 'start=lead_magnet:freebie:requested&tags=chor';
        $id = collect($this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=test&{$query}")->json('entries'))->firstWhere('label', 'Welcome')['id'];

        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/mail?{$query}&entry={$id}&label=Welcome")->assertOk();
    }

    #[Test]
    public function a_real_person_is_found_and_copied_read_only(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();
        $base = "/cp/funnels/landkarten/{$landkarte->id}/probelauf/persons";

        $this->postJson($base, ['q' => 'lena'])->assertOk()->assertExactJson(['persons' => [['key' => 'crm|7', 'label' => 'Lena Probe']]]);
        $this->postJson($base, ['q' => 'l'])->assertOk()->assertExactJson(['persons' => []]);

        $copy = $this->postJson($base, ['like' => 'crm|7'])->assertOk()->json();

        $this->assertSame(['chor'], $copy['tags']);
        $this->assertSame(['kurs-stimme'], $copy['access']);
        $this->assertSame(['welcome'], $copy['ran']);
        $this->assertTrue($copy['unsubscribed']);
        $this->assertSame(['contact.status' => 'customer'], $copy['traits']);

        $this->postJson($base, ['like' => 'crm|999'])->assertNotFound();
        $this->postJson($base, ['like' => 'nope|7'])->assertNotFound();

        // A person is looked up in a body: the route takes no GET, whose query would sit in every access log.
        $this->assertContains($this->getJson("{$base}?q=lena")->getStatusCode(), [404, 405]);
    }

    #[Test]
    public function a_real_person_is_not_found_by_someone_who_may_not_read_her(): void
    {
        $landkarte = $this->landkarte();
        Role::make('plain')->title('Plain')->permissions(['access cp', 'access funnels utility'])->save();
        $this->actingAs(tap(User::make()->email('plain3@example.com')->assignRole('plain'))->save());
        $base = "/cp/funnels/landkarten/{$landkarte->id}/probelauf/persons";

        $this->postJson($base, ['q' => 'lena'])->assertOk()->assertExactJson(['persons' => []]);
        $this->postJson($base, ['like' => 'crm|7'])->assertNotFound();
    }

    #[Test]
    public function finding_and_copying_a_person_writes_nothing_and_logs_nobody(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (! preg_match('/^\s*(select|pragma)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.json_encode($message->context);
        });

        $this->postJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/persons", ['q' => 'lena'])->assertOk();
        $this->postJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf/persons", ['like' => 'crm|7'])->assertOk();

        $this->assertSame([], $writes);
        // Nobody is named in the log: not the search, not her address, not her name.
        foreach ($logged as $line) {
            $this->assertStringNotContainsStringIgnoringCase('lena', $line);
        }
    }

    #[Test]
    public function a_person_posted_as_json_is_planned_like_one_in_a_query(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();

        $json = $this->postJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf", [
            'person' => 'test',
            'start' => 'lead_magnet:freebie:requested',
            'tags' => ['chor', 'solo'],
            'access' => ['kurs-stimme'],
            'ran' => ['welcome'],
            'traits' => ['contact.status' => 'customer', 'bad key!' => 'x', 'nested' => ['a' => ['b']]],
            'actions' => [['type' => 'buy', 'mail' => 'lead_magnet:freebie|Please confirm', 'product' => 'product:masterclass', 'after_seconds' => 0]],
        ])->assertOk()->json();

        $this->assertSame(['chor', 'solo'], $json['state']['tags']);
        $this->assertSame(['kurs-stimme'], $json['state']['access']);
        $this->assertSame(['welcome'], $json['state']['ran']);
        $this->assertContains('For choirs', collect($json['entries'])->pluck('label')->all());
        $this->assertContains('0 action:buys: Product masterclass', array_map(fn ($e) => $e['day'].' '.$e['kind'].':'.$e['label'], $json['entries']));
    }

    #[Test]
    public function a_person_is_capped_to_what_the_sources_hand_over_not_refused(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();

        $json = $this->postJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf", [
            'person' => 'test',
            'tags' => array_map(fn ($i) => "tag{$i}", range(1, 45)),
            'access' => array_map(fn ($i) => "acc{$i}", range(1, 80)),
            'ran' => array_map(fn ($i) => "run{$i}", range(1, 80)),
        ])->assertOk()->json();

        $this->assertCount(30, $json['state']['tags']);
        $this->assertCount(50, $json['state']['access']);
        $this->assertCount(50, $json['state']['ran']);
    }

    #[Test]
    public function the_unsubscribe_of_a_copied_person_silences_the_marketing_mails_from_the_start(): void
    {
        $landkarte = $this->landkarte();
        $this->asAdmin();
        $kinds = fn (array $json) => collect($json['entries'])->whereIn('label', ['For everyone', 'Unsubscribed: this marketing mail does not go out'])->pluck('kind')->all();

        $this->assertSame(['mail'], $kinds($this->walk($landkarte, '')));
        $this->assertSame(['stop'], $kinds($this->walk($landkarte, 'unsubscribed=1')));

        // A trait that is no word, and one that is not scalar, are left out; the rest is read.
        $this->getJson("/cp/funnels/landkarten/{$landkarte->id}/probelauf?person=test&traits=%7Bbroken")->assertStatus(422);
    }

    #[Test]
    public function the_map_page_offers_the_dry_run(): void
    {
        $landkarte = $this->landkarte();

        $this->asAdmin()->get("/cp/funnels/landkarten/{$landkarte->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('probelaufUrl', cp_route('statamic-funnels.landkarten.probelauf', $landkarte->id)));
    }
}
