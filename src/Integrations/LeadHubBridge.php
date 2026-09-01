<?php

namespace Goldnead\StatamicFunnels\Integrations;

use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The optional path from a captured address to a contact.
 *
 * Off unless the sibling is installed *and* the site switched it on. Installing
 * a funnel addon must not start writing into somebody's CRM.
 *
 * **Kauf und Newsletter getrennt.** Wer im Formular seine Adresse laesst, wird
 * ein Kontakt — **ohne** Einwilligung. Die Einwilligung kommt allein vom
 * Newsletter-Haken, und nur dann. Wer kauft, bekommt das Tag `kunde`. So sind
 * „hat gekauft" und „will Post" zwei Aussagen, die getrennt lesbar bleiben;
 * vorher fielen sie in einer Zeile zusammen, und das ist genau der Fehler, den
 * Kajabi nicht macht.
 *
 * Two rules this family learned the hard way:
 *
 * 1. `class_exists` on the class actually called, not `interface_exists` on a
 *    contract the sibling may rename.
 * 2. Never `method_exists()` on a Facade: it forwards through `__callStatic`
 *    and declares none of the methods it forwards, so the probe is always
 *    false. Ask the object behind it.
 */
class LeadHubBridge
{
    protected const FACADE = '\Goldnead\LeadHub\Facades\LeadHub';

    /**
     * Der Weg zur Einwilligung.
     *
     * `LeadHub::ingest()` und `LeadHub::create()` kennen keine Einwilligung
     * (`SourceEvent` hat kein Feld dafuer, `update()` laesst `consent` nicht
     * zu). Der `ContactResolver` kennt sie ueber das DTO und setzt sie auch an
     * einem bestehenden Kontakt — das ist derselbe Weg, den LeadHub fuer
     * Formular-Einsendungen mit Einwilligungsfeld geht.
     */
    protected const RESOLVER = '\Goldnead\Leadhub\Services\ContactResolver';

    protected const DTO = '\Goldnead\Leadhub\Support\ContactDto';

    public const TAG_CUSTOMER = 'kunde';

    public function available(): bool
    {
        if (! config('statamic-funnels.integrations.leadhub', false)) {
            return false;
        }

        $facade = self::FACADE;

        if (! class_exists($facade)) {
            return false;
        }

        try {
            $root = $facade::getFacadeRoot();
        } catch (Throwable) {
            return false;
        }

        return $root !== null && method_exists($root, 'ingest');
    }

    /**
     * A failure here never fails the funnel.
     *
     * The visitor is trying to get to the next page. Losing that because a CRM
     * was slow would be the wrong trade, and the address is already saved on
     * the walk either way.
     */
    public function capture(FunnelVisit $visit): void
    {
        if (! $this->available() || ! $visit->email) {
            return;
        }

        try {
            $facade = self::FACADE;
            $facade::ingest([
                'email' => $visit->email,
                'type' => 'funnel_form_submitted',
                'summary' => 'Funnel: '.$visit->funnel->title,
                'source' => 'funnel:'.$visit->funnel->handle,
                'source_type' => 'funnel_visit',
                'source_id' => $visit->getKey(),
                'contact' => array_filter(['full_name' => $visit->name]),
            ]);
        } catch (Throwable $e) {
            Log::warning('statamic-funnels: handing the contact to LeadHub failed; the walk continues.', [
                'visit' => $visit->getKey(),
                'exception' => $e->getMessage(),
            ]);

            return;
        }

        if ((bool) data_get($visit->meta, 'newsletter.opted_in')) {
            $this->grantConsent($visit);
        }
    }

    /**
     * Wer gekauft hat, traegt das Tag `kunde` — und sonst nichts Neues.
     *
     * Kein Consent: ein Kauf ist keine Einwilligung in Werbung. Dass die
     * Kaufbestaetigung trotzdem gehen darf, entscheidet das Transaktionsrecht,
     * nicht dieses Tag.
     */
    public function purchased(FunnelVisit $visit): void
    {
        if (! $this->available() || ! $visit->email) {
            return;
        }

        try {
            $facade = self::FACADE;
            $facade::ingest([
                'email' => $visit->email,
                'type' => 'funnel_purchase',
                'summary' => 'Kauf im Funnel: '.$visit->funnel->title,
                'source' => 'funnel:'.$visit->funnel->handle,
                'source_type' => 'funnel_visit',
                'source_id' => $visit->getKey(),
                'dedupe_key' => 'funnel:'.$visit->getKey().':purchase:'.($visit->payment_id ?? 'x'),
                'tags' => [self::TAG_CUSTOMER],
                'contact' => array_filter(['full_name' => $visit->name]),
            ]);
        } catch (Throwable $e) {
            Log::warning('statamic-funnels: tagging the buyer in LeadHub failed.', [
                'visit' => $visit->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Die Einwilligung setzen, mit dem Weg, der sie kennt.
     *
     * Fehlt der Resolver oder das DTO — eine LeadHub-Fassung, die anders
     * heisst — wird das gesagt, nicht geraten: eine still nicht gesetzte
     * Einwilligung saehe im CRM aus wie ein Kontakt, der nie zugestimmt hat.
     */
    protected function grantConsent(FunnelVisit $visit): void
    {
        $resolver = self::RESOLVER;
        $dto = self::DTO;

        if (! class_exists($resolver) || ! class_exists($dto)) {
            Log::notice('statamic-funnels: LeadHub has no ContactResolver/ContactDto in this version; the newsletter consent was recorded on the visit but not in LeadHub.', [
                'visit' => $visit->getKey(),
            ]);

            return;
        }

        try {
            $service = app($resolver);

            if (! method_exists($service, 'resolveOrCreate')) {
                Log::notice('statamic-funnels: LeadHub\'s ContactResolver has no resolveOrCreate(); consent not handed over.', ['visit' => $visit->getKey()]);

                return;
            }

            $service->resolveOrCreate(new $dto(
                email: $visit->email,
                fullName: $visit->name,
                consent: true,
                source: 'funnel:'.$visit->funnel->handle,
            ));
        } catch (Throwable $e) {
            Log::warning('statamic-funnels: handing the newsletter consent to LeadHub failed.', [
                'visit' => $visit->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
