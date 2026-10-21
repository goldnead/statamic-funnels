<?php

namespace Goldnead\StatamicFunnels\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Thumbnails\Thumbnails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Log;
use Illuminate\View\FileViewFinder;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;
use Statamic\Http\Responses\DataResponse;
use Throwable;

/**
 * The page of a Statamic entry, as a person on the Probelauf (dry run) would see it, without anyone visiting it.
 *
 * The page is rendered here, on the server, through Statamic's own response for the entry (the one the front end
 * uses for any entry it serves, with the entry's template and layout), on a request that is marked as a look
 * ({@see ProbePreview}) so that nothing which counts, counts it. What comes back is the markup of the page made
 * safe, to be shown in an iframe with an empty `sandbox`:
 *
 * - no script runs there (the sandbox) and none is left in the markup either, nor a `<noscript>` (a sandbox with no
 *   scripts shows a `<noscript>` pixel, which is a request that counts), a frame, an object or a form's action;
 * - a policy on the document lets it load stylesheets, images and fonts from the site itself and nothing from
 *   anywhere else, so an analytics script's host, a pixel or a web font of a third party is never asked;
 * - every link is kept as text with its target remembered (`data-sim-href`) and has no `href` left, so a click does
 *   nothing: the lead magnet's confirmation link, a download counter and a smartlink are never followed, only shown.
 *
 * Not every page can be rendered here. A page that the application serves through a route of its own (a controller
 * that builds it with a front-end framework) is no Statamic response and is not requested over the network to find
 * out; that, a draft, a protected page and a render that fails answer `unavailable` with the reason, and the caller
 * shows what it has instead (the picture of a funnel step that names the entry, the address).
 *
 * One limit: the render runs in the process of the Control Panel request, so what a template reads of "the user" or the
 * session (`{{ user }}`, a CSRF field) is the Control Panel user's, not an anonymous visitor's. The page is shown to that
 * same user, and a page that looks different to a signed-in visitor is seen as that user would see it.
 */
class PagePreview
{
    /** A page bigger than this is not shipped to the browser as a string. */
    public const MAX_BYTES = 1_500_000;

    public const LINKS = 40;

    public const GONE = 'gone';

    public const DRAFT = 'draft';

    public const NO_ADDRESS = 'no_address';

    /** Served by a route of the application, not by Statamic: it cannot be built from the entry. */
    public const APP_ROUTE = 'app_route';

    /** Redirect, 404, protected: what a visitor would not see as this page. */
    public const NOT_A_PAGE = 'not_a_page';

    public const TOO_BIG = 'too_big';

    public const FAILED = 'failed';

    /**
     * @return array{state: 'rendered', url: string, address: string, html: string, links: list<array{text: string, url: string}>}|array{state: 'unavailable', reason: string, url: string|null, address: string|null, thumbnail: string|null}
     */
    public function render(string $entryId): array
    {
        $entry = Entry::find($entryId);

        if (! $entry instanceof StatamicEntry) {
            return $this->unavailable(self::GONE, null, null);
        }

        $url = $entry->url();

        if (! $entry->published()) {
            return $this->unavailable(self::DRAFT, $url, $entry);
        }

        if (! is_string($url) || $url === '') {
            return $this->unavailable(self::NO_ADDRESS, null, $entry);
        }

        $request = ProbePreview::mark(Request::create((string) $entry->absoluteUrl(), 'GET'));

        if (! $this->servedByStatamic($request)) {
            return $this->unavailable(self::APP_ROUTE, $url, $entry);
        }

        try {
            $html = $this->response($entry, $request);
        } catch (Throwable $e) {
            // The class and the entry, not the message: a template error can quote whatever it was rendering.
            Log::info('statamic-funnels: a page could not be rendered for the dry run.', ['entry' => $entryId, 'exception' => $e::class]);

            return $this->unavailable(self::FAILED, $url, $entry);
        }

        if ($html === null) {
            return $this->unavailable(self::NOT_A_PAGE, $url, $entry);
        }

        if (strlen($html) > self::MAX_BYTES) {
            return $this->unavailable(self::TOO_BIG, $url, $entry);
        }

        $made = $this->safe($html, $request);

        return ['state' => 'rendered', 'url' => $url, 'address' => $this->address($entry), 'html' => $made['html'], 'links' => $made['links']];
    }

