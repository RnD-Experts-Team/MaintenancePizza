<?php

namespace App\Http\Controllers;

use App\Http\Requests\TechnicianPurchaseIndexRequest;
use App\Http\Requests\TechnicianPurchaseMistakenRequest;
use App\Http\Requests\TechnicianPurchaseStoreRequest;
use App\Models\TechnicianPurchase;
use App\Models\Trip;
use App\Models\User;
use App\Services\TechnicianPurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Technician purchases from the dashboard (docs/MAINTENANCE-V2.md §4
 * "Technician purchases (dashboard)"): the list (by technician, unpaid
 * only...), one typed in by the office, and marking one as a mistake. Paying
 * them back is a parts payment line (payments).
 */
class TechnicianPurchaseController extends Controller
{
    public function __construct(private TechnicianPurchaseService $purchases) {}

    public function index(TechnicianPurchaseIndexRequest $request): JsonResponse
    {
        return response()->json([
            'data' => $this->purchases->list($request->validated())
                ->map(fn (TechnicianPurchase $p) => $this->purchases->present($p))
                ->values()->all(),
        ]);
    }

    public function store(TechnicianPurchaseStoreRequest $request): JsonResponse
    {
        $data = $request->validated();

        if (isset($data['trip_id'], $data['ticket_issue_id'])) {
            $data['trip_stop_id'] = $this->purchases->stopIdFor(Trip::query()->findOrFail($data['trip_id']), (int) $data['ticket_issue_id']);
        }

        $purchase = $this->purchases->create($data, (array) $request->file('receipt', []), $this->user());

        return response()->json(['data' => $this->purchases->present($purchase->refresh())], 201);
    }

    public function mistaken(TechnicianPurchaseMistakenRequest $request, TechnicianPurchase $technicianPurchase): JsonResponse
    {
        $this->purchases->markMistaken($technicianPurchase, $request->validated('reason'), $this->user());

        return response()->json(['data' => $this->purchases->present($technicianPurchase->refresh())]);
    }

    private function user(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
