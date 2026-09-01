<?php

namespace Goldnead\StatamicFunnels\Http\Controllers\Web;

use Goldnead\StatamicFunnels\Events\FunnelFormSubmitted;
use Goldnead\StatamicFunnels\Events\FunnelOfferAccepted;
use Goldnead\StatamicFunnels\Events\FunnelOfferDeclined;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicFunnels\Nodes\CaptureStep;
use Goldnead\StatamicFunnels\Support\Consent;
use Goldnead\StatamicFunnels\Support\Countdown;
use Goldnead\StatamicFunnels\Support\FunnelWalk;
use Goldnead\StatamicFunnels\Support\SavedCard;
use Goldnead\StatamicOffers\Models\Offer;
use Goldnead\StatamicOffers\Support\Basket;
use Goldnead\StatamicPayments\Models\Payment;
use Goldnead\StatamicPayments\Support\Checkout;
use Goldnead\StatamicPayments\Support\FollowUp;
use Goldnead\StatamicPayments\Support\PaymentDetails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Moving on from a step.
 *
 * A normal form post with a CSRF token, deliberately: this is a browser, a
 * person, and — on an offer step — an order. A page on another site must not be
 * able to advance somebody's funnel, let alone buy something in it.
 */
class AdvanceController
{
    public function __construct(
        protected FunnelWalk $walk,
        protected Checkout $checkout,
        protected FollowUp $followUp,
        protected SavedCard $savedCard,
    ) {}

    public function __invoke(Request $request, string $funnel, string $nodeKey)
    {
        $model = Funnel::with(['steps', 'edges'])->where('handle', $funnel)->first();

        abort_unless($model && $model->published, 404);

        $step = $model->stepByKey($nodeKey);

        // Ein Mail-Knoten ist keine Seite: es gibt nichts, von dem aus man
        // weitergehen koennte.
        abort_unless($step && ! $step->disabled && $step->type !== 'mail', 404);

        $visit = $this->walk->visit($model);

        // You can only leave a step you are standing on. Every page is directly
        // reachable by URL — it has to be, because the provider and half the
        // emails link straight into the middle of a flow — but *advancing* from
        // one nobody entered is how somebody skips a form, or takes the
        // accepted branch of an offer they never saw.
        abort_unless($visit->hasReached($step->node_key), 403);

        return match ($step->type) {
            'offer' => $this->offer($request, $model, $step, $visit),
            'capture' => $this->capture($request, $model, $step, $visit),
            default => $this->plain($model, $step, $visit),
        };
    }

    /** An ordinary "continue". */
    protected function plain(Funnel $funnel, FunnelStep $step, $visit)
    {
        $next = $this->walk->advance($visit, $step, 'default', FunnelStepEvent::SUBMITTED);

        return $this->go($funnel, $next);
    }

