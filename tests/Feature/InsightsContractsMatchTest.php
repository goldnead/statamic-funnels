<?php

namespace Goldnead\StatamicFunnels\Tests\Feature;

use Goldnead\StatamicFunnels\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The guard over the copies.
 *
 * `tests/Fakes/insights-contracts.php` claims to be the analytics addon's
 * contract copied byte for byte, and `tests/Fakes/insights-table-metric.php`
 * claims to be its base class. Until this file existed, nothing checked either
 * claim. The sibling is neither a `require` nor a `require-dev`, so the
 * `interface_exists` locks never engage: the copies are what the whole suite
 * runs against **and** what PHPStan analyses through `scanFiles`. A method
 * added to `Metric` upstream would therefore leave every test here green and
 * fatal on the first install that has both addons — a green suite over a
 * contract that no longer exists is worse than no suite, because it is
 * believed.
 *
 * So the copies are held against the originals wherever the originals can be
 * found: installed in `vendor/`, or checked out beside this package, which is
 * how the family is developed. Where they cannot be found the tests skip and
 * say why — a machine without the sibling cannot answer the question, and
 * pretending otherwise is the failure this file exists to prevent.
 *
 * The comparison of the interfaces runs through a second PHP process
 * (`tests/Support/insights-contract-probe.php`) because both sides declare the
 * same fully qualified names and one process can hold only one of them.
 *
 * The contract is semver-locked from the release onwards. This is the thing
 * that notices when it moves anyway.
 */
class InsightsContractsMatchTest extends TestCase
{
    /** The interfaces a metric here implements or is read through. */
    protected const VERTRAEGE = ['Contracts\Metric', 'Contracts\HasBreakdowns', 'Contracts\HasFilterOptions'];

    /** The value objects the queries read. */
    protected const WERTOBJEKTE = ['Support\MetricQuery', 'Support\Period', 'Support\Unit'];

    /**
     * Constants whose **value** this package depends on, not just their name.
     *
     * `TableMetric::bucketExpression()` compares against `BUCKET_MONTH`, and
     * `unit()` returns these strings straight to a screen that formats by them.
     * A renamed value upstream would silently turn every monthly chart daily,
     * or every completion rate into a plain count.
     *
     * `CURRENCY` and `DURATION` are deliberately absent: no figure in this
     * addon is money or a span of time, so their values are not something this
     * package can be broken by.
     */
    protected const TRAGENDE_KONSTANTEN = [
        'Support\MetricQuery' => ['BUCKET_DAY', 'BUCKET_MONTH'],
        'Support\Unit' => ['COUNT', 'PERCENT'],
    ];

    /**
     * An addition upstream breaks the copy just as a removal does.
     *
     * A metric written against a stand-in that is missing a method does not
     * implement the real interface at all, so equality is the right test here
     * and a subset would not be: both directions are fatal.
     */
    #[Test]
    public function the_copied_contract_still_matches_the_real_one(): void
    {
        [$echt, $kopie] = $this->beideSeiten();

        foreach (self::VERTRAEGE as $name) {
            $this->assertNotNull($echt[$name], "The sibling no longer declares {$name}.");
            $this->assertNotNull($kopie[$name], "The stand-in does not declare {$name}.");

            $this->assertSame(
                $echt[$name]['methods'],
                $kopie[$name]['methods'],
                "The stand-in for {$name} has drifted from the real contract. Copy it across again — every metric in src/Integrations/Insights is written against this shape, and the whole suite runs on the copy.",
            );
        }
    }

    /**
     * The value objects, as a subset rather than an equality.
     *
     * Deliberately looser than the interfaces above, and for a reason that does
     * not apply to them: a field added to `MetricQuery` upstream breaks nothing
     * here — the queries read what they read. Demanding equality would paint
     * this test red on a purely additive release and teach whoever sees it to
     * ignore the file. What must hold is that everything the copy promises is
     * really there and really has that shape.
     */
    #[Test]
    public function the_copied_value_objects_still_carry_what_the_metrics_read(): void
    {
        [$echt, $kopie] = $this->beideSeiten();

        foreach (self::WERTOBJEKTE as $name) {
            $this->assertNotNull($echt[$name], "The sibling no longer declares {$name}.");
            $this->assertNotNull($kopie[$name], "The stand-in does not declare {$name}.");

            foreach ($kopie[$name]['methods'] as $methode => $form) {
                $this->assertArrayHasKey($methode, $echt[$name]['methods'], "{$name}::{$methode}() exists only in the stand-in.");
                $this->assertSame($echt[$name]['methods'][$methode], $form, "{$name}::{$methode}() has a different signature upstream.");
            }

            foreach ($kopie[$name]['properties'] as $eigenschaft => $form) {
                $this->assertArrayHasKey($eigenschaft, $echt[$name]['properties'], "{$name}::\${$eigenschaft} exists only in the stand-in.");
                $this->assertSame($echt[$name]['properties'][$eigenschaft], $form, "{$name}::\${$eigenschaft} is declared differently upstream.");
            }
        }

        foreach (self::TRAGENDE_KONSTANTEN as $name => $konstanten) {
            foreach ($konstanten as $konstante) {
                $this->assertArrayHasKey($konstante, $echt[$name]['constants'], "{$name}::{$konstante} is gone upstream.");
                $this->assertSame(
                    $echt[$name]['constants'][$konstante],
                    $kopie[$name]['constants'][$konstante] ?? null,
                    "{$name}::{$konstante} means something else upstream, and this package reads its value.",
                );
            }
        }
    }

