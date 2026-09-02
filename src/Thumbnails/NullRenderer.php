<?php

namespace Goldnead\StatamicFunnels\Thumbnails;

use Goldnead\StatamicFunnels\Contracts\ThumbnailRenderer;
use Illuminate\Support\Facades\Log;

/**
 * No browser, so no picture.
 *
 * Renders nothing and says so **once per process**, as a notice: it is a fact
 * about the host, not a fault, and a queue worker that wrote the same line for
 * every step of every save would bury the warnings that matter. The Control
 * Panel says the same thing in the editor's side panel, which is where the
 * person who can do something about it is looking.
 */
class NullRenderer implements ThumbnailRenderer
{
    protected static bool $noticed = false;

    public function render(string $url, int $width, int $height, array $cookies = [], array $hideSelectors = []): ?string
    {
        if (! self::$noticed) {
            self::$noticed = true;

            Log::notice('statamic-funnels: no thumbnail renderer. Install spatie/browsershot and a Chromium, or set statamic-funnels.thumbnails.chrome_path; step thumbnails stay off until then.');
        }

        return null;
    }

    /** Say it again next time. For tests. */
    public static function reset(): void
    {
        self::$noticed = false;
    }
}
