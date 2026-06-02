<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceEntryRequest;
use App\Models\AttendanceEntry;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\WorkflowRecordService;

class AttendanceEntryController extends Controller
{
    public function __construct(private WorkflowRecordService $workflow) {}

    public function store(StoreAttendanceEntryRequest $request, Store $store, Ticket $ticket)
    {
        return response()->json([
            'data' => $this->workflow->createAttendance($request->validated(), (array) $request->file('files', [])),
        ], 201);
    }

    public function mistaken(Store $store, Ticket $ticket, AttendanceEntry $attendanceEntry)
    {
        return ['data' => $this->workflow->markAttendanceMistaken($attendanceEntry)];
    }
}
