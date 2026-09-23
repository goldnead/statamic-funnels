<?php

namespace Goldnead\StatamicFunnels\Support;

use Throwable;

/**
 * Was statamic-payments vor die Kasse stellt (P7, P8), aus Sicht des Funnels.
 *
 * - **Captcha und Sperren (P7).** payments prueft vor jeder Zahlung Sperrliste,
 *   Rate-Limit und Captcha und gibt bei einer Ablehnung nur `null` zurueck.
 *   Den Grund meldet es als Ereignis `CheckoutBlocked`; diese Klasse merkt
 *   ihn sich fuer die Dauer der Anfrage, damit die Kasse der Kaeuferin sagen
 *   kann, woran es lag, statt „nicht verfuegbar".
 * - **Erinnerung (P8).** Abbruch-Mails und -Ereignisse nur mit eigenem Haken.
 *   Ob die Kasse danach fragt, haengt an der Einstellung von payments.
 *
 * Beides optional: gegen ein payments ohne diese Teile gibt es kein Widget,
 * keinen Haken und keinen Grund.
 */
class PaymentsDoor
{
    protected const GUARD = 'Goldnead\StatamicPayments\Support\CheckoutGuard';

    protected const ABANDONMENT = 'Goldnead\StatamicPayments\Support\Abandonment';

    public const BLOCKED_EVENT = 'Goldnead\StatamicPayments\Events\CheckoutBlocked';

    /** Der Grund der letzten Ablehnung in dieser Anfrage. */
    public ?string $reason = null;

    /** Das Captcha-Widget fuer die Kassenseite, oder leer. */
    public static function captcha(): string
    {
        if (! class_exists(self::GUARD)) {
            return '';
        }

        try {
            $html = Sibling::call(app(self::GUARD), 'widget');
        } catch (Throwable) {
            return '';
        }

        return is_string($html) ? $html : '';
    }

    /** Fragt die Kasse nach der Erinnerung? Nur wenn payments sie verschickt und dafuer eine Einwilligung will. */
    public static function asksForReminder(): bool
    {
        if (! class_exists(self::ABANDONMENT) || ! config('statamic-payments.abandoned.enabled', false)) {
            return false;
        }

        try {
            return Sibling::call(app(self::ABANDONMENT), 'capture') === 'consent';
        } catch (Throwable) {
            return false;
        }
    }

    public static function reminderLabel(): string
    {
        return (string) __('statamic-funnels::messages.reminder_consent_label');
    }

    /** Der Satz, den das Ereignis mitbrachte (payments ab 1.25). */
    public ?string $said = null;

    public function remember(mixed $event): void
    {
        $reason = data_get($event, 'reason');
        $message = data_get($event, 'message');
        $this->reason = is_string($reason) ? $reason : null;
        $this->said = is_string($message) && $message !== '' ? $message : null;
    }

    /** Der Satz fuer die Kaeuferin zum gemerkten Grund, oder null. */
    public function message(): ?string
    {
        if ($this->said !== null) {
            return $this->said;
        }

        if ($this->reason === null) {
            return null;
        }

        // Welche Sperre gegriffen hat, bleibt ungesagt: wer auf der Liste
        // steht, soll nicht erfahren, ob es die Adresse, die Domain oder die
        // IP ist.
        $key = match (true) {
            $this->reason === 'captcha' => 'checkout_blocked_captcha',
            $this->reason === 'rate_limited' => 'checkout_blocked_rate_limited',
            default => 'checkout_blocked',
        };

        return (string) __('statamic-funnels::messages.'.$key);
    }
}
