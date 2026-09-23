<?php

/*
 * Steht fuer `Goldnead\StatamicConsent\Support\Registry`, das diese Suite nicht
 * installiert. Liest das Einwilligungs-Cookie **wie das echte**
 * (`Registry::rawDecision()`/`decision()`/`granted()` in statamic-consent,
 * Stand 23.09.2026): `rawurlencode(json_encode({v, granted, ...}))`, nur mit
 * der aktuellen Fassung `statamic-consent.version`. Nur in Tests, die in einem
 * eigenen Prozess laufen: einmal geladen, gibt es die Klasse bis zum Ende.
 */

namespace Goldnead\StatamicConsent\Support {
    use Illuminate\Http\Request;

    if (! class_exists(Registry::class)) {
        class Registry
        {
            /** @return list<string> */
            public function requiredHandles(): array
            {
                return [];
            }

            public function granted(string $handle, ?Request $request = null): bool
            {
                return in_array($handle, $this->requiredHandles(), true)
                    || in_array($handle, $this->decision($request), true);
            }

            /** @return list<string> */
            public function decision(?Request $request = null): array
            {
                $request ??= request();
                $raw = $request->cookie((string) config('statamic-consent.cookie.name', 'statamic_consent'));

                if (! is_string($raw) || $raw === '') {
                    return [];
                }

                $decoded = json_decode(rawurldecode($raw), true);

                if (! is_array($decoded) || (int) ($decoded['v'] ?? 0) !== (int) config('statamic-consent.version', 1)) {
                    return [];
                }

                return array_values(array_filter((array) ($decoded['granted'] ?? []), 'is_string'));
            }

            /** @return list<array{handle: string, name: string}> */
            public function services(): array
            {
                return [['handle' => 'meta_pixel', 'name' => 'Meta-Pixel'], ['handle' => 'statistik', 'name' => 'Statistik']];
            }
        }
    }
}
