<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDiagnosisRequest;
use App\Models\Diagnosis;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\WorkflowRecordService;

class DiagnosisController extends Controller
{
    public function __construct(private WorkflowRecordService $workflow) {}

    public function store(StoreDiagnosisRequest $request, Store $store, Ticket $ticket)
    {
        $data = $request->validated();

        return response()->json([
            'data' => $this->workflow->createDiagnosis(
                $data['ticket_issue_ids'],
                $data['body'] ?? null,
                (array) $request->file('files', []),
            ),
        ], 201);
    }

    public function mistaken(Store $store, Ticket $ticket, Diagnosis $diagnosis)
    {
        return ['data' => $this->workflow->markDiagnosisMistaken($diagnosis)];
    }
}
