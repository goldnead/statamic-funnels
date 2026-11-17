<?php

use Goldnead\BrandContext\Http\Middleware\GuardSetup;
use Goldnead\StatamicFunnels\Http\Controllers\Cp\LandkarteController;
use Goldnead\StatamicFunnels\Support\Setup;
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
 *
 * The detail routes carry the setup guard for the Landkarten's tables. It runs
 * before route-model binding, which a guard inside the controller cannot.
 */
$guard = GuardSetup::using(Setup::class, 'statamic-funnels::messages.landkarten_title', 'funnel_landkarten', 'funnel_landkarte_stages', 'funnel_landkarte_refs', 'funnel_landkarte_edges');

Route::prefix('funnels/landkarten')->name('statamic-funnels.landkarten.')->group(function () use ($guard) {
    Route::get('/', [LandkarteController::class, 'index'])->name('index');
    Route::post('/', [LandkarteController::class, 'store'])->name('store');
    Route::get('{landkarte}', [LandkarteController::class, 'show'])->name('show')->middleware($guard);
    Route::put('{landkarte}', [LandkarteController::class, 'update'])->name('update')->middleware($guard);
    Route::delete('{landkarte}', [LandkarteController::class, 'destroy'])->name('destroy')->middleware($guard);
    Route::get('{landkarte}/objects', [LandkarteController::class, 'objects'])->name('objects')->middleware($guard);
    Route::get('{landkarte}/details', [LandkarteController::class, 'details'])->name('details')->middleware($guard);
    // GET for a way without a person; POST for one with a person, so what describes her (tags, address-bearing traits, a search
    // for her name) travels in the body and not in a URL that every access log keeps.
    Route::match(['get', 'post'], '{landkarte}/probelauf', [LandkarteController::class, 'probelauf'])->name('probelauf')->middleware($guard);
    Route::match(['get', 'post'], '{landkarte}/probelauf/mail', [LandkarteController::class, 'probelaufMail'])->name('probelauf.mail')->middleware($guard);
    // POST only: the person travels in the body, and rendering a page is no "read" a link or a prefetch may trigger.
    Route::post('{landkarte}/probelauf/page', [LandkarteController::class, 'probelaufPage'])->name('probelauf.page')->middleware($guard);
    Route::post('{landkarte}/probelauf/persons', [LandkarteController::class, 'probelaufPersons'])->name('probelauf.persons')->middleware($guard);
    Route::post('{landkarte}/connections/preview', [LandkarteController::class, 'previewConnection'])->name('connections.preview')->middleware($guard);
    Route::post('{landkarte}/connections', [LandkarteController::class, 'connect'])->name('connections.store')->middleware($guard);
});
