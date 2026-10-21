<?php

namespace Goldnead\StatamicFunnels\Support;

use Statamic\Entries\Entry;

/**
 * The address a visitor reaches an entry at.
 *
 * Statamic knows it from the collection's route. A site whose pages are served by the application's own
 * controllers has collections with no route (`route: null`), and `Entry::url()` is then null for every one
 * of them although the page is there. Such a site says which field of the entry holds the path, in
 * `statamic-funnels.page_preview.address_field` (adriangoldner.com: `route_path`), and that is asked, and only
 * when Statamic has no address of its own. It is read as written and never followed as written: a value
 * that is not a path of this site (another host, a scheme, a control character) is no address.
 *
 * The addon does not know the name of any site's field; the site names it. An empty setting is the
 * behaviour the addon always had.
 */
final class EntryAddress
{
    /** The path (`/minikurs`), or null when the entry has no address. */
    public static function path(Entry $entry): ?string
    {
        $url = $entry->url();

        if (is_string($url) && $url !== '') {
            return $url;
        }

        $field = config('statamic-funnels.page_preview.address_field');

        if (! is_string($field) || $field === '') {
            return null;
        }

        $value = $entry->get($field);

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        if ($value === '' || ! str_starts_with($value, '/') || str_starts_with($value, '//') || str_contains($value, '\\') || preg_match('/[\x00-\x20\x7f]/', $value) === 1) {
            return null;
        }

        return $value;
    }

    /** The full address on the entry's site, or null when the entry has no address. */
    public static function absolute(Entry $entry): ?string
    {
        $absolute = $entry->absoluteUrl();

        if (is_string($absolute) && $absolute !== '') {
            return $absolute;
        }

        $path = self::path($entry);

        if ($path === null) {
            return null;
        }

        $base = (string) $entry->site()?->absoluteUrl();

        return rtrim($base, '/').$path;
    }
}
