<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Http\Middleware\MarkPagePreview;
use Goldnead\StatamicFunnels\Tokens\PagePreviewHandler;
use Illuminate\Http\Request;
use Statamic\Contracts\Tokens\TokenRepository;
use Statamic\Facades\Token;

/**
 * The pass that lets the server look at one of its own pages without being counted.
 *
 * A page the application builds itself (a front-end framework with server-side rendering) cannot be made from the entry,
 * so the Probelauf asks the application for it, over a request of its own to the site's address (or to the web server itself, see `page_preview.url`). That request carries this pass
 * in a header, and the middleware ({@see MarkPagePreview}) turns it into the mark
 * ({@see ProbePreview}) that everything which counts asks about.
 *
 * What the pass is, and what it is not:
 *
 * - **It never reaches a browser.** It is minted by the server for the one request the server makes, sent in a header (not in
 *   an address: no access log, no `Referer`), and is gone when that request has been answered. The person at the Control
 *   Panel is never handed it, so there is nothing to copy, paste or share. Whoever may see the Probelauf is decided before
 *   the server asks, by the Probelauf itself.
 * - **It is good for one path and one request.** It names the path it was minted for and is deleted on the first use that
 *   passes; a second request with it finds nothing.
 * - **It expires.** Two minutes, in case the delete never runs because the process died; the platform collects expired
 *   tokens like its own.
 * - **It only looks.** A request that is not a plain GET is never marked.
 */
final class PagePreviewToken
{
    public const HEADER = 'X-Funnels-Page-Preview';

    public const HANDLER = PagePreviewHandler::class;

    public const MINUTES = 2;

    /** A pass for this path (the address of the page, as the application routes it: `/pfad`, no host). */
    public static function mint(string $path): string
    {
        $token = Token::make(null, self::HANDLER, ['path' => $path]);
        $token->expireAt(now()->addMinutes(self::MINUTES));
        $token->save();

        return $token->token();
    }

    /** Throw the pass away. Through the repository: the facade promises a token, the repository admits it may be gone. */
    public static function forget(string $token): void
    {
        app(TokenRepository::class)->find($token)?->delete();
    }

    /** No pass, or none that is good (made up, spent, expired, of another kind, not a GET): an ordinary visit. */
    public const NONE = 'none';

    /** A good pass for the path asked: the request is a look. */
    public const GOOD = 'good';

    /** A good pass, but for another path than the one asked: the request must not be served as a visit either. */
    public const MISMATCH = 'mismatch';

    /**
     * What the pass in the request says. Using it up is part of the answer: asked twice, it says `good` once.
     *
     * @return self::NONE|self::GOOD|self::MISMATCH
     */
    public static function consume(Request $request): string
    {
        $given = $request->headers->get(self::HEADER);

        if (! is_string($given) || $given === '' || ! $request->isMethod('GET')) {
            return self::NONE;
        }

        $token = app(TokenRepository::class)->find($given);

        if ($token === null || $token->handler() !== self::HANDLER || $token->hasExpired()) {
            return self::NONE;
        }

        $path = $token->get('path');

        // One pass, one use: gone before the page is built, whatever the answer is.
        $token->delete();

        return is_string($path) && self::same($path, $request->getPathInfo()) ? self::GOOD : self::MISMATCH;
    }

    /** The same page: an address is the same with its characters written out or not, and with or without a slash at the end. */
    protected static function same(string $minted, string $asked): bool
    {
        $normal = fn (string $path): string => '/'.trim(rawurldecode($path), '/');

        return $normal($minted) === $normal($asked);
    }
}
