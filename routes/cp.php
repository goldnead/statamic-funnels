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
    Route::get('{landkarte}/details', [LandkarteController::class, 'details'])->name('details');
    // GET for a way without a person; POST for one with a person, so what describes her (tags, address-bearing traits, a search
    // for her name) travels in the body and not in a URL that every access log keeps.
    Route::match(['get', 'post'], '{landkarte}/probelauf', [LandkarteController::class, 'probelauf'])->name('probelauf');
    Route::match(['get', 'post'], '{landkarte}/probelauf/mail', [LandkarteController::class, 'probelaufMail'])->name('probelauf.mail');
    // POST only: the person travels in the body, and rendering a page is no "read" a link or a prefetch may trigger.
    Route::post('{landkarte}/probelauf/page', [LandkarteController::class, 'probelaufPage'])->name('probelauf.page');
    Route::post('{landkarte}/probelauf/persons', [LandkarteController::class, 'probelaufPersons'])->name('probelauf.persons');
    Route::post('{landkarte}/connections/preview', [LandkarteController::class, 'previewConnection'])->name('connections.preview');
    Route::post('{landkarte}/connections', [LandkarteController::class, 'connect'])->name('connections.store');
});
