<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttendanceEntryRequest;
use App\Http\Requests\StoreGlobalAttendanceEntryRequest;
use App\Enums\AttendanceEventKind;
use App\Http\Requests\AttendanceEventRequest;
use App\Models\AttendanceEntry;
use App\Models\AttendanceEvent;
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

    /* ------------------------------------------------------------- Events */

    /*
     * Attendance is an event ledger: one row per thing that happened, so a
     * session can hold as many breaks as the day actually had, and adding to a
     * saved session is an insert rather than a correction.
     *
     * The routes are NOT scope-bound -- an attendance entry is not a child of
     * a ticket, it reaches issues through a pivot and can span several. So the
     * event-belongs-to-this-entry check is done here, explicitly, by the
     * helper below.
     */

    /**
     * Refuses an event reached through the wrong session's URL.
     *
     * 404 rather than 403: whether that event exists is none of this URL's
     * business, and saying "it exists but not here" leaks more than it helps.
     */
    private function ownedEvent(AttendanceEntry $entry, AttendanceEvent $event): AttendanceEvent
    {
        abort_unless($event->attendance_entry_id === $entry->id, 404);

        return $event;
    }


    public function eventsStore(
        AttendanceEventRequest $request,
        Store $store,
        Ticket $ticket,
        AttendanceEntry $attendanceEntry
    ) {
        $data = $request->validated();

        return response()->json([
            'data' => $this->workflow->appendAttendanceEvent(
                $attendanceEntry,
                AttendanceEventKind::from($data['kind']),
                $data['at'],
            ),
        ], 201);
    }

    /** Moves an event in time. Refused once a pay sheet has claimed the
     *  session -- flag it mistaken and record the right one instead. */
    public function eventsUpdate(
        AttendanceEventRequest $request,
        Store $store,
        Ticket $ticket,
        AttendanceEntry $attendanceEntry,
        AttendanceEvent $event
    ) {
        return ['data' => $this->workflow->updateAttendanceEvent(
            $this->ownedEvent($attendanceEntry, $event),
            $request->validated()['at'],
        )];
    }

    public function eventsMistaken(
        Store $store,
        Ticket $ticket,
        AttendanceEntry $attendanceEntry,
        AttendanceEvent $event
    ) {
        return ['data' => $this->workflow->markAttendanceEventMistaken(
            $this->ownedEvent($attendanceEntry, $event),
        )];
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
