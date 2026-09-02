<?php

namespace Goldnead\StatamicFunnels\Tests\Support;

use Goldnead\StatamicFunnels\Contracts\ThumbnailRenderer;

/**
 * A renderer that remembers what it was asked for and hands back a few bytes
 * that begin like a PNG. What the job does with a picture is the thing under
 * test; a browser is not.
 */
class FakeRenderer implements ThumbnailRenderer
{
    /** @var list<string> */
    public array $urls = [];

    /** @var list<array<string, string>> */
    public array $cookies = [];

    /** @var list<list<string>> */
    public array $hideSelectors = [];

    public function render(string $url, int $width, int $height, array $cookies = [], array $hideSelectors = []): ?string
    {
        $this->urls[] = $url;
        $this->cookies[] = $cookies;
        $this->hideSelectors[] = $hideSelectors;

        return "\x89PNG\r\n\x1a\n fake {$width}x{$height}";
    }
}
