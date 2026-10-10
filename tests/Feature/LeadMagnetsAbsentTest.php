<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Integrations\LeadMagnetsBridge;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Tests\Support\WalksAFunnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Eine Site ohne lead-magnets.
 *
 * Die uebrige Suite ist eine solche Site: das Geschwister ist weder `require`
 * noch `require-dev`, und der Stand-in (`tests/Fakes/lead-magnets.php`) wird
 * nur in den eigenen Prozessen von `LeadMagnetStepTest` geladen. Hier steht
 * die Zusicherung, die der Stand-in nicht geben kann: ohne das Geschwister
 * gibt es den Schritt nicht, und ein gespeicherter Schritt dieses Typs bringt
 * keinen Besucher zu Fall.
 */
class LeadMagnetsAbsentTest extends TestCase
{
    use WalksAFunnel;

    protected function setUp(): void
    {
        parent::setUp();

        if (class_exists('Goldnead\LeadMagnets\Facades\LeadMagnets')) {
            $this->markTestSkipped('lead-magnets is installed on this machine; this file checks the site without it.');
        }
    }

    #[Test]
    public function the_step_type_is_not_registered(): void
    {
        $registry = new StepRegistry;

        $this->assertFalse($registry->has('lead_magnet'));
        $this->assertNull(collect($registry->library()['pages'] ?? [])->firstWhere('handle', 'lead_magnet'));
        $this->assertFalse(LeadMagnetsBridge::available());
        $this->assertSame([], LeadMagnetsBridge::options());
    }

    #[Test]
    public function the_editor_drops_a_node_of_the_unknown_type_instead_of_storing_it(): void
    {
        // Das ist das Verhalten des GraphWriter fuer jeden unbekannten Typ und
        // steht im README: wer lead-magnets entfernt, verliert den Schritt
        // beim naechsten Speichern.
        $this->assertFalse(app(StepRegistry::class)->has('lead_magnet'));
    }

    #[Test]
    public function a_saved_step_of_the_type_stops_the_walk_visibly_and_does_not_fail(): void
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start', 'slug' => null],
            ['node_key' => 'lm_1', 'type' => 'lead_magnet', 'label' => 'Geschenk', 'slug' => 'geschenk', 'config' => ['resource' => 'einsing']],
        ]);

        $this->asVisitor()->get('/f/kurs/geschenk')
            ->assertOk()
            ->assertSee('data-status="unavailable"', false);
    }
}
