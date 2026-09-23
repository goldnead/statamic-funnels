<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Support\Split;
use Goldnead\StatamicFunnels\Support\SplitResults;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * F2: A/B-Test mit waehlbarem Ziel und automatischem Gewinner.
 *
 * Das Ziel entscheidet, was gezaehlt wird: weitergegangen, gekauft, ein Upsell
 * danach angenommen, oder Umsatz je Besuch. Ein Gewinner steht erst fest, wenn
 * jede Fassung die Mindestzahl hat **und** der Unterschied mit 95 % Sicherheit
 * kein Zufall ist; ab dann sehen neue Besucher nur noch ihn.
 */
class SplitGoalTest extends TestCase
{
    protected function funnel(array $split = []): Funnel
    {
        $funnel = Funnel::create(['handle' => 'kurs', 'title' => 'Kurs', 'published' => true]);
        $funnel->steps()->createMany([
            ['node_key' => 'entry_1', 'type' => 'entry', 'config' => ['headline' => 'Start']],
            ['node_key' => 'kasse', 'type' => 'offer', 'slug' => 'kasse', 'config' => array_merge([
                'offer' => 'kurs', 'headline' => 'Fassung A', 'split_share' => '50', 'variant_headline' => 'Fassung B',
            ], $split)],
            ['node_key' => 'upsell', 'type' => 'offer', 'slug' => 'upsell', 'config' => ['offer' => 'kurs']],
            ['node_key' => 'danke', 'type' => 'finish', 'slug' => 'danke'],
        ]);
        $funnel->edges()->createMany([
            ['from_node_key' => 'entry_1', 'to_node_key' => 'kasse', 'from_output' => 'default'],
            ['from_node_key' => 'kasse', 'to_node_key' => 'upsell', 'from_output' => 'accepted'],
            ['from_node_key' => 'kasse', 'to_node_key' => 'danke', 'from_output' => 'declined'],
            ['from_node_key' => 'upsell', 'to_node_key' => 'danke', 'from_output' => 'accepted'],
            ['from_node_key' => 'upsell', 'to_node_key' => 'danke', 'from_output' => 'declined'],
        ]);

        Offer::create(['handle' => 'kurs', 'name' => 'Kurs', 'product' => 'kurs', 'amount_cent' => 9900, 'slot' => 'standalone', 'active' => true]);

        return $funnel->fresh(['steps', 'edges']);
    }

    /**
     * `n` Besuche in Fassung `variant`, davon `kauf` mit Kauf an der Kasse und
     * `upsell` davon zusaetzlich mit angenommenem Upsell.
     */
    protected function besuche(Funnel $funnel, string $variant, int $n, int $kauf = 0, int $upsell = 0, int $betrag = 9900): void
    {
        for ($i = 0; $i < $n; $i++) {
            $visit = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => Str::random(32), 'meta' => ['variants' => ['kasse' => $variant]]]);
            $visit->record('kasse', FunnelStepEvent::ENTERED, ['variant' => $variant]);

