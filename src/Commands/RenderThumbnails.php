<?php

namespace Goldnead\StatamicFunnels\Commands;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Thumbnails\StepRenderer;
use Goldnead\StatamicFunnels\Thumbnails\Thumbnails;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Statamic\Console\RunsInPlease;

/**
 * Photograph the pages of one funnel, or of all of them, now.
 *
 * For the cases the save-time job cannot see: a Chromium that was installed
 * after the funnels were drawn, an entry or template edited behind a step, a
 * disk that was cleared. Runs inline rather than through the queue, so the
 * person at the terminal sees each result as it happens and gets a tally.
 *
 * Exit code 1 when nothing could be rendered because nothing is set up; a
 * green exit that rendered nothing is the failure that looks like a result.
 */
class RenderThumbnails extends Command
{
    use RunsInPlease;

    protected $signature = 'funnels:thumbnails
        {funnel? : Handle or id of one funnel; every funnel when left out}
        {--force : Render again, even where the stored picture still matches the step}';

    protected $description = 'Render the step thumbnails the funnel editor shows on its cards.';

    public function handle(StepRenderer $renderer): int
    {
        if (! Thumbnails::enabled()) {
            $this->components->warn('Step thumbnails are switched off (statamic-funnels.thumbnails.enabled).');

            return self::FAILURE;
        }

        if (! Thumbnails::available()) {
            $this->components->warn('No thumbnail renderer: install spatie/browsershot and a Chromium, or set statamic-funnels.thumbnails.chrome_path.');

            return self::FAILURE;
        }

        $funnels = $this->funnels();

        if ($funnels === null) {
            $this->components->error('No funnel called "'.$this->argument('funnel').'".');

            return self::FAILURE;
        }

        $tally = [];

        foreach ($funnels as $funnel) {
            $this->components->info($funnel->title.' ('.$funnel->handle.')');

            foreach ($funnel->steps as $step) {
                if (! Thumbnails::isPage($step)) {
                    continue;
                }

                $outcome = $renderer->render($step, (bool) $this->option('force'));
                $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;

                $this->components->twoColumnDetail($this->stepName($step), $outcome);
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Rendered', (string) ($tally[StepRenderer::RENDERED] ?? 0));
        $this->components->twoColumnDetail('Already current', (string) ($tally[StepRenderer::CURRENT] ?? 0));
        $this->components->twoColumnDetail('Failed', (string) ($tally[StepRenderer::FAILED] ?? 0));

        return ($tally[StepRenderer::FAILED] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, Funnel>|null Null when a named funnel does not exist.
     */
    protected function funnels(): ?Collection
    {
        $query = Funnel::query()->with('steps')->orderBy('title');
        $wanted = $this->argument('funnel');

        if ($wanted === null || $wanted === '') {
            return $query->get();
        }

        $query->where('handle', $wanted);

        if (ctype_digit((string) $wanted)) {
            $query->orWhere('id', (int) $wanted);
        }

        $found = $query->get();

        return $found->isEmpty() ? null : $found;
    }

    protected function stepName(FunnelStep $step): string
    {
        return trim(($step->label ?: $step->type).' <fg=gray>'.$step->node_key.'</>');
    }
}
