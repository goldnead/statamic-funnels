<?php

namespace Goldnead\StatamicFunnels\Thumbnails;

use Illuminate\Support\Str;
use Throwable;

/**
 * The sibling's consent cookie, with everything granted.
 *
 * `goldnead/statamic-consent` shows its banner until a decision is stored, and
 * stores it as a first-party cookie the browser writes:
 * `encodeURIComponent(JSON.stringify({ v, granted, ts, how, id }))`, where `v`
 * is `statamic-consent.version` and `granted` the service handles. Its script
 * accepts any value with the current `v` and a `granted` array (see `parse()`
 * in its `consent.js`), so a value built here with every registered service is
 * a decision it honours and the banner stays down.
 *
 * Built behind a `class_exists` guard and never throwing: the sibling is not a
 * dependency, and a thumbnail is not worth breaking a save over.
 */
class ConsentCookie
{
    protected const REGISTRY = '\Goldnead\StatamicConsent\Support\Registry';

    /**
     * `[name => value]` for the installed consent addon, or null without one.
     *
     * @return array<string, string>|null
     */
    public static function default(): ?array
    {
        if (! class_exists(self::REGISTRY)) {
            return null;
        }

        try {
            $registry = app(self::REGISTRY);

            if (! is_object($registry) || ! method_exists($registry, 'services')) {
                return null;
            }

            $handles = collect($registry->services())
                ->pluck('handle')
                ->filter(fn ($handle) => is_string($handle) && $handle !== '')
                ->values()
                ->all();

            $name = (string) config('statamic-consent.cookie.name', 'statamic_consent');
            $version = (int) config('statamic-consent.version', 1);

            return [$name => self::value($handles, $version)];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The cookie's value in the shape the consent script reads.
     *
     * @param  list<string>  $granted
     */
    public static function value(array $granted, int $version): string
    {
        return rawurlencode((string) json_encode([
            'v' => $version,
            'granted' => $granted,
            'ts' => (int) (microtime(true) * 1000),
            'how' => 'thumbnail',
            'id' => Str::random(16),
        ]));
    }
}
