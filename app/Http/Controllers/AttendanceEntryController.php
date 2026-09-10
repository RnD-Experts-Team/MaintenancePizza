<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceEntryRequest;
use App\Http\Requests\StoreGlobalAttendanceEntryRequest;
use App\Models\AttendanceEntry;
use App\Models\Store;
use App\Models\Ticket;
use App\Services\WorkflowRecordService;
use Illuminate\Http\Request;

class AttendanceEntryController extends Controller
{
    public function __construct(private WorkflowRecordService $workflow) {}

    public function store(StoreAttendanceEntryRequest $request, Store $store, Ticket $ticket)
    {
        return response()->json([
            'data' => $this->workflow->createAttendance(
                $request->validated(),
                (array) $request->file('files', []),
                $this->extractNoteFiles($request),
            ),
        ], 201);
    }

    /**
     * Attendance covering issues on more than one ticket, filed outside any of
     * them. The ticket-nested endpoint above remains the way to file a visit
     * that sits under a single ticket.
     */
    public function storeGlobal(StoreGlobalAttendanceEntryRequest $request)
    {
        return response()->json([
            'data' => $this->workflow->createAttendance(
                $request->validated(),
                (array) $request->file('files', []),
                $this->extractNoteFiles($request),
            ),
        ], 201);
    }

    public function mistaken(Store $store, Ticket $ticket, AttendanceEntry $attendanceEntry)
    {
        return ['data' => $this->workflow->markAttendanceMistaken($attendanceEntry)];
    }

    /**
     * Pull each inline note's files out of the multipart request, keyed by the
     * note's index in the payload.
     *
     * @return array<int, array<int, \Illuminate\Http\UploadedFile>>
     */
    private function extractNoteFiles(Request $request): array
    {
        $noteFiles = [];

        foreach ($request->input('notes', []) as $i => $_) {
            $noteFiles[$i] = (array) $request->file("notes.{$i}.files", []);
        }

        return $noteFiles;
    }
}
