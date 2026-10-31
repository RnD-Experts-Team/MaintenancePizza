<?php

use App\Http\Controllers\StockCountController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Maintenance: storage (docs/MAINTENANCE.md)
|--------------------------------------------------------------------------
| Required from routes/api.php INSIDE the auth.token.store group, at the top
| level (no prefix): write full paths from the /api root.
*/

// Stock counts: a sheet per location (or place), applied as one stock_count movement.
Route::get('stock-counts', [StockCountController::class, 'index'])->name('stock-counts.index');
Route::post('stock-counts', [StockCountController::class, 'store'])->name('stock-counts.store');
Route::get('stock-counts/{stockCount}', [StockCountController::class, 'show'])->name('stock-counts.show');
Route::patch('stock-counts/{stockCount}', [StockCountController::class, 'update'])->name('stock-counts.update');
Route::post('stock-counts/{stockCount}/apply', [StockCountController::class, 'apply'])->name('stock-counts.apply');
Route::delete('stock-counts/{stockCount}', [StockCountController::class, 'destroy'])->name('stock-counts.destroy');
