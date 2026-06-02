<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAssignmentDelayRequest;
use App\Models\Assignment;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\AssignmentService;

class AssignmentDelayController extends Controller
{
    public function __construct(private AssignmentService $assignments) {}

    /**
     * Delay an assignment (snapshots the old date/time into history).
     */
    public function store(StoreAssignmentDelayRequest $request, Store $store, Ticket $ticket, Assignment $assignment)
    {
        return ['data' => $this->assignments->delay($assignment, $request->validated())];
    }
}
