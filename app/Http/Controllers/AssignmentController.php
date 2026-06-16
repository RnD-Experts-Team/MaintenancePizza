<?php

namespace App\Http\Controllers;

use App\Http\Requests\ChangeTechniciansRequest;
use App\Http\Requests\StoreAssignmentRequest;
use App\Models\Assignment;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\AssignmentService;

class AssignmentController extends Controller
{
    public function __construct(private AssignmentService $assignments) {}

    /**
     * Assign one or more issues to technicians for a date/time.
     */
    public function store(StoreAssignmentRequest $request, Store $store, Ticket $ticket)
    {
        return response()->json(['data' => $this->assignments->create($ticket, $request->validated())], 201);
    }

    /**
     * Replace the technicians on this assignment's issues (no delay/history).
     */
    public function changeTechnicians(ChangeTechniciansRequest $request, Store $store, Ticket $ticket, Assignment $assignment)
    {
        return ['data' => $this->assignments->changeTechnicians($assignment, $request->validated()['technician_ids'])];
    }

    /**
     * Flag an assignment as entered in error.
     */
    public function mistaken(Store $store, Ticket $ticket, Assignment $assignment)
    {
        return ['data' => $this->assignments->markAssignmentMistaken($assignment)];
    }
}