    /**
     * A form step.
     *
     * The submission itself belongs to Statamic — its form, its validation, its
     * notifications, its submissions screen. What happens here is only that the
     * walk learns who this is and moves on.
     */
    protected function capture(Request $request, Funnel $funnel, FunnelStep $step, $visit)
    {
        // Wonach dieser Schritt fragt, entscheidet der Schritt — nicht das
        // Formular, das ankommt. Ein Funnel, der ueber 250 Euro verkauft, muss
        // Name und Anschrift verlangen koennen, sonst schreibt der
        // `InvoiceWriter` spaeter gar keine Rechnung (§ 14 UStG, § 33 UStDV).
        // Umgekehrt darf ein Lead-Magnet weiterhin nur die Adresse wollen.
        $billing = (string) ($step->config('billing') ?: CaptureStep::BILLING_MINIMAL);

        if (! in_array($billing, CaptureStep::billingModes(), true)) {
            // Ein unbekannter Wert kaeme aus einem Graphen, den jemand von Hand
            // geschrieben hat. Die mildeste Lesart gewinnt: fragen ist erlaubt,
            // jemanden am Kauf hindern nicht.
            $billing = CaptureStep::BILLING_MINIMAL;
        }

        $wantsName = $billing !== CaptureStep::BILLING_MINIMAL;
        $wantsAddress = $billing === CaptureStep::BILLING_FULL;

        $data = $request->validate([
            'email' => ['required', 'email', 'max:191'],
            'name' => [$wantsName ? 'required' : 'nullable', 'string', 'max:191'],
            'street' => [$wantsAddress ? 'required' : 'nullable', 'string', 'max:191'],
            'postal_code' => [$wantsAddress ? 'required' : 'nullable', 'string', 'max:32'],
            'city' => [$wantsAddress ? 'required' : 'nullable', 'string', 'max:191'],
            // Zwei Buchstaben, weil `payments.country` genau das speichert und
            // der Steuerfall daran haengt. Ein freies Textfeld waere
            // „Deutschland", „DE", „de" und „Germany" in derselben Spalte.
            'country' => [$wantsAddress ? 'required' : 'nullable', 'string', 'size:2', 'alpha'],
        ]);

        // Eine andere Adresse als eben heisst: hier sitzt jemand anderes.
        //
        // Der Besuch haengt an einem Cookie, der einen Monat haelt, und trug
        // bisher die Zahlung des vorigen Laufs mit sich. Das hatte zwei Wege
        // ins Verderben, beide auf demselben Familien- oder Bueforechner: die
        // Vorgaengerzahlung liess den naechsten Kauf per gespeichertem Mandat
        // auf die *fremde* Karte abbuchen — und wo das nicht griff, hielt
        // `pendingPaymentFor()` den alten, laengst bezahlten Kauf fuer diesen
        // hier und winkte die zweite Person ohne Zahlung durch.
        //
        // Der Lauf faengt deshalb neu an: keine Zahlung, kein gemerkter
        // Schritt, kein Name. Nur die Wegmarken des Besuchs bleiben, damit die
        // Auswertung nicht luegt.
        $sameBuyer = ! is_string($visit->email)
            || $visit->email === ''
            || mb_strtolower(trim($visit->email)) === mb_strtolower(trim($data['email']));

        $meta = $visit->meta ?? [];

        if (! $sameBuyer) {
            unset($meta['payments'], $meta['pending_step'], $meta['billing']);
        }

        // Die Rechnungsanschrift bleibt am Besuch, nicht am Schritt: bezahlt
        // wird ein, zwei Schritte spaeter, und die Zahlung braucht sie dort.
        // Nur ueberschreiben, wenn wirklich etwas kam — sonst loescht ein
        // spaeterer Capture-Schritt ohne Adressfelder die Anschrift, die der
        // erste erhoben hat, und die Rechnung platzt an einer Stelle, an der
        // niemand nach der Ursache sucht.
        $anschrift = array_filter([
            'street' => trim((string) ($data['street'] ?? '')),
            'postal_code' => trim((string) ($data['postal_code'] ?? '')),
            'city' => trim((string) ($data['city'] ?? '')),
            'country' => strtoupper(trim((string) ($data['country'] ?? ''))),
        ], static fn (string $wert): bool => $wert !== '');

        if ($anschrift !== []) {
            $meta['billing'] = array_merge((array) ($meta['billing'] ?? []), $anschrift);
        }

        $visit->forceFill([
            'email' => $data['email'],
            'name' => $data['name'] ?? ($sameBuyer ? $visit->name : null),
            'payment_id' => $sameBuyer ? $visit->payment_id : null,
            'meta' => $meta === [] ? null : $meta,
        ])->save();

        // Announced before moving on, so a sibling that wants the contact gets
        // it whether or not the next step exists.
        FunnelFormSubmitted::dispatch($visit->fresh() ?? $visit, $step, $data);

        $next = $this->walk->advance($visit, $step, 'default', FunnelStepEvent::SUBMITTED, [
            'email' => $data['email'],
        ]);

        return $this->go($funnel, $next);
    }

