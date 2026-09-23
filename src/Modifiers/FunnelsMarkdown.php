<?php

namespace Goldnead\StatamicFunnels\Modifiers;

use Illuminate\Support\Str;
use Statamic\Modifiers\Modifier;

/**
 * Markdown aus dem CP, ohne HTML und ohne unsichere Links.
 *
 * `sanitize | markdown` maskierte zu frueh: Zitate (`>`) und Autolinks
 * (`<https://…>`) kamen woertlich an, und `[x](javascript:…)` blieb ein Link.
 * CommonMark selbst maskiert HTML und verwirft `javascript:`, `vbscript:`,
 * `file:` und `data:` (ausser Bildern).
 */
class FunnelsMarkdown extends Modifier
{
    protected static $handle = 'funnels_markdown';

    public function index($value): string
    {
        return Str::markdown((string) $value, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }
}
