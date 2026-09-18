<?php

namespace App\Http\Controllers;

use App\Services\StockService;
use Illuminate\Http\Request;

/**
 * What is on hand.
 *
 * Two shapes of the same truth. The default is one row per (part, location),
 * which is how the balances are actually stored and the only shape that can
 * answer "which shelf". ?group_by=part rolls those up into one row per part
 * with the cross-location total, which is the figure a human wants first --
 * and which cannot be derived on the client, because this endpoint paginates.
 */
class StockBalanceController extends Controller
{
    public function __construct(private StockService $stock) {}

    public function __invoke(Request $request)
    {
        $filters = $request->only([
            'part_ids',
            'storage_location_ids',
            'non_zero',
            'per_page',
        ]);

        return $request->query('group_by') === 'part'
            ? $this->stock->listBalancesByPart($filters)
            : $this->stock->listBalances($filters);
    }
}
