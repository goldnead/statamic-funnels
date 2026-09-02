<?php

namespace Goldnead\StatamicFunnels\Thumbnails;

use Goldnead\StatamicFunnels\Contracts\ThumbnailRenderer;
use Spatie\Browsershot\Browsershot;

/**
 * A real browser, when there is one.
 *
 * Bound only when `spatie/browsershot` is installed *and* {@see Chromium} found
 * a binary — see {@see Thumbnails::detectRenderer()}. Browsershot is a `suggest`
 * in composer.json, not a `require`: an addon that quietly drags Node, puppeteer
 * and a 200 MB browser onto a host is an addon that stops being installable.
 * The class name is only touched behind a `class_exists` guard, so PHP never
 * tries to load it on a site without the package.
 *
 * `noSandbox()` because the usual place this runs is a container as root, where
 * Chromium refuses to start sandboxed and says nothing useful about why.
 */
class BrowsershotRenderer implements ThumbnailRenderer
{
    public function __construct(protected string $chromePath) {}

    public static function available(): bool
    {
        return class_exists(Browsershot::class) && Chromium::find() !== null;
    }

    public function render(string $url, int $width, int $height): ?string
    {
        return Browsershot::url($url)
            ->setChromePath($this->chromePath)
            ->windowSize($width, $height)
            ->deviceScaleFactor(1)
            ->noSandbox()
            ->timeout(30)
            // `networkidle2`, not `networkidle0`: a page with a live poll or
            // a chat widget never reaches zero connections, and a screenshot
            // that waits for it never happens.
            ->waitUntilNetworkIdle(false)
            ->screenshot();
    }
}
