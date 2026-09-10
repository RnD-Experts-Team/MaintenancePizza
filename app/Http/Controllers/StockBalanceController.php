<?php

namespace App\Http\Controllers;

use App\Services\StockService;
use Illuminate\Http\Request;

/**
 * What is on hand, per part per location.
 */
class StockBalanceController extends Controller
{
    public function __construct(private StockService $stock) {}

    public function __invoke(Request $request)
    {
        return $this->stock->listBalances($request->only([
            'part_ids',
            'storage_location_ids',
            'non_zero',
            'per_page',
        ]));
    }
}
