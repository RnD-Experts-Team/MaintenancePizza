<?php

use App\Http\Controllers\AssignmentController;
use App\Http\Controllers\DailyPayEntryController;
use App\Http\Controllers\AssignmentDelayController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AttendanceEntryController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DiagnosisController;
use App\Http\Controllers\ExportController;
use App\Http\Controllers\NoteController;
use App\Http\Controllers\PartUsageController;
use App\Http\Controllers\PayEntryController;
use App\Http\Controllers\TechnicianAssignmentController;
use App\Http\Controllers\TicketCancellationController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TicketFinalNoteController;
use App\Http\Controllers\TicketIssueCancellationController;
use App\Http\Controllers\TicketIssueController;
use App\Http\Controllers\TicketIssueDeferralController;
use App\Http\Controllers\TicketIssueStatusController;
use App\Http\Controllers\WarrantyController;
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
    | Notes & attachments for the global catalogs. Any number can be appended
    | (one note + optional files per note call; one or many files per upload).
    |--------------------------------------------------------------------------
    */
    Route::post('issues/{issue}/notes', [NoteController::class, 'catalogIssue'])->name('issues.notes');
    Route::post('issues/{issue}/attachments', [AttachmentController::class, 'catalogIssue'])->name('issues.attachments');
    Route::post('technicians/{technician}/notes', [NoteController::class, 'technician'])->name('technicians.notes');
    Route::post('technicians/{technician}/attachments', [AttachmentController::class, 'technician'])->name('technicians.attachments');
    Route::post('categories/{category}/notes', [NoteController::class, 'category'])->name('categories.notes');
    Route::post('categories/{category}/attachments', [AttachmentController::class, 'category'])->name('categories.attachments');
    Route::post('parts/{part}/notes', [NoteController::class, 'part'])->name('parts.notes');
    Route::post('parts/{part}/attachments', [AttachmentController::class, 'part'])->name('parts.attachments');

    /*
    |--------------------------------------------------------------------------
    | Daily Pay Entries (global, not store-scoped)
    |--------------------------------------------------------------------------
    */
    Route::get('daily-pay-entries', [DailyPayEntryController::class, 'index'])->name('daily-pay-entries.index');
    Route::post('daily-pay-entries', [DailyPayEntryController::class, 'store'])->name('daily-pay-entries.store');
    Route::get('daily-pay-entries/{dailyPayEntry}', [DailyPayEntryController::class, 'show'])->name('daily-pay-entries.show');
    Route::post('daily-pay-entries/{dailyPayEntry}/edit', [DailyPayEntryController::class, 'edit'])->name('daily-pay-entries.edit');

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
        Route::post('tickets/{ticket}/cancel', TicketCancellationController::class)->name('stores.tickets.cancel');
        // Append a typed closing note (Final Notes / What we learned) — many allowed.
        Route::post('tickets/{ticket}/final-note', TicketFinalNoteController::class)->name('stores.tickets.final-note');

        // Generic notes & attachments on the ticket and on the store itself.
        Route::post('tickets/{ticket}/notes', [NoteController::class, 'ticket'])->name('stores.tickets.notes');
        Route::post('tickets/{ticket}/attachments', [AttachmentController::class, 'ticket'])->name('stores.tickets.attachments');
        Route::post('notes', [NoteController::class, 'store'])->name('stores.notes');
        Route::post('attachments', [AttachmentController::class, 'store'])->name('stores.attachments');

        Route::prefix('tickets/{ticket}')->group(function () {
            // The "one look" lifecycle views.
            Route::get('issues', [TicketIssueController::class, 'index'])->name('tickets.issues.index');
            Route::get('issues/{ticketIssue}', [TicketIssueController::class, 'show'])->name('tickets.issues.show');

            // Issue state transitions.
            Route::post('issues/status', [TicketIssueStatusController::class, 'store'])->name('tickets.issues.status');
            Route::post('issues/{ticketIssue}/defer', TicketIssueDeferralController::class)->name('tickets.issues.defer');
            Route::post('issues/{ticketIssue}/cancel', TicketIssueCancellationController::class)->name('tickets.issues.cancel');

            // Generic notes & attachments on an individual issue.
            Route::post('issues/{ticketIssue}/notes', [NoteController::class, 'ticketIssue'])->name('tickets.issues.notes');
            Route::post('issues/{ticketIssue}/attachments', [AttachmentController::class, 'ticketIssue'])->name('tickets.issues.attachments');

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
        Route::post('warranties/{warranty}/mistaken', [WarrantyController::class, 'mistaken'])->name('tickets.warranties.mistaken');
        Route::post('assignments/{assignment}/mistaken', [AssignmentController::class, 'mistaken'])->name('tickets.assignments.mistaken');
        Route::post('assignments/{assignment}/delays/{delay}/mistaken', [AssignmentDelayController::class, 'mistaken'])->name('tickets.assignments.delays.mistaken');

        // Notes & attachments for each leaf workflow record (any number).
        Route::post('diagnoses/{diagnosis}/notes', [NoteController::class, 'diagnosis'])->name('tickets.diagnoses.notes');
        Route::post('diagnoses/{diagnosis}/attachments', [AttachmentController::class, 'diagnosis'])->name('tickets.diagnoses.attachments');
        Route::post('attendance-entries/{attendanceEntry}/notes', [NoteController::class, 'attendance'])->name('tickets.attendance.notes');
        Route::post('attendance-entries/{attendanceEntry}/attachments', [AttachmentController::class, 'attendance'])->name('tickets.attendance.attachments');
        Route::post('part-usages/{partUsage}/notes', [NoteController::class, 'partUsage'])->name('tickets.part-usages.notes');
        Route::post('part-usages/{partUsage}/attachments', [AttachmentController::class, 'partUsage'])->name('tickets.part-usages.attachments');
        Route::post('pay-entries/{payEntry}/notes', [NoteController::class, 'payEntry'])->name('tickets.pay-entries.notes');
        Route::post('pay-entries/{payEntry}/attachments', [AttachmentController::class, 'payEntry'])->name('tickets.pay-entries.attachments');
        Route::post('warranties/{warranty}/notes', [NoteController::class, 'warranty'])->name('tickets.warranties.notes');
        Route::post('warranties/{warranty}/attachments', [AttachmentController::class, 'warranty'])->name('tickets.warranties.attachments');
        Route::post('assignments/{assignment}/notes', [NoteController::class, 'assignment'])->name('tickets.assignments.notes');
        Route::post('assignments/{assignment}/attachments', [AttachmentController::class, 'assignment'])->name('tickets.assignments.attachments');
    });
});
