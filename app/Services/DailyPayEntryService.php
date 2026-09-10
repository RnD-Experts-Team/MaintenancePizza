<?php

namespace App\Services;

use App\Models\DailyPayEntry;
use App\Models\DailyPayEntryRevision;
use App\Models\DailyPayLine;
use App\Models\DailyPayPayment;
use App\Models\TicketIssue;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A daily pay entry is one day's pay sheet, in three levels:
 *
 *   Entry (the date)
 *     └── Payment (one payee — a Technician row, which may be named after a
 *         company — carrying the money that is not attributable to one store)
 *           └── Line (one store: its hours, its money, the issues it covers)
 *
 * Each payment gathers its own hours and reimbursable parts from the attendance
 * and part-usage records already on those issues, and freezes them; see
 * DailyPayAggregationService.
 */
class DailyPayEntryService
{
    /** Money and hours a payment carries in its own right. */
    private const PAYMENT_FIELDS = [
        'hourly_payment_rate',
        'lump_sum',
        'gas',
        'money_owed',
    ];

    /** Per-store figures on a line. */
    private const LINE_FIELDS = [
        'other_store',
        'total_working_hours',
        'gas',
        'lump_sum',
        'hourly_payment_rate',
        'money_owed',
        'travel_time',
        'total_break_time',
        'parts_run_time',
    ];

    /**
     * Hours that, when explicitly supplied, mark a line as hand-corrected so
     * recalculate() leaves it alone.
     */
    private const OVERRIDABLE_HOURS = [
        'total_working_hours',
        'travel_time',
        'total_break_time',
        'parts_run_time',
    ];

    private const SHOW_WITH = [
        'creator',
        'payments.creator',
        'payments.aggregator',
        'payments.technician.category',
        'payments.notes.creator',
        'payments.notes.attachments.creator',
        'payments.attachments.creator',
        'payments.lines.creator',
        'payments.lines.technician.category',
        'payments.lines.store',
        'payments.lines.ticketIssues.creator',
        'payments.lines.ticketIssues.ticket.creator',
        'payments.lines.ticketIssues.ticket.store',
        'payments.lines.ticketIssues.issue.creator',
        'payments.lines.ticketIssues.technicians.creator',
        'payments.lines.ticketIssues.technicians.category',
        'payments.lines.notes.creator',
        'payments.lines.notes.attachments.creator',
        'payments.lines.attachments.creator',
        'revisions.creator',
    ];

    private const LIST_WITH = [
        'creator',
        'payments.creator',
        'payments.technician',
        'payments.lines.creator',
        'payments.lines.technician',
        'payments.lines.store',
    ];

