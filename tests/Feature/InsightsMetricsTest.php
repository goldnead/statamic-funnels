<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Integrations\Insights\Completed;
use Goldnead\StatamicFunnels\Integrations\Insights\CompletionRate;
use Goldnead\StatamicFunnels\Integrations\Insights\FunnelMetric;
use Goldnead\StatamicFunnels\Integrations\Insights\StepEvents;
use Goldnead\StatamicFunnels\Integrations\Insights\Visits;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Tests\TestCase;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Facades\Insights as InsightsStandIn;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The four numbers this addon offers the analytics addon.
 *
 * Every expectation below is worked out by hand from one small fixture — five
 * walks and ten recorded steps, small enough to add up in the head — following
 * the rules the metrics write down: visits on `created_at`, completions on
 * `completed_at`, the rate as a cohort of the walks that began here. A query
 * that drifts shows up as an arithmetic disagreement rather than as a green
 * suite over a different report.
 *
 * Tested against a stand-in for the contract rather than the real package: the
 * sibling is optional, and a test that needed it installed would be proving the
 * opposite of what this addon claims. See `tests/Fakes/insights-contracts.php`
 * for why those are required files and not autoload entries, and
 * `InsightsContractsMatchTest` for what holds the copies to account.
 *
 * Time is frozen. The buckets are asserted as literal dates, and a suite that
 * ran across midnight would otherwise fail once a night for reasons that have
 * nothing to do with the code.
 */
class InsightsMetricsTest extends TestCase
{
    /** The day everything below is measured from. */
    protected const HEUTE = '2026-08-20 12:00:00';

    /** Collects what the service provider registers. */
    protected object $insights;

    protected function setUp(): void
    {
        // Before the application exists, all three. The contracts have to be
        // there before a metric class is loaded, the base class after them and
        // before the same, and the facade before the provider's `booted()`
        // callback asks whether it is — a callback that has already run cannot
        // be given a second chance.
        require_once __DIR__.'/../Fakes/insights-contracts.php';

        if (! class_exists('Goldnead\StatamicInsights\Support\TableMetric')) {
            require_once __DIR__.'/../Fakes/insights-table-metric.php';
        }

        require_once __DIR__.'/../Fakes/insights-facade.php';

        $this->insights = new class
        {
            /** @var array<string, string> */
            public array $registered = [];

            /**
             * Stricter than the real manager on purpose.
             *
             * The genuine one accepts a metric without a handle and works one
             * out by constructing it. Accepting that here would let the
             * provider drop the handle and still look correct — and the handle
             * is the half that ends up in saved dashboards and URLs.
             */
            public function registerMetric(string|Metric|\Closure $metric, ?string $handle = null): void
            {
                if (! is_string($metric) || $handle === null) {
                    throw new \InvalidArgumentException('This addon registers metrics lazily: a class name and a handle.');
                }

                $this->registered[$handle] = $metric;
            }
        };

        InsightsStandIn::$root = $this->insights;

        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HEUTE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        InsightsStandIn::$root = null;

        parent::tearDown();
    }

    // -- The fixture --------------------------------------------------------

