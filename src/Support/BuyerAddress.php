<?php

namespace Goldnead\StatamicFunnels\Support;

/**
 * Wer kauft, muss vorher seine Adresse angegeben haben.
 *
 * Die Adresse kommt aus einem Formular-Schritt (`capture`). Ein Funnel, der zur
 * Kasse fuehrt, ohne dass davor einer steht, verkaufte anonym: Zahlung ohne
 * Kaeufer, ohne Zugang, Rechnung ohne Empfaenger (Staging, Zahlung 99). Der
 * Editor prueft das beim Live-Schalten, die Kasse noch einmal zur Laufzeit.
 */
class BuyerAddress
{
    /** Der Schritt, der die Adresse erhebt. */
    public const COLLECTING_TYPE = 'capture';

    /** Der Schritt, der verkauft. */
    public const CHECKOUT_TYPE = 'offer';

    /**
     * Die Kassen-Schritte, vor denen kein eingeschalteter Formular-Schritt liegt.
     *
     * "Davor" heisst: auf irgendeinem Weg vom Einstieg zur Kasse. Ein Formular
     * auf einem Seitenzweig, den die Kasse nie erreicht, zaehlt nicht, ein
     * abgeschalteter Schritt auch nicht (er wird uebersprungen, nichts wird
     * erhoben). Ein Upsell hinter einer Kasse erbt, was davor steht.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $edges
     * @return list<string> Beschriftung (sonst Schluessel) je betroffener Kasse
     */
    public static function checkoutsWithoutForm(array $nodes, array $edges): array
    {
        $parents = [];

        foreach ($edges as $edge) {
            $parents[(string) ($edge['to_node_key'] ?? '')][] = (string) ($edge['from_node_key'] ?? '');
        }

        $byKey = [];

        foreach ($nodes as $node) {
            $byKey[(string) ($node['node_key'] ?? '')] = $node;
        }

        $open = [];

        foreach ($byKey as $key => $node) {
            if (($node['type'] ?? null) !== self::CHECKOUT_TYPE || ! empty($node['disabled'])) {
                continue;
            }

            if (! self::hasFormBefore($key, $parents, $byKey)) {
                $label = trim((string) ($node['label'] ?? ''));
                $open[] = $label !== '' ? $label : $key;
            }
        }

        return $open;
    }

    /**
     * @param  array<string, list<string>>  $parents
     * @param  array<string, array<string, mixed>>  $byKey
     */
    protected static function hasFormBefore(string $key, array $parents, array $byKey): bool
    {
        $seen = [$key => true];
        $queue = $parents[$key] ?? [];

        while ($queue !== []) {
            $current = array_shift($queue);

            if (isset($seen[$current])) {
                continue;
            }

            $seen[$current] = true;
            $node = $byKey[$current] ?? null;

            if ($node && ($node['type'] ?? null) === self::COLLECTING_TYPE && empty($node['disabled'])) {
                return true;
            }

            array_push($queue, ...($parents[$current] ?? []));
        }

        return false;
    }
}
