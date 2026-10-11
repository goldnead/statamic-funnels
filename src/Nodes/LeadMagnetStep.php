<?php

namespace Goldnead\StatamicFunnels\Nodes;

use Goldnead\StatamicFunnels\Integrations\LeadMagnetsBridge;

/**
 * Das Gratis-Geschenk, ausgeliefert von statamic-lead-magnets.
 *
 * Der Schritt steht nach dem Formular, das die Adresse erhebt. Er fragt die
 * gewaehlte Ressource fuer diese Adresse an und geht erst weiter, wenn
 * lead-magnets sagt, dass der Zugang steht: sofort bei einer Ressource ohne
 * Double-Opt-in, sonst nach dem Klick auf den Bestaetigungslink in der Mail.
 * Bis dahin zeigt die Seite „Schau in dein Postfach".
 *
 * Der Schritt wird nur angeboten, wenn lead-magnets installiert ist
 * ({@see LeadMagnetsBridge::available()}). Eine harte Abhaengigkeit ist es
 * nicht: dieses Addon laeuft ohne.
 */
class LeadMagnetStep extends StepType
{
    public static function handle(): string
    {
        return 'lead_magnet';
    }

    public static function kind(): string
    {
        return 'page';
    }

    public static function label(): string
    {
        return __('statamic-funnels::nodes.lead_magnet_label');
    }

    public static function description(): string
    {
        return __('statamic-funnels::nodes.lead_magnet_description');
    }

    public static function icon(): string
    {
        return 'mail';
    }

    public static function isPage(): bool
    {
        return true;
    }

    public static function schema(): array
    {
        return array_merge([
            [
                'handle' => 'resource',
                'type' => 'select',
                'label' => __('statamic-funnels::nodes.field_lead_magnet_resource'),
                'instructions' => __('statamic-funnels::nodes.field_lead_magnet_resource_help'),
                'options' => LeadMagnetsBridge::options(),
            ],
        ], self::pageSchema());
    }
}
