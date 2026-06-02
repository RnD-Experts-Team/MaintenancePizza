<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePartUsageRequest;
use App\Models\PartUsage;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\WorkflowRecordService;

class PartUsageController extends Controller
{
    public function __construct(private WorkflowRecordService $workflow) {}

    public function store(StorePartUsageRequest $request, Store $store, Ticket $ticket)
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->workflow->createPartUsage(
                $data['ticket_issue_ids'],
                $data['part_id'],
                $data['cost'],
                (array) $request->file('files', []),
            ),
        ], 201);
    }

    public function mistaken(Store $store, Ticket $ticket, PartUsage $partUsage)
    {
        return ['data' => $this->workflow->markPartUsageMistaken($partUsage)];
    }
}