    /** Whether the front end hands this address to Statamic (the catch-all) and not to a route of the application. */
    protected function servedByStatamic(Request $request): bool
    {
        try {
            return app('router')->getRoutes()->match($request)->getName() === 'statamic.site';
        } catch (Throwable) {
            return false;
        }
    }

    /** The markup Statamic answers for the entry, or null when it is not a page (a redirect, an error, an empty answer). */
    protected function response(StatamicEntry $entry, Request $request): ?string
    {
        $previousRequest = app('request');
        $site = Site::current()->handle();
        /** @var FileViewFinder $finder */
        $finder = view()->getFinder();
        $paths = $finder->getPaths();

        // The response reads the current request here and there; for the length of the render it is the look.
        app()->instance('request', $request);
        Facade::clearResolvedInstance('request');
        Site::setCurrent($entry->locale());

        try {
            $response = (new DataResponse($entry))
                ->with(['probe_preview' => true, 'live_preview' => true])
                ->toResponse($request);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $content = $response->getContent();

            return is_string($content) && trim($content) !== '' ? $content : null;
        } finally {
            // The response widens the view paths by the site's folder every time it runs.
            $finder->setPaths($paths);
            Site::setCurrent($site);
            app()->instance('request', $previousRequest);
            Facade::clearResolvedInstance('request');
        }
    }

