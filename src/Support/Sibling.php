<?php

namespace Goldnead\StatamicFunnels\Support;

use Throwable;

/**
 * Eine Methode eines Nachbar-Addons rufen, die es in dessen Fassung geben
 * kann oder nicht.
 *
 * Die Familie wird nicht im Gleichschritt aktualisiert: dieses Addon kann
 * neben einem offers laufen, das `withdrawalTerms()` schon hat, und neben
 * einem, das es noch nicht hat. Beides muss gehen, ohne dass ein Kauf wirft.
 *
 * In einer Klasse, damit die statische Analyse nicht an jeder Stelle meldet,
 * `method_exists` sei „immer wahr" — sie sieht nur die eine Fassung, die
 * gerade im vendor liegt, und genau darauf darf sich der Code nicht verlassen.
 *
 * Nie fuer Facades: eine Facade deklariert nichts von dem, was sie
 * weiterleitet, und `method_exists` auf ihr ist immer falsch.
 */
class Sibling
{
    /** @param  object|string  $target  Ein Objekt oder ein Klassenname, den es geben kann oder nicht. */
    public static function has(object|string $target, string $method): bool
    {
        if (is_string($target) && ! class_exists($target)) {
            return false;
        }

        return method_exists($target, $method);
    }

    /**
     * Der Rueckgabewert, oder null, wenn es die Methode nicht gibt oder sie wirft.
     *
     * @param  list<mixed>  $args
     */
    public static function call(object|string $target, string $method, array $args = []): mixed
    {
        if (! self::has($target, $method)) {
            return null;
        }

        try {
            return is_string($target) ? $target::$method(...$args) : $target->$method(...$args);
        } catch (Throwable) {
            return null;
        }
    }
}
