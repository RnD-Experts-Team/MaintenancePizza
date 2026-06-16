<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNoteRequest;
use App\Models\Assignment;
use App\Models\AttendanceEntry;
use App\Models\Category;
use App\Models\Diagnosis;
use App\Models\Issue;
use App\Models\Part;
use App\Models\PartUsage;
use App\Models\PayEntry;
use App\Models\Store;
use App\Models\Technician;
use App\Models\Ticket;
use App\Models\TicketIssue;
use App\Models\Warranty;
use App\Services\NoteService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Generic note creation for every entity that can own notes. Each route binds
 * its own owner model (so scoping/route-model binding stays intact) and hands
 * it to the shared make() helper.
 */
class NoteController extends Controller
{
    public function __construct(private NoteService $notes) {}

    // -------------------------------------------------- Store-scoped (tickets)

    public function ticket(StoreNoteRequest $request, Store $store, Ticket $ticket): JsonResponse
    {
        return $this->make($request, $ticket);
    }

    public function ticketIssue(StoreNoteRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): JsonResponse
    {
        return $this->make($request, $ticketIssue);
    }

    public function diagnosis(StoreNoteRequest $request, Store $store, Ticket $ticket, Diagnosis $diagnosis): JsonResponse
    {
        return $this->make($request, $diagnosis);
    }

    public function attendance(StoreNoteRequest $request, Store $store, Ticket $ticket, AttendanceEntry $attendanceEntry): JsonResponse
    {
        return $this->make($request, $attendanceEntry);
    }

    public function partUsage(StoreNoteRequest $request, Store $store, Ticket $ticket, PartUsage $partUsage): JsonResponse
    {
        return $this->make($request, $partUsage);
    }

    public function payEntry(StoreNoteRequest $request, Store $store, Ticket $ticket, PayEntry $payEntry): JsonResponse
    {
        return $this->make($request, $payEntry);
    }

    public function warranty(StoreNoteRequest $request, Store $store, Ticket $ticket, Warranty $warranty): JsonResponse
    {
        return $this->make($request, $warranty);
    }

    public function assignment(StoreNoteRequest $request, Store $store, Ticket $ticket, Assignment $assignment): JsonResponse
    {
        return $this->make($request, $assignment);
    }

    public function store(StoreNoteRequest $request, Store $store): JsonResponse
    {
        return $this->make($request, $store);
    }

    // -------------------------------------------------------- Global catalogs

    public function catalogIssue(StoreNoteRequest $request, Issue $issue): JsonResponse
    {
        return $this->make($request, $issue);
    }

    public function technician(StoreNoteRequest $request, Technician $technician): JsonResponse
    {
        return $this->make($request, $technician);
    }

    public function category(StoreNoteRequest $request, Category $category): JsonResponse
    {
        return $this->make($request, $category);
    }

    public function part(StoreNoteRequest $request, Part $part): JsonResponse
    {
        return $this->make($request, $part);
    }

    // ----------------------------------------------------------------- Helper

    private function make(StoreNoteRequest $request, Model $owner): JsonResponse
    {
        $data = $request->validated();

        $note = $this->notes->store(
            $owner,
            $data['body'],
            $data['type'] ?? null,
            (array) $request->file('files', []),
        );

        return response()->json(['data' => $this->notes->present($note)], 201);
    }
}
