<?php

namespace App\Http\Controllers;

use App\Http\Requests\PartUnitUpdateRequest;
use App\Models\Part;
use App\Models\PartUnit;
use App\Services\CatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The individual pieces of serial-tracked parts (docs/MAINTENANCE-V2.md §1).
 * Units are created and moved by stock movements and parts payment lines;
 * here they are only read and corrected.
 */
class PartUnitController extends Controller
{
    public function __construct(private CatalogService $catalog) {}

    public function index(Request $request, Part $part): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::in(PartUnit::STATUSES)],
        ]);

        return response()->json(['data' => $this->catalog->listPartUnits($part, $request->query('status'))]);
    }

    public function update(PartUnitUpdateRequest $request, PartUnit $unit): JsonResponse
    {
        return response()->json(['data' => $this->catalog->updatePartUnit($unit, $request->validated())]);
    }
}
