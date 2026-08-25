<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Web;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\Countdown;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\PreviewToken;
use Goldnead\StatamicFunnels\Support\Split;
use Goldnead\StatamicOffers\Models\Offer;
use Illuminate\Http\Request;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Http\Responses\DataResponse;

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

    /**
     * A step as the Control Panel sees it.
     *
     * Same rendering as the real thing, and deliberately so: a preview that
     * takes a different path through the code is a preview of the preview. What
     * differs is what it is allowed to touch, which is nothing. No visit is
     * created, no step event is written, no offer impression is counted, and an
     * unpublished funnel renders — because looking at a draft before it goes
     * live is the reason this exists.
     */
    public function preview(Request $request, string $funnel, string $nodeKey)
    {
        // Not `$this->funnel()`: that one insists on `published`, which would
        // make the draft case, the whole point, the one case that 404s.
        $model = Funnel::with(['steps', 'edges'])->where('handle', $funnel)->first();

        abort_unless($model !== null, 404);

        $graph = PreviewToken::graph($request->query('token'), $model);

        abort_unless($graph !== null, 404);

        $step = PreviewToken::step($graph, $nodeKey, $model);

        abort_unless($step !== null, 404);

        return $this->render($model, $step, preview: true);
    }

    protected function funnel(string $handle): Funnel
    {
        $funnel = Funnel::with(['steps', 'edges'])->where('handle', $handle)->first();

        abort_unless($funnel && $funnel->published, 404);

        return $funnel;
    }

    protected function render(Funnel $funnel, FunnelStep $step, bool $preview = false)
    {
        // In preview nothing about a visitor is real, so nothing about a
        // visitor is written. The template still gets a `visit` shape, because
        // a template that has to test for it would be a template that behaves
        // differently in preview — which defeats the purpose.
        $visit = $preview ? new FunnelVisit(['name' => null, 'email' => null]) : $this->walk->visit($funnel);

        if (! $preview) {
            $this->walk->enter($visit, $step);
        }

        // Reaching the end *is* the end. Waiting for a form submit on a page
        // that has no form meant `completed_at` was never set in the ordinary
        // path: `FunnelCompleted` never fired, and "carry on where you left
        // off" sent people back to the thank-you page for ever.
        if ($step->type === 'finish' && ! $preview) {
            $this->walk->complete($visit, $step);

            if ($redirect = $step->config('redirect')) {
                return redirect()->away((string) $redirect);
            }
        }

        // The funnel's context, in **one** bag under one key.
        //
        // It used to be spread flat across the view data, and that was a bug
        // with a long fuse: `View::gatherData()` merges `with()` *over* the
        // cascade, so a step handing over `body => null` blanked the `body`
        // field of the very entry it was rendering. It never showed up here
        // because the shipped template has no `body` field of its own and the
        // playground page happened to call its field `sections`. Namespacing is
        // what makes a collision impossible rather than unlikely.
        // Which version this visitor gets, decided once per walk per step. In a
        // preview there is no visitor, so it is always A and nothing is written:
        // an editor clicking through their own funnel must not be counted into
        // their own experiment.
        $variant = Split::variantFor($step, $preview ? null : $visit);

        $context = [
            'handle' => $funnel->handle,
            'title' => $funnel->title,
            // Null when the site has switched the shipped styling off, which is
            // what somebody with their own design does.
            'styles' => config('statamic-funnels.styles', true)
                ? asset('vendor/statamic-funnels/funnels.css')
                : null,
            // The ticking script, and only where a step actually has a clock.
            // Same switch as the stylesheet: a site with its own front end
            // turns both off and loses nothing but the drawing.
            'scripts' => config('statamic-funnels.styles', true)
                ? asset('vendor/statamic-funnels/funnels.js')
                : null,
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
            'offer' => $this->offerFor($step, $preview),
            // Null when this step has no deadline. A template that has to test
            // for it is a template that behaves the same either way.
            'countdown' => Countdown::forTemplate($step, $preview ? null : $visit),
            'visit' => ['email' => $visit->email, 'name' => $visit->name],
            // Templates can say so. Statamic's own preview sets `live_preview`;
            // this is the same idea under this addon's own name.
            'preview' => $preview,
            // So a template can style or measure the two apart if it wants to.
            'variant' => $variant,
        ];

        // B's overrides, where B has any. Applied to the bag rather than to the
        // step, so nothing about the saved graph changes when somebody looks.
        $context = Split::apply($step, $variant, $context);

        // A step can point at a Statamic entry, and then *that* is the page:
        // its own template, its own content, its own page builder. The funnel
        // adds its context on top and otherwise stays out of the way.
        //
        // This is the whole answer to "where is the landing page builder". There
        // isn't one and there should not be one: Statamic has Bard, Replicator
        // and whatever sets the site has defined, and a second, worse builder
        // inside an addon would be the wrong thing to maintain.
        //
        // Delivered through Statamic's own `DataResponse` rather than by
        // building the view by hand. Hand-building looked equivalent and was
        // not: it skipped `protect()`, `handlePrivateEntries()` and the entry's
        // `redirect` field, so a password-protected or date-gated page went out
        // in the clear the moment a funnel step pointed at it.
        if ($entry = $this->entryFor($step, $context['entry'] ?? null)) {
            return (new DataResponse($entry))
                ->with(['funnel' => $context])
                ->toResponse(request());
        }

        $template = $this->templateFor($step, $context['template'] ?? null);

        // A named template if the site has one, and a shipped fallback if not.
        // The fallback matters more than it looks: a funnel that renders nothing
        // until somebody writes four templates never gets tried out.
        $view = $template !== null && view()->exists($template) ? $template : 'statamic-funnels::step';

        // The shipped view keeps the flat keys as well, because it is this
        // addon's own template and there is nothing here to collide with. A
        // site writing its own template should read `funnel:` — that is the
        // documented shape, and the only one that is safe on an entry.
        return view($view, ['funnel' => $context] + $context);
    }

    /**
     * The template a step names, if it is allowed to name it.
     *
     * A step's `template` is typed in the Control Panel by somebody with the
     * funnels permission, and it used to go straight into `view()`. Together
     * with the preview that was a way to render **any** view in the application
     * with data of one's choosing, without saving anything and without leaving a
     * trace. A namespace is refused outright and the name has to look like a
     * template name.
     */
    protected function templateFor(FunnelStep $step, mixed $named = null): ?string
    {
        $template = trim((string) ($named ?? $step->config('template')));

        if ($template === '' || str_contains($template, '::')) {
            return null;
        }

        if (! preg_match('/^[A-Za-z0-9._\/-]+$/', $template) || str_contains($template, '..')) {
            return null;
        }

        $prefix = trim((string) config('statamic-funnels.template_prefix', ''), '/');

        return $prefix === '' ? $template : $prefix.'/'.$template;
    }

    /**
     * The entry a step points at, if it points at one that is fit to show.
     *
     * An unpublished entry is treated as no entry rather than as an error: a
     * page pulled back into draft should make the funnel fall back to its own
     * rendering, not break the walk for everybody mid-purchase.
     */
    protected function entryFor(FunnelStep $step, mixed $id = null): ?Entry
    {
        $id ??= $step->config('entry');

        if (! is_string($id) || $id === '') {
            return null;
        }

        $entry = EntryFacade::find($id);

        if (! $entry) {
            return null;
        }

        // The localisation for the site being served. Without this a
        // multi-site install shows one language's page under every site's URL,
        // and the entry's own template lookup misses site-specific views.
        $entry = $entry->in(Site::current()->handle()) ?? $entry;

        // Draft is the one case handled here rather than by `DataResponse`.
        // `handleDraft()` would 404, and a page pulled back into draft must not
        // take the funnel down with it: the step falls back to its own
        // rendering and the walk carries on. Everything else — password
        // protection, `private`, the entry's `redirect` — is Statamic's job and
        // is deliberately left to it.
        if (! $entry->published()) {
            return null;
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function offerFor(FunnelStep $step, bool $preview = false): ?array
    {
        $handle = $step->config('offer');

        if (! is_string($handle) || $handle === '') {
            return null;
        }

        $offer = Offer::query()->where('handle', $handle)->first();

        if (! $offer || ! $offer->isSellable()) {
            return null;
        }

        // Counted only when somebody real saw it. An editor clicking through
        // their own funnel twenty times while building it would otherwise drive
        // the acceptance rate to nothing, and that number is the one thing the
        // offers screen is for.
        if (! $preview) {
            $offer->recordShown();
        }

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
            // The tick-boxes beside the order button. Named by the offer, not
            // by the page, so a template cannot add one — and a bump that has
            // been switched off simply stops appearing.
            'bumps' => array_map(fn (Offer $bump) => [
                'handle' => $bump->handle,
                'name' => $bump->name,
                'headline' => $bump->headline ?: $bump->name,
                'body' => $bump->body,
                'amount' => $bump->amount(),
                'compare_at' => $bump->compareAt(),
                'currency' => $bump->currency(),
            ], $offer->bumpOffers()),
            // Whether the page should show a field for a code at all.
            'coupons' => (bool) config('statamic-funnels.coupons', true),
        ];
    }
}