            if ($i < $kauf) {
                $zahlung = Payment::create(['provider' => 'fake', 'provider_id' => 'tr_'.Str::random(8), 'product' => 'offer:kurs', 'amount_cent' => $betrag, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID]);
                $visit->record('kasse', FunnelStepEvent::ACCEPTED, ['payment_id' => $zahlung->id]);
                $visit->record('upsell', FunnelStepEvent::ENTERED);

                if ($i < $upsell) {
                    $zweite = Payment::create(['provider' => 'fake', 'provider_id' => 'tr_'.Str::random(8), 'product' => 'offer:kurs', 'amount_cent' => 4900, 'currency' => 'EUR', 'status' => Payment::STATUS_PAID]);
                    $visit->record('upsell', FunnelStepEvent::ACCEPTED, ['payment_id' => $zweite->id]);
                }
            }
        }
    }

    protected function kasse(Funnel $funnel): FunnelStep
    {
        return $funnel->fresh(['steps', 'edges'])->stepByKey('kasse');
    }

    #[Test]
    public function ziel_kauf_zaehlt_die_kaeufe_je_fassung(): void
    {
        $funnel = $this->funnel(['split_goal' => 'purchase']);
        $this->besuche($funnel, 'a', 10, 2);
        $this->besuche($funnel, 'b', 10, 5);

        $r = SplitResults::forStep($funnel->fresh(['steps', 'edges']), $this->kasse($funnel));

        $this->assertSame('purchase', $r['goal']);
        $this->assertSame(10, $r['variants']['a']['visits']);
        $this->assertSame(2, $r['variants']['a']['conversions']);
        $this->assertSame(5, $r['variants']['b']['conversions']);
        $this->assertSame(50.0, $r['variants']['b']['rate']);
    }

    #[Test]
    public function ziel_upsell_zaehlt_nur_den_angenommenen_upsell_danach(): void
    {
        $funnel = $this->funnel(['split_goal' => 'upsell']);
        $this->besuche($funnel, 'a', 10, 6, 1);
        $this->besuche($funnel, 'b', 10, 3, 3);

        $r = SplitResults::forStep($funnel->fresh(['steps', 'edges']), $this->kasse($funnel));

        $this->assertSame(1, $r['variants']['a']['conversions']);
        $this->assertSame(3, $r['variants']['b']['conversions']);
    }

    #[Test]
    public function ziel_umsatz_rechnet_umsatz_je_besuch(): void
    {
        $funnel = $this->funnel(['split_goal' => 'revenue']);
        $this->besuche($funnel, 'a', 4, 2, 0, 10000); // 20000 / 4 = 5000
        $this->besuche($funnel, 'b', 4, 1, 1, 10000); // (10000 + 4900) / 4 = 3725

        $r = SplitResults::forStep($funnel->fresh(['steps', 'edges']), $this->kasse($funnel));

        $this->assertSame(5000, $r['variants']['a']['revenue_per_visit_cent']);
        $this->assertSame(3725, $r['variants']['b']['revenue_per_visit_cent']);
    }

    #[Test]
    public function ohne_ziel_bleibt_es_beim_weitergehen(): void
    {
        $funnel = $this->funnel();
        $this->besuche($funnel, 'a', 4, 1);

        $r = SplitResults::forStep($funnel->fresh(['steps', 'edges']), $this->kasse($funnel));

        $this->assertSame('continue', $r['goal']);
        $this->assertSame(1, $r['variants']['a']['conversions']);
    }

    #[Test]
    public function ein_deutlicher_unterschied_ueber_der_mindestzahl_ergibt_einen_gewinner(): void
    {
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_auto' => true, 'split_min_visits' => '40']);
        $this->besuche($funnel, 'a', 40, 4);
        $this->besuche($funnel, 'b', 40, 20);

        $winner = SplitResults::decide($funnel->fresh(['steps', 'edges']), $this->kasse($funnel));

        $this->assertSame('b', $winner);
        $this->assertSame('b', $funnel->fresh()->meta['split_winners']['kasse']['variant'] ?? null);
    }

    #[Test]
    public function neue_besucher_sehen_danach_nur_den_gewinner(): void
    {
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_auto' => true, 'split_min_visits' => '40']);
        $this->besuche($funnel, 'a', 40, 4);
        $this->besuche($funnel, 'b', 40, 20);
        SplitResults::decide($funnel->fresh(['steps', 'edges']), $this->kasse($funnel));

        $step = $this->kasse($funnel);
        $step->setRelation('funnel', $funnel->fresh());

        // Zwanzig neue Besucher, deren Muenzwurf ohne Gewinner auch A ergaebe.
        for ($i = 0; $i < 20; $i++) {
            $visit = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => Str::random(32)]);
            $this->assertSame(Split::B, Split::variantFor($step, $visit));
        }
    }

    #[Test]
    public function ein_bestehender_besucher_behaelt_seine_fassung(): void
    {
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_auto' => true, 'split_min_visits' => '40']);
        $alt = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => Str::random(32), 'meta' => ['variants' => ['kasse' => 'a']]]);
        $this->besuche($funnel, 'a', 40, 4);
        $this->besuche($funnel, 'b', 40, 20);
        SplitResults::decide($funnel->fresh(['steps', 'edges']), $this->kasse($funnel));

        $step = $this->kasse($funnel);
        $step->setRelation('funnel', $funnel->fresh());

        $this->assertSame(Split::A, Split::variantFor($step, $alt));
    }

    #[Test]
    public function unter_der_mindestzahl_gibt_es_keinen_gewinner(): void
    {
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_auto' => true, 'split_min_visits' => '100']);
        $this->besuche($funnel, 'a', 40, 4);
        $this->besuche($funnel, 'b', 40, 20);

        $this->assertNull(SplitResults::decide($funnel->fresh(['steps', 'edges']), $this->kasse($funnel)));
        $this->assertNull($funnel->fresh()->meta['split_winners'] ?? null);
    }

    #[Test]
    public function ein_zufaelliger_unterschied_ergibt_keinen_gewinner(): void
    {
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_auto' => true, 'split_min_visits' => '40']);
        $this->besuche($funnel, 'a', 40, 10);
        $this->besuche($funnel, 'b', 40, 12);

        $this->assertNull(SplitResults::decide($funnel->fresh(['steps', 'edges']), $this->kasse($funnel)));
    }

    #[Test]
    public function ohne_automatik_wird_nichts_festgelegt(): void
    {
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_min_visits' => '40']);
        $this->besuche($funnel, 'a', 40, 4);
        $this->besuche($funnel, 'b', 40, 20);

        $this->assertNull(SplitResults::decide($funnel->fresh(['steps', 'edges']), $this->kasse($funnel)));
    }

    #[Test]
    public function ein_gewinner_fuer_ein_anderes_ziel_gilt_nicht(): void
    {
        $funnel = $this->funnel(['split_goal' => 'revenue', 'split_auto' => true]);
        $funnel->forceFill(['meta' => ['split_winners' => ['kasse' => ['variant' => 'b', 'goal' => 'purchase']]]])->save();

        $step = $this->kasse($funnel);
        $step->setRelation('funnel', $funnel->fresh());

        $this->assertNull(Split::winner($step));
    }

    #[Test]
    public function der_editor_bekommt_ergebnis_ziel_und_gewinner(): void
    {
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_auto' => true, 'split_min_visits' => '40']);
        $this->besuche($funnel, 'a', 40, 4);
        $this->besuche($funnel, 'b', 40, 20);

        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();
        $response = $this->actingAs($user)->get('/cp/utilities/funnels/'.$funnel->id.'/edit')->assertOk();

        preg_match('/data-page="(.*?)"/s', $response->getContent(), $m);
        $props = json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];

        $split = $props['splits']['kasse'];
        $this->assertSame('purchase', $split['goal']);
        $this->assertSame(20, $split['variants']['b']['conversions']);
        // Der Editor legt den Gewinner fest, wenn er feststeht: niemand muss
        // auf den naechsten Besucher warten, um es zu sehen.
        $this->assertSame('b', $split['winner']);
        $this->assertGreaterThan(0.95, $split['confidence']);
    }
}
