<?php

namespace App\Http\Controllers;

use App\Http\Requests\StockCountCreateRequest;
use App\Http\Requests\StockCountUpdateRequest;
use App\Models\StockCount;
use App\Services\StockCountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Stock counts (docs/MAINTENANCE-V2.md §5). All logic in StockCountService.
 */
class StockCountController extends Controller
{
    public function __construct(private StockCountService $counts) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'storage_location_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['draft', 'applied'])],
        ]);

        $page = $this->counts->list(
            $request->only(['storage_location_id', 'status']),
            max(1, min(200, $request->integer('per_page', 25))),
        );

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function store(StockCountCreateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $count = $this->counts->create(
            (int) $data['storage_location_id'],
            isset($data['storage_place_value_id']) ? (int) $data['storage_place_value_id'] : null,
            $data['notes'] ?? null,
        );

        return response()->json(['data' => $this->counts->show($count)], 201);
    }

    public function show(StockCount $stockCount): JsonResponse
    {
        return response()->json(['data' => $this->counts->show($stockCount)]);
    }

    public function update(StockCountUpdateRequest $request, StockCount $stockCount): JsonResponse
    {
        $data = $request->validated();
        $this->counts->update($stockCount, $data['lines'] ?? [], array_key_exists('notes', $data), $data['notes'] ?? null);

        return response()->json(['data' => $this->counts->show($stockCount->refresh())]);
    }

    public function apply(StockCount $stockCount): JsonResponse
    {
        return response()->json(['data' => $this->counts->show($this->counts->apply($stockCount))]);
    }

    public function destroy(StockCount $stockCount): Response
    {
        $this->counts->delete($stockCount);

        return response()->noContent();
    }
}
