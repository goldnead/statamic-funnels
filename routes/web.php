<?php

use Goldnead\StatamicFunnels\Http\Controllers\Web\AdvanceController;
use Goldnead\StatamicFunnels\Http\Controllers\Web\FunnelController;
use Illuminate\Support\Facades\Route;

/*
 * A funnel's pages.
 *
 * Real URLs, one per step, so a visitor can bookmark where they are, the back
 * button works, and an email can link into the middle of a flow — which is how
 * the second half of most funnels actually starts.
 */
$prefix = trim((string) config('statamic-funnels.route_prefix', 'f'), '/');

Route::get($prefix.'/{funnel}', [FunnelController::class, 'entry'])
    ->name('statamic-funnels.entry');

/*
 * The Control Panel looking at a step.
 *
 * Above the `{slug}` route on purpose: `_preview` would otherwise be read as
 * the slug of a step, and the first funnel with a step slugged `_preview`
 * would be a puzzle nobody enjoys.
 *
 * Needs a pass minted in the Control Panel. No pass, no page — not a 403 but a
 * 404, because from outside there is nothing here.
 */
Route::get($prefix.'/{funnel}/_preview/{nodeKey}', [FunnelController::class, 'preview'])
    ->middleware('throttle:60,1')
    ->name('statamic-funnels.preview');

Route::get($prefix.'/{funnel}/{slug}', [FunnelController::class, 'step'])
    ->name('statamic-funnels.step');

/*
 * Moving on. Keeps CSRF, unlike a provider webhook: the caller here is a
 * browser and a person, and on an offer step it is an order.
 */
Route::post($prefix.'/{funnel}/{nodeKey}/advance', AdvanceController::class)
    ->middleware('throttle:30,1')
    ->name('statamic-funnels.advance');