    /**
     * The markup, made safe, with the policy around it.
     *
     * @return array{html: string, links: list<array{text: string, url: string}>}
     */
    protected function safe(string $html, Request $request): array
    {
        $origin = $request->getSchemeAndHttpHost();
        $links = [];

        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        // An element the parser reads as a name and a browser as a link (`<a/href=…>`) is made inert first.
        DomSafety::flattenOddElements($document);

        $xpath = new DOMXPath($document);

        foreach (['script', 'noscript', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'base', 'audio', 'video', 'source', 'track', 'portal', 'fencedframe', 'template'] as $name) {
            foreach (iterator_to_array($xpath->query('//'.$name) ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        // A meta that redirects or sets its own policy has no place beside ours.
        foreach (iterator_to_array($xpath->query('//meta[@http-equiv]') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        foreach (iterator_to_array($xpath->query('//comment()') ?: []) as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Only a stylesheet of the site itself; a preload or prefetch of anything is a request nobody asked for.
        foreach (iterator_to_array($xpath->query('//link') ?: []) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $rel = strtolower((string) $node->getAttribute('rel'));
            $href = trim((string) $node->getAttribute('href'));

            if (! str_contains($rel, 'stylesheet') || ! $this->ofTheSite($href, $origin)) {
                $node->parentNode?->removeChild($node);
            }
        }

        foreach (iterator_to_array($xpath->query('//*') ?: []) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);

                if (str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'ping', 'formaction', 'action', 'poster'], true)) {
                    $element->removeAttribute($attribute->nodeName);
                }
            }

            if (in_array(strtolower($element->tagName), ['a', 'area'], true)) {
                $this->neutralize($element, $origin, $links);
            }
        }

        $head = $xpath->query('//head')->item(0);

        if (! $head instanceof DOMElement) {
            $head = $document->createElement('head');
            $root = $document->getElementsByTagName('html')->item(0);

            if ($root === null) {
                $root = $document->appendChild($document->createElement('html'));
            }

            $root->insertBefore($head, $root->firstChild);
        }

        $csp = $document->createElement('meta');
        $csp->setAttribute('http-equiv', 'Content-Security-Policy');
        $csp->setAttribute('content', "default-src 'none'; style-src 'unsafe-inline' {$origin}; img-src {$origin} data:; font-src {$origin} data:; form-action 'none'");
        $charset = $document->createElement('meta');
        $charset->setAttribute('charset', 'utf-8');
        $base = $document->createElement('base');
        $base->setAttribute('href', $origin.'/');
        // Whatever link might be left opens "in a new window", which a sandbox without `allow-popups` refuses: a second line of defence
        // behind the removal of every address.
        $base->setAttribute('target', '_blank');

        // The policy first, so that it covers everything after it; the base next, so that the site's relative addresses
        // are read against the site and not against the screen that shows the frame.
        foreach ([$base, $csp, $charset] as $node) {
            $head->insertBefore($node, $head->firstChild);
        }

        return ['html' => '<!doctype html>'.$document->saveHTML($document->documentElement), 'links' => $links];
    }

    protected function ofTheSite(string $address, string $origin): bool
    {
        if ($address === '' || str_starts_with($address, '//')) {
            return false;
        }

        return str_starts_with($address, '/') || str_starts_with($address, $origin.'/');
    }

    /** @param  list<array{text: string, url: string}>  $links */
    protected function neutralize(DOMElement $element, string $origin, array &$links): void
    {
        // A browser throws tabs, newlines and control characters out of an address before it reads the scheme.
        $target = preg_replace('/[\x00-\x20\x7f]+/', '', $element->getAttribute('href') ?: $element->getAttribute('xlink:href')) ?? '';

        // No `href` at all, not even `#`: with the base element in the document, a bare `#` is read against the site and a click on it
        // would load the site's front page into the frame, which is a visit. A link without an address is not a link; its target is
        // remembered below, and shown by the screen.
        foreach (['href', 'xlink:href', 'target', 'rel'] as $name) {
            $element->removeAttribute($name);
        }

        if ($target === '' || str_starts_with($target, '#') || ! preg_match('~^(https?:|mailto:|tel:|/(?!/))~i', $target)) {
            return;
        }

        $element->setAttribute('data-sim-href', $target);

        if (count($links) >= self::LINKS) {
            return;
        }

        $text = trim(preg_replace('/\s+/', ' ', $element->textContent) ?? '');
        $shown = str_starts_with($target, $origin.'/') ? substr($target, strlen($origin)) : $target;

        foreach ($links as $known) {
            if ($known['url'] === $shown && $known['text'] === ($text !== '' ? mb_strimwidth($text, 0, 120, '…') : $shown)) {
                return;
            }
        }

        $links[] = ['text' => $text !== '' ? mb_strimwidth($text, 0, 120, '…') : $shown, 'url' => $shown];
    }

    /** @return array{state: 'unavailable', reason: string, url: string|null, address: string|null, thumbnail: string|null} */
    protected function unavailable(string $reason, ?string $url, ?StatamicEntry $entry): array
    {
        return [
            'state' => 'unavailable',
            'reason' => $reason,
            'url' => $url,
            'address' => $url !== null && $entry ? $this->address($entry) : null,
            'thumbnail' => $entry ? $this->thumbnail($entry) : null,
        ];
    }

    /** The address as a visitor reads it: the host of the site and the path, without the scheme. */
    protected function address(StatamicEntry $entry): string
    {
        $parts = parse_url((string) $entry->absoluteUrl()) ?: [];

        return ($parts['host'] ?? '').(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '');
    }

    /** The picture of a funnel step that shows this entry, when there is one: what stands in for a page that cannot be rendered. */
    protected function thumbnail(StatamicEntry $entry): ?string
    {
        $step = FunnelStep::query()
            ->where('type', 'entry')
            ->get()
            ->first(fn (FunnelStep $s) => ($s->config['entry'] ?? null) === (string) $entry->id());

        return $step ? Thumbnails::url($step) : null;
    }
}
