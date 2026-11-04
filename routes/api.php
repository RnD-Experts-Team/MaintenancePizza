<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\IssueApprovalController;
use App\Http\Controllers\IssueHistoryController;
use App\Http\Controllers\IssueInfoRequestController;
use App\Http\Controllers\IssueInspectionController;
use App\Http\Controllers\IssueScheduleController;
use App\Http\Controllers\IssueWorkflowController;
use App\Http\Controllers\MaintenanceAnalyticsController;
use App\Http\Controllers\MaintenanceSettingController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PartUnitController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PaymentMethodController;
use App\Http\Controllers\PublicTripController;
use App\Http\Controllers\StockBalanceController;
use App\Http\Controllers\StockCountController;
use App\Http\Controllers\StockMovementController;
use App\Http\Controllers\StorageLocationController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TechnicianAbilityController;
use App\Http\Controllers\TechnicianAnalyticsController;
use App\Http\Controllers\TechnicianPurchaseController;
use App\Http\Controllers\TicketCancellationController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketFinalNoteController;
use App\Http\Controllers\TicketIssueCancellationController;
use App\Http\Controllers\TicketIssueController;
use App\Http\Controllers\TicketNoteController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\TripSessionController;
use App\Http\Controllers\TroubleshootingController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Maintenance: the technician's trip link (docs/MAINTENANCE.md §3)
|--------------------------------------------------------------------------
| OUTSIDE auth.token.store: these URLs carry no dashboard token, only the
| trip link token, so the group applies its own token middleware and the
| per-link throttle.
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

