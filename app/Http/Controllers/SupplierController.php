<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Requests\StoreNoteRequest;
use App\Http\Requests\SupplierRequest;
use App\Models\Supplier;
use App\Services\AttachmentService;
use App\Services\NoteService;
use App\Services\SupplierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Suppliers (docs/MAINTENANCE.md §1): the places we buy from.
 */
class SupplierController extends Controller
{
    public function __construct(
        private SupplierService $suppliers,
        private NoteService $notes,
        private AttachmentService $attachments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->suppliers->list(
            $request->only(['search', 'with_trashed']),
            max(1, min(200, $request->integer('per_page', 50))),
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

    public function store(SupplierRequest $request): JsonResponse
    {
        $supplier = $this->suppliers->create($request->validated());

        return response()->json(['data' => $this->suppliers->show($supplier)], 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => $this->suppliers->show($supplier)]);
    }

    public function update(SupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $this->suppliers->update($supplier, $request->validated());

        return response()->json(['data' => $this->suppliers->show($supplier)]);
    }

    public function destroy(Supplier $supplier): Response
    {
        $this->suppliers->delete($supplier);

        return response()->noContent();
    }

    public function restore(Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => $this->suppliers->show($this->suppliers->restore($supplier))]);
    }

    public function notesStore(StoreNoteRequest $request, Supplier $supplier): JsonResponse
    {
        $data = $request->validated();
        $note = $this->notes->store($supplier, $data['body'], $data['type'] ?? null, (array) $request->file('files', []));

        return response()->json(['data' => $this->notes->present($note)], 201);
    }

    public function attachmentsStore(StoreAttachmentRequest $request, Supplier $supplier): JsonResponse
    {
        $created = $this->attachments->store($supplier, (array) $request->file('files', []));

        return response()->json([
            'data' => array_map(fn ($a) => $this->attachments->present($a), $created),
        ], 201);
    }
}
