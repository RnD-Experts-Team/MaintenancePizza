<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAttachmentRequest;
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
use App\Services\AttachmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;

/**
 * Generic attachment upload for every entity that can own attachments. Each
 * route binds its own owner model and hands it to the shared make() helper.
 */
class AttachmentController extends Controller
{
    public function __construct(private AttachmentService $attachments) {}

    // -------------------------------------------------- Store-scoped (tickets)

    public function ticket(StoreAttachmentRequest $request, Store $store, Ticket $ticket): JsonResponse
    {
        return $this->make($request, $ticket);
    }

    public function ticketIssue(StoreAttachmentRequest $request, Store $store, Ticket $ticket, TicketIssue $ticketIssue): JsonResponse
    {
        return $this->make($request, $ticketIssue);
    }

    public function diagnosis(StoreAttachmentRequest $request, Store $store, Ticket $ticket, Diagnosis $diagnosis): JsonResponse
    {
        return $this->make($request, $diagnosis);
    }

    public function attendance(StoreAttachmentRequest $request, Store $store, Ticket $ticket, AttendanceEntry $attendanceEntry): JsonResponse
    {
        return $this->make($request, $attendanceEntry);
    }

    public function partUsage(StoreAttachmentRequest $request, Store $store, Ticket $ticket, PartUsage $partUsage): JsonResponse
    {
        return $this->make($request, $partUsage);
    }

    public function payEntry(StoreAttachmentRequest $request, Store $store, Ticket $ticket, PayEntry $payEntry): JsonResponse
    {
        return $this->make($request, $payEntry);
    }

    public function warranty(StoreAttachmentRequest $request, Store $store, Ticket $ticket, Warranty $warranty): JsonResponse
    {
        return $this->make($request, $warranty);
    }

    public function assignment(StoreAttachmentRequest $request, Store $store, Ticket $ticket, Assignment $assignment): JsonResponse
    {
        return $this->make($request, $assignment);
    }

    public function store(StoreAttachmentRequest $request, Store $store): JsonResponse
    {
        return $this->make($request, $store);
    }

    // -------------------------------------------------------- Global catalogs

    public function catalogIssue(StoreAttachmentRequest $request, Issue $issue): JsonResponse
    {
        return $this->make($request, $issue);
    }

    public function technician(StoreAttachmentRequest $request, Technician $technician): JsonResponse
    {
        return $this->make($request, $technician);
    }

    public function category(StoreAttachmentRequest $request, Category $category): JsonResponse
    {
        return $this->make($request, $category);
    }

    public function part(StoreAttachmentRequest $request, Part $part): JsonResponse
    {
        return $this->make($request, $part);
    }

    // ----------------------------------------------------------------- Helper

    private function make(StoreAttachmentRequest $request, Model $owner): JsonResponse
    {
        $created = $this->attachments->store($owner, (array) $request->file('files', []));

        return response()->json([
            'data' => array_map(fn ($a) => $this->attachments->present($a), $created),
        ], 201);
    }
}