/*
| Rate limits (AppServiceProvider): `throttle:api` covers every route, ahead of
| the pizzasys call. Routes that upload, export or run analytics add their own
| tier on top.
*/
Route::middleware(['throttle:api', 'auth.token.store'])->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Controlled reference catalogs (global, not store-scoped)
    |--------------------------------------------------------------------------
    */
    Route::get('issues', [CatalogController::class, 'issuesIndex'])->name('issues.index');
    Route::post('issues', [CatalogController::class, 'issuesStore'])->name('issues.store');
    Route::patch('issues/{issue}', [CatalogController::class, 'issuesUpdate'])->name('issues.update');
    Route::delete('issues/{issue}', [CatalogController::class, 'issuesDestroy'])->name('issues.destroy');
    Route::post('issues/{issue}/restore', [CatalogController::class, 'issuesRestore'])->withTrashed()->name('issues.restore');

    // Troubleshooting guides: what to try before opening a ticket for an
    // issue -- several per issue, one per specific problem.
    Route::get('troubleshooting-guides', [TroubleshootingController::class, 'index'])->name('troubleshooting.index');
    Route::get('issues/{issue}/troubleshooting', [TroubleshootingController::class, 'show'])->withTrashed()->name('issues.troubleshooting.show');
    Route::post('issues/{issue}/troubleshooting-guides', [TroubleshootingController::class, 'store'])->name('issues.troubleshooting.store');
    Route::put('troubleshooting-guides/{guide}', [TroubleshootingController::class, 'update'])->name('troubleshooting.update');
    Route::delete('troubleshooting-guides/{guide}', [TroubleshootingController::class, 'destroy'])->name('troubleshooting.destroy');
    Route::post('troubleshooting-guides/{guide}/attachments', [TroubleshootingController::class, 'attachmentsStore'])->middleware('throttle:api-upload')->name('troubleshooting.attachments.store');
    Route::delete('troubleshooting-guides/{guide}/attachments/{attachment}', [TroubleshootingController::class, 'attachmentsDestroy'])->name('troubleshooting.attachments.destroy');
    Route::post('troubleshooting-steps/{step}/attachments', [TroubleshootingController::class, 'stepAttachmentsStore'])->middleware('throttle:api-upload')->name('troubleshooting.steps.attachments.store');
    Route::delete('troubleshooting-steps/{step}/attachments/{attachment}', [TroubleshootingController::class, 'stepAttachmentsDestroy'])->name('troubleshooting.steps.attachments.destroy');

    Route::get('technicians', [CatalogController::class, 'techniciansIndex'])->name('technicians.index');
    Route::post('technicians', [CatalogController::class, 'techniciansStore'])->name('technicians.store');
    // The Technicians page: one technician, and their pay and work in a range.
    Route::get('technician-analytics', [TechnicianAnalyticsController::class, 'index'])->name('technician-analytics.index');
    Route::get('technicians/{technician}', [CatalogController::class, 'techniciansShow'])->withTrashed()->name('technicians.show');
    Route::get('technicians/{technician}/analytics', [TechnicianAnalyticsController::class, 'show'])->withTrashed()->name('technicians.analytics');
    Route::patch('technicians/{technician}', [CatalogController::class, 'techniciansUpdate'])->name('technicians.update');
    Route::delete('technicians/{technician}', [CatalogController::class, 'techniciansDestroy'])->name('technicians.destroy');
    Route::post('technicians/{technician}/restore', [CatalogController::class, 'techniciansRestore'])->withTrashed()->name('technicians.restore');

    // Abilities: stars, notes and the "call first" pin, per issue and overall.
    // Their own read, never folded into GET technicians, which store users read.
    Route::get('technician-abilities', [TechnicianAbilityController::class, 'index'])->name('technician-abilities.index');
    Route::put('technicians/{technician}/abilities/{issue}', [TechnicianAbilityController::class, 'update'])->name('technicians.abilities.update');
    Route::delete('technicians/{technician}/abilities/{issue}', [TechnicianAbilityController::class, 'destroy'])->withTrashed()->name('technicians.abilities.destroy');

    Route::get('categories', [CatalogController::class, 'categoriesIndex'])->name('categories.index');
    Route::post('categories', [CatalogController::class, 'categoriesStore'])->name('categories.store');
    Route::patch('categories/{category}', [CatalogController::class, 'categoriesUpdate'])->name('categories.update');
    Route::delete('categories/{category}', [CatalogController::class, 'categoriesDestroy'])->name('categories.destroy');

    // How we paid: the editable list behind a payment's method.
    Route::get('payment-methods', [PaymentMethodController::class, 'index'])->name('payment-methods.index');
    Route::post('payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
    Route::patch('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'update'])->name('payment-methods.update');
    Route::delete('payment-methods/{paymentMethod}', [PaymentMethodController::class, 'destroy'])->name('payment-methods.destroy');

    // The owner's settings: approval thresholds, how long an idle trip link
    // lives (docs/MAINTENANCE.md §2 "Settings").
    Route::get('maintenance-settings', [MaintenanceSettingController::class, 'show'])->name('maintenance-settings.show');
    Route::put('maintenance-settings', [MaintenanceSettingController::class, 'update'])->name('maintenance-settings.update');

    Route::get('parts', [CatalogController::class, 'partsIndex'])->name('parts.index');
    Route::post('parts', [CatalogController::class, 'partsStore'])->name('parts.store');
    Route::get('parts/{part}', [CatalogController::class, 'partsShow'])->withTrashed()->name('parts.show');
    Route::patch('parts/{part}', [CatalogController::class, 'partsUpdate'])->name('parts.update');
    Route::delete('parts/{part}', [CatalogController::class, 'partsDestroy'])->name('parts.destroy');
    Route::post('parts/{part}/restore', [CatalogController::class, 'partsRestore'])->withTrashed()->name('parts.restore');
    // What we have paid for this part, and when.
    Route::get('parts/{part}/price-history', [CatalogController::class, 'partsPriceHistory'])->name('parts.price-history');

    /*
    |--------------------------------------------------------------------------
    | Notes & attachments for the global catalogs. Any number can be appended
    | (one note + optional files per note call; one or many files per upload).
    |--------------------------------------------------------------------------
    */
    Route::post('issues/{issue}/notes', [NoteController::class, 'catalogIssue'])->middleware('throttle:api-upload')->name('issues.notes');
    Route::post('issues/{issue}/attachments', [AttachmentController::class, 'catalogIssue'])->middleware('throttle:api-upload')->name('issues.attachments');
    Route::post('technicians/{technician}/notes', [NoteController::class, 'technician'])->middleware('throttle:api-upload')->name('technicians.notes');
    Route::post('technicians/{technician}/attachments', [AttachmentController::class, 'technician'])->middleware('throttle:api-upload')->name('technicians.attachments');
    Route::post('categories/{category}/notes', [NoteController::class, 'category'])->middleware('throttle:api-upload')->name('categories.notes');
    Route::post('categories/{category}/attachments', [AttachmentController::class, 'category'])->middleware('throttle:api-upload')->name('categories.attachments');
    Route::post('parts/{part}/notes', [NoteController::class, 'part'])->middleware('throttle:api-upload')->name('parts.notes');
    Route::post('parts/{part}/attachments', [AttachmentController::class, 'part'])->middleware('throttle:api-upload')->name('parts.attachments');

    /*
    |--------------------------------------------------------------------------
    | Storage. Locations are a controlled catalog; movements are an append-only
    | ledger (corrections are reversals, never edits) and stock-balances is the
    | cached SUM(quantity * direction) over that ledger.
    |--------------------------------------------------------------------------
    */
    Route::get('storage-locations', [StorageLocationController::class, 'index'])->name('storage-locations.index');
    Route::post('storage-locations', [StorageLocationController::class, 'store'])->name('storage-locations.store');
    Route::delete('storage-locations/{storageLocation}', [StorageLocationController::class, 'destroy'])->name('storage-locations.destroy');
    Route::post('storage-locations/{storageLocation}/restore', [StorageLocationController::class, 'restore'])->withTrashed()->name('storage-locations.restore');

    /*
     | How a location addresses the space inside it -- its levels (Shelf, Row,
     | Column) and the values declared on each.
     |
     | scopeBindings keeps a child belonging to its parent, so one location's
     | level can never be reached through another's URL.
     |
     | PARAMETER NAMES ARE LOAD-BEARING. Laravel resolves a scoped binding's
     | relation as Str::plural(Str::camel($param)), so {placeLevel} requires
     | StorageLocation::placeLevels() and {placeValue} requires
     | StoragePlaceLevel::placeValues(). The slots feature this replaces used
     | {storageSlot} against a slots() relation and 500'd on every PATCH and
     | DELETE -- and its tests never caught it because they called the service
     | instead of the endpoint. Every route here is tested over HTTP.
     */
    Route::prefix('storage-locations/{storageLocation}/place-levels')->scopeBindings()->group(function () {
        Route::get('/', [StorageLocationController::class, 'placeLevelsIndex'])->name('storage-locations.place-levels.index');
        Route::post('/', [StorageLocationController::class, 'placeLevelsStore'])->name('storage-locations.place-levels.store');
        Route::patch('{placeLevel}', [StorageLocationController::class, 'placeLevelsUpdate'])->name('storage-locations.place-levels.update');
        Route::delete('{placeLevel}', [StorageLocationController::class, 'placeLevelsDestroy'])->name('storage-locations.place-levels.destroy');

        Route::post('{placeLevel}/values', [StorageLocationController::class, 'placeValuesStore'])->name('storage-locations.place-values.store');
        Route::patch('{placeLevel}/values/{placeValue}', [StorageLocationController::class, 'placeValuesUpdate'])->name('storage-locations.place-values.update');
        Route::delete('{placeLevel}/values/{placeValue}', [StorageLocationController::class, 'placeValuesDestroy'])->name('storage-locations.place-values.destroy');
    });
    Route::post('storage-locations/{storageLocation}/notes', [NoteController::class, 'storageLocation'])->middleware('throttle:api-upload')->name('storage-locations.notes');
    Route::post('storage-locations/{storageLocation}/attachments', [AttachmentController::class, 'storageLocation'])->middleware('throttle:api-upload')->name('storage-locations.attachments');

    Route::get('stock-movements', [StockMovementController::class, 'index'])->name('stock-movements.index');
    Route::post('stock-movements', [StockMovementController::class, 'store'])->name('stock-movements.store');
    Route::get('stock-movements/{stockMovement}', [StockMovementController::class, 'show'])->name('stock-movements.show');
    Route::post('stock-movements/{stockMovement}/mistaken', [StockMovementController::class, 'mistaken'])->name('stock-movements.mistaken');
    Route::post('stock-movements/{stockMovement}/notes', [NoteController::class, 'stockMovement'])->middleware('throttle:api-upload')->name('stock-movements.notes');
    Route::post('stock-movements/{stockMovement}/attachments', [AttachmentController::class, 'stockMovement'])->middleware('throttle:api-upload')->name('stock-movements.attachments');

    Route::get('stock-balances', [StockBalanceController::class, 'index'])->name('stock-balances.index');
    // Where a part sits inside its location. The complete address each time --
    // a level left out is cleared, because a part has one address per location.
    Route::put('stock-balances/{stockBalance}/place', [StockBalanceController::class, 'setPlace'])->name('stock-balances.place');

    /*
    |--------------------------------------------------------------------------
    | Maintenance: suppliers, serial-tracked parts, issue lookup
    |--------------------------------------------------------------------------
    */
    // Suppliers: the places we buy from. Names unique like technicians'.
    Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->withTrashed()->name('suppliers.show');
    Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
    Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
    Route::post('suppliers/{supplier}/restore', [SupplierController::class, 'restore'])->withTrashed()->name('suppliers.restore');
    Route::post('suppliers/{supplier}/notes', [SupplierController::class, 'notesStore'])->middleware('throttle:api-upload')->name('suppliers.notes');
    Route::post('suppliers/{supplier}/attachments', [SupplierController::class, 'attachmentsStore'])->middleware('throttle:api-upload')->name('suppliers.attachments');

    // The pieces of serial-tracked parts.
    Route::get('parts/{part}/units', [PartUnitController::class, 'index'])->withTrashed()->name('parts.units.index');
    Route::patch('part-units/{unit}', [PartUnitController::class, 'update'])->name('part-units.update');

    // The issue pickers: a light lookup across every store (not the full read).
    Route::get('ticket-issues', [TicketIssueController::class, 'lookup'])->name('ticket-issues.lookup');

    /*
    |--------------------------------------------------------------------------
    | Maintenance: trips (docs/MAINTENANCE.md §3)
    |--------------------------------------------------------------------------
    */
    Route::get('trips', [TripController::class, 'index'])->name('trips.index');
    Route::post('trips', [TripController::class, 'store'])->name('trips.store');

    // Sessions nobody has paid yet (for the payment form). Literal path, so it
    // can never be read as a {trip}.
    Route::get('trip-sessions/unpaid', [TripSessionController::class, 'unpaid'])->name('trip-sessions.unpaid');

    /*
     | scopeBindings: {stop} and {session} resolve through Trip::stops() and
     | Trip::sessions(), {event} through TripSession::events(), so a child of
     | another trip is a 404. PARAMETER NAMES ARE LOAD-BEARING (same rule as the issue workflow routes below).
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

    /*
    |--------------------------------------------------------------------------
    | Maintenance: payments (docs/MAINTENANCE.md §4)
    |--------------------------------------------------------------------------
    */
    // Payments: one invoice paid to one technician or one supplier, split into lines.
    Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
    Route::post('payments', [PaymentController::class, 'store'])->name('payments.store');
    Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');
    Route::post('payments/{payment}/edit', [PaymentController::class, 'edit'])->name('payments.edit');
    Route::post('payments/{payment}/mistaken', [PaymentController::class, 'mistaken'])->name('payments.mistaken');
    Route::post('payments/{payment}/notes', [PaymentController::class, 'notesStore'])->middleware('throttle:api-upload')->name('payments.notes');
    Route::post('payments/{payment}/attachments', [PaymentController::class, 'attachmentsStore'])->middleware('throttle:api-upload')->name('payments.attachments');

    // What has not been paid yet, per technician: unpaid sessions and purchases.
    Route::get('unpaid-work', [PaymentController::class, 'unpaidWork'])->name('unpaid-work.index');

    // Inspections to pick on an Inspection line, with whether each is paid yet.
    Route::get('issue-inspections', [IssueInspectionController::class, 'index'])->name('issue-inspections.index');

    /*
    |--------------------------------------------------------------------------
    | Maintenance: stock counts (docs/MAINTENANCE.md §5)
    |--------------------------------------------------------------------------
    */
    // Stock counts: a sheet per location (or place), applied as one stock_count movement.
    Route::get('stock-counts', [StockCountController::class, 'index'])->name('stock-counts.index');
    Route::post('stock-counts', [StockCountController::class, 'store'])->name('stock-counts.store');
    Route::get('stock-counts/{stockCount}', [StockCountController::class, 'show'])->name('stock-counts.show');
    Route::patch('stock-counts/{stockCount}', [StockCountController::class, 'update'])->name('stock-counts.update');
    Route::post('stock-counts/{stockCount}/apply', [StockCountController::class, 'apply'])->name('stock-counts.apply');
    Route::delete('stock-counts/{stockCount}', [StockCountController::class, 'destroy'])->name('stock-counts.destroy');

    /*
    |--------------------------------------------------------------------------
    | Tickets — global index + Excel export
    |--------------------------------------------------------------------------
    */
    // Unified calendar feed. Stores are in the query (stores=a,b): the pizzasys
    // rule must read store_id_sources.query ["stores"] with policy "all".
    Route::get('calendar/feed', \App\Http\Controllers\CalendarFeedController::class)->name('calendar.feed');

    Route::get('tickets', [TicketController::class, 'globalIndex'])->name('tickets.global');
    Route::get('tickets/analytics', [TicketController::class, 'globalAnalytics'])->middleware('throttle:api-heavy')->name('tickets.analytics');
    Route::post('tickets', [TicketController::class, 'storeOther'])->name('tickets.store-other');
    // Unscoped read. Declared AFTER tickets/analytics so the literal segment
    // still wins over {ticket}. The store-scoped twin stays the canonical route;
    // this one exists because an other_store ticket has no store to bind to.
    Route::get('tickets/{ticket}/issues', [TicketIssueController::class, 'globalIndex'])->name('tickets.issues.global');

    // The analytics page: one or many stores, a range of instants.
    Route::get('maintenance-analytics/{section}', MaintenanceAnalyticsController::class)
        ->whereIn('section', ['summary', 'activity', 'watchlist', 'finances', 'coverage'])
        ->name('maintenance-analytics.show');
    Route::get('export/excel', ExportController::class)->name('export.excel')->withoutMiddleware('auth.token.store')->middleware(['throttle:service-secret', 'throttle:api-export', 'auth.secret.key']);

    /*
    |--------------------------------------------------------------------------
    | Store-scoped tickets + issue workflow.
    | {store} binds by store_number; scopeBindings keeps {ticket} and
    | {ticketIssue} within their parent.
    |--------------------------------------------------------------------------
    */
    Route::prefix('stores/{store}')->scopeBindings()->group(function () {
        Route::get('tickets', [TicketController::class, 'index'])->name('stores.tickets.index');
        Route::get('tickets/analytics', [TicketController::class, 'analytics'])->middleware('throttle:api-heavy')->name('stores.tickets.analytics');
        Route::post('tickets', [TicketController::class, 'store'])->name('stores.tickets.store');
        Route::post('tickets/{ticket}/restore', [TicketController::class, 'restore'])->withTrashed()->name('stores.tickets.restore');
        Route::post('tickets/{ticket}/cancel', TicketCancellationController::class)->name('stores.tickets.cancel');
        // Append a typed closing note (Final Notes / What we learned) — many allowed.
        Route::post('tickets/{ticket}/final-note', TicketFinalNoteController::class)->name('stores.tickets.final-note');

        // Generic notes & attachments on the ticket and on the store itself.
        Route::post('tickets/{ticket}/notes', [NoteController::class, 'ticket'])->middleware('throttle:api-upload')->name('stores.tickets.notes');
        // Every note on the ticket, its issues and records: the normal notes,
        // or all of them (private ones too). The auth rules decide who gets
        // which; the screen asks for /all when they allow it.
        Route::get('tickets/{ticket}/notes', [TicketNoteController::class, 'index'])->name('stores.tickets.notes.index');
        Route::get('tickets/{ticket}/notes/all', [TicketNoteController::class, 'all'])->name('stores.tickets.notes.all');
        Route::post('tickets/{ticket}/attachments', [AttachmentController::class, 'ticket'])->middleware('throttle:api-upload')->name('stores.tickets.attachments');
        Route::post('notes', [NoteController::class, 'store'])->middleware('throttle:api-upload')->name('stores.notes');
        Route::post('attachments', [AttachmentController::class, 'store'])->middleware('throttle:api-upload')->name('stores.attachments');

        Route::prefix('tickets/{ticket}')->group(function () {
            // The "one look" lifecycle views.
            Route::get('issues', [TicketIssueController::class, 'index'])->name('tickets.issues.index');
            Route::get('issues/{ticketIssue}', [TicketIssueController::class, 'show'])->name('tickets.issues.show');
            Route::patch('issues/{ticketIssue}', [TicketIssueController::class, 'update'])->name('tickets.issues.update');

            Route::post('issues/{ticketIssue}/cancel', TicketIssueCancellationController::class)->name('tickets.issues.cancel');
            Route::post('issues/{ticketIssue}/assigned-priority', [TicketIssueController::class, 'assignPriority'])->name('tickets.issues.assigned-priority');

            // Generic notes & attachments on an individual issue.
            Route::post('issues/{ticketIssue}/notes', [NoteController::class, 'ticketIssue'])->middleware('throttle:api-upload')->name('tickets.issues.notes');
            Route::post('issues/{ticketIssue}/attachments', [AttachmentController::class, 'ticketIssue'])->middleware('throttle:api-upload')->name('tickets.issues.attachments');

            /*
             | The issue workflow (review, info requests, inspections,
             | approvals, schedule, work done, confirmation, close, jump).
             |
             | Still inside scopeBindings(), so every nested parameter is
             | resolved as a child of the one before it, through the relation
             | Str::plural(Str::camel($param)) on the parent. PARAMETER NAMES
             | ARE LOAD-BEARING: {infoRequest} → TicketIssue::infoRequests(),
             | {inspection} → TicketIssue::inspections(), {approval} →
             | TicketIssue::approvals(), {assignment} → TicketIssue::assignments().
             | A parameter named after no relation (e.g. {request}) 500s.
             */
            // Every step answers with the issue's full read ({data}, plus {trip_link} when
            // it created a trip); a step that does not fit the issue's status is a 422
            // whose errors.status says why. The machine is IssueWorkflowService.

            Route::post('issues/{ticketIssue}/review', [IssueWorkflowController::class, 'review'])->name('tickets.issues.review');

            Route::post('issues/{ticketIssue}/info-requests', [IssueInfoRequestController::class, 'store'])->name('tickets.issues.info-requests.store');
            Route::post('issues/{ticketIssue}/info-requests/{infoRequest}/answer', [IssueInfoRequestController::class, 'answer'])->name('tickets.issues.info-requests.answer');

            Route::post('issues/{ticketIssue}/inspections', [IssueInspectionController::class, 'store'])->name('tickets.issues.inspections.store');
            Route::post('issues/{ticketIssue}/inspections/{inspection}/result', [IssueInspectionController::class, 'result'])->name('tickets.issues.inspections.result');

            Route::post('issues/{ticketIssue}/approval-request', [IssueApprovalController::class, 'ask'])->name('tickets.issues.approval-request');
            Route::post('issues/{ticketIssue}/approvals', [IssueApprovalController::class, 'store'])->name('tickets.issues.approvals.store');

            Route::post('issues/{ticketIssue}/schedule', [IssueScheduleController::class, 'store'])->name('tickets.issues.schedule');
            Route::post('issues/{ticketIssue}/schedules/{assignment}/move', [IssueScheduleController::class, 'move'])->name('tickets.issues.schedules.move');

            Route::post('issues/{ticketIssue}/work-done', [IssueWorkflowController::class, 'workDone'])->name('tickets.issues.work-done');
            Route::post('issues/{ticketIssue}/confirmation', [IssueWorkflowController::class, 'confirmation'])->name('tickets.issues.confirmation');
            Route::post('issues/{ticketIssue}/close', [IssueWorkflowController::class, 'close'])->name('tickets.issues.close');
            Route::post('issues/{ticketIssue}/jump', [IssueWorkflowController::class, 'jump'])->name('tickets.issues.jump');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Store reads about catalog items. NOT scopeBindings(): a catalog issue is
    | not a child of a store, and scoping would make Laravel call the
    | non-existent Store::issues() and 500.
    |--------------------------------------------------------------------------
    */
    Route::prefix('stores/{store}')->group(function () {
        // Earlier tickets at this store for the same catalog issue. withTrashed:
        // history stays readable after the issue leaves the catalog.
        Route::get('issues/{issue}/history', [IssueHistoryController::class, 'index'])
            ->withTrashed()
            ->name('stores.issues.history');

        // "This fixed it": troubleshooting solved the problem, no ticket opened.
        Route::post('troubleshooting-fixes', [TroubleshootingController::class, 'fix'])->name('stores.troubleshooting-fixes.store');
    });

    /*
    |--------------------------------------------------------------------------
    | Leaf-record actions. Not scope-bound, because these records (a booking,
    | a warranty) are not direct children of the ticket — they reach it
    | through its issues and are bound by id. Their FormRequests check the
    | record is the ticket's and the ticket the store's
    | (Requests\Concerns\ChecksTicketRecord).
    |--------------------------------------------------------------------------
    */
    Route::prefix('stores/{store}/tickets/{ticket}')->group(function () {
        // Lock / unlock any note on this ticket (private = MOS only).
        Route::patch('notes/{note}/privacy', [TicketNoteController::class, 'privacy'])->name('stores.tickets.notes.privacy');

        // Notes & attachments for each leaf workflow record (any number).
        Route::post('warranties/{warranty}/notes', [NoteController::class, 'warranty'])->middleware('throttle:api-upload')->name('tickets.warranties.notes');
        Route::post('warranties/{warranty}/attachments', [AttachmentController::class, 'warranty'])->middleware('throttle:api-upload')->name('tickets.warranties.attachments');
        Route::post('assignments/{assignment}/notes', [NoteController::class, 'assignment'])->middleware('throttle:api-upload')->name('tickets.assignments.notes');
        Route::post('assignments/{assignment}/attachments', [AttachmentController::class, 'assignment'])->middleware('throttle:api-upload')->name('tickets.assignments.attachments');
    });
});
