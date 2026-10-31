<?php

use App\Http\Controllers\PublicTripController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Maintenance: the technician trip link (docs/MAINTENANCE.md §3)
|--------------------------------------------------------------------------
| Required from routes/api.php OUTSIDE the auth.token.store group: these
| URLs carry no dashboard token, only the trip link token. Paths start at
| `public/trips/{token}`; apply the token middleware and the 60/min
| throttle here, on the group.
*/

Route::prefix('public/trips/{token}')
    ->middleware(['throttle:trip-link', 'trip.token'])
    ->group(function () {
        Route::get('/', [PublicTripController::class, 'show'])->name('public.trips.show');
        Route::post('clock-in', [PublicTripController::class, 'clockIn'])->name('public.trips.clock-in');
        Route::post('clock-out', [PublicTripController::class, 'clockOut'])->name('public.trips.clock-out');
        Route::post('events', [PublicTripController::class, 'events'])->name('public.trips.events');

        Route::post('stops', [PublicTripController::class, 'stopsStore'])->name('public.trips.stops.store');
        Route::post('stops/{stop}/arrive', [PublicTripController::class, 'arrive'])->name('public.trips.stops.arrive');
        Route::post('stops/{stop}/leave', [PublicTripController::class, 'leave'])->name('public.trips.stops.leave');
        Route::post('stops/{stop}/not-done', [PublicTripController::class, 'stopNotDone'])->name('public.trips.stops.not-done');
        Route::post('stops/{stop}/issues/{ticketIssue}/done', [PublicTripController::class, 'issueDone'])->name('public.trips.issues.done');
        Route::post('stops/{stop}/issues/{ticketIssue}/not-done', [PublicTripController::class, 'issueNotDone'])->name('public.trips.issues.not-done');

        Route::post('purchases', [PublicTripController::class, 'purchasesStore'])->name('public.trips.purchases.store');
        Route::post('notes', [PublicTripController::class, 'notesStore'])->name('public.trips.notes');

        Route::get('suppliers', [PublicTripController::class, 'suppliers'])->name('public.trips.suppliers');
        Route::get('parts', [PublicTripController::class, 'parts'])->name('public.trips.parts');
    });
