<?php

namespace App\Http\Controllers;

use App\Http\Requests\PaymentMistakenRequest;
use App\Http\Requests\PaymentRequest;
use App\Http\Requests\StoreAttachmentRequest;
use App\Http\Requests\StoreNoteRequest;
use App\Models\Payment;
use App\Services\AttachmentService;
use App\Services\NoteService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Payments and unpaid work (docs/MAINTENANCE.md §4). All logic lives in
 * PaymentService; the shapes in PaymentPresenter.
 */
class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $payments,
        private NoteService $notes,
        private AttachmentService $attachments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'technician_id' => ['nullable', 'integer'],
            'supplier_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
        ]);

        $result = $this->payments->list(
            $request->only(['from', 'to', 'technician_id', 'supplier_id', 'category_id', 'store', 'type', 'search', 'include_mistaken']),
            max(1, min(200, $request->integer('per_page', 25))),
        );
        $page = $result['page'];

        return response()->json([
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'totals' => $result['totals'],
            ],
        ]);
    }

    public function store(PaymentRequest $request): JsonResponse
    {
        $payment = $this->payments->create($request->validated(), (array) $request->file('files', []));

        return response()->json(['data' => $this->payments->show($payment)], 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        return response()->json(['data' => $this->payments->show($payment)]);
    }

    public function edit(PaymentRequest $request, Payment $payment): JsonResponse
    {
        $payment = $this->payments->edit($payment, $request->validated(), (array) $request->file('files', []));

        return response()->json(['data' => $this->payments->show($payment)]);
    }

    public function mistaken(PaymentMistakenRequest $request, Payment $payment): JsonResponse
    {
        $payment = $this->payments->markMistaken($payment, $request->validated()['reason']);

        return response()->json(['data' => $this->payments->show($payment)]);
    }

    public function notesStore(StoreNoteRequest $request, Payment $payment): JsonResponse
    {
        $data = $request->validated();
        $note = $this->notes->store($payment, $data['body'], $data['type'] ?? null, (array) $request->file('files', []));

        return response()->json(['data' => $this->notes->present($note)], 201);
    }

    public function attachmentsStore(StoreAttachmentRequest $request, Payment $payment): JsonResponse
    {
        $created = $this->attachments->store($payment, (array) $request->file('files', []));

        return response()->json([
            'data' => array_map(fn ($a) => $this->attachments->present($a), $created),
        ], 201);
    }

    /**
     * Unpaid sessions and purchases, per technician.
     */
    public function unpaidWork(Request $request): JsonResponse
    {
        $request->validate(['technician_id' => ['nullable', 'integer']]);

        return response()->json([
            'data' => $this->payments->unpaidWork($request->filled('technician_id') ? $request->integer('technician_id') : null),
        ]);
    }
}
