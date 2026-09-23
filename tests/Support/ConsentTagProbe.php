<?php

namespace Goldnead\StatamicFunnels\Tests\Support;

use Statamic\Tags\Tags;

/**
 * Steht fuer `{{ consent:head }}` und `{{ consent:banner }}` von
 * goldnead/statamic-consent, das diese Suite nicht installiert. Gibt nur
 * Markierungen aus, an denen ein Test sieht, dass die Vorlage sie ruft.
 */
class ConsentTagProbe extends Tags
{
    protected static $handle = 'consent';

    public function head(): string
    {
        return '<!--consent-head-->';
    }

    public function banner(): string
    {
        return '<!--consent-banner-->';
    }
}