    /**
     * An offer step: accepted or declined.
     *
     * Declining is a first-class answer, not an error. Most visitors decline,
     * and a funnel that treats that as a failure has nowhere to send them.
     */
    protected function offer(Request $request, Funnel $funnel, FunnelStep $step, $visit)
    {
        if (! $request->boolean('accept')) {
            $next = $this->walk->advance($visit, $step, 'declined', FunnelStepEvent::DECLINED);

            // Gesagt, damit eine Mail am Ausgang `declined` einen Moment hat.
            FunnelOfferDeclined::dispatch($visit->fresh() ?? $visit, $step);

            return $this->go($funnel, $next);
        }

        // The deadline, enforced. A countdown that only counts is a lie told in
        // Javascript: the number runs out, the visitor reloads, and the offer is
        // still there. Checked before anything else on the accepting path, so a
        // late order cannot start a payment.
        if (Countdown::expired($step, $visit)) {
            return back()->withErrors(['offer' => __('statamic-funnels::messages.offer_expired')]);
        }

        $offer = Offer::query()->where('handle', (string) $step->config('offer'))->first();

        if (! $offer || ! $offer->isSellable()) {
            Log::warning('statamic-funnels: an offer step points at something that cannot be sold.', [
                'funnel' => $funnel->handle,
                'step' => $step->node_key,
                'offer' => $step->config('offer'),
            ]);

            return back()->withErrors(['offer' => __('statamic-funnels::messages.offer_unavailable')]);
        }

        // Der Wortlaut, dem hier zugestimmt wird — vom Angebot, mit Fassung,
        // sonst aus der Sprachdatei. Und die Frage, ob der Haken Pflicht ist.
        $terms = Consent::terms($offer);
        $consentText = Consent::text($terms, Consent::isB2b($visit));

        $request->validate([
            // The order button's own confirmation. Not decoration: it is the
            // record that somebody clicked something labelled as an order.
            'confirmed' => [Consent::checkboxRequired($terms) ? 'accepted' : 'nullable'],
            'consent_text' => ['nullable', 'string', 'max:4000'],
        ]);

        // Die Seite sagt, welchen Wortlaut sie gezeigt hat. Stimmt er nicht
        // mit dem ueberein, der jetzt gilt, war die Seite veraltet — jemand
        // hat sie gestern geoeffnet und der Text wurde heute geaendert — oder
        // jemand hat am Formular gedreht. In beiden Faellen ist „zugestimmt"
        // zu einem anderen Text als dem geltenden keine Zustimmung. Eine
        // Vorlage, die das Feld nicht schickt, bleibt bestellbar; der Server
        // protokolliert dann den geltenden Text.
        if ($request->filled('consent_text') && (string) $request->input('consent_text') !== $consentText) {
            throw ValidationException::withMessages([
                'consent_text' => __('statamic-funnels::messages.consent_stale'),
            ]);
        }

        // What was actually ticked and typed, checked against the offer rather
        // than believed. The browser says which boxes were checked; the offer
        // says which boxes exist, and only their intersection is bought.
        $basket = Basket::make(
            $offer,
            array_values(array_filter((array) $request->input('bumps', []), 'is_string')),
            config('statamic-funnels.coupons', true) ? (string) $request->input('coupon', '') : null,
        );

        $prefix = (string) config('statamic-offers.handle_prefix', 'offer:');
        $buyHandle = $prefix.$offer->handle;

        // Already paid once in this walk, by this same person? Then this is a
        // follow-up, charged against what the first payment left behind, and
        // the buyer types nothing. Otherwise it is a first checkout and they go
        // to the provider.
        //
        // „By this same person" ist der Teil, der nicht fehlen darf. Der Besuch
        // haengt an einem Cookie, der einen Monat haelt; wer ihn allein
        // befragt, bucht der zweiten Person am selben Rechner die Karte der
        // ersten ab und liefert an deren Adresse. Die Pruefung steht in
        // {@see SavedCard}, weil die Seite vorher dasselbe wissen muss.
        $previous = $this->savedCard->chargeableFrom($visit);

        // Die Rechnungsangaben dieses Laufs — **fuer beide Wege**.
        //
        // Sie standen zuerst nur am Checkout-Zweig, und das war derselbe Fehler
        // noch einmal, nur eine Methode tiefer: `FollowUp::accept()` uebernimmt
        // von der Vorgaengerzahlung nur Adresse, Name, Mandat und Herkunft —
        // **nicht** Land und Anschrift. Ein Upsell ueber 250 Euro haette damit
        // wieder keine Rechnung bekommen, an einer zweiten, unbehandelten
        // Stelle derselben Methode.
        $angaben = self::rechnungsangaben($visit);

        // Die Einwilligung dazu: Zeitpunkt und Wortlaut, die Konditionen des
        // Angebots eingefroren, das Zugangsfenster. Fuer beide Wege, weil beide
        // eine Zahlung anlegen — ein Upsell ohne Beleg waere derselbe Fehler
        // wie ein Upsell ohne Anschrift.
        $angaben = self::mitEinwilligung($angaben, Consent::details($offer, $visit, $consentText));

        if ($previous) {
            $payment = $this->followUp->accept($previous, $buyHandle, [
                'funnel' => $funnel->handle,
                'step' => $step->node_key,
            ], $angaben, $visit->email);

            if (! $payment) {
                return back()->withErrors(['offer' => __('statamic-funnels::messages.offer_unavailable')]);
            }

            $this->rememberPending($visit, $step, $payment);

            // A recurring charge is usually accepted now and settled later, so
            // the payment comes back `pending` more often than not. Moving on
            // here would be the one thing this whole family is written against:
            // treating acceptance as payment. The webhook decides, exactly as
            // on the checkout path — `AdvanceOnPayment` picks it up.
            if (! $payment->isPaid()) {
                return $this->waiting($funnel, $step);
            }

            $next = $this->walk->advance($visit, $step, 'accepted', FunnelStepEvent::ACCEPTED, [
                'payment_id' => $payment->getKey(),
            ]);

            FunnelOfferAccepted::dispatch($visit->fresh() ?? $visit, $step, $payment);

            return $this->go($funnel, $next);
        }

        // Back into the funnel, not to the site's thank-you page. A buyer who
        // returns from the provider outside the flow they were walking has been
        // dropped halfway through a purchase — and the rest of the funnel, the
        // part that was meant to follow the sale, never happens.
        $accepted = $funnel->nextStep($step->node_key, 'accepted');

        // Not twice. A second submit — a double click, a reloaded confirmation,
        // an impatient visitor — would start a second payment and overwrite the
        // first one's id on the visit. The webhook of the first would then find
        // nothing: the money arrives and the walk stands still.
        if ($existing = $this->pendingPaymentFor($visit, $step)) {
            return $existing->isPaid()
                ? $this->go($funnel, $funnel->nextStep($step->node_key, 'accepted'))
                : $this->waiting($funnel, $step);
        }

        // Die Anschrift geht **in** die Zahlung hinein, nicht danach hinterher.
        // Der `InvoiceWriter` liest `payment.meta['address']` in dem Moment, in
        // dem die Zahlung bezahlt gemeldet wird; was danach nachgetragen wird,
        // kommt fuer die Rechnung zu spaet. Fehlt sie, wird nichts erfunden —
        // dann ist es ein Kauf unter 250 Euro, und die Kleinbetragsrechnung
        // kommt ohne aus (§ 33 UStDV).
        $result = $this->checkout->start($basket->handles(), array_filter([
            'email' => $visit->email,
            'name' => $visit->name,
            'country' => $angaben['country'] ?? null,
        ], static fn ($wert): bool => $wert !== null && $wert !== ''), $accepted?->slug
            ? route('statamic-funnels.step', [$funnel->handle, $accepted->slug])
            : route('statamic-funnels.entry', $funnel->handle), $basket->discount(), $angaben);

        if (! $result) {
            return back()->withErrors(['offer' => __('statamic-funnels::messages.offer_unavailable')]);
        }

        $this->rememberPending($visit, $step, $result->payment);

        // Off to the provider. Nothing is accepted yet — only the webhook
        // decides that, exactly as everywhere else in this family.
        return redirect()->away($result->checkoutUrl);
    }