    /**
     * Two funnels, five walks, ten steps.
     *
     * Every awkward case is in it: a walk that finished the same day, one that
     * never finished, one that began on one day and finished on another, a walk
     * from before the window that finished inside it, a step of a kind nobody
     * has heard of, and a step with no kind at all.
     *
     * In the window 11.–20. August: four walks began, of which two ever
     * finished, and three walks finished — a different three, which is the
     * whole distinction between `funnels.completed` and the rate.
     */
    protected function fixture(): void
    {
        $eins = Funnel::create(['handle' => 'sommer-kurs', 'title' => 'Sommer-Kurs', 'published' => true]);
        $zwei = Funnel::create(['handle' => 'chorleiter-paket', 'title' => 'Chorleiter-Paket', 'published' => true]);

        // Began and finished on the same day.
        $a = $this->visit($eins, 'a', '2026-08-15 10:00:00', '2026-08-15 10:20:00');
        $this->event($a, 'seite', FunnelStepEvent::ENTERED, '2026-08-15 10:00:00');
        $this->event($a, 'formular', FunnelStepEvent::SUBMITTED, '2026-08-15 10:10:00');
        $this->event($a, 'danke', FunnelStepEvent::COMPLETED, '2026-08-15 10:20:00');

        // Began and stopped. Never finished, and must not be counted as if it had.
        $b = $this->visit($eins, 'b', '2026-08-15 18:00:00', null);
        $this->event($b, 'seite', FunnelStepEvent::ENTERED, '2026-08-15 18:00:00');

        // Began on the 18th, finished on the 19th: the two axes come apart.
        $c = $this->visit($eins, 'c', '2026-08-18 09:00:00', '2026-08-19 08:00:00');
        $this->event($c, 'seite', FunnelStepEvent::ENTERED, '2026-08-18 09:00:00');
        $this->event($c, 'angebot', FunnelStepEvent::ACCEPTED, '2026-08-18 09:05:00');

        $d = $this->visit($zwei, 'd', '2026-08-18 11:00:00', null);
        $this->event($d, 'seite', FunnelStepEvent::ENTERED, '2026-08-18 11:00:00');
        $this->event($d, 'angebot', FunnelStepEvent::DECLINED, '2026-08-18 11:05:00');

        // Began in July, finished inside the window. It belongs to
        // `funnels.completed` and to no cohort measured here.
        $e = $this->visit($eins, 'e', '2026-07-02 10:00:00', '2026-08-14 10:00:00');
        $this->event($e, 'seite', FunnelStepEvent::ENTERED, '2026-07-02 10:00:00');

        // A kind this addon does not write, and a kind that is missing
        // altogether. Through the query builder, because neither is something
        // the application would produce — and both have to survive the report
        // rather than quietly falling out of it.
        DB::table('funnel_step_events')->insert([
            [
                'visit_id' => $c->getKey(),
                'node_key' => 'angebot',
                'event' => 'skipped',
                'payload' => null,
                'created_at' => '2026-08-18 09:10:00',
                'updated_at' => '2026-08-18 09:10:00',
            ],
            [
                'visit_id' => $d->getKey(),
                'node_key' => 'angebot',
                'event' => '',
                'payload' => null,
                'created_at' => '2026-08-18 11:10:00',
                'updated_at' => '2026-08-18 11:10:00',
            ],
        ]);
    }

