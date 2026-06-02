<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePayEntryRequest;
use App\Models\PayEntry;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\WorkflowRecordService;

class PayEntryController extends Controller
{
    public function __construct(private WorkflowRecordService $workflow) {}

    public function store(StorePayEntryRequest $request, Store $store, Ticket $ticket)
    {
        return response()->json(['data' => $this->workflow->createPayEntry($request->validated())], 201);
    }

    public function mistaken(Store $store, Ticket $ticket, PayEntry $payEntry)
    {
        return ['data' => $this->workflow->markPayEntryMistaken($payEntry)];
    }
}