    public function __construct(
        private NoteService $notes,
        private AttachmentService $attachments,
        private CatalogService $catalog,
        private DailyPayAggregationService $aggregation,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, array<int, UploadedFile>>  $paymentFilesMap        paymentIndex → files
     * @param  array<int, array<int, array<int, UploadedFile>>>  $paymentNoteFilesMap  paymentIndex → noteIndex → files
     * @param  array<int, array<int, array<int, UploadedFile>>>  $lineFilesMap         paymentIndex → lineIndex → files
     * @param  array<int, array<int, array<int, array<int, UploadedFile>>>>  $lineNoteFilesMap  paymentIndex → lineIndex → noteIndex → files
     * @return array<string, mixed>
     */
    public function create(
        array $validated,
        array $paymentFilesMap = [],
        array $paymentNoteFilesMap = [],
        array $lineFilesMap = [],
        array $lineNoteFilesMap = [],
    ): array {
        $entry = DB::transaction(function () use ($validated, $paymentFilesMap, $paymentNoteFilesMap, $lineFilesMap, $lineNoteFilesMap) {
            $entry = new DailyPayEntry(['date' => $validated['date']]);
            $entry->created_by = Auth::id();
            $entry->save();

            $this->buildPayments($entry, $validated, $paymentFilesMap, $paymentNoteFilesMap, $lineFilesMap, $lineNoteFilesMap);

            // Freeze inside the same transaction, so an entry is never stored
            // with stale gathered figures.
            $this->aggregation->recalculate($entry);

            return $entry;
        });

        return $this->show($entry);
    }

    /**
     * Snapshot the current state, then replace the payments wholesale.
     *
     * Deleting the payments cascades to their lines, and from there to the
     * issue links and both aggregation claim pivots — so unlike a hand-rolled
     * walk it cannot leave a stale claim behind.
     *
     * @param  array<string, mixed>  $validated
     * @param  array<int, array<int, UploadedFile>>  $paymentFilesMap
     * @param  array<int, array<int, array<int, UploadedFile>>>  $paymentNoteFilesMap
     * @param  array<int, array<int, array<int, UploadedFile>>>  $lineFilesMap
     * @param  array<int, array<int, array<int, array<int, UploadedFile>>>>  $lineNoteFilesMap
     * @return array<string, mixed>
     */
    public function edit(
        DailyPayEntry $entry,
        array $validated,
        array $paymentFilesMap = [],
        array $paymentNoteFilesMap = [],
        array $lineFilesMap = [],
        array $lineNoteFilesMap = [],
    ): array {
        $this->guardAgainstConcurrentEdit($entry, $validated['expected_updated_at'] ?? null);

        DB::transaction(function () use ($entry, $validated, $paymentFilesMap, $paymentNoteFilesMap, $lineFilesMap, $lineNoteFilesMap) {
            DailyPayEntryRevision::create([
                'daily_pay_entry_id' => $entry->id,
                'snapshot' => $this->show($entry),
                'schema_version' => DailyPayEntryRevision::SCHEMA_VERSION,
                'edited_by' => Auth::id(),
            ]);

            // Polymorphic notes and attachments have no DB-level cascade, so
            // they are walked bottom-up before the rows that own them go.
            $entry->load('payments.lines.notes', 'payments.lines.attachments', 'payments.notes', 'payments.attachments');

            $entry->payments->each(function (DailyPayPayment $payment) {
                $payment->lines->each(function (DailyPayLine $line) {
                    $line->notes->each(fn ($note) => $note->attachments()->delete());
                    $line->notes()->delete();
                    $line->attachments()->delete();
                });

                $payment->notes->each(fn ($note) => $note->attachments()->delete());
                $payment->notes()->delete();
                $payment->attachments()->delete();
            });

            $entry->payments()->delete();
            // Belt and braces: any line left behind by an older backfill state
            // would otherwise survive its payment being removed.
            $entry->lines()->delete();

            $entry->update(['date' => $validated['date']]);
            $entry->setRelation('payments', $entry->payments()->getRelated()->newCollection());

            $this->buildPayments($entry, $validated, $paymentFilesMap, $paymentNoteFilesMap, $lineFilesMap, $lineNoteFilesMap);
            $this->aggregation->recalculate($entry);
        });

        return $this->show($entry->refresh());
    }

    /**
     * Re-pull the gathered hours and reimbursable parts for every payment on
     * the entry. Lines whose hours were typed in are left as typed.
     *
     * @return array<string, mixed>
     */
    public function recalculate(DailyPayEntry $entry): array
    {
        DB::transaction(fn () => $this->aggregation->recalculate($entry));

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
            $query->whereHas('payments', fn(Builder $q) => $q->whereIn('technician_id', $ids));
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
            $query->whereIn('created_by', array_filter((array) $filters['filled_by']));
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
     * An edit replaces the whole entry, so two people saving the same pay sheet
     * would silently overwrite each other — and with figures now computed and
     * frozen, the loser's numbers would vanish with no trace.
     *
     * Callers that send back the `updated_at` they last read get a 409 instead.
     * Optional, so existing callers are unaffected.
     */
    private function guardAgainstConcurrentEdit(DailyPayEntry $entry, ?string $expectedUpdatedAt): void
    {
        if ($expectedUpdatedAt === null) {
            return;
        }

        $current = $entry->updated_at?->toIso8601String();
        $expected = \Illuminate\Support\Carbon::parse($expectedUpdatedAt)->toIso8601String();

        if ($current !== null && $current !== $expected) {
            abort(409, 'This pay entry was changed by someone else since you loaded it. Reload it and reapply your edit.');
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @param  array<int, array<int, UploadedFile>>  $paymentFilesMap
     * @param  array<int, array<int, array<int, UploadedFile>>>  $paymentNoteFilesMap
     * @param  array<int, array<int, array<int, UploadedFile>>>  $lineFilesMap
     * @param  array<int, array<int, array<int, array<int, UploadedFile>>>>  $lineNoteFilesMap
     */
    private function buildPayments(
        DailyPayEntry $entry,
        array $validated,
        array $paymentFilesMap,
        array $paymentNoteFilesMap,
        array $lineFilesMap,
        array $lineNoteFilesMap,
    ): void {
        foreach ($validated['payments'] as $p => $paymentData) {
            $payment = $this->createPayment(
                $entry,
                $paymentData,
                $paymentFilesMap[$p] ?? [],
                $paymentNoteFilesMap[$p] ?? [],
            );

            foreach ($paymentData['lines'] ?? [] as $l => $lineData) {
                $this->createLine(
                    $payment,
                    $lineData,
                    $lineFilesMap[$p][$l] ?? [],
                    $lineNoteFilesMap[$p][$l] ?? [],
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, array<int, UploadedFile>>  $noteFiles
     */
    private function createPayment(DailyPayEntry $entry, array $data, array $files, array $noteFiles): DailyPayPayment
    {
        $payment = $entry->payments()->create(array_merge(
            [
                'technician_id' => $data['technician_id'],
                'created_by' => Auth::id(),
            ],
            array_intersect_key($data, array_flip(self::PAYMENT_FIELDS)),
        ));

        $this->attachments->store($payment, $files);

        foreach ($data['notes'] ?? [] as $i => $note) {
            $this->notes->store($payment, $note['body'], $note['type'] ?? null, $noteFiles[$i] ?? []);
        }

        return $payment;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, UploadedFile>  $files
     * @param  array<int, array<int, UploadedFile>>  $noteFiles
     */
    private function createLine(DailyPayPayment $payment, array $data, array $files, array $noteFiles): DailyPayLine
    {
        $line = $payment->lines()->create(array_merge(
            [
                'daily_pay_entry_id' => $payment->daily_pay_entry_id,
                // Denormalised from the payment on purpose — see DailyPayLine.
                'technician_id' => $payment->technician_id,
                'store_id' => $data['store_id'] ?? null,
                // array_key_exists, not !empty: 0 is a legitimate override.
                'hours_overridden' => $this->statesItsOwnHours($data),
                'created_by' => Auth::id(),
            ],
            array_intersect_key($data, array_flip(self::LINE_FIELDS)),
        ));

        if (!empty($data['ticket_issue_ids'])) {
            $line->ticketIssues()->attach($data['ticket_issue_ids']);
        }

        $this->attachments->store($line, $files);

        foreach ($data['notes'] ?? [] as $i => $note) {
            $this->notes->store($line, $note['body'], $note['type'] ?? null, $noteFiles[$i] ?? []);
        }

        return $line;
    }

    /**
     * Whether the payload states any of the hours itself, in which case the
     * gather must not overwrite them.
     *
     * @param  array<string, mixed>  $data
     */
    private function statesItsOwnHours(array $data): bool
    {
        foreach (self::OVERRIDABLE_HOURS as $field) {
            if (array_key_exists($field, $data) && $data[$field] !== null) {
                return true;
            }
        }

        return false;
    }

    // --------------------------------------------------------------- Presenters

    /** @return array<string, mixed> */
    private function presentEntry(DailyPayEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'date' => $entry->date->toDateString(),
            'payments' => $entry->relationLoaded('payments')
                ? $entry->payments->map(fn(DailyPayPayment $p) => $this->presentPayment($p))->all()
                : null,
            'total_amount' => $entry->relationLoaded('payments')
                ? $entry->payments->reduce(
                    fn(string $carry, DailyPayPayment $p) => bcadd($carry, number_format((float) ($p->total_amount ?? 0), 2, '.', ''), 2),
                    '0.00'
                )
                : null,
            'revisions' => $entry->relationLoaded('revisions')
                ? $entry->revisions->map(fn(DailyPayEntryRevision $r) => $this->presentRevision($r))->all()
                : null,
            'created_by' => $entry->created_by,
            'creator' => $entry->relationLoaded('creator') && $entry->creator
                ? $this->catalog->presentUser($entry->creator)
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
            'payments' => $entry->relationLoaded('payments')
                ? $entry->payments->map(fn(DailyPayPayment $p) => $this->presentPaymentSummary($p))->all()
                : null,
            'total_amount' => $entry->relationLoaded('payments')
                ? $entry->payments->reduce(
                    fn(string $carry, DailyPayPayment $p) => bcadd($carry, number_format((float) ($p->total_amount ?? 0), 2, '.', ''), 2),
                    '0.00'
                )
                : null,
            'created_by' => $entry->created_by,
            'creator' => $entry->relationLoaded('creator') && $entry->creator
                ? $this->catalog->presentUser($entry->creator)
                : null,
            'created_at' => $entry->created_at,
            'updated_at' => $entry->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function presentPayment(DailyPayPayment $payment): array
    {
        return array_merge($this->presentPaymentSummary($payment), [
            'lines' => $payment->relationLoaded('lines')
                ? $payment->lines->map(fn(DailyPayLine $l) => $this->presentLine($l))->all()
                : null,
            'aggregation_warnings' => $payment->aggregation_warnings ?? [],
            'notes' => $this->notes->presentMany($payment),
            'attachments' => $this->attachments->presentMany($payment),
        ]);
    }

    /** @return array<string, mixed> */
    private function presentPaymentSummary(DailyPayPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'daily_pay_entry_id' => $payment->daily_pay_entry_id,
            'technician_id' => $payment->technician_id,
            // The payee. A "company" is a technician row named after it.
            'technician' => $payment->relationLoaded('technician') && $payment->technician
                ? $this->catalog->presentTechnician($payment->technician)
                : null,
            'hourly_payment_rate' => $payment->hourly_payment_rate,
            'lump_sum' => $payment->lump_sum,
            'gas' => $payment->gas,
            // An optional extra we also owe them — NOT the total.
            'money_owed' => $payment->money_owed,
            'gathered' => [
                'work_hours' => $payment->frozen_work_hours,
                'travel_hours' => $payment->frozen_travel_hours,
                // Tracked, but not paid.
                'break_hours' => $payment->frozen_break_hours,
                'parts_run_hours' => $payment->frozen_parts_run_hours,
                'reimbursable_parts' => $payment->frozen_reimbursable_parts,
                'at' => $payment->aggregated_at,
                'by' => $payment->relationLoaded('aggregator') && $payment->aggregator
                    ? $this->catalog->presentUser($payment->aggregator)
                    : null,
            ],
            'lines_total' => $payment->lines_total,
            // The payable figure.
            'total_amount' => $payment->total_amount,
            'created_by' => $payment->created_by,
            'creator' => $payment->relationLoaded('creator') && $payment->creator
                ? $this->catalog->presentUser($payment->creator)
                : null,
            'created_at' => $payment->created_at,
            'updated_at' => $payment->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function presentLine(DailyPayLine $line): array
    {
        return [
            'id' => $line->id,
            'daily_pay_entry_id' => $line->daily_pay_entry_id,
            'daily_pay_payment_id' => $line->daily_pay_payment_id,
            'technician_id' => $line->technician_id,
            'technician' => $line->relationLoaded('technician') && $line->technician
                ? $this->catalog->presentTechnician($line->technician)
                : null,
            'store_id' => $line->store_id,
            // Set instead of store_id for a location outside the replicated
            // store list, exactly as tickets do it.
            'other_store' => $line->other_store,
            'store' => $line->relationLoaded('store') && $line->store
                ? ['id' => $line->store->id, 'store_number' => $line->store->store_number]
                : null,
            'total_working_hours' => $line->total_working_hours,
            'travel_time' => $line->travel_time,
            'total_break_time' => $line->total_break_time,
            'parts_run_time' => $line->parts_run_time,
            // True when the hours above were typed in rather than gathered;
            // recalculate() then leaves them alone.
            'hours_overridden' => $line->hours_overridden,
            'gas' => $line->gas,
            'lump_sum' => $line->lump_sum,
            'hourly_payment_rate' => $line->hourly_payment_rate,
            'money_owed' => $line->money_owed,
            'gathered' => [
                'work_hours' => $line->frozen_work_hours,
                'travel_hours' => $line->frozen_travel_hours,
                'break_hours' => $line->frozen_break_hours,
                'parts_run_hours' => $line->frozen_parts_run_hours,
                'reimbursable_parts' => $line->frozen_reimbursable_parts,
            ],
            'line_total' => $line->line_total,
            'ticket_issues' => $line->relationLoaded('ticketIssues')
                ? $line->ticketIssues->map(fn($ti) => $this->presentTicketIssue($ti))->all()
                : null,
            'notes' => $this->notes->presentMany($line),
            'attachments' => $this->attachments->presentMany($line),
            'created_by' => $line->created_by,
            'creator' => $line->relationLoaded('creator') && $line->creator
                ? $this->catalog->presentUser($line->creator)
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
            // 1 = the old {date, lines} shape, 2 = {date, payments[{lines}]}.
            // Snapshots are never rewritten, so both shapes exist in history.
            'schema_version' => $revision->schema_version,
            'edited_by' => $revision->edited_by,
            'creator' => $revision->relationLoaded('creator') && $revision->creator
                ? $this->catalog->presentUser($revision->creator)
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
                ? [
                    'id' => $issue->ticket->id,
                    'store_id' => $issue->ticket->store_id,
                    'other_store' => $issue->ticket->other_store,
                    'store' => $issue->ticket->relationLoaded('store') && $issue->ticket->store
                        ? ['id' => $issue->ticket->store->id, 'store_number' => $issue->ticket->store->store_number]
                        : null,
                    'created_by' => $issue->ticket->created_by,
                    'creator' => $issue->ticket->relationLoaded('creator') && $issue->ticket->creator
                        ? $this->catalog->presentUser($issue->ticket->creator)
                        : null,
                ]
                : null,
            'issue_id' => $issue->issue_id,
            'issue' => $issue->relationLoaded('issue') && $issue->issue
                ? [
                    'id' => $issue->issue->id,
                    'title' => $issue->issue->title,
                    'description' => $issue->issue->description,
                    'created_by' => $issue->issue->created_by,
                    'creator' => $issue->issue->relationLoaded('creator') && $issue->issue->creator
                        ? $this->catalog->presentUser($issue->issue->creator)
                        : null,
                ]
                : null,
            'other_title' => $issue->other_title,
            'priority' => $issue->priority,
            'description' => $issue->description,
            'status' => $issue->status,
            'technicians' => $issue->relationLoaded('technicians')
                ? $issue->technicians->map(fn($t) => array_merge($this->catalog->presentTechnician($t), [
                    'creator' => $t->relationLoaded('creator') && $t->creator
                        ? $this->catalog->presentUser($t->creator)
                        : null,
                ]))->all()
                : null,
            'created_by' => $issue->created_by,
            'creator' => $issue->relationLoaded('creator') && $issue->creator
                ? $this->catalog->presentUser($issue->creator)
                : null,
            'created_at' => $issue->created_at,
            'updated_at' => $issue->updated_at,
        ];
    }
}
