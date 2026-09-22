<?php

namespace App\Http\Controllers;

use App\Http\Requests\SetStockBalancePlaceRequest;
use App\Models\StockBalance;
use App\Services\StockService;
use Illuminate\Http\Request;

/**
 * What is on hand, and where inside the location it sits.
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

    public function index(Request $request)
    {
        $filters = $request->only([
            'part_ids',
            'storage_location_ids',
            'non_zero',
            'negative_only',
            'per_page',
        ]);

        return $request->query('group_by') === 'part'
            ? $this->stock->listBalancesByPart($filters)
            : $this->stock->listBalances($filters);
    }

    /**
     * Say where this part sits inside its location.
     *
     * PUT rather than PATCH because it replaces the whole address: a level left
     * out of the payload is cleared. A part has one address per location, so a
     * partial update has nothing to mean, and an empty list is how you say "we
     * no longer know" -- which is different from never having said.
     *
     * This is the write path the slots feature never had. The catalogue, the
     * read path and the display all existed; nothing could ever set one.
     */
    public function setPlace(SetStockBalancePlaceRequest $request, StockBalance $stockBalance)
    {
        return [
            'data' => $this->stock->setBalancePlace(
                $stockBalance,
                $request->validated()['place_value_ids'],
            ),
        ];
    }
}
