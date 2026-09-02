<?php

namespace Goldnead\StatamicFunnels\Thumbnails;

/**
 * Where the browser is, if there is one.
 *
 * Browsershot needs a Chromium binary and does not go looking for it: it uses
 * whatever puppeteer was installed with, or the path it is given. Neither is a
 * given on a Statamic host, so this asks the question once per process and
 * answers with a path or with nothing.
 *
 * Order: the configured path wins, and if it is configured but not executable
 * the answer is nothing rather than a fallback — a site that named a binary
 * wants that binary, not a surprise. Then the usual names on `PATH`, then the
 * two places a Mac keeps it.
 */
class Chromium
{
    /** @var string|false|null Null until asked; false when asked and not found. */
    protected static string|false|null $found = null;

    /** @var list<string> */
    protected const NAMES = [
        'google-chrome',
        'google-chrome-stable',
        'chromium',
        'chromium-browser',
        'chrome',
    ];

    /** @var list<string> */
    protected const MAC_PATHS = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
    ];

    public static function find(): ?string
    {
        if (self::$found === null) {
            self::$found = self::locate() ?? false;
        }

        return self::$found === false ? null : self::$found;
    }

    /** Ask again next time. For tests, and for a command that installed one. */
    public static function forget(): void
    {
        self::$found = null;
    }

    protected static function locate(): ?string
    {
        $configured = config('statamic-funnels.thumbnails.chrome_path');

        if (is_string($configured) && $configured !== '') {
            return is_executable($configured) ? $configured : null;
        }

        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir === '') {
                continue;
            }

            foreach (self::NAMES as $name) {
                $candidate = rtrim($dir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;

                if (is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        foreach (self::MAC_PATHS as $path) {
            if (is_executable($path)) {
                return $path;
            }
        }

        return null;
    }
}
