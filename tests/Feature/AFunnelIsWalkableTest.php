<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * A visitor has to be able to walk a funnel. That is the whole addon.
 *
 * They could not. The step renderer used a plain Laravel `view()`, which does
 * not run Statamic's cascade — and the cascade is where `csrf_field` comes
 * from. So the four `{{ csrf_field }}` in the shipped template rendered to an
 * empty string, every form posted without a token, and every step of every
 * funnel answered **419 Page Expired**.
 *
 * Nothing in the suite noticed, because the suite posted to the advance route
 * directly rather than submitting the form the page actually renders. The page
 * rendered, the route worked, and the two were never joined up.
 */
class AFunnelIsWalkableTest extends TestCase
{
    private function funnel(): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->create(['node_key' => 'entry_1', 'type' => 'entry', 'config' => ['headline' => 'Start']]);
        $funnel->steps()->create(['node_key' => 'danke_1', 'type' => 'finish', 'slug' => 'danke', 'config' => ['headline' => 'Danke']]);
        $funnel->edges()->create(['from_node_key' => 'entry_1', 'to_node_key' => 'danke_1', 'from_output' => 'default']);

        return $funnel->fresh(['steps', 'edges']);
    }

    #[Test]
    public function the_page_carries_a_token_a_visitor_can_post_back(): void
    {
        $this->funnel();

        $antwort = $this->get('/f/kurs');

        $antwort->assertOk();
        // The literal the browser needs. Without it the next request is a 419.
        $antwort->assertSee('name="_token"', escape: false);
    }

    #[Test]
    public function submitting_the_form_the_page_renders_actually_advances(): void
    {
        // The test the suite was missing: take the token off the rendered page
        // and post it back, exactly as a browser does. Posting to the route
        // with a hand-made session proves the route, not the page.
        $this->funnel();

        $seite = $this->get('/f/kurs');
        $seite->assertOk();

        preg_match('/name="_token"\s+value="([^"]+)"/', $seite->getContent(), $treffer);

        $this->assertNotEmpty($treffer, 'The rendered page has no CSRF token to post back.');

        $antwort = $this->post('/f/kurs/entry_1/advance', ['_token' => $treffer[1]]);

        // 419 is the failure this test exists for. A 403 would mean something
        // else entirely — that the visitor never reached the step — and is not
        // what a token proves or disproves.
        $this->assertNotSame(419, $antwort->getStatusCode(), 'The token the page rendered was not accepted.');
    }
}
