<?php

namespace Goldnead\StatamicFunnels\Support;

use Closure;
use Illuminate\Http\Request;
use Throwable;

/**
 * Ob Tracking-Code und Meta-Ereignisse hinaus duerfen (F6, F7).
 *
 * **Mit `goldnead/statamic-consent`** entscheidet dessen Einwilligung, und das
 * an zwei Stellen: im Browser, wo jedes Skript als `type="text/plain"` unter
 * dem Dienst `tracking.consent_service` geparkt wird und erst mit der
 * Einwilligung startet (auch nachtraeglich, ohne neu zu laden), und auf dem
 * Server, wo die Conversions API nur Ereignisse schickt, wenn das
 * Einwilligungs-Cookie den Dienst nennt.
 *
 * **Ohne das Addon** entscheidet `tracking.without_consent_addon`: `block`
 * gibt nichts aus, `render` gibt den Code aus, wie er ist, fuer eine Site,
 * deren eigenes Banner ihn steuert. Vorgabe ist `block`: ein Pixel, der ohne
 * Einwilligung feuert, ist das Teure an dieser Funktion, nicht der, der fehlt.
 *
 * Optional gekoppelt: der Klassenname steht als Zeichenkette hier, das Addon
 * ist kein `require`.
 */
class TrackingConsent
{
    public const PARK = 'park';

    public const RENDER = 'render';

    public const BLOCK = 'block';

    protected const REGISTRY = '\Goldnead\StatamicConsent\Support\Registry';

    /** @var (Closure(Request): array{addon: bool, granted: bool})|null */
    protected static ?Closure $resolver = null;

    /**
     * Fuer Tests, und fuer eine Site mit einem anderen Einwilligungs-Werkzeug.
     *
     * @param  (Closure(Request): array{addon: bool, granted: bool})|null  $resolver
     */
    public static function resolveUsing(?Closure $resolver): void
    {
        static::$resolver = $resolver;
    }

    public static function service(): string
    {
        $service = trim((string) config('statamic-funnels.tracking.consent_service', 'meta_pixel'));

        return $service === '' ? 'meta_pixel' : $service;
    }

    /**
     * `mode`: wie der Code in die Seite kommt. `granted`: ob der Server jetzt
     * Ereignisse schicken darf.
     *
     * @return array{mode: string, granted: bool}
     */
    public static function state(Request $request): array
    {
        [$addon, $granted] = self::read($request);

        if ($addon) {
            return ['mode' => self::PARK, 'granted' => $granted];
        }

        return config('statamic-funnels.tracking.without_consent_addon', self::BLOCK) === self::RENDER
            ? ['mode' => self::RENDER, 'granted' => true]
            : ['mode' => self::BLOCK, 'granted' => false];
    }

    /**
     * Fuer den Editor: gilt eine Einwilligung, und wenn nicht, warum nichts
     * ausgegeben wird.
     *
     * @return array{mode: string, service: string, message: string}
     */
    public static function describe(): array
    {
        $mode = self::state(request())['mode'];

        return [
            'mode' => $mode,
            'service' => self::service(),
            'message' => (string) __('statamic-funnels::messages.tracking_mode_'.$mode, ['service' => self::service()]),
        ];
    }

    /** @return array{0: bool, 1: bool} */
    protected static function read(Request $request): array
    {
        if (static::$resolver) {
            $r = (static::$resolver)($request);

            return [(bool) ($r['addon'] ?? false), (bool) ($r['granted'] ?? false)];
        }

        if (! class_exists(self::REGISTRY)) {
            return [false, false];
        }

        try {
            $registry = app(self::REGISTRY);

            if (! is_object($registry) || ! method_exists($registry, 'granted')) {
                return [false, false];
            }

            return [true, (bool) $registry->granted(self::service(), $request)];
        } catch (Throwable) {
            // Installiert, aber nicht lesbar: dann gilt keine Einwilligung.
            return [true, false];
        }
    }
}
