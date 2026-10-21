<?php

namespace Goldnead\StatamicFunnels\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Thumbnails\Thumbnails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
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
 * A page that the application serves through a route of its own (a controller that builds it with a front-end
 * framework, as most pages of adriangoldner.com) is no Statamic response. It is asked of the application itself
 * ({@see fromApplication()}): a request of the server to the site's own address, marked as a look by a pass that only the server holds
 * ({@see PagePreviewToken}), answered with the markup the application's server-side rendering produces. That markup goes
 * through the same safety as any other: it is shown without a single script (the page the person sees is the page as the
 * server drew it, not as a browser would redraw it).
 *
 * A draft, a protected page, a render that fails and an application page where this is switched off answer `unavailable`
 * with the reason, and the caller shows what it has instead (the picture of a funnel step that names the entry, the address).
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
        $this->inlined = 0;

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

        $byApplication = ! $this->servedByStatamic($request);

        if ($byApplication && ! config('statamic-funnels.page_preview.app_pages', true)) {
            return $this->unavailable(self::APP_ROUTE, $url, $entry);
        }

        try {
            $html = $byApplication ? $this->fromApplication($entry, $url) : $this->response($entry, $request);
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

    /**
     * The markup the application answers for the page it builds itself (a front-end framework with server-side rendering),
     * or null when it is not a page.
     *
     * Asked over a request of its own: the application's own routes, middleware and renderer run exactly as for a visitor,
     * and none of it shares the request, the session or the user of the Control Panel. The request is marked as a look by a
     * pass that only this server holds ({@see PagePreviewToken}); redirects are not followed, because a redirect is no page,
     * and the pass is gone when the answer is in.
     *
     * Where the request goes is not a thing a person can steer. The path is the entry's own. The host is the entry's host, and
     * only when it is one of the site's own (the application's address and the addresses of its sites); a host that arrives in
     * the Control Panel request and is not one of them is refused, because with a site address of `/` the entry's host is
     * whatever the caller's `Host` header said. The place to connect to is that host's own address, or `page_preview.url`
     * (the web server itself, for a host that has a login or a proxy in front of its public address): the pass travels there
     * and nowhere else.
     *
     * @throws \RuntimeException when the host is not the site's own, or the site answers with an error (the caller says "failed")
     */
    protected function fromApplication(StatamicEntry $entry, string $path): ?string
    {
        $parts = parse_url((string) $entry->absoluteUrl()) ?: [];
        $host = strtolower((string) ($parts['host'] ?? '')).(isset($parts['port']) ? ':'.$parts['port'] : '');

        // The path of the entry, and only that: a path that is not one (a scheme-relative `//host`) is no page of this site.
        if ($host === '' || ! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\')) {
            return null;
        }

        if (! in_array($host, $this->ownHosts(), true)) {
            throw new \RuntimeException('the host of the page is not one of the site\'s own');
        }

        $scheme = ($parts['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $base = rtrim((string) (config('statamic-funnels.page_preview.url') ?: $scheme.'://'.$host), '/');
        $token = PagePreviewToken::mint($path);

        try {
            $response = Http::withHeaders([
                PagePreviewToken::HEADER => $token,
                'Host' => $host,
                'X-Forwarded-Proto' => $scheme,
                'X-Forwarded-Host' => $host,
                'Accept' => 'text/html',
            ])
                ->withoutRedirecting()
                ->timeout(20)
                ->get($base.$path);
        } finally {
            PagePreviewToken::forget($token);
        }

        $status = $response->status();

        // A redirect or a missing page is no page ("not a page"); a refusal, a failure of the site or of the renderer is not
        // "what a visitor would see" either, it is a failure, and is said so.
        if ($status >= 300 && $status < 400 || $status === 404 || $status === 410) {
            return null;
        }

        if ($status !== 200) {
            Log::info('statamic-funnels: the site answered a page of the dry run with an error.', ['status' => $status, 'path' => $path]);

            throw new \RuntimeException('the site answered '.$status);
        }

        if (! str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')) {
            return null;
        }

        $body = $response->body();

        return trim($body) !== '' ? $body : null;
    }

    /**
     * The hosts that are the site's own: the application's address and the written-out addresses of the Statamic sites.
     *
     * @return list<string>
     */
    protected function ownHosts(): array
    {
        $hosts = [];

        // Only a site whose address is written out in its configuration: one that is `/` takes its host from the request in hand.
        foreach (array_merge([(string) config('app.url')], Site::all()->map(fn ($site) => (string) $site->url())->all()) as $address) {
            $parts = parse_url($address) ?: [];

            if (isset($parts['host'])) {
                $hosts[] = strtolower($parts['host']).(isset($parts['port']) ? ':'.$parts['port'] : '');
            }
        }

        return array_values(array_unique($hosts));
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

                continue;
            }

            // The frame has no origin (an empty `sandbox`), and a browser fetches a font or a mask image of another origin only when
            // that origin allows it with a CORS header, which a site's static files do not send: the page would show in a fallback
            // font and without its drawn strokes. So the site's own stylesheet is read here and put into the page, with the fonts and
            // the vector images it names as data. If that does not work the link stays as it is (the page shows, a little plainer).
            $inline = $this->inlineStylesheet($href, $origin);

            if ($inline !== null) {
                $style = $document->createElement('style');
                $style->appendChild($document->createTextNode($inline));
                $node->parentNode?->replaceChild($style, $node);
            }
        }

        foreach (iterator_to_array($xpath->query('//*') ?: []) as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);

                if (str_starts_with($name, 'on') || in_array($name, ['srcdoc', 'ping', 'formaction', 'action', 'poster', 'data-page'], true)) {
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

    /** The most of font and vector data that one page takes into itself, in bytes, and the most of one file. */
    private const INLINE_BUDGET = 900_000;

    private const INLINE_FILE = 300_000;

    private const INLINE_TYPES = ['woff2' => 'font/woff2', 'woff' => 'font/woff', 'ttf' => 'font/ttf', 'otf' => 'font/otf', 'svg' => 'image/svg+xml'];

    private int $inlined = 0;

    /** One file of the site, by its path, over the same road as the page itself (see {@see fromApplication()}). Null when it is not there. */
    protected function siteFile(string $path, string $origin, ?string $contentType = null): ?string
    {
        $parts = parse_url($origin) ?: [];
        $host = strtolower((string) ($parts['host'] ?? '')).(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (! str_starts_with($path, '/') || str_starts_with($path, '//') || str_contains($path, '\\') || ! in_array($host, $this->ownHosts(), true)) {
            return null;
        }

        $base = rtrim((string) (config('statamic-funnels.page_preview.url') ?: $origin), '/');

        try {
            $response = Http::withHeaders(['Host' => $host])->withoutRedirecting()->timeout(8)->get($base.$path);
        } catch (Throwable) {
            return null;
        }

        if ($response->status() !== 200 || $response->body() === '') {
            return null;
        }

        if ($contentType !== null && ! str_contains(strtolower((string) $response->header('Content-Type')), $contentType)) {
            return null;
        }

        return $response->body();
    }

    /** The stylesheet at this address with every font and vector image it names written into it as data; null when it cannot be read. */
    protected function inlineStylesheet(string $href, string $origin): ?string
    {
        $path = str_starts_with($href, '/') ? $href : substr($href, strlen($origin));
        $css = $this->siteFile($path, $origin, 'text/css');

        // A stylesheet has no use for `</`, and inside a `<style>` it is the one thing that ends the element and lets what follows
        // become markup: a file that carries it is not put into the page.
        if ($css === null || strlen($css) > 600_000 || str_contains($css, '</') || str_contains($css, '<!--')) {
            return null;
        }

        $directory = dirname((string) parse_url($path, PHP_URL_PATH));

        return preg_replace_callback('/url\(\s*(["\']?)([^)"\']+)\1\s*\)/', function (array $match) use ($directory, $origin) {
            $ref = trim($match[2]);

            if ($ref === '' || str_starts_with($ref, 'data:') || str_starts_with($ref, '#') || str_starts_with($ref, '//')) {
                return $match[0];
            }

            if (str_starts_with($ref, $origin.'/')) {
                $ref = substr($ref, strlen($origin));
            } elseif (preg_match('~^[a-z][a-z0-9+.-]*:~i', $ref) === 1 || str_contains($ref, '\\')) {
                // Another origin: left as it is, the page's policy does not let it load.
                return $match[0];
            }

            $target = str_starts_with($ref, '/') ? $ref : $directory.'/'.$ref;
            $file = (string) parse_url($target, PHP_URL_PATH);
            $segments = [];

            foreach (explode('/', $file) as $segment) {
                if ($segment === '..') {
                    array_pop($segments);
                } elseif ($segment !== '' && $segment !== '.') {
                    $segments[] = $segment;
                }
            }

            $file = '/'.implode('/', $segments);
            $type = self::INLINE_TYPES[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? null;

            // Whatever is not taken in stays a link to the site, written out in full so that it means the same wherever the style sits.
            $stays = 'url("'.$origin.$file.'")';

            // A font package ships one file per script (`-latin-ext-`, `-cyrillic-`, ...) and the browser asks only for the ones whose
            // characters a page uses; the page of a German site uses Latin. The others stay links (they would not load, and are not needed).
            $otherScript = preg_match('/-(latin-ext|cyrillic(-ext)?|greek(-ext)?|vietnamese|hebrew|arabic|devanagari|thai)-/i', basename($file)) === 1;

            if ($type === null || $otherScript || $this->inlined >= self::INLINE_BUDGET) {
                return $stays;
            }

            $data = $this->siteFile($file, $origin);

            if ($data === null || strlen($data) > self::INLINE_FILE || $this->inlined + strlen($data) > self::INLINE_BUDGET) {
                return $stays;
            }

            $this->inlined += strlen($data);

            return 'url("data:'.$type.';base64,'.base64_encode($data).'")';
        }, $css);
    }

    protected function ofTheSite(string $address, string $origin): bool
    {
        // A browser reads a backslash as a slash: `/\evil.example/x.css` is `//evil.example/x.css`.
        if ($address === '' || str_starts_with($address, '//') || str_contains($address, '\\')) {
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

        $shown = str_starts_with($target, $origin.'/') ? substr($target, strlen($origin)) : $target;

        // The list is the links of the page's own content. What an embed brings along (the privacy notice of a video host, in the
        // placeholder that stands where the video would load) is not one of them, and a place that is linked twice is listed once.
        if ($this->ofAnEmbed($element, $shown) || $this->ofTheSiteFrame($element)) {
            return;
        }

        $text = trim(preg_replace('/\s+/', ' ', $element->textContent) ?? '');
        $text = $text !== '' ? mb_strimwidth($text, 0, 120, '…') : $shown;
        $key = $this->destination($shown);

        foreach ($links as $known) {
            if ($this->destination($known['url']) === $key) {
                return;
            }
        }

        if (count($links) >= self::LINKS) {
            return;
        }

        $links[] = ['text' => $text, 'url' => $shown];
    }

    /** The notices an embedded service puts on a page (privacy, terms), which no page of the site wrote. Host, then path. */
    private const EMBED_NOTICES = [
        ['policies.google.com', '~.*~'],
        ['google.com', '~^/(policies|intl/[^/]+/policies)~'],
        ['vimeo.com', '~^/(privacy|terms|cookie_policy)~'],
        ['player.vimeo.com', '~.*~'],
        ['youtube.com', '~^/(t/(privacy|terms)|static|howyoutubeworks|about/policies)~'],
        ['youtube-nocookie.com', '~.*~'],
        ['spotify.com', '~^/(legal|[a-z-]+/legal)~'],
        ['soundcloud.com', '~^/(pages|terms-of-use)~'],
    ];

    /** Whether the link belongs to an embedded service: it is the service's own notice, or it sits in an embed's placeholder. */
    protected function ofAnEmbed(DOMElement $element, string $target): bool
    {
        $host = (string) preg_replace('/^www\./', '', strtolower((string) parse_url($target, PHP_URL_HOST)));
        $path = (string) parse_url($target, PHP_URL_PATH);

        foreach (self::EMBED_NOTICES as [$known, $paths]) {
            if (($host === $known || str_ends_with($host, '.'.$known)) && preg_match($paths, $path === '' ? '/' : $path) === 1) {
                return true;
            }
        }

        // The placeholder of an embed, named in the way embeds usually are (a class or an id with `embed`, `consent` or `oembed`).
        // The page's own wrappers (`html`, `body`, `main`) are not an embed, whatever state class they carry (`has-consent`).
        for ($node = $element->parentNode; $node instanceof DOMElement && ! in_array(strtolower($node->tagName), ['html', 'body', 'main'], true); $node = $node->parentNode) {
            $name = strtolower($node->getAttribute('class').' '.$node->getAttribute('id').' '.$node->getAttribute('data-embed'));

            if (preg_match('/(^|[\s_-])(o?embed|consent|video-?placeholder|iframe-?placeholder|cookie-?banner)($|[\s_-])/', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the link belongs to the site's frame (its menu, the header above the content, the footer) and not to the page's own
     * content: a person who reads the page reads those links on every page, and they would fill the list before the page's own do.
     * A `header` inside the content (`main`, `article`, `section`) is the page's own and stays.
     */
    protected function ofTheSiteFrame(DOMElement $element): bool
    {
        $header = false;
        $content = false;

        for ($node = $element->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
            $tag = strtolower($node->tagName);
            $role = strtolower($node->getAttribute('role'));

            if ($tag === 'nav' || $tag === 'footer' || in_array($role, ['navigation', 'contentinfo'], true)) {
                return true;
            }

            $header = $header || $tag === 'header' || $role === 'banner';
            $content = $content || in_array($tag, ['main', 'article', 'section'], true);
        }

        return $header && ! $content;
    }

    /** The place a link leads to, as one string: the same place linked with a slash or a fragment more is the same place. */
    protected function destination(string $address): string
    {
        $address = (string) preg_replace('/#.*$/', '', $address);

        return strtolower(rtrim($address, '/')) ?: '/';
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
