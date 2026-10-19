<?php

use Goldnead\StatamicFunnels\Http\Controllers\Cp\LandkarteController;
use Illuminate\Support\Facades\Route;

/*
 * The Landkarten. Registered through the ServiceProvider's `$routes['cp']`, so
 * Statamic adds the `/cp` prefix, the `statamic.cp.` name prefix and the CP
 * authentication: `cp_route('statamic-funnels.landkarten.show', $id)`.
 *
 * They do not live under `utilities/funnels`, where the Kassen & Besucherwege
 * screen is: the navigation marks an entry active by URL prefix, and with the
 * Landkarten below that prefix both entries lit up on every map. The old
 * addresses under `utilities/funnels/maps` redirect here (see the utility's
 * routes in the ServiceProvider).
 */
Route::prefix('funnels/landkarten')->name('statamic-funnels.landkarten.')->group(function () {
    Route::get('/', [LandkarteController::class, 'index'])->name('index');
    Route::post('/', [LandkarteController::class, 'store'])->name('store');
    Route::get('{landkarte}', [LandkarteController::class, 'show'])->name('show');
    Route::put('{landkarte}', [LandkarteController::class, 'update'])->name('update');
    Route::delete('{landkarte}', [LandkarteController::class, 'destroy'])->name('destroy');
    Route::get('{landkarte}/objects', [LandkarteController::class, 'objects'])->name('objects');
});
