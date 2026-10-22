<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The address the editor links to ("Live"): the funnel's own `/f/{handle}` only where something answers there.
 * A funnel that starts on a page of the site has no entry step, `/f/{handle}` is a 404, and the editor shows the page.
 */
class FunnelPublicUrlTest extends TestCase
{
    #[Test]
    public function a_funnel_with_an_entry_links_to_its_own_address(): void
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->create(['node_key' => 'entry_1', 'type' => 'entry', 'label' => 'Start']);

        $this->assertSame(route('statamic-funnels.entry', 'kurs'), $funnel->fresh('steps')->publicUrl());
    }

    #[Test]
    public function a_funnel_that_starts_on_a_page_of_the_site_links_to_that_page(): void
    {
        $funnel = Funnel::create(['handle' => 'reise', 'title' => 'Reise', 'published' => true]);
        $funnel->steps()->createMany([
            ['node_key' => 'ende', 'type' => 'finish', 'label' => 'Ende', 'slug' => 'ende'],
            ['node_key' => 'optin', 'type' => 'external', 'label' => 'Seite', 'config' => ['path' => '/cvt-im-chor-minikurs']],
            ['node_key' => 'kurs', 'type' => 'external', 'label' => 'Kurs', 'config' => ['automation' => 'mail-kurs']],
        ]);

        $this->assertSame(url('/cvt-im-chor-minikurs'), $funnel->fresh('steps')->publicUrl());
    }

    #[Test]
    public function a_funnel_with_neither_keeps_the_old_address(): void
    {
        $funnel = Funnel::create(['handle' => 'leer', 'title' => 'Leer', 'published' => false]);

        $this->assertSame(route('statamic-funnels.entry', 'leer'), $funnel->fresh('steps')->publicUrl());
    }
}
