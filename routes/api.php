<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\AssignmentDelayController;
use App\Http\Controllers\AttendanceEntryController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DiagnosisController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\PartUsageController;
use App\Http\Controllers\PayEntryController;
use App\Http\Controllers\TechnicianAssignmentController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketFinalNoteController;
use App\Http\Controllers\TicketIssueController;
use App\Http\Controllers\TicketIssueDeferralController;
use App\Http\Controllers\TicketIssueStatusController;
use App\Http\Controllers\WarrantyController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth.token.store')->group(function () {
    /*
    |--------------------------------------------------------------------------
    | Controlled reference catalogs (global, not store-scoped)
    |--------------------------------------------------------------------------
    */
    Route::get('issues', [CatalogController::class, 'issuesIndex'])->name('issues.index');
    Route::post('issues', [CatalogController::class, 'issuesStore'])->name('issues.store');
    Route::delete('issues/{issue}', [CatalogController::class, 'issuesDestroy'])->name('issues.destroy');
    Route::post('issues/{issue}/restore', [CatalogController::class, 'issuesRestore'])->withTrashed()->name('issues.restore');

    Route::get('technicians', [CatalogController::class, 'techniciansIndex'])->name('technicians.index');
    Route::post('technicians', [CatalogController::class, 'techniciansStore'])->name('technicians.store');
    Route::delete('technicians/{technician}', [CatalogController::class, 'techniciansDestroy'])->name('technicians.destroy');
    Route::post('technicians/{technician}/restore', [CatalogController::class, 'techniciansRestore'])->withTrashed()->name('technicians.restore');

    Route::get('categories', [CatalogController::class, 'categoriesIndex'])->name('categories.index');
    Route::post('categories', [CatalogController::class, 'categoriesStore'])->name('categories.store');
    Route::delete('categories/{category}', [CatalogController::class, 'categoriesDestroy'])->name('categories.destroy');

    Route::get('parts', [CatalogController::class, 'partsIndex'])->name('parts.index');
    Route::post('parts', [CatalogController::class, 'partsStore'])->name('parts.store');
    Route::delete('parts/{part}', [CatalogController::class, 'partsDestroy'])->name('parts.destroy');
    Route::post('parts/{part}/restore', [CatalogController::class, 'partsRestore'])->withTrashed()->name('parts.restore');

    /*
    |--------------------------------------------------------------------------
    | Tickets — global index + Excel export
    |--------------------------------------------------------------------------
    */
    Route::get('tickets', [TicketController::class, 'globalIndex'])->name('tickets.global');
    Route::get('export/excel', ExportController::class)->name('export.excel')->withoutMiddleware('auth.token.store')->middleware('auth.secret.key');

    /*
    |--------------------------------------------------------------------------
    | Store-scoped tickets + issue workflow.
    | {store} binds by store_number; scopeBindings keeps {ticket} and
    | {ticketIssue} within their parent.
    |--------------------------------------------------------------------------
    */
    Route::prefix('stores/{store}')->scopeBindings()->group(function () {
        Route::get('tickets', [TicketController::class, 'index'])->name('stores.tickets.index');
        Route::post('tickets', [TicketController::class, 'store'])->name('stores.tickets.store');
        Route::delete('tickets/{ticket}', [TicketController::class, 'destroy'])->name('stores.tickets.destroy');
        Route::post('tickets/{ticket}/restore', [TicketController::class, 'restore'])->withTrashed()->name('stores.tickets.restore');
        Route::post('tickets/{ticket}/final-note', TicketFinalNoteController::class)->name('stores.tickets.final-note');

        Route::prefix('tickets/{ticket}')->group(function () {
            // The "one look" lifecycle views.
            Route::get('issues', [TicketIssueController::class, 'index'])->name('tickets.issues.index');
            Route::get('issues/{ticketIssue}', [TicketIssueController::class, 'show'])->name('tickets.issues.show');

            // Issue state transitions.
            Route::post('issues/status', [TicketIssueStatusController::class, 'store'])->name('tickets.issues.status');
            Route::post('issues/{ticketIssue}/defer', TicketIssueDeferralController::class)->name('tickets.issues.defer');

            // Creating workflow records (each targets one-or-many issues).
            Route::post('assignments', [AssignmentController::class, 'store'])->name('tickets.assignments.store');
            Route::post('diagnoses', [DiagnosisController::class, 'store'])->name('tickets.diagnoses.store');
            Route::post('attendance-entries', [AttendanceEntryController::class, 'store'])->name('tickets.attendance.store');
            Route::post('part-usages', [PartUsageController::class, 'store'])->name('tickets.part-usages.store');
            Route::post('pay-entries', [PayEntryController::class, 'store'])->name('tickets.pay-entries.store');
            Route::post('warranties', [WarrantyController::class, 'store'])->name('tickets.warranties.store');
            Route::post('technicians', [TechnicianAssignmentController::class, 'store'])->name('tickets.technicians.attach');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Leaf-record actions. Not scope-bound, because these records (assignment,
    | diagnosis, ...) are not direct children of the ticket — they are bound by id.
    |--------------------------------------------------------------------------
    */
    Route::prefix('stores/{store}/tickets/{ticket}')->group(function () {
        Route::post('assignments/{assignment}/delays', [AssignmentDelayController::class, 'store'])->name('tickets.assignments.delays');
        Route::post('assignments/{assignment}/change-technicians', [AssignmentController::class, 'changeTechnicians'])->name('tickets.assignments.change-technicians');

        Route::post('diagnoses/{diagnosis}/mistaken', [DiagnosisController::class, 'mistaken'])->name('tickets.diagnoses.mistaken');
        Route::post('attendance-entries/{attendanceEntry}/mistaken', [AttendanceEntryController::class, 'mistaken'])->name('tickets.attendance.mistaken');
        Route::post('part-usages/{partUsage}/mistaken', [PartUsageController::class, 'mistaken'])->name('tickets.part-usages.mistaken');
        Route::post('pay-entries/{payEntry}/mistaken', [PayEntryController::class, 'mistaken'])->name('tickets.pay-entries.mistaken');
    });
});
