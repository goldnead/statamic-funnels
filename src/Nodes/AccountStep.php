<?php

namespace Goldnead\StatamicFunnels\Nodes;

/**
 * Der Konto-Schritt nach dem Kauf.
 *
 * Bisher entstand der Benutzer still im Hintergrund — der Testkaeufer hiess
 * `info+funneltest2`, aus der Adresse gebaut — und niemand sagte ihm, dass es
 * ihn gibt. Kajabis Reihenfolge: Kasse → Upsell → Name und Passwort
 * (ueberspringbar) → Bibliothek.
 *
 * Diese Seite zeigt die Adresse des Besuchs (nicht aenderbar), fragt Name und
 * Passwort, legt den Statamic-User an oder aktualisiert ihn, meldet ihn an und
 * geht weiter. „Spaeter" geht weiter ohne Konto. Kein eigenes Mailing: die
 * Zugangsmail macht die Site.
 */
class AccountStep extends StepType
{
    public static function handle(): string
    {
        return 'account';
    }

    public static function kind(): string
    {
        return 'page';
    }

    public static function label(): string
    {
        return __('statamic-funnels::nodes.account_label');
    }

    public static function description(): string
    {
        return __('statamic-funnels::nodes.account_description');
    }

    public static function icon(): string
    {
        return 'user-avatar';
    }

    public static function isPage(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return array_merge([
            [
                'handle' => 'optional',
                'type' => 'select',
                'label' => __('statamic-funnels::nodes.field_account_optional'),
                'instructions' => __('statamic-funnels::nodes.field_account_optional_help'),
                'default' => 'yes',
                'options' => [
                    ['value' => 'yes', 'label' => __('statamic-funnels::nodes.account_optional_yes')],
                    ['value' => 'no', 'label' => __('statamic-funnels::nodes.account_optional_no')],
                ],
            ],
            [
                'handle' => 'login_after',
                'type' => 'select',
                'label' => __('statamic-funnels::nodes.field_login_after'),
                'instructions' => __('statamic-funnels::nodes.field_login_after_help'),
                'default' => 'yes',
                'options' => [
                    ['value' => 'yes', 'label' => __('statamic-funnels::nodes.login_after_yes')],
                    ['value' => 'no', 'label' => __('statamic-funnels::nodes.login_after_no')],
                ],
            ],
        ], self::pageSchema());
    }

    /** @param  array<string, mixed>  $config */
    public static function isOptional(array $config): bool
    {
        return ($config['optional'] ?? 'yes') !== 'no' && ($config['optional'] ?? true) !== false;
    }

    /** @param  array<string, mixed>  $config */
    public static function logsIn(array $config): bool
    {
        return ($config['login_after'] ?? 'yes') !== 'no' && ($config['login_after'] ?? true) !== false;
    }
}
