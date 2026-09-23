<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\Funnel;
use Illuminate\Http\Request;

/**
 * Der Hinweis, die Seite im echten Browser zu oeffnen (F3).
 *
 * Wer aus einer Instagram-Story, einer Facebook-Anzeige, einem TikTok oder
 * einem LinkedIn-Beitrag kommt, landet im Browser der App. Dort fehlen
 * gespeicherte Passwoerter und Karten, Apple Pay, Google Pay und die
 * Banking-App fuer die Freigabe, und ein Kauf bricht an genau der Stelle ab,
 * an der er Geld bringen wuerde.
 *
 * Erkannt am User-Agent, auf dem Server: die Seite kommt gleich mit Hinweis,
 * ohne Skript und ohne Flackern. Auf Android fuehrt ein Link direkt in Chrome;
 * auf iOS gibt es diesen Weg nicht, dort bleibt „Link kopieren" und der Satz,
 * wo das Menue der App sitzt.
 */
class InAppBrowser
{
    /**
     * Name der App oder null.
     *
     * Reihenfolge ist Absicht: Instagram laeuft auf Facebooks Webview und
     * traegt je nach Fassung auch `FBAV`.
     */
    public static function detect(?string $agent): ?string
    {
        $agent = (string) $agent;

        if ($agent === '') {
            return null;
        }

        // Threads meldet sich als „Barcelona" (der Projektname bei Meta) und
        // steht vor Instagram, weil es denselben Unterbau hat.
        return match (true) {
            (bool) preg_match('/\bBarcelona \d/', $agent) => 'Threads',
            str_contains($agent, 'Instagram') => 'Instagram',
            (bool) preg_match('/\[?Pinterest\/|Pinterest for /', $agent) => 'Pinterest',
            str_contains($agent, 'Snapchat') => 'Snapchat',
            (bool) preg_match('/\bFBAN\/|\bFBAV\/|\bFB_IAB\/|\bFBIOS\b|\[FB/', $agent) => 'Facebook',
            (bool) preg_match('/musical_ly|BytedanceWebview|\bTikTok\b|trill_/i', $agent) => 'TikTok',
            str_contains($agent, 'LinkedInApp') => 'LinkedIn',
            default => null,
        };
    }

    public static function isAndroid(?string $agent): bool
    {
        return stripos((string) $agent, 'Android') !== false;
    }

    /**
     * Was die Seite zeigt, oder null.
     *
     * @return array{app: string, text: string, url: string, android_url: string|null, copy_label: string, copied_label: string, open_label: string}|null
     */
    public static function forTemplate(Funnel $funnel, Request $request, bool $preview = false): ?array
    {
        if ($preview || ! config('statamic-funnels.in_app_browser.enabled', true)) {
            return null;
        }

        $settings = FunnelSettings::of($funnel);

        if (! $settings['in_app_enabled']) {
            return null;
        }

        $agent = $request->userAgent();
        $app = self::detect($agent);

        if ($app === null) {
            return null;
        }

        // Ohne die Einbett-Angaben: wer die Seite im Browser oeffnet, soll sie
        // als ganze Seite sehen und nicht als Rahmen ohne Rahmen.
        $url = $request->fullUrlWithoutQuery(['embed', 'w', 'e']);

        // Auf Android gibt es Chrome statt Apple Pay; der Text sagt es so.
        $text = $settings['in_app_text'] !== null
            ? str_replace(':app', $app, $settings['in_app_text'])
            : (string) __(self::isAndroid($agent) ? 'statamic-funnels::messages.in_app_default_android' : 'statamic-funnels::messages.in_app_default', ['app' => $app]);

        return [
            'app' => $app,
            'text' => $text,
            'url' => $url,
            'android_url' => self::isAndroid($agent) ? self::intent($url) : null,
            'copy_label' => (string) __('statamic-funnels::messages.in_app_copy'),
            'copied_label' => (string) __('statamic-funnels::messages.in_app_copied'),
            'open_label' => (string) __('statamic-funnels::messages.in_app_open'),
        ];
    }

    /** Ein Link, den Android an Chrome gibt statt an die App. */
    protected static function intent(string $url): string
    {
        $teile = parse_url($url);
        $scheme = $teile['scheme'] ?? 'https';
        $rest = preg_replace('#^[a-z]+://#i', '', $url);

        return 'intent://'.$rest.'#Intent;scheme='.$scheme.';package=com.android.chrome;end';
    }
}
