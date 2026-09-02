<?php

/*
 * A stand-in for the one class this addon touches in `spatie/browsershot`,
 * which is a `suggest` and not in vendor. Scanned by PHPStan (see
 * phpstan.neon), never autoloaded: at runtime `BrowsershotRenderer` is only
 * built behind a `class_exists` guard, and the tests use their own renderer
 * double. Only the methods the renderer calls, with their real signatures.
 */

namespace Spatie\Browsershot;

class Browsershot
{
    public static function url(string $url): static
    {
        return new static;
    }

    public function setChromePath(string $executablePath): static
    {
        return $this;
    }

    public function windowSize(int $width, int $height): static
    {
        return $this;
    }

    public function deviceScaleFactor(int $deviceScaleFactor): static
    {
        return $this;
    }

    public function noSandbox(): static
    {
        return $this;
    }

    public function timeout(int $timeout): static
    {
        return $this;
    }

    public function waitUntilNetworkIdle(bool $strict = true): static
    {
        return $this;
    }

    public function screenshot(): string
    {
        return '';
    }
}
