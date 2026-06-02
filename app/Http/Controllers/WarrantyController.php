<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWarrantyRequest;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\WorkflowRecordService;

class WarrantyController extends Controller
{
    public function __construct(private WorkflowRecordService $workflow) {}

    public function store(StoreWarrantyRequest $request, Store $store, Ticket $ticket)
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->workflow->createWarranty(
                $data['ticket_issue_ids'],
                $data['body'],
                (array) $request->file('files', []),
            ),
        ], 201);
    }
}
