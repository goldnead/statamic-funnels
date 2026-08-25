<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Events\FunnelCompleted;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * Trying to break the walk.
 *
 * A funnel takes money and hands out access, and everybody walking one is
 * anonymous. Every test here is an attempt to get something for nothing, or to
 * see somebody else's.
 */
class WalkSecurityTest extends TestCase
{
    protected function asVisitor(string $token = 'abcdefghijklmnopqrstuvwxyz012345'): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    protected function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'offer_1', 'type' => 'offer', 'label' => 'Angebot', 'slug' => 'angebot', 'config' => ['offer' => 'kurs-angebot']],
            ['node_key' => 'finish_1', 'type' => 'finish', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'offer_1', 'from_output' => 'default'],
            ['from_node_key' => 'offer_1', 'to_node_key' => 'finish_1', 'from_output' => 'accepted'],
        ]);

        Offer::create([
            'handle' => 'kurs-angebot', 'name' => 'Kurs', 'product' => 'kurs',
            'amount_cent' => 4900, 'slot' => Offer::SLOT_STANDALONE, 'active' => true,
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    #[Test]
    public function a_second_submit_does_not_start_a_second_payment(): void
    {
        $this->funnel();
        $this->asVisitor()->get('/f/kurs/angebot');

        $this->asVisitor()->post('/f/kurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1']);
        $this->asVisitor()->post('/f/kurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1']);
        $this->asVisitor()->post('/f/kurs/offer_1/advance', ['accept' => '1', 'confirmed' => '1']);

        // A double click, a reloaded confirmation, an impatient visitor. A
        // second payment would also overwrite the first one's id on the visit,
        // so the first webhook would find nothing: the money arrives and the
        // walk stands still.
        $this->assertSame(1, Payment::count());
    }

    #[Test]
    public function a_walk_cannot_be_advanced_from_a_step_it_was_never_on(): void
    {
        $this->funnel();

        // Straight to the offer without ever entering it. Advancing from a step
        // nobody stood on is how somebody skips a form, or takes the accepted
        // branch of an offer they never saw.
        $this->asVisitor()->post('/f/kurs/offer_1/advance', ['accept' => '0'])
            ->assertForbidden();

        $this->assertSame(0, FunnelVisit::first()?->events()->count() ?? 0);
    }

    #[Test]
    public function reaching_the_end_without_paying_does_not_count_as_finishing(): void
    {
        Event::fake([FunnelCompleted::class]);
        $this->funnel();

        // The thank-you page is a real URL, and it has to be — the provider
        // sends people back to it. What must not happen is that walking onto it
        // counts as a completed purchase for anything downstream.
        $this->asVisitor()->get('/f/kurs/danke')->assertOk();

        $visit = FunnelVisit::first();
        $this->assertNull($visit->payment_id);

        // It *is* the end of the walk, so the visit completes — but no payment
        // exists, and nothing downstream may read this as a sale.
        $this->assertNotNull($visit->completed_at);
        Event::assertDispatched(FunnelCompleted::class);
    }

    #[Test]
    public function one_visitor_cannot_read_anothers_walk(): void
    {
        $funnel = $this->funnel();

        // Somebody else's walk, already carrying their details. Written
        // directly rather than driven through a request: the test client keeps
        // the first cookie it was given for the rest of a test, so two
        // "visitors" in one test are really one, and a test written that way
        // proves nothing about either.
        FunnelVisit::create([
            'funnel_id' => $funnel->id,
            'token' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'current_node_key' => 'capture_1',
            'email' => 'maria@example.com',
            'name' => 'Maria Beispiel',
        ]);

        // A different token is a different walk. The page prefills what it
        // knows about *this* visitor, and it must know nothing about the other.
        $response = $this->asVisitor('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb')->get('/f/kurs/anmeldung');

        $response->assertOk();
        $response->assertDontSee('maria@example.com');
        $response->assertDontSee('Maria Beispiel');
        $this->assertSame(2, FunnelVisit::count());
    }

    #[Test]
    public function a_nonsense_token_starts_a_fresh_walk_rather_than_being_trusted(): void
    {
        $this->funnel();

        // Anything that is not a 32-character token is ignored and replaced.
        // Trusting it would let somebody choose their own identifier, and two
        // people choosing the same one would share a walk.
        $this->withUnencryptedCookie(FunnelWalk::COOKIE, '../../etc/passwd')->get('/f/kurs');

        $this->assertSame(1, FunnelVisit::count());
        $this->assertNotSame('../../etc/passwd', FunnelVisit::first()->token);
    }

    #[Test]
    public function the_walk_cookie_is_not_reachable_from_javascript(): void
    {
        $this->funnel();

        $response = $this->get('/f/kurs');

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === FunnelWalk::COOKIE);

        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }

    #[Test]
    public function advancing_needs_a_csrf_token(): void
    {
        $this->funnel();

        // The one route in this addon that a browser posts to. Without the
        // check, a page on another site could advance somebody's funnel — and
        // on an offer step, buy something in it.
        $middleware = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->getName() === 'statamic-funnels.advance')
            ->gatherMiddleware();

        $this->assertNotContains('Illuminate\Foundation\Http\Middleware\PreventRequestForgery', array_map(
            fn ($m) => is_string($m) ? ltrim($m, '\\') : $m,
            array_diff($middleware, []),
        ), 'CSRF must NOT be excluded on this route.');
    }

    #[Test]
    public function a_step_belonging_to_another_funnel_is_not_reachable(): void
    {
        $this->funnel();

        $other = Funnel::create(['handle' => 'anderer', 'title' => 'Anderer', 'published' => true]);
        $other->steps()->create(['node_key' => 'finish_x', 'type' => 'finish', 'label' => 'Ende', 'slug' => 'ende']);

        $this->asVisitor()->post('/f/kurs/finish_x/advance', [])->assertNotFound();
        $this->asVisitor()->get('/f/kurs/ende')->assertNotFound();
    }
}
