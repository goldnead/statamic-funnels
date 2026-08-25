<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Web;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicOffers\Models\Offer;
use Illuminate\Http\Request;
use Statamic\Facades\Site;

/**
 * The front end of a funnel.
 *
 * One route per step, so a visitor can bookmark where they are, a browser back
 * button works, and an email can link into the middle of a flow — which is how
 * the second half of most funnels actually starts.
 */
class FunnelController
{
    public function __construct(
        protected FunnelWalk $walk,
        protected StepRegistry $registry,
    ) {}

    /** The entry step, under the funnel's own URL. */
    public function entry(Request $request, string $funnel)
    {
        $model = $this->funnel($funnel);
        $step = $model->entryStep();

        // A funnel with no entry cannot be walked. 404 rather than an error
        // page: to a visitor it simply is not there, and a half-built funnel
        // that takes money in the middle is worse than a missing page.
        abort_unless($step !== null, 404);

        return $this->render($model, $step);
    }

    public function step(Request $request, string $funnel, string $slug)
    {
        $model = $this->funnel($funnel);
        $step = $model->stepBySlug($slug);

        abort_unless($step !== null && ! $step->disabled, 404);

        return $this->render($model, $step);
    }

    protected function funnel(string $handle): Funnel
    {
        $funnel = Funnel::with(['steps', 'edges'])->where('handle', $handle)->first();

        abort_unless($funnel && $funnel->published, 404);

        return $funnel;
    }

    protected function render(Funnel $funnel, FunnelStep $step)
    {
        $visit = $this->walk->visit($funnel);
        $this->walk->enter($visit, $step);

        $data = [
            'funnel' => [
                'handle' => $funnel->handle,
                'title' => $funnel->title,
                // Null when the site has switched the shipped styling off,
                // which is what somebody with their own design does.
                'styles' => config('statamic-funnels.styles', true)
                    ? asset('vendor/statamic-funnels/funnels.css')
                    : null,
            ],
            'step' => [
                'key' => $step->node_key,
                'type' => $step->type,
                'label' => $step->label,
                'slug' => $step->slug,
            ],
            'headline' => $step->config('headline'),
            'body' => $step->config('body'),
            // What the template posts back to. Built here so no template has to
            // know how this addon routes.
            'action' => route('statamic-funnels.advance', [$funnel->handle, $step->node_key]),
            'form' => $step->config('form'),
            'offer' => $this->offerFor($step),
            'visit' => ['email' => $visit->email, 'name' => $visit->name],
        ];

        $template = (string) $step->config('template');

        // A named template if the site has one, and a shipped fallback if not.
        // The fallback matters more than it looks: a funnel that renders nothing
        // until somebody writes four templates never gets tried out.
        $view = $template !== '' && view()->exists($template) ? $template : 'statamic-funnels::step';

        return view($view, $data);
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function offerFor(FunnelStep $step): ?array
    {
        $handle = $step->config('offer');

        if (! is_string($handle) || $handle === '') {
            return null;
        }

        $offer = Offer::query()->where('handle', $handle)->first();

        if (! $offer || ! $offer->isSellable()) {
            return null;
        }

        $offer->recordShown();

        $prefix = (string) config('statamic-offers.handle_prefix', 'offer:');

        return [
            'handle' => $offer->handle,
            'buy_handle' => $prefix.$offer->handle,
            'name' => $offer->name,
            'headline' => $offer->headline ?: $offer->name,
            'body' => $offer->body,
            'amount' => $offer->amount(),
            'compare_at' => $offer->compareAt(),
            'currency' => $offer->currency(),
            'button_label' => $offer->button_label,
        ];
    }
}
