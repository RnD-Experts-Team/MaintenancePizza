<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Maintenance v2: payments (docs/MAINTENANCE-V2.md)
|--------------------------------------------------------------------------
| Required from routes/api.php INSIDE the auth.token.store group, at the top
| level (no prefix): write full paths from the /api root.
*/

// Payments: one invoice paid to one technician or one supplier, split into lines.
Route::get('payments', [\App\Http\Controllers\PaymentController::class, 'index'])->name('payments.index');
Route::post('payments', [\App\Http\Controllers\PaymentController::class, 'store'])->name('payments.store');
Route::get('payments/{payment}', [\App\Http\Controllers\PaymentController::class, 'show'])->name('payments.show');
Route::post('payments/{payment}/edit', [\App\Http\Controllers\PaymentController::class, 'edit'])->name('payments.edit');
Route::post('payments/{payment}/mistaken', [\App\Http\Controllers\PaymentController::class, 'mistaken'])->name('payments.mistaken');
Route::post('payments/{payment}/notes', [\App\Http\Controllers\PaymentController::class, 'notesStore'])->name('payments.notes');
Route::post('payments/{payment}/attachments', [\App\Http\Controllers\PaymentController::class, 'attachmentsStore'])->name('payments.attachments');

// What has not been paid yet, per technician: unpaid sessions and purchases.
Route::get('unpaid-work', [\App\Http\Controllers\PaymentController::class, 'unpaidWork'])->name('unpaid-work.index');
