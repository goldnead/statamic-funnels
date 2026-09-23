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

    /** Die feste Stichprobe (100 je Fassung), ausgewertet nach der Ruhezeit. */
    protected function deutlich(array $split = []): Funnel
    {
        $funnel = $this->funnel(array_merge(['split_goal' => 'purchase', 'split_auto' => true, 'split_min_visits' => '100'], $split));
        $this->besuche($funnel, 'a', 100, 10);
        $this->besuche($funnel, 'b', 100, 45);
        $this->travel(2)->days();

        return $funnel;
    }

    protected function entscheide(Funnel $funnel): ?string
    {
        return SplitResults::decide($funnel->fresh(['steps', 'edges']), $this->kasse($funnel));
    }

    #[Test]
    public function ein_deutlicher_unterschied_ueber_der_mindestzahl_ergibt_einen_gewinner(): void
    {
        $funnel = $this->deutlich();

        $this->assertSame('b', $this->entscheide($funnel));
        $this->assertSame('b', $funnel->fresh()->meta['split_winners']['kasse']['variant'] ?? null);
        $this->assertSame(100, $funnel->fresh()->meta['split_winners']['kasse']['sample'] ?? null);
    }

    #[Test]
    public function vor_der_ruhezeit_wird_nicht_entschieden(): void
    {
        // Die letzten Besucher der Stichprobe hatten noch keine Zeit zu kaufen.
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_auto' => true]);
        $this->besuche($funnel, 'a', 100, 10);
        $this->besuche($funnel, 'b', 100, 45);

        $this->assertNull($this->entscheide($funnel));
        $this->assertNull($funnel->fresh()->meta['split_winners'] ?? null);
    }

    #[Test]
    public function eine_mindestzahl_unter_hundert_gilt_als_hundert(): void
    {
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_auto' => true, 'split_min_visits' => '20']);
        $this->besuche($funnel, 'a', 40, 4);
        $this->besuche($funnel, 'b', 40, 30);
        $this->travel(2)->days();

        $this->assertSame(100, SplitResults::minVisits($this->kasse($funnel)));
        $this->assertNull($this->entscheide($funnel));
    }

    #[Test]
    public function kein_unterschied_wird_einmal_festgehalten_und_nie_neu_entschieden(): void
    {
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_auto' => true]);
        $this->besuche($funnel, 'a', 100, 25);
        $this->besuche($funnel, 'b', 100, 28);
        $this->travel(2)->days();

        $this->assertNull($this->entscheide($funnel));
        $this->assertArrayHasKey('kasse', $funnel->fresh()->meta['split_winners'] ?? []);
        $this->assertNull($funnel->fresh()->meta['split_winners']['kasse']['variant']);

        // Spaetere, einseitige Besuche aendern nichts: wer nach jedem Besuch
        // neu entscheidet, findet irgendwann einen Zufallsgewinner.
        $this->besuche($funnel, 'b', 200, 150);
        $this->travel(2)->days();

        $this->assertNull($this->entscheide($funnel));
    }

    #[Test]
    public function entschieden_wird_mit_den_ersten_besuchen_der_stichprobe(): void
    {
        // Die ersten 100 je Fassung sind gleich; was danach kommt, zaehlt
        // fuer die Entscheidung nicht mit.
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_auto' => true]);
        $this->besuche($funnel, 'a', 100, 20);
        $this->besuche($funnel, 'b', 100, 20);
        $this->besuche($funnel, 'b', 100, 100);
        $this->travel(2)->days();

        $this->assertNull($this->entscheide($funnel));
    }

    /**
     * A/A: zwei gleiche Fassungen, 2000 Mal. Nachgesehen wird nach jedem
     * zehnten Besuch je Fassung, wie es ein Editor tut, der oft geoeffnet
     * wird. Die feste Stichprobe haelt die Fehlerquote bei den zugesagten 5 %;
     * das alte Verfahren (aufhoeren beim ersten Mal ueber 95 %) lag bei rund
     * einem Drittel.
     */
    #[Test]
    public function zwei_gleiche_fassungen_ergeben_hoechstens_in_sechs_prozent_einen_gewinner(): void
    {
        mt_srand(20260923);

        $falsch = 0;
        $laeufe = 2000;
        $min = 100;

        for ($lauf = 0; $lauf < $laeufe; $lauf++) {
            $a = [];
            $b = [];
            $entschieden = null;

            for ($n = 10; $n <= 400; $n += 10) {
                while (count($a) < $n) {
                    $a[] = mt_rand(1, 1000) <= 100 ? 1 : 0;
                    $b[] = mt_rand(1, 1000) <= 100 ? 1 : 0;
                }

                $entschieden ??= SplitResults::fixedHorizon(SplitResults::GOAL_PURCHASE, $a, $b, $min);
            }

            if (($entschieden['winner'] ?? null) !== null) {
                $falsch++;
            }
        }

        $quote = $falsch / $laeufe;

        $this->assertLessThanOrEqual(0.06, $quote, sprintf('A/A-Fehlerquote %.1f %%', $quote * 100));
        $this->assertGreaterThan(0.01, $quote, 'die Simulation misst nichts');
    }

    #[Test]
    public function neue_besucher_sehen_danach_nur_den_gewinner(): void
    {
        $funnel = $this->deutlich();
        $this->entscheide($funnel);

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
        $funnel = $this->deutlich();
        $alt = FunnelVisit::create(['funnel_id' => $funnel->id, 'token' => Str::random(32), 'meta' => ['variants' => ['kasse' => 'a']]]);
        $this->entscheide($funnel);

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
        $this->travel(2)->days();

        $this->assertNull($this->entscheide($funnel));
        $this->assertNull($funnel->fresh()->meta['split_winners'] ?? null);
    }

    #[Test]
    public function ohne_automatik_wird_nichts_festgelegt(): void
    {
        $funnel = $this->deutlich(['split_auto' => false]);

        $this->assertNull($this->entscheide($funnel));
    }

    #[Test]
    public function ein_gewinner_fuer_ein_anderes_ziel_gilt_nicht(): void
    {
        $funnel = $this->funnel(['split_goal' => 'revenue', 'split_auto' => true]);
        $funnel->forceFill(['meta' => ['split_winners' => ['kasse' => ['variant' => 'b', 'goal' => 'purchase', 'decided_at' => now()->toIso8601String(), 'sample' => 100]]]])->save();

        $step = $this->kasse($funnel);
        $step->setRelation('funnel', $funnel->fresh());

        $this->assertNull(Split::winner($step));
    }

    #[Test]
    public function ein_gewinner_aus_der_zeit_vor_der_festen_stichprobe_gilt_nicht(): void
    {
        // Entschieden mit dem alten Verfahren (nach jedem Aufruf, ohne
        // Stichprobe): kein belastbares Ergebnis, also neu auswerten.
        $funnel = $this->funnel(['split_goal' => 'purchase', 'split_auto' => true]);
        $funnel->forceFill(['meta' => ['split_winners' => ['kasse' => ['variant' => 'b', 'goal' => 'purchase', 'confidence' => 0.99, 'decided_at' => now()->toIso8601String()]]]])->save();

        $step = $this->kasse($funnel);
        $step->setRelation('funnel', $funnel->fresh());

        $this->assertNull(Split::winner($step));
    }

    #[Test]
    public function der_editor_bekommt_ergebnis_ziel_und_gewinner(): void
    {
        $funnel = $this->deutlich();

        $user = tap(User::make()->email('studio@example.com')->makeSuper())->save();
        $response = $this->actingAs($user)->get('/cp/utilities/funnels/'.$funnel->id.'/edit')->assertOk();

        preg_match('/data-page="(.*?)"/s', $response->getContent(), $m);
        $props = json_decode(html_entity_decode($m[1], ENT_QUOTES), true)['props'];

        $split = $props['splits']['kasse'];
        $this->assertSame('purchase', $split['goal']);
        $this->assertSame(45, $split['variants']['b']['conversions']);
        // Der Editor legt den Gewinner fest, wenn er feststeht: niemand muss
        // auf den naechsten Besucher warten, um es zu sehen.
        $this->assertSame('b', $split['winner']);
        $this->assertTrue($split['decided']);
        $this->assertGreaterThan(0.95, $split['decided_confidence']);
    }
}
