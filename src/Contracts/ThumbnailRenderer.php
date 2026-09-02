<?php

namespace Goldnead\StatamicFunnels\Contracts;

/**
 * Something that can turn a page into a picture.
 *
 * One method, on purpose. The job that wants a thumbnail does not care whether
 * a browser, a service or a test double produced it; it cares that it gets PNG
 * bytes or a clear "not here". Two implementations ship: Browsershot when a
 * Chromium can be found, and a null one that renders nothing and says so once.
 */
interface ThumbnailRenderer
{
    /**
     * The page at `$url`, as PNG bytes of exactly `$width` × `$height`.
     *
     * Null means "this renderer cannot render at all" — nothing is installed —
     * and the caller leaves the step as it was. A renderer that *tried* and
     * failed throws instead; the two are different facts and are logged
     * differently.
     *
     * `$cookies` (`name => value`) are set for the page's host before it loads,
     * and `$hideSelectors` name elements to hide — both exist for the one thing
     * that otherwise sits on top of every picture: a cookie banner.
     *
     * @param  array<string, string>  $cookies
     * @param  list<string>  $hideSelectors
     */
    public function render(string $url, int $width, int $height, array $cookies = [], array $hideSelectors = []): ?string;
}
