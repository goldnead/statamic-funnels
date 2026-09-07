<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Web;

use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Nodes\AccountStep;
use Goldnead\StatamicFunnels\Nodes\CaptureStep;
use Goldnead\StatamicFunnels\Registries\StepRegistry;
use Goldnead\StatamicFunnels\Support\BillingFields;
use Goldnead\StatamicFunnels\Support\Consent;
use Goldnead\StatamicFunnels\Support\Countdown;
use Goldnead\StatamicFunnels\Support\FunnelMailRenderer;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\OrderSummary;
use Goldnead\StatamicFunnels\Support\PreviewToken;
use Goldnead\StatamicFunnels\Support\SavedCard;
use Goldnead\StatamicFunnels\Support\Split;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicPayments\Support\Catalogue;
use Goldnead\StatamicPayments\Support\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Http\Responses\DataResponse;
use Throwable;

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
        protected SavedCard $savedCard,
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
            // Ob dieser Schritt ohne erneute Karteneingabe abbuchen wuerde, und
            // womit. Null heisst: normale Kasse, der Kaeufer geht zum Anbieter.
            //
            // Die Seite muss es sagen, bevor sie es tut. Eine Abbuchung, die
            // erst im Kontoauszug auftaucht, ist keine Bequemlichkeit mehr —
            // und § 312j Abs. 3 BGB verlangt die wesentlichen Angaben
            // unmittelbar ueber dem Knopf, die Zahlungsart eingeschlossen.
            // In der Vorschau gibt es keinen Besucher und damit nichts zu
            // sagen.
            'saved_card' => $step->type === 'offer'
                ? $this->savedCard->forTemplate($preview ? null : $visit)
                : null,
            // Die Belehrung und der Wortlaut am Haken, mit Fassung. Was hier
            // steht, schickt die Seite als `consent_text` zurueck, und der
            // Server nimmt nur an, was mit dem geltenden Text uebereinstimmt.
            'withdrawal' => $this->withdrawalFor($step, $preview ? null : $visit),
            // Null when this step has no deadline. A template that has to test
            // for it is a template that behaves the same either way.
            'countdown' => Countdown::forTemplate($step, $preview ? null : $visit),
            'visit' => ['email' => $visit->email, 'name' => $visit->name],
            // Wonach der Capture-Schritt fragt, und was davon schon dasteht.
            // Getrennt von `visit`, weil `visit` sagt, wer da ist, und dies
            // hier, was die Rechnung von ihm braucht.
            'billing' => $step->type === 'capture'
                ? self::billingForTemplate($funnel, $step, $preview ? null : $visit)
                : null,
            // Der Newsletter-Haken unter dem E-Mail-Feld. Null, wenn der
            // Schritt ihn verbirgt. Nie vorangekreuzt — ausser der Besuch hat
            // ihn schon einmal selbst gesetzt und laedt die Seite neu.
            'newsletter' => $step->type === 'capture'
                ? self::newsletterForTemplate($step, $preview ? null : $visit)
                : null,
            // Der Konto-Schritt: ob „Spaeter" erlaubt ist.
            'account' => $step->type === 'account'
                ? [
                    'optional' => AccountStep::isOptional((array) ($step->config ?? [])),
                    // Wohin, wenn es das Konto schon gibt: die Seite der Site
                    // fuer „Passwort vergessen", sonst die des Control Panels.
                    'reset_url' => self::passwordResetUrl(),
                ]
                : null,
            // Was in diesem Lauf gekauft wurde. Null, solange nichts bezahlt
            // ist — eine Danke-Seite, die „Danke" sagt und den Kauf nicht
            // kennt, ist der Zustand, den das hier beendet.
            'order' => OrderSummary::forVisit($preview ? null : $visit),
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
        //
        // `csrf_field` has to be handed over explicitly. A plain Laravel
        // `view()` does not run Statamic's cascade, which is where that
        // variable normally comes from — so the four `{{ csrf_field }}` in the
        // shipped template rendered to nothing, every form posted without a
        // token, and **every step of every funnel answered 419 Page Expired**.
        // The addon's own core function was not walkable, and it looked like a
        // session problem rather than a missing variable.
        return view($view, [
            'funnel' => $context,
            'csrf_field' => csrf_field(),
            'csrf_token' => csrf_token(),
        ] + $context);
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
     * Was der Capture-Schritt an Rechnungsangaben will, und was schon dasteht.
     *
     * `address` ist der Schalter fuer die vier Felder, `name_required` der fuer
     * das Sternchen am Namen. Die Werte daneben kommen aus dem Besuch, damit
     * jemand, der zurueckgeht oder neu laedt, nicht alles noch einmal tippt —
     * und damit ein zweiter Schritt im selben Lauf zeigt, was der erste schon
     * erhoben hat.
     *
     * In der Vorschau gibt es keinen Besucher: dann stehen die Felder leer da,
     * was genau das ist, was ein neuer Besucher sieht.
     *
     * @return array<string, mixed>
     */
    protected static function billingForTemplate(Funnel $funnel, FunnelStep $step, ?FunnelVisit $visit): array
    {
        $mode = (string) ($step->config('billing') ?: CaptureStep::BILLING_MINIMAL);

        if (! in_array($mode, CaptureStep::billingModes(), true)) {
            $mode = CaptureStep::BILLING_MINIMAL;
        }

        // Die Felder aus der Bibliothek, wenn das Angebot sie bestimmt. Null
        // heisst: Bibliothek nicht erreichbar, dann wie `minimal`.
        $fields = BillingFields::forStep($funnel, $step);

        if ($mode === CaptureStep::BILLING_OFFER && $fields === null) {
            $mode = CaptureStep::BILLING_MINIMAL;
        }

        // `??` faengt den fehlenden Besuch der Vorschau schon ab; ein `?->`
        // davor waere doppelt gemoppelt und faellt der statischen Analyse auf.
        $bekannt = (array) (($visit->meta ?? [])['billing'] ?? []);

        return [
            'mode' => $mode,
            'address' => $mode === CaptureStep::BILLING_FULL,
            'name_required' => $mode === CaptureStep::BILLING_NAME || $mode === CaptureStep::BILLING_FULL,
            'street' => $bekannt['street'] ?? null,
            'postal_code' => $bekannt['postal_code'] ?? null,
            'city' => $bekannt['city'] ?? null,
            'country' => $bekannt['country'] ?? null,
            // Modus `offer`: die Felder mit dem, was schon dasteht. Das
            // Namensfeld der Vorlage bleibt, wenn die Bibliothek `name` fuehrt,
            // sonst ist es freiwillig wie bei `minimal`.
            'fields' => $mode === CaptureStep::BILLING_OFFER
                ? array_map(function (array $field) use ($bekannt) {
                    $value = $bekannt[$field['key']] ?? null;

                    // Optionen als Liste mit `selected`, weil Antlers in der
                    // Schleife den aeusseren Wert nicht mehr sieht.
                    $options = [];

                    foreach ($field['options'] as $optionValue => $label) {
                        $options[] = ['value' => (string) $optionValue, 'label' => $label, 'selected' => (string) $optionValue === (string) $value];
                    }

                    return ['options' => $options, 'value' => $value] + $field;
                }, array_values(array_filter($fields, fn (array $field) => $field['key'] !== 'name')))
                : [],
            'name_from_library' => $mode === CaptureStep::BILLING_OFFER
                ? collect($fields)->firstWhere('key', 'name')
                : null,
        ];
    }

    /**
     * Die gerenderte Mail eines Mail-Knotens, mit Beispieldaten.
     *
     * Derselbe Pass wie die Seiten-Vorschau, dieselbe Bindung an einen Funnel,
     * derselbe Graph — auch ein Mail-Knoten, der noch nie gespeichert wurde.
     * Ohne Vorlage kommt kein Fehler, sondern ein Platzhalter, der es sagt:
     * das ist der Zustand, in dem jemand den Knoten gerade angelegt hat.
     */
    public function previewMail(Request $request, string $funnel, string $nodeKey)
    {
        $model = Funnel::with(['steps', 'edges'])->where('handle', $funnel)->first();

        abort_unless($model !== null, 404);

        $graph = PreviewToken::graph($request->query('token'), $model);

        abort_unless($graph !== null, 404);

        $step = PreviewToken::step($graph, $nodeKey, $model);

        abort_unless($step !== null && $step->type === 'mail', 404);

        // Ein Besuch, den es nicht gibt: Beispielname, Beispieladresse, nichts
        // geschrieben. Und eine Bestellung ueber das Angebot des Funnels, damit
        // `order.*` in der Vorschau etwas zeigt.
        $visit = new FunnelVisit([
            'name' => 'Maria Beispiel',
            'email' => 'maria.beispiel@example.com',
            'current_node_key' => $model->entryStep()?->node_key,
        ]);
        $visit->setRelation('funnel', $model);

        $offer = $this->firstOfferIn($graph);

        $sample = OrderSummary::sample(
            $offer?->name,
            $offer?->amountCent(),
            $offer?->currency() ?? 'EUR',
            $visit->email,
        );

        try {
            $mail = app(FunnelMailRenderer::class)->render($step, $visit, $sample);
        } catch (Throwable $e) {
            return response($this->placeholder($e->getMessage()))
                ->header('Content-Type', 'text/html; charset=utf-8');
        }

        return response($mail['html'])->header('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Das erste Angebot im vorgeschauten Graphen, fuer die Beispielbestellung.
     *
     * @param  array<string, mixed>  $graph
     */
    protected function firstOfferIn(array $graph): ?Offer
    {
        foreach ($graph['nodes'] ?? [] as $node) {
            if (($node['type'] ?? null) !== 'offer') {
                continue;
            }

            $handle = $node['config']['offer'] ?? null;

            if (! is_string($handle) || $handle === '') {
                continue;
            }

            $offer = Offer::query()->where('handle', $handle)->first();

            if ($offer) {
                return $offer;
            }
        }

        return null;
    }

    /** Was das Vorschaufenster zeigt, wenn es keine Mail zu zeigen gibt. */
    protected function placeholder(string $reason): string
    {
        $title = e(__('statamic-funnels::messages.mail_preview_empty'));
        $reason = e($reason);

        return <<<HTML
        <!doctype html>
        <html lang="de">
        <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$title}</title>
            <style>
                body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f3f4f6; color: #6b7280; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; text-align: center; padding: 24px; }
                p { margin: 0; font-size: 14px; line-height: 1.5; max-width: 32rem; }
                strong { display: block; color: #111827; font-weight: 600; margin-bottom: 4px; }
            </style>
        </head>
        <body>
            <p><strong>{$title}</strong>{$reason}</p>
        </body>
        </html>
        HTML;
    }

    protected static function passwordResetUrl(): ?string
    {
        $configured = trim((string) config('statamic-funnels.password_reset_url', ''));

        if ($configured !== '') {
            return $configured;
        }

        return Route::has('statamic.cp.password.request') ? route('statamic.cp.password.request') : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected static function newsletterForTemplate(FunnelStep $step, ?FunnelVisit $visit): ?array
    {
        $mode = (string) ($step->config('newsletter') ?: CaptureStep::NEWSLETTER_OPTIONAL);

        if ($mode === CaptureStep::NEWSLETTER_HIDDEN) {
            return null;
        }

        $bisher = (array) ((($visit->meta ?? [])['newsletter']) ?? []);

        return [
            'label' => AdvanceController::newsletterLabel($step),
            'checked' => (bool) ($bisher['opted_in'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function withdrawalFor(FunnelStep $step, ?FunnelVisit $visit): ?array
    {
        if ($step->type !== 'offer') {
            return null;
        }

        $handle = $step->config('offer');

        if (! is_string($handle) || $handle === '') {
            return null;
        }

        $offer = Offer::query()->where('handle', $handle)->first();

        return $offer ? Consent::forTemplate($offer, $visit) : null;
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
            // Two shapes on purpose: `amount` keeps the dot for anything that
            // parses, `amount_local` is what a person reads. A German page
            // showing "249.00" is a machine talking.
            'amount' => $offer->amount(),
            'amount_local' => $offer->amountLocal(),
            'compare_at' => $offer->compareAt(),
            'compare_at_local' => $offer->compareAtLocal(),
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
                'amount_local' => $bump->amountLocal(),
                'compare_at' => $bump->compareAt(),
                'compare_at_local' => $bump->compareAtLocal(),
                'currency' => $bump->currency(),
            ], $offer->bumpOffers()),
            // Whether the page should show a field for a code at all.
            'coupons' => (bool) config('statamic-funnels.coupons', true),
            // **Der Zahlungsrhythmus, wenn das Angebot einen fuehrt.**
            //
            // Nicht Schmuck, sondern Pflichtangabe: § 312j Abs. 2 BGB will den
            // Gesamtpreis und die Laufzeit unmittelbar ueber dem Bestellknopf.
            // Ohne diesen Schluessel kann eine Vorlage nur raten, und die
            // Vorlage auf adriangoldner.com riet falsch — sie schrieb
            // „Einmalig · kein Abo" ueber einen Vertrag ueber drei Raten.
            'plan' => $this->planFor($prefix.$offer->handle, $offer->currency()),
        ];
    }

    /**
     * Was die Kassenseite ueber den Rhythmus sagen muss, oder null.
     *
     * Gefragt wird der **Katalog**, nicht das Angebot. Beide antworten heute
     * dasselbe, aber der Katalog ist die Stelle, an der die Zahlung ihren Preis
     * holt — und eine Seite, die einen anderen Rhythmus nennt als den, der
     * abgebucht wird, ist genau der Fehler, den diese Angabe verhindern soll.
     *
     * Bei einer festen Anzahl steht die Gesamtsumme dabei. Bei einem Abo ohne
     * Ende nicht: sie steht erst fest, wenn gekuendigt wird, und eine erfundene
     * waere eine Preisangabe, die nicht stimmt.
     *
     * @return array<string, mixed>|null
     */
    protected function planFor(string $handle, ?string $currency): ?array
    {
        if (! class_exists(Subscriptions::class)) {
            return null;
        }

        $plan = app(Subscriptions::class)->planFor($handle);

        if (! $plan) {
            return null;
        }

        $rate = app(Catalogue::class)->find($handle)['amount_cent'] ?? null;
        $times = $plan['times'];
        $gesamt = is_int($rate) && is_int($times) ? $rate * $times : null;

        return [
            'interval' => $plan['interval'],
            'interval_label' => self::intervalLabel($plan['interval']),
            'times' => $times,
            // Wie viele Einzuege nach dem heutigen noch kommen. Ausgerechnet
            // hier und nicht in der Vorlage: „Danach 2 x 520 €" ist eine
            // Preisangabe, und Rechnen gehoert nicht in eine Seite, die kein
            // Test anfasst.
            'times_remaining' => is_int($times) ? max(0, $times - 1) : null,
            'trial_days' => $plan['trial_days'] ?: null,
            'total' => $gesamt === null ? null : number_format($gesamt / 100, 2, '.', ''),
            'total_local' => Offer::localise($gesamt),
            'currency' => $currency,
        ];
    }

    /**
     * Der Rhythmus in Worten, oder unveraendert, wenn es dafuer keine gibt.
     *
     * Mollie nimmt „1 month", „3 months", „1 year" und einiges dazwischen. Fuer
     * die gelaeufigen steht eine Uebersetzung bereit; alles andere geht so
     * durch, wie der Anbieter es schreibt. Haesslicher als eine erfundene
     * Formulierung, und dafuer nie falsch — was hier steht, ist eine
     * Preisangabe.
     */
    protected static function intervalLabel(string $interval): string
    {
        $schluessel = 'statamic-funnels::messages.interval_'.trim($interval);
        $wort = __($schluessel);

        return is_string($wort) && $wort !== $schluessel ? $wort : trim($interval);
    }
}
