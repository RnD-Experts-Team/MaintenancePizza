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
            'moved_from',
            'moved_to',
            'per_page',
            'sort',
            'dir',
        ]));
    }

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
        $reversal = $this->stock->reverse($stockMovement);

        return response()->json([
            'data' => $this->stock->presentMovement($this->load($stockMovement->refresh())),
            'reversal' => $this->stock->presentMovement($this->load($reversal)),
        ]);
    }

    private function load(StockMovement $movement): StockMovement
    {
        return $movement->load([
            'lines.part',
            'lines.storageLocation',
            'storageLocation',
            'paidByTechnician',
            'creator',
            'notes.creator',
            'notes.attachments.creator',
            'attachments.creator',
        ]);
    }
}
