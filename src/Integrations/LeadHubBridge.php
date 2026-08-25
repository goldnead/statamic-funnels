<?php

namespace Goldnead\StatamicFunnels\Integrations;

use Goldnead\StatamicFunnels\Models\FunnelVisit;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The optional path from a captured address to a contact.
 *
 * Off unless the sibling is installed *and* the site switched it on. Installing
 * a funnel addon must not start writing into somebody's CRM.
 *
 * Two rules this family learned the hard way:
 *
 * 1. `class_exists` on the class actually called, not `interface_exists` on a
 *    contract the sibling may rename.
 * 2. Never `method_exists()` on a Facade: it forwards through `__callStatic`
 *    and declares none of the methods it forwards, so the probe is always
 *    false. Ask the object behind it.
 */
class LeadHubBridge
{
    protected const FACADE = '\Goldnead\LeadHub\Facades\LeadHub';

    public function available(): bool
    {
        if (! config('statamic-funnels.integrations.leadhub', false)) {
            return false;
        }

        $facade = self::FACADE;

        if (! class_exists($facade)) {
            return false;
        }

        try {
            $root = $facade::getFacadeRoot();
        } catch (Throwable) {
            return false;
        }

        return $root !== null && method_exists($root, 'ingest');
    }

    /**
     * A failure here never fails the funnel.
     *
     * The visitor is trying to get to the next page. Losing that because a CRM
     * was slow would be the wrong trade, and the address is already saved on
     * the walk either way.
     */
    public function capture(FunnelVisit $visit): void
    {
        if (! $this->available() || ! $visit->email) {
            return;
        }

        try {
            $facade = self::FACADE;
            $facade::ingest([
                'email' => $visit->email,
                'name' => $visit->name,
                'source' => 'funnel:'.$visit->funnel->handle,
            ]);
        } catch (Throwable $e) {
            Log::warning('statamic-funnels: handing the contact to LeadHub failed; the walk continues.', [
                'visit' => $visit->getKey(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
