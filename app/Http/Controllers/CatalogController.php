<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreCategoryRequest;
use App\Http\Requests\StoreIssueRequest;
use App\Http\Requests\StorePartRequest;
use App\Http\Requests\StoreTechnicianRequest;
use App\Models\Category;
use App\Models\Issue;
use App\Models\Part;
use App\Models\Technician;
use App\Services\CatalogService;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Single controller for every controlled catalog (issues, technicians,
 * categories, parts). All logic lives in CatalogService; methods stay thin.
 */
class CatalogController extends Controller
{
    public function __construct(
        private CatalogService $catalog,
        private StockService $stock,
    ) {}

    // ------------------------------------------------------------------ Issues

    public function issuesIndex(Request $request)
    {
        return $this->catalog->listIssues($request->query('trashed'), $request->integer('per_page', 15));
    }

    public function issuesStore(StoreIssueRequest $request)
    {
        return response()->json(['data' => $this->catalog->createIssue($request->validated())], 201);
    }

    public function issuesDestroy(Issue $issue): Response
    {
        $this->catalog->deleteIssue($issue);

        return response()->noContent();
    }

    public function issuesRestore(Issue $issue)
    {
        return ['data' => $this->catalog->restoreIssue($issue)];
    }

    // ------------------------------------------------------------- Technicians

    public function techniciansIndex(Request $request)
    {
        return $this->catalog->listTechnicians($request->query('trashed'), $request->integer('per_page', 15));
    }

    public function techniciansStore(StoreTechnicianRequest $request)
    {
        return response()->json(['data' => $this->catalog->createTechnician($request->validated())], 201);
    }

    public function techniciansDestroy(Technician $technician): Response
    {
        $this->catalog->deleteTechnician($technician);

        return response()->noContent();
    }

    public function techniciansRestore(Technician $technician)
    {
        return ['data' => $this->catalog->restoreTechnician($technician)];
    }

    // -------------------------------------------------------------- Categories

    public function categoriesIndex(Request $request)
    {
        return $this->catalog->listCategories($request->integer('per_page', 15));
    }

    public function categoriesStore(StoreCategoryRequest $request)
    {
        return response()->json(['data' => $this->catalog->createCategory($request->validated())], 201);
    }

    public function categoriesDestroy(Category $category): Response
    {
        $this->catalog->deleteCategory($category);

        return response()->noContent();
    }

    // ------------------------------------------------------------------- Parts

    public function partsIndex(Request $request)
    {
        return $this->catalog->listParts($request->query('trashed'), $request->integer('per_page', 15));
    }

    public function partsStore(StorePartRequest $request)
    {
        return response()->json(['data' => $this->catalog->createPart($request->validated())], 201);
    }

    public function partsDestroy(Part $part): Response
    {
        $this->catalog->deletePart($part);

        return response()->noContent();
    }

    public function partsRestore(Part $part)
    {
        return ['data' => $this->catalog->restorePart($part)];
    }

    /**
     * What we have paid for this part, and when.
     *
     * The honest answer to "what does this cost us" once the price has moved:
     * not one number, but the actual purchases. Bought 12 at 35 in June, 8 at 40
     * in September. No valuation method to argue about, and nothing averaged
     * into a figure nobody chose.
     */
    public function partsPriceHistory(Part $part)
    {
        return ['data' => $this->stock->priceHistory($part->id)];
    }
}
