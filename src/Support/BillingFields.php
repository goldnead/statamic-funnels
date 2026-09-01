<?php

namespace Goldnead\StatamicFunnels\Support;

use Closure;
use Goldnead\StatamicFunnels\Models\Funnel;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Nodes\CaptureStep;
use Goldnead\StatamicOffers\Models\Offer;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Die Felder des Capture-Schritts, wenn das Angebot sie bestimmt.
 *
 * Kajabi haelt die Felder seitenweit und hakt je Angebot an, welche die Kasse
 * abfragt. In dieser Familie steht die Bibliothek in `statamic-offers`
 * (`Offers::fieldLibrary()`, key => label/type/required/options) und die
 * Auswahl am Angebot (`Offer::checkoutFields()`). Der Capture-Schritt im
 * Modus `offer` liest beides: er sucht im Graphen vorwaerts das naechste
 * Angebot und fragt genau die Felder ab, die dieses Angebot will. So steht
 * „Anschrift" an einer Stelle, und Formular, Kasse und Rechnung lesen dasselbe.
 *
 * Fehlt eines von beiden — aelteres offers, kein Angebot hinter dem Schritt —
 * verhaelt sich der Schritt wie `minimal`, und das Log sagt es.
 */
class BillingFields
{
    /** Was jeder Typ der Bibliothek an Regeln bekommt, wenn er nichts eigenes sagt. */
    protected const RULES = [
        'text' => ['string', 'max:191'],
        'email' => ['email', 'max:191'],
        'tel' => ['string', 'max:64'],
        'number' => ['numeric'],
        'textarea' => ['string', 'max:2000'],
        'select' => ['string', 'max:191'],
        'checkbox' => ['boolean'],
        'country' => ['string', 'size:2', 'alpha'],
    ];

    protected static ?Closure $libraryResolver = null;

    protected static ?Closure $fieldsResolver = null;

    /**
     * @param  (Closure(): (array<string, array<string, mixed>>|null))|null  $resolver
     */
    public static function resolveLibraryUsing(?Closure $resolver): void
    {
        static::$libraryResolver = $resolver;
    }

    /**
     * @param  (Closure(Offer): (list<string>|null))|null  $resolver
     */
    public static function resolveFieldsUsing(?Closure $resolver): void
    {
        static::$fieldsResolver = $resolver;
    }

    /**
     * Die Felder fuer diesen Schritt, oder null, wenn er nicht im Modus `offer`
     * ist oder die Bibliothek nicht erreichbar ist.
     *
     * @return list<array{key: string, label: string, type: string, required: bool, options: array<string, string>, rules: list<string>}>|null
     */
    public static function forStep(Funnel $funnel, FunnelStep $step): ?array
    {
        if ((string) $step->config('billing') !== CaptureStep::BILLING_OFFER) {
            return null;
        }

        $offer = self::nextOffer($funnel, $step);

        if (! $offer) {
            Log::notice('statamic-funnels: a capture step asks for the offer\'s fields, but no offer step follows it; asking for email only.', [
                'funnel' => $funnel->handle,
                'step' => $step->node_key,
            ]);

            return null;
        }

        $keys = self::fields($offer);
        $library = self::library();

        if ($keys === null || $library === null) {
            Log::notice('statamic-funnels: a capture step asks for the offer\'s fields, but the installed statamic-offers has no field library; asking for email only.', [
                'funnel' => $funnel->handle,
                'step' => $step->node_key,
            ]);

            return null;
        }

        $fields = [];

        foreach ($keys as $key) {
            $key = (string) $key;

            if ($key === '' || $key === 'email' || ! isset($library[$key])) {
                // `email` fragt der Schritt ohnehin; ein Schluessel, den die
                // Bibliothek nicht kennt, hat kein Label und keinen Typ.
                continue;
            }

            $def = (array) $library[$key];

            $fields[] = [
                'key' => $key,
                'label' => (string) ($def['label'] ?? $key),
                'type' => (string) ($def['type'] ?? 'text'),
                'required' => (bool) ($def['required'] ?? false),
                'options' => self::options($def['options'] ?? null),
                'rules' => array_values(array_filter((array) ($def['rules'] ?? []), 'is_string')),
            ];
        }

        return $fields;
    }

    /**
     * Die Regeln aus der Bibliothek: `required` je Feld, der Rest nach Typ.
     *
     * @param  list<array{key: string, label: string, type: string, required: bool, options: array<string, string>, rules?: list<string>}>  $fields
     * @return array<string, list<mixed>>
     */
    public static function rules(array $fields): array
    {
        $rules = [];

        foreach ($fields as $field) {
            $own = self::RULES[$field['type']] ?? self::RULES['text'];

            if ($field['type'] === 'select' && $field['options'] !== []) {
                $own = [Rule::in(array_keys($field['options']))];
            }

            // Was die Bibliothek selbst an Regeln mitgibt, kommt dazu — eine
            // USt-IdNr hat ein Muster, das dieses Addon nicht kennen muss.
            $rules[$field['key']] = array_values(array_unique(array_merge(
                [$field['required'] ? 'required' : 'nullable'],
                $own,
                $field['rules'] ?? [],
            ), SORT_REGULAR));
        }

        return $rules;
    }

    /**
     * Das naechste Angebot hinter diesem Schritt — vorwaerts im Graphen, an
     * Mails vorbei, der erste Angebots-Knoten.
     */
    public static function nextOffer(Funnel $funnel, FunnelStep $step): ?Offer
    {
        $queue = [$step->node_key];
        $seen = [$step->node_key => true];

        while ($queue !== []) {
            $key = array_shift($queue);

            foreach ($funnel->edges->where('from_node_key', $key) as $edge) {
                $to = $edge->to_node_key;

                if (isset($seen[$to])) {
                    continue;
                }

                $seen[$to] = true;
                $target = $funnel->stepByKey($to);

                if (! $target || $target->type === 'mail') {
                    continue;
                }

                if ($target->type === 'offer') {
                    $handle = (string) $target->config('offer');

                    return $handle === '' ? null : Offer::query()->where('handle', $handle)->first();
                }

                $queue[] = $to;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    protected static function fields(Offer $offer): ?array
    {
        try {
            $keys = static::$fieldsResolver
                ? (static::$fieldsResolver)($offer)
                : Sibling::call($offer, 'checkoutFields');
        } catch (Throwable) {
            return null;
        }

        return is_array($keys) ? array_values(array_map('strval', $keys)) : null;
    }

    /**
     * @return array<string, array<string, mixed>>|null
     */
    protected static function library(): ?array
    {
        if (static::$libraryResolver) {
            $library = (static::$libraryResolver)();

            return is_array($library) ? $library : null;
        }

        // Eine gewoehnliche statische Klasse in statamic-offers, keine Facade —
        // deshalb darf `method_exists` hier gefragt werden.
        $library = Sibling::call('\Goldnead\StatamicOffers\Offers', 'fieldLibrary');

        return is_array($library) && $library !== [] ? $library : null;
    }

    /**
     * Optionen in eine Form: value => label.
     *
     * @return array<string, string>
     */
    protected static function options(mixed $options): array
    {
        if (! is_array($options)) {
            return [];
        }

        $out = [];

        foreach ($options as $key => $value) {
            if (is_array($value)) {
                $out[(string) ($value['value'] ?? $key)] = (string) ($value['label'] ?? $value['value'] ?? $key);
            } elseif (is_int($key)) {
                $out[(string) $value] = (string) $value;
            } else {
                $out[(string) $key] = (string) $value;
            }
        }

        return $out;
    }
}