    protected function visit(Funnel $funnel, string $token, string $createdAt, ?string $completedAt): FunnelVisit
    {
        return FunnelVisit::create([
            'funnel_id' => $funnel->getKey(),
            'token' => $token,
            'completed_at' => $completedAt,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    protected function event(FunnelVisit $visit, string $nodeKey, string $event, string $createdAt): FunnelStepEvent
    {
        return FunnelStepEvent::create([
            'visit_id' => $visit->getKey(),
            'node_key' => $nodeKey,
            'event' => $event,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    /** The ten days the fixture lives in, bucketed by day. */
    protected function metricQuery(array $filters = [], string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
    {
        return new MetricQuery(
            Period::between(Carbon::parse('2026-08-11')->startOfDay(), Carbon::parse('2026-08-20')->endOfDay()),
            $bucket,
            $filters,
        );
    }

    /** @return array<string|int, int|float> */
    protected function keyed(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row['key'] ?? ''] = $row['value'];
        }

        return $keyed;
    }

    // -- The four numbers ---------------------------------------------------

    /**
     * Every figure at once, against hand-worked totals.
     *
     * One test rather than four, deliberately: they are read side by side on a
     * screen and have to agree with each other. A visit count that changed
     * without the rate following it is the failure worth catching, and four
     * separate tests are four chances to fix one and leave the rest.
     */
    #[Test]
    public function the_four_figures_are_the_ones_the_fixture_adds_up_to(): void
    {
        $this->fixture();
        $frage = $this->metricQuery();

        $this->assertSame(4, (new Visits)->value($frage), 'visits: a, b, c and d began in the window');
        $this->assertSame(3, (new Completed)->value($frage), 'completed: a, c and e finished in the window');
        $this->assertSame(50.0, (new CompletionRate)->value($frage), 'rate: of a, b, c, d only a and c ever finished');
        $this->assertSame(10, (new StepEvents)->value($frage), 'steps: 3 + 1 + 2 + 2 in the window, plus the odd two');
    }

    /**
     * The completion figure is not the numerator of the completion rate.
     *
     * Three walks finished inside the window; two of the four that *began*
     * inside it ever finished. Both are true, they are different questions, and
     * the fixture is built so that a query which confused the two cannot pass:
     * walk `e` began in July and finished here, walk `c` began here and
     * finished here, and `50 %` is only reachable by counting the cohort.
     */
    #[Test]
    public function the_rate_counts_the_cohort_and_not_the_completions_in_the_window(): void
    {
        $this->fixture();
        $frage = $this->metricQuery();

        $this->assertSame(3, (new Completed)->value($frage));
        $this->assertSame(4, (new Visits)->value($frage));

        // 3 / 4 would be 75 %, and that is the number a mixed-axis rate would
        // print. The cohort answer is 2 of 4.
        $this->assertSame(50.0, (new CompletionRate)->value($frage));
    }

    /** The handles are a contract. They end up in saved dashboards and in URLs. */
    #[Test]
    public function the_handles_and_units_are_the_ones_that_were_promised(): void
    {
        $erwartet = [
            [Visits::class, 'funnels.visits', Unit::COUNT],
            [Completed::class, 'funnels.completed', Unit::COUNT],
            [CompletionRate::class, 'funnels.completion_rate', Unit::PERCENT],
            [StepEvents::class, 'funnels.step_events', Unit::COUNT],
        ];

        foreach ($erwartet as [$klasse, $handle, $unit]) {
            /** @var FunnelMetric $metrik */
            $metrik = new $klasse;

            $this->assertSame($handle, $metrik->handle());
            $this->assertSame($unit, $metrik->unit());
            $this->assertSame(__('statamic-funnels::messages.metric_group'), $metrik->group());
            $this->assertNotSame('', $metrik->label());
            $this->assertNotEmpty($metrik->description());

            // Nothing here is money or a duration, so nothing needs anything
            // beyond its unit to be printed.
            $this->assertSame([], $metrik->meta($this->metricQuery()));
        }
    }

    /** The group is one translated name, and it is the same for all four. */
    #[Test]
    public function the_group_is_translated_rather_than_a_bare_key(): void
    {
        $gruppe = (new Visits)->group();

        $this->assertSame('Funnels', $gruppe);
        $this->assertStringNotContainsString('::', $gruppe, 'the translation did not resolve');
    }

    // -- Nothing to measure -------------------------------------------------

    /**
     * No tables, no answer — and not a zero.
     *
     * "Nothing to measure" and "measured nothing" are different statements, and
     * a zero for the first is the quiet kind of wrong: it puts a confident 0 on
     * a dashboard for a site that has not installed this addon at all.
     */
    #[Test]
    public function a_metric_cannot_answer_without_the_tables(): void
    {
        $this->assertTrue((new Visits)->available());

        // A second, empty database rather than dropping the tables in this one.
        // Dropping them would leave the suite unable to roll its own migrations
        // back, and a test that breaks its neighbours' teardown reports the
        // wrong failure everywhere afterwards.
        config()->set('database.connections.ohne_funnels', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $vorher = DB::getDefaultConnection();
        DB::purge('ohne_funnels');
        DB::setDefaultConnection('ohne_funnels');

        try {
            foreach ([Visits::class, Completed::class, CompletionRate::class, StepEvents::class] as $klasse) {
                $metrik = new $klasse;

                $this->assertFalse($metrik->available(), $klasse.' answered without its table.');
                $this->assertNull($metrik->value($this->metricQuery()), $klasse.' returned a figure without its table.');
                $this->assertSame([], $metrik->series($this->metricQuery()), $klasse.' drew a chart without its table.');
            }

            $this->assertSame([], (new Visits)->breakdown($this->metricQuery(), 'funnel_id'));
            $this->assertSame([], (new StepEvents)->breakdown($this->metricQuery(), 'event'));
        } finally {
            DB::setDefaultConnection($vorher);
        }
    }

    // -- Null is not zero ---------------------------------------------------

    /**
     * A rate against nothing is a question, not a small number.
     *
     * "0 % completed" is a statement about walks that did not happen, sitting
     * on the screen beside a visit count of zero that contradicts it.
     */
    #[Test]
    public function the_completion_rate_is_null_when_nothing_began(): void
    {
        $this->fixture();

        $leer = new MetricQuery(
            Period::between(Carbon::parse('2025-01-01')->startOfDay(), Carbon::parse('2025-01-31')->endOfDay()),
        );

        $this->assertNull((new CompletionRate)->value($leer));
        $this->assertSame([], (new CompletionRate)->series($leer));

        // Its neighbours do answer, because "nothing happened" is an answer to
        // what they ask.
        $this->assertSame(0, (new Visits)->value($leer));
        $this->assertSame(0, (new Completed)->value($leer));
        $this->assertSame(0, (new StepEvents)->value($leer));
    }

    /**
     * A walk that finished but began before the window still has no rate of its own.
     *
     * The window holds one completion and no beginnings, which is what a July
     * walk finishing in August looks like from August. A rate here would be an
     * infinity or a zero; both are false, and null is the honest answer.
     */
    #[Test]
    public function a_window_with_completions_but_no_beginnings_has_no_rate(): void
    {
        $this->fixture();

        $frage = new MetricQuery(
            Period::between(Carbon::parse('2026-08-13')->startOfDay(), Carbon::parse('2026-08-14')->endOfDay()),
        );

        $this->assertSame(0, (new Visits)->value($frage), 'nothing began in those two days');
        $this->assertSame(1, (new Completed)->value($frage), 'and yet walk e finished');
        $this->assertNull((new CompletionRate)->value($frage), 'so there is no rate to state');
    }

    // -- The open-ended period ----------------------------------------------

    /**
     * The `all` preset is where a nullable timestamp turns into a wrong number.
     *
     * With no bounds both `where` clauses fall away, and a metric counting on
     * `completed_at` would count every walk ever started — five here, of which
     * two never finished. That is the reason `FunnelMetric::inPeriod()` adds a
     * `whereNotNull`, and this is the test that would go red if somebody read
     * it as redundant and took it out.
     */
    #[Test]
    public function an_unbounded_period_still_only_counts_what_actually_happened(): void
    {
        $this->fixture();

        $alles = new MetricQuery(Period::fromPreset('all'));

        $this->assertSame(5, (new Visits)->value($alles), 'every walk ever begun');
        $this->assertSame(3, (new Completed)->value($alles), 'a, c and e — not the two that never finished');
        $this->assertSame(60.0, (new CompletionRate)->value($alles), '3 of 5');
    }

    // -- Over time ----------------------------------------------------------

    /**
     * Only the buckets that have something in them.
     *
     * The empty days are Insights' job — it fills the range for every metric at
     * once. A metric that filled its own would be filled twice, and one that
     * invented a bucket outside the range would draw a column the axis has no
     * place for.
     */
    #[Test]
    public function a_series_returns_only_the_buckets_that_have_data(): void
    {
        $this->fixture();
        $frage = $this->metricQuery();

        $this->assertSame(
            ['2026-08-15' => 2, '2026-08-18' => 2],
            (new Visits)->series($frage),
        );

        // Completions sit on the day they finished, which is why the 14th and
        // the 19th appear here and in no other series.
        $this->assertSame(
            ['2026-08-14' => 1, '2026-08-15' => 1, '2026-08-19' => 1],
            (new Completed)->series($frage),
        );

        $this->assertSame(
            ['2026-08-15' => 4, '2026-08-18' => 6],
            (new StepEvents)->series($frage),
        );
    }

    /**
     * A rate per bucket, on the cohort's own date and only where there is a denominator.
     *
     * The 19th holds walk `c`'s completion and no beginning at all, so it has
     * no bar — a rate needs something to divide by. Both halves are bucketed on
     * `created_at`, which is why `c` raises the 18th and not the 19th.
     */
    #[Test]
    public function the_rate_series_skips_buckets_in_which_nothing_began(): void
    {
        $this->fixture();

        $this->assertSame(
            ['2026-08-15' => 50.0, '2026-08-18' => 50.0],
            (new CompletionRate)->series($this->metricQuery()),
            'two walks began on each day and one of each pair finished',
        );
    }

    /**
     * The grain comes from the question, not from the period.
     *
     * Insights decides the grain and puts it in the query. A metric that worked
     * it out again from the period length could disagree with the axis it is
     * drawn on.
     */
    #[Test]
    public function a_monthly_question_gets_monthly_buckets(): void
    {
        $this->fixture();

        $this->assertSame(
            ['2026-08' => 4],
            (new Visits)->series($this->metricQuery([], MetricQuery::BUCKET_MONTH)),
        );

        $this->assertSame(
            ['2026-08' => 50.0],
            (new CompletionRate)->series($this->metricQuery([], MetricQuery::BUCKET_MONTH)),
        );
    }

    // -- The splits ---------------------------------------------------------

    /** Visits split by funnel, named as the person who made them named them. */
    #[Test]
    public function the_visit_split_names_the_funnels_and_is_ordered_by_size(): void
    {
        $this->fixture();

        $zeilen = (new Visits)->breakdown($this->metricQuery(), 'funnel_id');

        $this->assertCount(2, $zeilen);

        $this->assertSame('Sommer-Kurs', $zeilen[0]['label']);
        $this->assertSame(3, $zeilen[0]['value']);

        $this->assertSame('Chorleiter-Paket', $zeilen[1]['label']);
        $this->assertSame(1, $zeilen[1]['value']);

        // And the split adds up to the figure it splits.
        $this->assertSame(4, array_sum(array_column($zeilen, 'value')));
    }

    /**
     * A visit whose funnel is gone keeps its id and its place.
     *
     * It cannot normally happen — deleting a funnel cascades its visits away —
     * but a lookup that dropped the row would make the split disagree with the
     * total, and nothing on the screen would say why.
     */
    #[Test]
    public function a_visit_whose_funnel_is_missing_keeps_its_id_as_its_label(): void
    {
        $this->fixture();

        Schema::withoutForeignKeyConstraints(function (): void {
            DB::table('funnel_visits')->insert([
                'funnel_id' => 999,
                'token' => 'waise',
                'created_at' => '2026-08-16 12:00:00',
                'updated_at' => '2026-08-16 12:00:00',
            ]);
        });

        $zeilen = (new Visits)->breakdown($this->metricQuery(), 'funnel_id');

        $this->assertSame(5, (new Visits)->value($this->metricQuery()));
        $this->assertSame(5, array_sum(array_column($zeilen, 'value')), 'the orphan is still in the split');

        $waise = collect($zeilen)->firstWhere('key', '999');

        $this->assertNotNull($waise, 'the row was dropped instead of labelled');
        $this->assertSame('999', $waise['label']);
    }

    /**
     * A step with no kind is a row, not an omission.
     *
     * `event` is a plain string, so an empty one is data the database will
     * accept. Grouping those rows under one heading is honest; dropping them
     * makes the split disagree with the total.
     */
    #[Test]
    public function a_step_without_a_kind_keeps_its_place_in_the_split(): void
    {
        $this->fixture();

        $zeilen = (new StepEvents)->breakdown($this->metricQuery(), 'event');

        // Sorted by key before comparing: six of the seven kinds are tied at
        // one, and which of them the database hands back first is not something
        // this addon promises. What it promises is that all seven are there
        // with the right counts.
        $gezaehlt = $this->keyed($zeilen);
        ksort($gezaehlt);

        $this->assertSame([
            '' => 1,
            'accepted' => 1,
            'completed' => 1,
            'declined' => 1,
            'entered' => 4,
            'skipped' => 1,
            'submitted' => 1,
        ], $gezaehlt);

        // Largest first, whatever the ties do below it.
        $this->assertSame('entered', $zeilen[0]['key']);
        $this->assertSame(__('statamic-funnels::messages.metric_event_entered'), $zeilen[0]['label']);

        $ohne = collect($zeilen)->firstWhere('key', null);
        $this->assertSame(__('statamic-funnels::messages.metric_no_event'), $ohne['label']);

        // A kind nobody declared keeps its raw value: visible and odd, which is
        // what an unexpected value should be.
        $unbekannt = collect($zeilen)->firstWhere('key', 'skipped');
        $this->assertSame('skipped', $unbekannt['label']);

        $this->assertSame(10, array_sum(array_column($zeilen, 'value')), 'the split adds up to the figure');
    }

    /** Every dimension on offer has words for the rows that have no value. */
    #[Test]
    public function each_dimension_has_its_own_words_for_a_missing_value(): void
    {
        $sonde = new class extends Visits
        {
            public function wortFuer(string $dimension): string
            {
                return $this->missingLabel($dimension);
            }
        };

        foreach (['funnel_id', 'event'] as $dimension) {
            $wort = $sonde->wortFuer($dimension);

            $this->assertSame(__('statamic-funnels::messages.metric_no_'.$dimension), $wort);
            $this->assertStringNotContainsString('::', $wort, "no words for a missing {$dimension}");
        }
    }

    /** A split nobody offers is empty, not an error. */
    #[Test]
    public function an_unknown_split_is_empty(): void
    {
        $this->fixture();

        $this->assertSame([], (new Visits)->breakdown($this->metricQuery(), 'weather'));
        $this->assertSame([], (new Visits)->breakdown($this->metricQuery(), 'event'));
        $this->assertSame([], (new StepEvents)->breakdown($this->metricQuery(), 'funnel_id'));

        $this->assertSame(['funnel_id'], array_keys((new Visits)->breakdowns()));
        $this->assertSame(['event'], array_keys((new StepEvents)->breakdowns()));
    }

    /** Largest first, and no more than asked for. */
    #[Test]
    public function a_split_respects_the_limit(): void
    {
        $this->fixture();

        $zeilen = (new Visits)->breakdown($this->metricQuery(), 'funnel_id', 1);

        $this->assertCount(1, $zeilen);
        $this->assertSame('Sommer-Kurs', $zeilen[0]['label']);

        $this->assertCount(2, (new StepEvents)->breakdown($this->metricQuery(), 'event', 2));
    }

    // -- The wiring ---------------------------------------------------------

    /**
     * The provider hands all four to the sibling, lazily and by handle.
     *
     * By class name rather than instance, so booting this addon does not build
     * four metric objects on a request that renders none of them.
     */
    #[Test]
    public function the_service_provider_offers_every_metric_to_the_sibling(): void
    {
        $this->assertSame([
            'funnels.visits' => Visits::class,
            'funnels.completed' => Completed::class,
            'funnels.completion_rate' => CompletionRate::class,
            'funnels.step_events' => StepEvents::class,
        ], $this->insights->registered);
    }
}
