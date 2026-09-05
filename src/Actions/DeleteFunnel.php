<?php

namespace Goldnead\StatamicFunnels\Actions;

use Goldnead\StatamicFunnels\Models\Funnel;

use function Statamic\trans_choice;

/**
 * Deleting a funnel, from the row menu and from the bulk toolbar.
 *
 * An action rather than a delete route with a hand-built modal in front of it:
 * the same class then serves one row and twenty selected rows, and the asking,
 * the refusing and the toast all come from the Control Panel instead of from
 * this addon's own imitation of them.
 */
class DeleteFunnel extends FunnelAction
{
    protected static $handle = 'statamic_funnels_delete_funnel';

    protected $dangerous = true;

    protected $icon = 'trash';

    public static function title()
    {
        return __('statamic-funnels::messages.funnel_action_delete');
    }

    public function buttonText()
    {
        return __('statamic-funnels::messages.funnel_action_delete_button');
    }

    /**
     * A funnel takes its visits and its record of who got how far with it, so
     * the question says that rather than "are you sure".
     */
    public function confirmationText()
    {
        return __('statamic-funnels::messages.funnel_action_delete_confirm');
    }

    public function run($items, $values)
    {
        $items->each(fn (Funnel $funnel) => $funnel->delete());

        return trans_choice(__('statamic-funnels::messages.funnel_action_deleted'), $items->count(), ['count' => $items->count()]);
    }
}
