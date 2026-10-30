<?php

use App\Http\Controllers\PartUnitController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\TicketIssueController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Maintenance v2: catalog (docs/MAINTENANCE-V2.md)
|--------------------------------------------------------------------------
| Required from routes/api.php INSIDE the auth.token.store group, at the top
| level (no prefix): write full paths from the /api root.
*/

// Suppliers: the places we buy from. Names unique like technicians'.
Route::get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->withTrashed()->name('suppliers.show');
Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->name('suppliers.update');
Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->name('suppliers.destroy');
Route::post('suppliers/{supplier}/restore', [SupplierController::class, 'restore'])->withTrashed()->name('suppliers.restore');
Route::post('suppliers/{supplier}/notes', [SupplierController::class, 'notesStore'])->name('suppliers.notes');
Route::post('suppliers/{supplier}/attachments', [SupplierController::class, 'attachmentsStore'])->name('suppliers.attachments');

// The pieces of serial-tracked parts.
Route::get('parts/{part}/units', [PartUnitController::class, 'index'])->withTrashed()->name('parts.units.index');
Route::patch('part-units/{unit}', [PartUnitController::class, 'update'])->name('part-units.update');

// The issue pickers: a light lookup across every store (not the full read).
Route::get('ticket-issues', [TicketIssueController::class, 'lookup'])->name('ticket-issues.lookup');
