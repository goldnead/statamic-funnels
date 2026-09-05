<?php

namespace Goldnead\StatamicFunnels\Actions;

use Goldnead\StatamicFunnels\Models\Funnel;
use Statamic\Actions\Action;

/**
 * The two things every funnel action has to get right.
 *
 * Actions are registered globally and offered on every listing in the Control
 * Panel, so one that forgets `visibleTo` turns up in the bulk toolbar of the
 * Entries screen — and one that forgets `authorize` is a writing endpoint with
 * no lock on it, reachable by anybody who can open the CP at all.
 */
abstract class FunnelAction extends Action
{
    public function visibleTo($item)
    {
        return $item instanceof Funnel;
    }

    public function authorize($user, $item)
    {
        return $user->can('access funnels utility');
    }
}