    /**
     * Was von diesem Lauf an jede Zahlung gehoert.
     *
     * Genau die Form, die {@see PaymentDetails}
     * annimmt: `country` als eigene Spalte, weil der Steuersatz daran haengt,
     * und die Anschrift als eine Zeichenkette in `meta`, weil der
     * `InvoiceWriter` sie so liest.
     *
     * **Eine Stelle fuer beide Wege.** Die erste Fassung setzte das nur am
     * Checkout-Zweig zusammen, und der Upsell ueber die gespeicherte Karte ging
     * leer aus — derselbe fehlende Beleg, nur zwanzig Zeilen weiter oben. Ein
     * gemeinsamer Helfer macht es unmoeglich, den einen zu pflegen und den
     * anderen zu vergessen.
     *
     * Leer heisst leer: `array_filter` laesst nichts uebrig, was der Aufrufer
     * dann als gesetzt lesen koennte.
     *
     * @return array<string, mixed>
     */
    protected static function rechnungsangaben(FunnelVisit $visit): array
    {
        $billing = (array) (($visit->meta ?? [])['billing'] ?? []);

        return array_filter([
            'country' => $billing['country'] ?? null,
            'meta' => array_filter(['address' => self::anschriftZeilen($billing)]),
        ]);
    }

    /**
     * Zwei `PaymentDetails`-Haufen zusammenlegen, `meta` tief.
     *
     * `array_merge` allein liesse `meta['address']` von der Einwilligung
     * ueberschreiben — oder umgekehrt — und einer der beiden Belege waere
     * still weg.
     *
     * @param  array<string, mixed>  $angaben
     * @param  array<string, mixed>  $weitere
     * @return array<string, mixed>
     */
    protected static function mitEinwilligung(array $angaben, array $weitere): array
    {
        $meta = array_merge((array) ($angaben['meta'] ?? []), (array) ($weitere['meta'] ?? []));

        unset($angaben['meta'], $weitere['meta']);

        $zusammen = array_merge($angaben, $weitere);

        if ($meta !== []) {
            $zusammen['meta'] = $meta;
        }

        return $zusammen;
    }

