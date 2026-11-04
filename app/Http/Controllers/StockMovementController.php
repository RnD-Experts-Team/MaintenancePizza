<?php

namespace App\Http\Controllers;

use App\Enums\StockMovementType;
use App\Http\Requests\StoreStockMovementRequest;
use App\Models\StockMovement;
use App\Services\AttachmentService;
use App\Services\NoteService;
use App\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function __construct(
        private StockService $stock,
        private NoteService $notes,
        private AttachmentService $attachments,
    ) {}

    public function index(Request $request)
    {
        return $this->stock->listMovements($request->only([
            'types',
            'part_ids',
            'storage_location_ids',
            'paid_by',
            'paid_by_technician_ids',
            'ticket_issue_ids',
            'stock_count_id',
            'payment_id',
            'moved_from',
            'moved_to',
            'per_page',
            'sort',
            'dir',
        ]));
    }

    /**
     * Record stock moving by hand: used (optionally on a ticket issue),
     * returned, moved, opening stock, damaged or lost. Serial-tracked parts
     * name their pieces on each line (`serials[]`).
     */
    public function store(StoreStockMovementRequest $request): JsonResponse
    {
        $data = $request->validated();
        $type = StockMovementType::from($data['type']);

        $movement = DB::transaction(function () use ($request, $data, $type) {
            $movement = $this->stock->record(
                [
                    'moved_at' => $data['moved_at'],
                    'type' => $type,
                    'storage_location_id' => $data['storage_location_id'] ?? null,
                    'paid_by' => $data['paid_by'] ?? null,
                    'paid_by_technician_id' => $data['paid_by_technician_id'] ?? null,
                    'technician_id' => $data['technician_id'] ?? null,
                    'ticket_issue_id' => $type === StockMovementType::Used ? ($data['ticket_issue_id'] ?? null) : null,
                    'body' => $data['body'] ?? null,
                ],
                array_map(fn (array $line) => $line + ['direction' => $type->defaultDirection()], $data['lines']),
            );

            $this->attachments->store($movement, (array) $request->file('files', []));

            foreach ($data['notes'] ?? [] as $i => $note) {
                $this->notes->store(
                    $movement,
                    $note['body'],
                    $note['type'] ?? null,
                    (array) $request->file("notes.{$i}.files", []),
                );
            }

            return $movement;
        });

        return response()->json(['data' => $this->stock->presentMovement($this->load($movement))], 201);
    }

    public function show(StockMovement $stockMovement): JsonResponse
    {
        return response()->json(['data' => $this->stock->presentMovement($this->load($stockMovement))]);
    }

    /**
     * Flag a movement as a mistake and write the equal-and-opposite correction.
     * The original stays exactly as recorded — the ledger is append-only.
     */
    public function mistaken(StockMovement $stockMovement): JsonResponse
    {
        // Parts bought on a payment are undone with the payment (edit it or
        // mark it mistaken), so the payment and the shelf never disagree.
        if ($stockMovement->type === StockMovementType::Bought && $stockMovement->payment_line_id !== null && ! $stockMovement->mistaken) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'status' => 'These parts were bought on a payment: edit that payment or mark it mistaken instead.',
            ]);
        }

        $reversal = $this->stock->reverse($stockMovement);

        return response()->json([
            'data' => $this->stock->presentMovement($this->load($stockMovement->refresh())),
            'reversal' => $this->stock->presentMovement($this->load($reversal)),
        ]);
    }

    private function load(StockMovement $movement): StockMovement
    {
        return $movement->load(StockService::MOVEMENT_WITH);
    }
}
