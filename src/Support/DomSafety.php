<?php

namespace Goldnead\StatamicFunnels\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * What a markup parser and a browser read differently, closed for the two places that put someone's HTML in a frame.
 *
 * libxml (what PHP reads HTML with) takes `<a/href="https://x">` for an element called `a/href`; a browser takes it for a link
 * with an address. A check that looks for `<a>` never sees it, and the frame would carry a live link. Every element whose name
 * is not a plain tag name is therefore turned into an inert `<span>` (its children stay, its attributes go) before the
 * markup is written out again.
 */
final class DomSafety
{
    /** A tag name as HTML has them: letters, digits and hyphens, with an optional namespace prefix (`svg:a`). */
    private const PLAIN = '/^[a-z][a-z0-9-]*(:[a-z][a-z0-9-]*)?$/i';

    public static function flattenOddElements(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        foreach (iterator_to_array($xpath->query('//*') ?: []) as $element) {
            if (! $element instanceof DOMElement || preg_match(self::PLAIN, $element->nodeName) === 1) {
                continue;
            }

            $inert = $document->createElement('span');

            while ($element->firstChild !== null) {
                $inert->appendChild($element->firstChild);
            }

            $element->parentNode?->replaceChild($inert, $element);
        }
    }
}
