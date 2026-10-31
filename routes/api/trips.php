<?php

use App\Http\Controllers\TechnicianPurchaseController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TripSessionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Maintenance: trips (docs/MAINTENANCE.md)
|--------------------------------------------------------------------------
| Required from routes/api.php INSIDE the auth.token.store group, at the top
| level (no prefix): write full paths from the /api root.
*/

Route::get('trips', [TripController::class, 'index'])->name('trips.index');
Route::post('trips', [TripController::class, 'store'])->name('trips.store');

// Sessions nobody has paid yet (for the payment form). Literal path, so it
// can never be read as a {trip}.
Route::get('trip-sessions/unpaid', [TripSessionController::class, 'unpaid'])->name('trip-sessions.unpaid');

/*
 | scopeBindings: {stop} and {session} resolve through Trip::stops() and
 | Trip::sessions(), {event} through TripSession::events(), so a child of
 | another trip is a 404. PARAMETER NAMES ARE LOAD-BEARING (see routes/api.php).
 */
Route::prefix('trips/{trip}')->scopeBindings()->group(function () {
    Route::get('/', [TripController::class, 'show'])->name('trips.show');
    Route::patch('/', [TripController::class, 'update'])->name('trips.update');
    Route::post('link', [TripController::class, 'link'])->name('trips.link');
    Route::post('close', [TripController::class, 'close'])->name('trips.close');
    Route::post('reopen', [TripController::class, 'reopen'])->name('trips.reopen');
    Route::post('mistaken', [TripController::class, 'mistaken'])->name('trips.mistaken');

    Route::post('stops', [TripController::class, 'stopsStore'])->name('trips.stops.store');
    Route::post('stops/{stop}/mistaken', [TripController::class, 'stopsMistaken'])->name('trips.stops.mistaken');

    Route::post('sessions', [TripSessionController::class, 'store'])->name('trips.sessions.store');
    Route::patch('sessions/{session}', [TripSessionController::class, 'update'])->name('trips.sessions.update');
    Route::post('sessions/{session}/mistaken', [TripSessionController::class, 'mistaken'])->name('trips.sessions.mistaken');
    Route::post('sessions/{session}/events', [TripSessionController::class, 'eventsStore'])->name('trips.sessions.events.store');
    Route::patch('sessions/{session}/events/{event}', [TripSessionController::class, 'eventsUpdate'])->name('trips.sessions.events.update');
    Route::post('sessions/{session}/events/{event}/mistaken', [TripSessionController::class, 'eventsMistaken'])->name('trips.sessions.events.mistaken');

    Route::post('notes', [TripController::class, 'notesStore'])->middleware('throttle:api-upload')->name('trips.notes');
    Route::post('attachments', [TripController::class, 'attachmentsStore'])->middleware('throttle:api-upload')->name('trips.attachments');
});

// Technician purchases (docs/MAINTENANCE.md §4).
Route::get('technician-purchases', [TechnicianPurchaseController::class, 'index'])->name('technician-purchases.index');
Route::post('technician-purchases', [TechnicianPurchaseController::class, 'store'])->name('technician-purchases.store');
Route::post('technician-purchases/{technicianPurchase}/mistaken', [TechnicianPurchaseController::class, 'mistaken'])->name('technician-purchases.mistaken');