    /**
     * Die Anschrift als die eine Zeichenkette, die eine Rechnung braucht.
     *
     * `payments.meta['address']` ist ein String und kein Feld je Zeile — der
     * `InvoiceWriter` setzt ihn so, wie er kommt, unter den Namen des
     * Empfaengers. Zwei Zeilen also, deutsche Reihenfolge: Strasse, dann
     * Postleitzahl und Ort.
     *
     * **Das Land steht nicht darin.** Es hat mit `payments.country` eine eigene
     * Spalte, weil der Steuersatz daran haengt; zweimal gefuehrt waeren es
     * zwei Wahrheiten, sobald jemand eine davon korrigiert.
     *
     * Unvollstaendig heisst leer, nicht halb. Eine Anschrift ohne Ort ist
     * keine, und eine halbe auf einer Rechnung ist schlechter als gar keine:
     * ohne sie faellt die Rechnung ueber 250 Euro laut aus, mit einer halben
     * entsteht sie fehlerhaft — und eine ausgestellte Rechnung ist danach
     * nicht mehr zu aendern.
     *
     * @param  array<string, mixed>  $billing
     */
    protected static function anschriftZeilen(array $billing): ?string
    {
        $strasse = trim((string) ($billing['street'] ?? ''));
        $plz = trim((string) ($billing['postal_code'] ?? ''));
        $ort = trim((string) ($billing['city'] ?? ''));

        if ($strasse === '' || $ort === '') {
            return null;
        }

        return $strasse."\n".trim($plz.' '.$ort);
    }

    /**
     * Note which payment this step started.
     *
     * Kept per step as well as on the visit: a walk can pay more than once —
     * the thing they came for, then an upsell — and a single `payment_id` would
     * point at the newest one while the older one's webhook was still in
     * flight.
     */
    protected function rememberPending(FunnelVisit $visit, FunnelStep $step, Payment $payment): void
    {
        $meta = $visit->meta ?? [];
        $meta['pending_step'] = $step->node_key;
        $meta['payments'][$step->node_key] = $payment->getKey();

        $visit->forceFill([
            'payment_id' => $payment->getKey(),
            'meta' => $meta,
        ])->save();
    }

    protected function pendingPaymentFor(FunnelVisit $visit, FunnelStep $step): ?Payment
    {
        $id = data_get($visit->meta, 'payments.'.$step->node_key);

        if (! $id) {
            return null;
        }

        $payment = Payment::find($id);

        // A refused charge is not a reason to stop somebody buying: they got
        // nothing, so they may try again.
        return $payment && $payment->status !== Payment::STATUS_FAILED ? $payment : null;
    }

    /** Sent back to the offer with a note that the money is on its way. */
    protected function waiting(Funnel $funnel, FunnelStep $step)
    {
        return back()->with('statamic-funnels.waiting', $step->node_key);
    }

    protected function go(Funnel $funnel, ?FunnelStep $next)
    {
        if (! $next) {
            return redirect()->route('statamic-funnels.entry', $funnel->handle);
        }

        return redirect()->route('statamic-funnels.step', [$funnel->handle, $next->slug]);
    }
}
