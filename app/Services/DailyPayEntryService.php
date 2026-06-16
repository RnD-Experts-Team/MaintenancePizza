<?php

namespace App\Services;

use App\Models\DailyPayEntry;
use App\Models\DailyPayEntryRevision;
use App\Models\DailyPayLine;
use App\Models\TicketIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DailyPayEntryService
{
    private const LINE_FIELDS = [
        'total_working_hours',
        'gas',
        'invoices',
        'hourly_payment_rate',
        'money_owed',
        'travel_time',
        'total_break_time',
    ];

    private const SHOW_WITH = [
        'creator',
        'lines.creator',
        'lines.technician.category',
        'lines.store',
        'lines.ticketIssues.creator',
        'lines.ticketIssues.ticket.creator',
        'lines.ticketIssues.ticket.store',
        'lines.ticketIssues.issue.creator',
        'lines.ticketIssues.technicians.creator',
        'lines.ticketIssues.technicians.category',
        'lines.notes.creator',
        'lines.notes.attachments.creator',
        'lines.attachments.creator',
        'revisions.creator',
    ];

    private const LIST_WITH = [
        'creator',
        'lines.creator',
        'lines.technician',
        'lines.store',
    ];

    public function __construct(
        private NoteService $notes,
        private AttachmentService $attachments,
        private CatalogService $catalog,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, array<int, UploadedFile>>  $lineFilesMap      lineIndex → files
     * @param  array<int, array<int, array<int, UploadedFile>>>  $lineNoteFilesMap  lineIndex → noteIndex → files
     * @return array<string, mixed>
     */
    public function create(array $validated, array $lineFilesMap, array $lineNoteFilesMap): array
    {
        $entry = DB::transaction(function () use ($validated, $lineFilesMap, $lineNoteFilesMap) {
            $entry = new DailyPayEntry(['date' => $validated['date']]);
            $entry->created_by = Auth::id();
            $entry->save();

            foreach ($validated['lines'] as $i => $lineData) {
                $this->createLine($entry, $lineData, $lineFilesMap[$i] ?? [], $lineNoteFilesMap[$i] ?? []);
            }

            return $entry;
        });

        return $this->show($entry);
    }

    /**
     * Snapshot the current state, then fully replace all lines with the new payload.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, array<int, UploadedFile>>  $lineFilesMap
     * @param  array<int, array<int, array<int, UploadedFile>>>  $lineNoteFilesMap
     * @return array<string, mixed>
     */
    public function edit(DailyPayEntry $entry, array $validated, array $lineFilesMap, array $lineNoteFilesMap): array
    {
        DB::transaction(function () use ($entry, $validated, $lineFilesMap, $lineNoteFilesMap) {
            // Capture the full state before making any changes.
            $snapshot = $this->show($entry);

            DailyPayEntryRevision::create([
                'daily_pay_entry_id' => $entry->id,
                'snapshot' => $snapshot,
                'edited_by' => Auth::id(),
            ]);

            // Polymorphic notes/attachments have no DB-level cascade; walk bottom-up.
            $entry->load('lines.notes.attachments', 'lines.attachments');
            $entry->lines->each(function (DailyPayLine $line) {
                $line->notes->each(fn($note) => $note->attachments()->delete());
                $line->notes()->delete();
                $line->attachments()->delete();
            });
            $entry->lines()->delete();

            $entry->update(['date' => $validated['date']]);

            foreach ($validated['lines'] as $i => $lineData) {
                $this->createLine($entry, $lineData, $lineFilesMap[$i] ?? [], $lineNoteFilesMap[$i] ?? []);
            }
        });

        return $this->show($entry->refresh());
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        /** @var Builder<DailyPayEntry> $query */
        $query = DailyPayEntry::query()->with(self::LIST_WITH);

        if (!empty($filters['technician_ids'])) {
            $ids = (array) $filters['technician_ids'];
            $query->whereHas('lines', fn(Builder $q) => $q->whereIn('technician_id', $ids));
        }

        if (!empty($filters['store_ids'])) {
            $ids = (array) $filters['store_ids'];
            $query->whereHas('lines', fn(Builder $q) => $q->whereIn('store_id', $ids));
        }

        if (!empty($filters['date'])) {
            $query->whereDate('date', $filters['date']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('date', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('date', '<=', $filters['date_to']);
        }

        if (!empty($filters['filled_by'])) {
            $query->where('created_by', $filters['filled_by']);
        }

        if (!empty($filters['created_from'])) {
            $query->where('created_at', '>=', $filters['created_from']);
        }

        if (!empty($filters['created_to'])) {
            $query->where('created_at', '<=', $filters['created_to']);
        }

        $sort = in_array($filters['sort'] ?? '', ['date', 'created_at'], true)
            ? $filters['sort']
            : 'created_at';
        $dir = strtolower((string) ($filters['dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $query->orderBy($sort, $dir);

        $perPage = max(1, (int) ($filters['per_page'] ?? 15));

        return $query->paginate($perPage)
            ->through(fn(DailyPayEntry $e) => $this->presentSummary($e));
    }

    /** @return array<string, mixed> */
    public function show(DailyPayEntry $entry): array
    {
        return $this->presentEntry($entry->load(self::SHOW_WITH));
    }

    // ---------------------------------------------------------------- Internals

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, array<int, UploadedFile>>  $noteFiles
     */
    private function createLine(DailyPayEntry $entry, array $data, array $files, array $noteFiles): DailyPayLine
    {
        $line = $entry->lines()->create(array_merge(
            [
                'technician_id' => $data['technician_id'],
                'store_id' => $data['store_id'],
                'created_by' => Auth::id(),
            ],
            array_intersect_key($data, array_flip(self::LINE_FIELDS))
        ));

        if (!empty($data['ticket_issue_ids'])) {
            $line->ticketIssues()->attach($data['ticket_issue_ids']);
        }

        $this->attachments->store($line, $files);

        foreach ($data['notes'] ?? [] as $ni => $noteData) {
            $this->notes->store(
                $line,
                $noteData['body'],
                $noteData['type'] ?? null,
                $noteFiles[$ni] ?? []
            );
        }

        return $line;
    }

    // --------------------------------------------------------------- Presenters

    /** @return array<string, mixed> */
    private function presentEntry(DailyPayEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->date->toDateString(),
            'lines' => $entry->relationLoaded('lines')
                ? $entry->lines->map(fn(DailyPayLine $l) => $this->presentLine($l))->all()
                : null,
            'revisions' => $entry->relationLoaded('revisions')
                ? $entry->revisions->map(fn(DailyPayEntryRevision $r) => $this->presentRevision($r))->all()
                : null,
            'created_by' => $entry->created_by,
            'creator' => $entry->relationLoaded('creator') && $entry->creator
                ? ['id' => $entry->creator->id, 'name' => $entry->creator->name, 'email' => $entry->creator->email]
                : null,
            'created_at' => $entry->created_at,
            'updated_at' => $entry->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function presentSummary(DailyPayEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->date->toDateString(),
            'lines' => $entry->relationLoaded('lines')
                ? $entry->lines->map(fn(DailyPayLine $l) => $this->presentLineSummary($l))->all()
                : null,
            'created_by' => $entry->created_by,
            'creator' => $entry->relationLoaded('creator') && $entry->creator
                ? ['id' => $entry->creator->id, 'name' => $entry->creator->name, 'email' => $entry->creator->email]
                : null,
            'created_at' => $entry->created_at,
            'updated_at' => $entry->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function presentLine(DailyPayLine $line): array
    {
        return [
            'id' => $line->id,
            'daily_pay_entry_id' => $line->daily_pay_entry_id,
            'technician_id' => $line->technician_id,
            'technician' => $line->relationLoaded('technician') && $line->technician
                ? $this->catalog->presentTechnician($line->technician)
                : null,
            'store_id' => $line->store_id,
            'store' => $line->relationLoaded('store') && $line->store
                ? ['id' => $line->store->id, 'store_number' => $line->store->store_number]
                : null,
            'total_working_hours' => $line->total_working_hours,
            'gas' => $line->gas,
            'invoices' => $line->invoices,
            'hourly_payment_rate' => $line->hourly_payment_rate,
            'money_owed' => $line->money_owed,
            'travel_time' => $line->travel_time,
            'total_break_time' => $line->total_break_time,
            'ticket_issues' => $line->relationLoaded('ticketIssues')
                ? $line->ticketIssues->map(fn($ti) => $this->presentTicketIssue($ti))->all()
                : null,
            'notes' => $this->notes->presentMany($line),
            'attachments' => $this->attachments->presentMany($line),
            'created_by' => $line->created_by,
            'creator' => $line->relationLoaded('creator') && $line->creator
                ? ['id' => $line->creator->id, 'name' => $line->creator->name, 'email' => $line->creator->email]
                : null,
            'created_at' => $line->created_at,
            'updated_at' => $line->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function presentLineSummary(DailyPayLine $line): array
    {
        return [
            'id' => $line->id,
            'daily_pay_entry_id' => $line->daily_pay_entry_id,
            'technician_id' => $line->technician_id,
            'technician' => $line->relationLoaded('technician') && $line->technician
                ? $this->catalog->presentTechnician($line->technician)
                : null,
            'store_id' => $line->store_id,
            'store' => $line->relationLoaded('store') && $line->store
                ? ['id' => $line->store->id, 'store_number' => $line->store->store_number]
                : null,
            'total_working_hours' => $line->total_working_hours,
            'gas' => $line->gas,
            'invoices' => $line->invoices,
            'hourly_payment_rate' => $line->hourly_payment_rate,
            'money_owed' => $line->money_owed,
            'travel_time' => $line->travel_time,
            'total_break_time' => $line->total_break_time,
            'created_by' => $line->created_by,
            'creator' => $line->relationLoaded('creator') && $line->creator
                ? ['id' => $line->creator->id, 'name' => $line->creator->name, 'email' => $line->creator->email]
                : null,
            'created_at' => $line->created_at,
            'updated_at' => $line->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function presentRevision(DailyPayEntryRevision $revision): array
    {
        return [
            'id' => $revision->id,
            'daily_pay_entry_id' => $revision->daily_pay_entry_id,
            'snapshot' => $revision->snapshot,
            'edited_by' => $revision->edited_by,
            'creator' => $revision->relationLoaded('creator') && $revision->creator
                ? ['id' => $revision->creator->id, 'name' => $revision->creator->name, 'email' => $revision->creator->email]
                : null,
            'created_at' => $revision->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function presentTicketIssue(TicketIssue $issue): array
    {
        return [
            'id' => $issue->id,
            'ticket_id' => $issue->ticket_id,
            'ticket' => $issue->relationLoaded('ticket') && $issue->ticket
                ? ['id' => $issue->ticket->id, 'store_id' => $issue->ticket->store_id, 'store' => $issue->ticket->relationLoaded('store') && $issue->ticket->store ? ['id' => $issue->ticket->store->id, 'store_number' => $issue->ticket->store->store_number] : null, 'created_by' => $issue->ticket->created_by, 'creator' => $issue->ticket->relationLoaded('creator') && $issue->ticket->creator ? ['id' => $issue->ticket->creator->id, 'name' => $issue->ticket->creator->name, 'email' => $issue->ticket->creator->email] : null]
                : null,
            'issue_id' => $issue->issue_id,
            'issue' => $issue->relationLoaded('issue') && $issue->issue
                ? ['id' => $issue->issue->id, 'title' => $issue->issue->title, 'description' => $issue->issue->description, 'created_by' => $issue->issue->created_by, 'creator' => $issue->issue->relationLoaded('creator') && $issue->issue->creator ? ['id' => $issue->issue->creator->id, 'name' => $issue->issue->creator->name, 'email' => $issue->issue->creator->email] : null]
                : null,
            'other_title' => $issue->other_title,
            'priority' => $issue->priority,
            'description' => $issue->description,
            'status' => $issue->status,
            'technicians' => $issue->relationLoaded('technicians')
                ? $issue->technicians->map(fn($t) => array_merge($this->catalog->presentTechnician($t), ['creator' => $t->relationLoaded('creator') && $t->creator ? ['id' => $t->creator->id, 'name' => $t->creator->name, 'email' => $t->creator->email] : null]))->all()
                : null,
            'created_by' => $issue->created_by,
            'creator' => $issue->relationLoaded('creator') && $issue->creator
                ? ['id' => $issue->creator->id, 'name' => $issue->creator->name, 'email' => $issue->creator->email]
                : null,
            'created_at' => $issue->created_at,
            'updated_at' => $issue->updated_at,
        ];
    }
}
