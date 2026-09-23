<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Events\FunnelCompleted;
use Goldnead\StatamicFunnels\Events\FunnelStepEntered;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelStepEvent;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Where a visitor is, and how they got there.
 *
 * The token lives in the visitor's own cookie and identifies a *walk*, not a
 * person: a funnel has to work before anybody has said who they are, and most
 * visitors never do. Nothing here needs a login.
 */
class FunnelWalk
{
    public const COOKIE = 'statamic_funnel';

    /**
     * An existing walk, or null.
     *
     * For anything that only wants to *look* — a "carry on where you left off"
     * link on an ordinary page. Creating one there would set a cookie and write
     * a row for every visitor and every crawler, for a funnel nobody entered.
     */
    public function existingVisit(Funnel $funnel): ?FunnelVisit
    {
        $token = Embed::tokenFromRequest($this->request()) ?? $this->request()->cookie(self::COOKIE);

        if (! is_string($token) || ! $this->looksLikeToken($token)) {
            return null;
        }

        return FunnelVisit::query()
            ->where('funnel_id', $funnel->id)
            ->where('token', $token)
            ->first();
    }

    /** The walk this request belongs to, started if it is the first step. */
    public function visit(Funnel $funnel): FunnelVisit
    {
        $token = $this->token();

        return FunnelVisit::firstOrCreate(
            ['funnel_id' => $funnel->id, 'token' => $token],
            ['current_node_key' => $funnel->entryStep()?->node_key],
        );
    }

    /**
     * The token for this browser.
     *
     * Read from the cookie if there is one, otherwise made up here and queued
     * on the response. Not a session id: a walk that survives a browser restart
     * is the ordinary case, because the second half of a funnel usually arrives
     * by email.
     */
    public function token(): string
    {
        $request = $this->request();

        // **Der signierte Weg zuerst** (F4). Im Rahmen auf einer fremden Seite
        // kommt kein Cookie an, und zurueck vom Anbieter steht der Besuch nur
        // in der Adresse. Er gewinnt auch gegen ein Cookie, das noch von einem
        // frueheren Besuch in diesem Browser stammt: die Adresse sagt, welcher
        // Weg gerade gegangen wird. Danach steht er auch im Cookie, damit die
        // naechste Seite ohne Adresse auskommt.
        $signed = Embed::tokenFromRequest($request);

        if ($signed !== null) {
            if ($request->cookie(self::COOKIE) !== $signed) {
                $this->queueCookie($signed, Embed::requested($request));
            }

            return $signed;
        }

        $existing = $request->cookie(self::COOKIE);

        if (is_string($existing) && $this->looksLikeToken($existing)) {
            return $existing;
        }

        $token = Str::random(32);
        $this->queueCookie($token, Embed::requested($request));

        return $token;
    }

    /**
     * Im Rahmen `SameSite=None; Secure`, sonst `Lax`.
     *
     * Ein Browser, der Cookies im fremden Rahmen noch zulaesst, behaelt den
     * Weg dann auch ohne Adresse. `None` geht nur mit `Secure`, also nur ueber
     * https; ueber http bleibt es bei `Lax` und der Weg reist in der Adresse.
     */
    protected function queueCookie(string $token, bool $framed): void
    {
        $sicher = $this->request()->isSecure();
        $sameSite = $framed && $sicher ? 'None' : 'Lax';

        cookie()->queue(cookie(self::COOKIE, $token, 60 * 24 * 30, null, null, $sicher ?: null, true, false, $sameSite));
    }

    /**
     * Der Request von **jetzt**, nicht der vom Bau des Objekts.
     *
     * Laravel haelt die Controller-Instanz am Route-Objekt fest; wo dasselbe
     * Route-Objekt einen zweiten Request bedient — in der Testsuite, unter
     * Octane — truege ein per Konstruktor gefangener Request den Cookie des
     * ersten Besuchers in den Weg des zweiten.
     */
    protected function request(): Request
    {
        return app('request');
    }

    protected function looksLikeToken(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9]{32}$/', $value) === 1;
    }

    /** Record arrival at a step, once per step per walk. */
    public function enter(FunnelVisit $visit, FunnelStep $step): void
    {
        $visit->forceFill(['current_node_key' => $step->node_key])->save();

        // Once. A visitor who reloads a page has not entered it twice, and a
        // drop-off report built on repeat views is a report about refreshing.
        if ($visit->hasReached($step->node_key)) {
            return;
        }

        // Which version they were shown, written onto the arrival itself. A
        // split test that is not recorded is a coin toss with extra steps, and
        // the arrival is the only row that exists for every visitor whether or
        // not they go on.
        $payload = Split::running($step)
            ? ['variant' => Split::variantFor($step, $visit)]
            : [];

        $visit->record($step->node_key, FunnelStepEvent::ENTERED, $payload);

        FunnelStepEntered::dispatch($visit, $step);
    }

    /** Move on, and say by which way out. */
    public function advance(FunnelVisit $visit, FunnelStep $from, string $output, string $event, array $payload = []): ?FunnelStep
    {
        $visit->record($from->node_key, $event, $payload);

        // Die Mails, die an diesem Ausgang haengen. **Der Ausgang wird genommen**
        // ist der eine Moment, an dem ein Mail-Knoten feuert — `weiter` auf
        // einer Seite, `abgeschickt` am Formular, `abgelehnt` am Angebot. Nur
        // `accepted` nicht: das nimmt niemand per Klick, das sagt der Webhook,
        // und dafuer gibt es `FunnelOfferAccepted`. Ein Ende des Wegs hat
        // keinen Ausgang; „abgeschlossen" ist der Ausgang, der zum Ende fuehrt.
        if ($output !== 'accepted') {
            app(MailTrigger::class)->fire($visit, $from, $output);
        }

        $next = $visit->funnel->nextStep($from->node_key, $output);

        if (! $next) {
            // Nothing beyond this way out. That is a legitimate shape — a
            // declined branch that simply stops — and it ends the walk rather
            // than erroring.
            $this->complete($visit, $from);

            return null;
        }

        return $next;
    }

    public function complete(FunnelVisit $visit, FunnelStep $step): void
    {
        if ($visit->completed_at) {
            return;
        }

        $visit->forceFill(['completed_at' => now()])->save();
        $visit->record($step->node_key, FunnelStepEvent::COMPLETED);

        FunnelCompleted::dispatch($visit->fresh() ?? $visit);
    }
}
