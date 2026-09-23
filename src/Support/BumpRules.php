<?php

namespace Goldnead\StatamicFunnels\Support;

use Goldnead\StatamicFunnels\Models\FunnelStep;
use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Wann ein Bump neben dem Bestellknopf steht.
 *
 * Die Regeln haengen am **Kassenschritt**, nicht am Angebot: dasselbe Angebot
 * kann in zwei Funnels zwei verschiedene Kassen haben. Je Bump vier Angaben,
 * alle freiwillig, und ohne Regel bleibt ein Bump, was er war:
 *
 * - `options`: nur zu diesen Zahlweisen (leer heisst: zu allen),
 * - `requires`: nur wenn dieser andere Bump angekreuzt ist,
 * - `preselected`: steht angekreuzt da,
 * - `returning`: `show` (alle), `hide` (nicht fuer wiederkehrende
 *   Kaeufer:innen), `only` (nur fuer sie).
 *
 * Die Seite zeichnet die Regeln als `data-`-Attribute, ein kleines Skript
 * blendet danach ein und aus. **Durchgesetzt wird hier**, beim Bestellen: was
 * die Regel verbietet, faellt aus dem Korb, egal was das Formular schickt.
 */
class BumpRules
{
    public const RETURNING_SHOW = 'show';

    public const RETURNING_HIDE = 'hide';

    public const RETURNING_ONLY = 'only';

    /** @return list<string> */
    public static function returningModes(): array
    {
        return [self::RETURNING_SHOW, self::RETURNING_HIDE, self::RETURNING_ONLY];
    }

    /**
     * Die Regel eines Bumps, gesaeubert.
     *
     * @return array{options: list<string>, requires: string|null, preselected: bool, returning: string}
     */
    public static function for(FunnelStep $step, string $bump): array
    {
        $regeln = $step->config('bump_rules');
        $regel = is_array($regeln) && is_array($regeln[$bump] ?? null) ? $regeln[$bump] : [];

        $options = array_values(array_filter(
            array_map(fn ($o) => is_scalar($o) ? trim((string) $o) : '', (array) ($regel['options'] ?? [])),
            fn (string $o) => $o !== '',
        ));

        $requires = is_string($regel['requires'] ?? null) && trim($regel['requires']) !== '' && trim($regel['requires']) !== $bump
            ? trim($regel['requires'])
            : null;

        $returning = in_array($regel['returning'] ?? null, self::returningModes(), true)
            ? $regel['returning']
            : self::RETURNING_SHOW;

        return [
            'options' => $options,
            'requires' => $requires,
            'preselected' => filter_var($regel['preselected'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'returning' => $returning,
        ];
    }

    /** Darf dieser Bump dieser Person ueberhaupt gezeigt werden? */
    public static function shownTo(FunnelStep $step, string $bump, bool $returning): bool
    {
        return match (self::for($step, $bump)['returning']) {
            self::RETURNING_HIDE => ! $returning,
            self::RETURNING_ONLY => $returning,
            default => true,
        };
    }

    /**
     * Die Haekchen, die nach den Regeln wirklich gekauft werden.
     *
     * Zwei Durchgaenge fuer `requires`: faellt ein Bump heraus, kann das einen
     * anderen mitnehmen, der an ihm hing. Ketten ueber mehr als zwei Glieder
     * sind im Editor nicht zu bauen (ein Bump nennt einen anderen), eine
     * dritte Runde schadet trotzdem nicht.
     *
     * @param  list<string>  $picked
     * @return list<string>
     */
    public static function filter(FunnelStep $step, array $picked, ?string $option, bool $returning): array
    {
        $kept = array_values(array_filter($picked, function (string $bump) use ($step, $option, $returning) {
            $regel = self::for($step, $bump);

            if (! self::shownTo($step, $bump, $returning)) {
                return false;
            }

            return $regel['options'] === [] || ($option !== null && in_array($option, $regel['options'], true));
        }));

        for ($runde = 0; $runde < 3; $runde++) {
            $kept = array_values(array_filter($kept, function (string $bump) use ($step, $kept) {
                $requires = self::for($step, $bump)['requires'];

                return $requires === null || in_array($requires, $kept, true);
            }));
        }

        return $kept;
    }

    /**
     * Ist das eine wiederkehrende Kaeuferin?
     *
     * Wer unter derselben Adresse schon einmal bezahlt hat — ausserhalb dieses
     * Laufs. Die Adresse kommt vom Besuch (der Anmeldung), sonst vom
     * angemeldeten Konto. Ohne Adresse ist niemand wiederkehrend: gefragt wird
     * nur, was die Person selbst gesagt hat.
     */
    public static function isReturning(?FunnelVisit $visit): bool
    {
        $email = mb_strtolower(trim((string) ($visit->email ?? '')));

        if ($email === '') {
            try {
                $user = Auth::user();
                $email = mb_strtolower(trim((string) match (true) {
                    $user === null => '',
                    method_exists($user, 'email') => $user->email(),
                    default => data_get($user, 'email') ?? '',
                }));
            } catch (Throwable) {
                $email = '';
            }
        }

        if ($email === '') {
            return false;
        }

        $eigene = array_values(array_filter(array_merge(
            array_values((array) (($visit->meta ?? [])['payments'] ?? [])),
            [$visit?->payment_id],
        )));

        return Payment::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('status', Payment::STATUS_PAID)
            ->when($eigene !== [], fn ($q) => $q->whereNotIn('id', $eigene))
            ->exists();
    }
}
