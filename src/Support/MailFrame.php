<?php

namespace Goldnead\StatamicFunnels\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The mail of the dry run, after the owner wrote it and brand-context made it safe: a last touch for the screen.
 *
 * Brand-context switches every image off and leaves its place as an `<img>` with no source (a broken-image box, or
 * nothing). On the screen that reads as a defect in the mail. Here each of those becomes a quiet area with the image's
 * alternative text, which is what a person who has images off sees too. Only markup that is already safe goes in and
 * comes out; nothing is added that loads or runs.
 *
 * The frame the mail sits in has an empty `sandbox` (no origin, no scripts, no popups), and a second line of defence
 * stands here anyway for what brand-context already does, and is not trusted to have worked: an address in a link or in an svg link,
 * an element the parser reads differently from a browser ({@see DomSafety}) and a meta that redirects are taken out again, and
 * every link that is left opens "in a new window", which a sandbox without `allow-popups` refuses. Whatever got past the first
 * layer cannot send the frame anywhere.
 */
final class MailFrame
{
    private const STYLE = '.sim-img{box-sizing:border-box;display:block;margin:12px 0;padding:28px 16px;border-radius:6px;background:#f4f4f5;color:#6b7280;'
        .'font:italic 13px/1.5 system-ui,sans-serif;text-align:center}'
        .'.sim-img svg{display:inline-block;vertical-align:-3px;margin-right:6px;width:16px;height:16px;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}';

    private const ICON = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect width="18" height="18" x="3" y="3" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/></svg>';

    /**
     * @param  string  $word  what an image without an alternative text is called ("Bild")
     */
    public static function calm(string $html, string $word): string
    {
        if (trim($html) === '') {
            return $html;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        DomSafety::flattenOddElements($document);

        $xpath = new DOMXPath($document);
        $blocked = 0;

        foreach (iterator_to_array($xpath->query('//img[@data-sim-blocked]') ?: []) as $image) {
            if (! $image instanceof DOMElement) {
                continue;
            }

            $blocked++;
            $alt = trim($image->getAttribute('alt'));
            $area = $document->createElement('div');
            $area->setAttribute('class', 'sim-img');
            $area->setAttribute('role', 'img');
            $area->setAttribute('aria-label', $alt !== '' ? $alt : $word);

            $icon = $document->createDocumentFragment();
            $icon->appendXML(self::ICON);
            $area->appendChild($icon);
            $area->appendChild($document->createTextNode($alt !== '' ? $alt : $word));

            $image->parentNode?->replaceChild($area, $image);
        }

        foreach (iterator_to_array($xpath->query('//*') ?: []) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);

                if ($name === 'xlink:href') {
                    $element->removeAttribute($attribute->nodeName);
                } elseif ($name === 'href' && trim($attribute->nodeValue ?? '') !== '#') {
                    $element->setAttribute($attribute->nodeName, '#');
                }
            }
        }

        foreach (iterator_to_array($xpath->query('//meta[@http-equiv]') ?: []) as $meta) {
            if ($meta instanceof DOMElement && strtolower(trim($meta->getAttribute('http-equiv'))) === 'refresh') {
                $meta->parentNode?->removeChild($meta);
            }
        }

        $head = $xpath->query('//head')->item(0);

        if ($head instanceof DOMElement) {
            // Whatever link is left opens somewhere else, and a frame that may not open windows opens nothing.
            $base = $document->createElement('base');
            $base->setAttribute('target', '_blank');
            $head->insertBefore($base, $head->firstChild);

            if ($blocked > 0) {
                $head->appendChild($document->createElement('style', self::STYLE));
            }
        }

        return '<!doctype html>'.$document->saveHTML($document->documentElement);
    }
}
