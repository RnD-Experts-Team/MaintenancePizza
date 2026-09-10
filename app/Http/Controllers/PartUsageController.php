<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePartUsageRequest;
use App\Models\PartUsage;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\WorkflowRecordService;
use Illuminate\Http\Request;

class PartUsageController extends Controller
{
    public function __construct(private WorkflowRecordService $workflow) {}

    public function store(StorePartUsageRequest $request, Store $store, Ticket $ticket)
    {
        $noteFiles = [];

        foreach ($request->input('notes', []) as $i => $_) {
            $noteFiles[$i] = (array) $request->file("notes.{$i}.files", []);
        }

        return response()->json([
            'data' => $this->workflow->createPartUsage(
                $request->validated(),
                (array) $request->file('files', []),
                $noteFiles,
            ),
        ], 201);
    }

    public function mistaken(Store $store, Ticket $ticket, PartUsage $partUsage)
    {
        return ['data' => $this->workflow->markPartUsageMistaken($partUsage)];
    }
}