    /**
     * The base class, compared byte for byte rather than by signature.
     *
     * **A stricter test than the ones above, because a base class is a
     * different kind of promise.** For an interface the signature *is* the
     * whole of it: nothing is inherited, and two declarations that agree on
     * every method agree on everything that can be depended upon. `TableMetric`
     * is the opposite — the four metrics in `src/Integrations/Insights` inherit
     * its **bodies**. The window it builds, the three SQL dialects it switches
     * between, its decision to keep the rows whose split value is null: all of
     * that is behaviour the signature says nothing about.
     *
     * So a signature check would pass while the copy quietly did something
     * else. Upstream fixes the month expression for MariaDB; production gets
     * the fix, this suite keeps testing the old one and stays green. That is
     * the precise shape of "a green suite over code nobody runs", and the only
     * cheap defence is to demand the two files be the same file.
     *
     * The cost is that a purely cosmetic edit upstream — a typo in a comment —
     * turns this red. That is the right trade: re-copying the file is one
     * command, and the alternative is not noticing a real change.
     *
     * **`pint.json` excludes the copy through `notPath`, and that exclusion
     * belongs to this test.** The formatter has the file in its finder and
     * happens to leave it alone under today's rules; a preset bump that
     * reformatted it would break the byte-equality here without a single line
     * of meaning having changed. The failure would then read "copy it across
     * again", the next `pint` run would undo the copy, and the two would take
     * turns. A file that is a copy of somebody else's is not this package's to
     * format. (`pint.json` is JSON and cannot say so itself, which is why it is
     * written down here.)
     */
    #[Test]
    public function the_copied_base_class_is_the_real_one(): void
    {
        $quelle = $this->geschwisterQuelle();

        if ($quelle === null) {
            $this->markTestSkipped(
                'goldnead/statamic-insights was not found — neither in vendor/ nor checked out beside this package. '
                .'It is a `suggest` and deliberately not installed, so this machine cannot say whether '
                .'tests/Fakes/insights-table-metric.php still matches the real base class. Run this where the sibling exists.'
            );
        }

        $echt = $quelle.'/Support/TableMetric.php';
        $kopie = __DIR__.'/../Fakes/insights-table-metric.php';

        $this->assertFileExists($echt, 'The sibling no longer carries Support/TableMetric.php.');
        $this->assertFileExists($kopie);

        $this->assertSame(
            file_get_contents($echt),
            file_get_contents($kopie),
            'tests/Fakes/insights-table-metric.php is no longer the file it stands in for. '
            ."Copy it across again: cp {$echt} tests/Fakes/insights-table-metric.php — "
            .'the four metrics inherit its query bodies, and this suite runs on the copy.',
        );
    }

    // -- Machinery ----------------------------------------------------------

    /** @return array{0: array<string, ?array<string, mixed>>, 1: array<string, ?array<string, mixed>>} */
    protected function beideSeiten(): array
    {
        $quelle = $this->geschwisterQuelle();

        if ($quelle === null) {
            $this->markTestSkipped(
                'goldnead/statamic-insights was not found — neither in vendor/ nor checked out beside this package. '
                .'It is a `suggest` and deliberately not installed, so this machine cannot say whether '
                .'tests/Fakes/insights-contracts.php still matches the real contract. Run this where the sibling exists.'
            );
        }

        $dateien = glob($quelle.'/Contracts/*.php') ?: [];

        foreach (['MetricQuery', 'Period', 'Unit'] as $wertobjekt) {
            $dateien[] = $quelle.'/Support/'.$wertobjekt.'.php';
        }

        return [
            $this->form($dateien),
            $this->form([__DIR__.'/../Fakes/insights-contracts.php']),
        ];
    }

    /** Where the real package is, if it is anywhere. */
    protected function geschwisterQuelle(): ?string
    {
        $wurzel = dirname(__DIR__, 2);

        $kandidaten = [
            $wurzel.'/vendor/goldnead/statamic-insights/src',
            dirname($wurzel).'/statamic-insights/src',
        ];

        foreach ($kandidaten as $kandidat) {
            if (is_dir($kandidat.'/Contracts')) {
                return $kandidat;
            }
        }

        return null;
    }

    /**
     * The shape of a contract, read in a process of its own.
     *
     * @param  array<int, string>  $dateien
     * @return array<string, ?array<string, mixed>>
     */
    protected function form(array $dateien): array
    {
        $sonde = dirname(__DIR__).'/Support/insights-contract-probe.php';

        $befehl = implode(' ', array_map(
            'escapeshellarg',
            array_merge([PHP_BINARY, $sonde], $dateien),
        ));

        $ausgabe = shell_exec($befehl.' 2>&1');
        $gelesen = json_decode((string) $ausgabe, true);

        $this->assertIsArray($gelesen, 'The contract probe returned nothing usable: '.$ausgabe);

        return $gelesen;
    }
}
