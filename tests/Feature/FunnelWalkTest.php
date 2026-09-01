<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Zwei Besucher, ein Controller.
 *
 * Laravel haelt die Controller-Instanz am Route-Objekt fest. `FunnelWalk` las
 * den Request einmal aus dem Konstruktor — und der zweite Besucher, der ueber
 * dasselbe Route-Objekt kam, lief mit dem Cookie des ersten. Der Weg des einen
 * gehoerte damit dem anderen.
 */
class FunnelWalkTest extends TestCase
{
    protected function asVisitor(string $token): static
    {
        return $this->withUnencryptedCookie(FunnelWalk::COOKIE, $token);
    }

    protected function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);

        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'capture_1', 'type' => 'capture', 'label' => 'Anmeldung', 'slug' => 'anmeldung'],
            ['node_key' => 'page_1', 'type' => 'page', 'label' => 'Danke', 'slug' => 'danke'],
        ]);

        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'capture_1', 'from_output' => 'default'],
            ['from_node_key' => 'capture_1', 'to_node_key' => 'page_1', 'from_output' => 'default'],
        ]);

        return $funnel->fresh(['steps', 'edges']);
    }

    #[Test]
    public function two_visitors_through_the_same_controller_keep_their_own_walks(): void
    {
        $this->funnel();

        $a = str_repeat('a', 32);
        $b = str_repeat('b', 32);

        // Beide sehen das Formular, nur A schickt es ab — ueber dieselbe
        // POST-Route, also denselben Controller.
        $this->asVisitor($a)->get('/f/kurs/anmeldung')->assertOk();
        $this->asVisitor($b)->get('/f/kurs/anmeldung')->assertOk();

        $this->asVisitor($a)->post('/f/kurs/capture_1/advance', ['email' => 'a@example.com'])->assertRedirect('/f/kurs/danke');
        $this->asVisitor($b)->post('/f/kurs/capture_1/advance', ['email' => 'b@example.com'])->assertRedirect('/f/kurs/danke');

        $this->assertSame(2, FunnelVisit::count());
        $this->assertSame('a@example.com', FunnelVisit::where('token', $a)->sole()->email);
        $this->assertSame('b@example.com', FunnelVisit::where('token', $b)->sole()->email);

        // Und einer, der den Schritt nie gesehen hat, kommt ueber denselben
        // Controller nicht weiter — auch nicht auf dem Cookie des Vorgaengers.
        $this->asVisitor(str_repeat('c', 32))->post('/f/kurs/capture_1/advance', ['email' => 'c@example.com'])->assertForbidden();
        $this->assertSame(3, FunnelVisit::count());
        $this->assertNull(FunnelVisit::where('token', str_repeat('c', 32))->sole()->email);
    }
}
